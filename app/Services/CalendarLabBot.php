<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class CalendarLabBot
{
    public function __construct(private CalendarLab $lab, private Telegram $tg) {}

    public function button(object $p, string $data, int $messageId): bool
    {
        if ($data!=='lab' && !str_starts_with($data,'lab:')) return false;
        if (!$this->lab->allowed($p->id)) return true;
        [$action,$value]=array_pad(explode(':',substr($data,4),2),2,'');
        if ($data==='lab') {
            $this->state($p->id,null);
            $c=DB::table('calendar_lab_connections')->where('profile_id',$p->id)->first();
            $text="🧪 Тест Google Calendar\n\nЦе окремий тест лише для тебе. Основні задачі, прогрес і штрафи не змінюються.\n\nЯ створю календар «Крок вперед · ТЕСТ 🧪». Нові події з нього з’являтимуться в цьому тестовому розділі, а тестові задачі з бота — у календарі. Інші календарі не читаю.\n\nОновлення перевіряються щохвилини. Події без конкретної години поки пропускаються. Час події — дедлайн тестової задачі.";
            $rows=[];
            if (!$this->lab->configured()) $text.="\n\n⏳ Підключення Google ще не активоване: власнику потрібно один раз налаштувати Google Cloud. Клієнтам цього робити не доведеться.";
            elseif (!$c || !$c->calendar_id) $rows[]=[['Підключити Google Calendar','lab:connect']];
            if ($c && $c->calendar_id) {
                $text.="\n\nСтан: ".match ($c->status) {'active'=>'підключено','deleting'=>'видаляю тестовий календар',default=>'потребує перевірки'};
                if ($c->last_synced_at) $text.="\nОстання перевірка: ".Time::parse($c->last_synced_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i');
                if ($c->error) $text.="\n⚠️ Остання спроба не завершилась. Перевір дозвіл Google; наступна спроба буде автоматично.";
                if ($c->status==='active') {
                    $rows[]=[['＋ Тестова задача','lab:new'],['Тестові плани','lab:list']];
                    $rows[]=[['Скопіювати мої плани на 7 днів','lab:copy']];
                }
                $rows[]=[['Завершити й видалити тест','lab:removeask']];
                $rows[]=[['Вимкнути, залишивши календар Google','lab:forgetask']];
            }
            $rows[]=[['Повернутися в меню','home']];
            $this->tg->edit($p->id,$messageId,$text,$rows); return true;
        }
        if ($action==='connect') {
            if (!$this->lab->configured()) { $this->button($p,'lab',$messageId); return true; }
            $url=$this->lab->connectLink($p->id);
            $this->tg->enqueue('sendMessage',['chat_id'=>$p->id,'text'=>"Відкрий сайт, обери Google-акаунт і дозволь підключення тестового календаря. Посилання діє 10 хвилин.\n\nЯкщо Google не відкриється всередині Telegram, відкрий посилання у звичайному браузері.",
                'link_preview_options'=>['is_disabled'=>true],
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'Підключити Google Calendar','url'=>$url]],[['text'=>'← Тест','callback_data'=>'lab']]]],
            ]); return true;
        }
        $c=DB::table('calendar_lab_connections')->where('profile_id',$p->id)->first();
        if (!$c) { $this->button($p,'lab',$messageId); return true; }
        if (in_array($action,['removeask','forgetask'],true)) {
            $token=bin2hex(random_bytes(8));
            $this->state($p->id,['step'=>'lab_remove','mode'=>$action,'token'=>$token,'message_id'=>$messageId,'expires_at'=>now()->addMinutes(10)->timestamp]);
            $text=$action==='removeask'
                ? 'Видалити календар «Крок вперед · ТЕСТ 🧪» з Google та всі тестові записи? Це незворотно для тесту. Основні задачі бота та інші календарі залишаться без змін.'
                : 'Вимкнути синхронізацію та видалити локальні тестові записи й ключі доступу? Тестовий календар у Google залишиться; його можна буде видалити там вручну.';
            $this->tg->edit($p->id,$messageId,$text,[[['Підтвердити','lab:remove:'.$token]],[['Ні, повернутися','lab']]]); return true;
        }
        if ($action==='remove') {
            $s=json_decode($p->dialog ?? 'null',true);
            if (($s['step'] ?? '')!=='lab_remove' || ($s['message_id'] ?? null)!==$messageId || ($s['expires_at'] ?? 0)<now()->timestamp || !hash_equals($s['token'] ?? '',$value)) return true;
            if ($s['mode']==='removeask') {
                DB::table('calendar_lab_connections')->where('profile_id',$p->id)->update(['status'=>'deleting','error'=>null]);
                $this->tg->edit($p->id,$messageId,'Завершую тест і видаляю окремий тестовий календар. Це виконається під час найближчої перевірки. Основні задачі не зміняться.',[[['Перевірити стан','lab']]]);
            } else {
                $this->lab->forget($p->id);
                $this->tg->edit($p->id,$messageId,'Тест вимкнений, тестові записи й ключі прибрані з бота. Календар Google залишився. Дозвіл застосунку також можна відкликати в налаштуваннях Google.',[[['← Тест','lab']]]);
            }
            $this->state($p->id,null); return true;
        }
        if ($c->status!=='active') return true;
        if ($action==='new') {
            $this->state($p->id,['step'=>'lab_title']);
            $this->tg->send($p->id,'Як назвати тестову задачу? Вона не потрапить у твої основні плани.'); return true;
        }
        if ($action==='copy') {
            $rows=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')
                ->where('occurrences.profile_id',$p->id)->whereIn('occurrences.status',['pending','rejected'])
                ->whereBetween('due_at',[now(),now()->addDays(7)])->select('occurrences.id','due_at','title')->get();
            foreach ($rows as $row) DB::table('calendar_lab_tasks')->insertOrIgnore([
                'profile_id'=>$p->id,'source_occurrence_id'=>$row->id,'title'=>$row->title,'due_at'=>$row->due_at,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->tg->edit($p->id,$messageId,'Копії планів додані лише в тест. Зміни цих копій не впливають на оригінали.',[[['Тестові плани','lab:list']]]); return true;
        }
        if ($action==='list') {
            $page=max(0,(int)$value);
            $query=DB::table('calendar_lab_tasks')->where('profile_id',$p->id)->orderBy('due_at');
            $total=(clone $query)->count(); $page=min($page,max(0,(int)ceil($total/8)-1));
            $text="🧪 Тестові плани\n"; $buttons=[];
            foreach ($query->offset($page*8)->limit(8)->get() as $task) {
                $label=Time::parse($task->due_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i').' · '.($task->status==='done' ? '✅ ' : ($task->status==='cancelled' ? 'Скасовано · ' : '')).$task->title;
                $text.="\n".$label; $buttons[]=[[mb_strimwidth($task->title,0,38,'…'),'lab:task:'.$task->id]];
            }
            if (!$total) $text.="Ще порожньо. Додай тестову задачу в боті або подію в Google-календарі «Крок вперед · ТЕСТ 🧪».";
            $nav=[];
            if ($page>0) $nav[]=['←','lab:list:'.($page-1)];
            if (($page+1)*8<$total) $nav[]=['→','lab:list:'.($page+1)];
            if ($nav) $buttons[]=$nav;
            $buttons[]=[['＋ Тестова задача','lab:new'],['← Тест','lab']];
            $this->tg->edit($p->id,$messageId,$text,$buttons); return true;
        }
        $task=DB::table('calendar_lab_tasks')->where('id',(int)$value)->where('profile_id',$p->id)->first();
        if (!$task) return true;
        if ($action==='done') DB::table('calendar_lab_tasks')->where('id',$task->id)->update(['status'=>$task->status==='done' ? 'pending' : 'done','updated_at'=>now()]);
        if ($action==='cancel') DB::table('calendar_lab_tasks')->where('id',$task->id)->update(['status'=>'cancelled','updated_at'=>now()]);
        if ($action==='move') {
            $this->state($p->id,['step'=>'lab_time','task_id'=>$task->id,'title'=>$task->title]);
            $this->tg->send($p->id,'Введи нові дату й час тестової задачі: ДД.ММ.РРРР ГГ:ХХ.'); return true;
        }
        if (in_array($action,['task','done','cancel'],true)) {
            $task=DB::table('calendar_lab_tasks')->find($task->id);
            $buttons=[];
            if ($task->status!=='cancelled') {
                $buttons[]=[[$task->status==='done' ? 'Позначити невиконаною' : 'Виконано','lab:done:'.$task->id],['Змінити час','lab:move:'.$task->id]];
                $buttons[]=[['Скасувати тестову задачу','lab:cancel:'.$task->id]];
            }
            $buttons[]=[['← Тестові плани','lab:list']];
            $this->tg->edit($p->id,$messageId,$task->title."\n".Time::parse($task->due_at,'UTC')->setTimezone($p->timezone)->format('d.m.Y H:i')."\nСтатус: ".$task->status."\nЦе тестова копія без штрафів.",$buttons);
        }
        return true;
    }

    public function message(object $p, string $text): bool
    {
        $s=json_decode($p->dialog ?? 'null',true);
        if (!in_array($s['step'] ?? '',['lab_title','lab_time'],true)) return false;
        if (!$this->lab->allowed($p->id)) { $this->state($p->id,null); return true; }
        if (!DB::table('calendar_lab_connections')->where('profile_id',$p->id)->where('status','active')->exists()) {
            $this->state($p->id,null); return true;
        }
        if ($s['step']==='lab_title') {
            if (mb_strlen($text)<3 || mb_strlen($text)>180) { $this->tg->send($p->id,'Назва: від 3 до 180 символів.'); return true; }
            $this->state($p->id,['step'=>'lab_time','title'=>$text]);
            $this->tg->send($p->id,'Коли дедлайн? Введи ДД.ММ.РРРР ГГ:ХХ. У тесті доступні наступні 90 днів.'); return true;
        }
        try { $due=Time::createFromFormat('!d.m.Y H:i',$text,$p->timezone); } catch (\Throwable) { $due=null; }
        if (!$due || $due->format('d.m.Y H:i')!==$text || $due->lessThanOrEqualTo(Time::now()) || $due->greaterThan(Time::now()->addDays(90))) {
            $this->tg->send($p->id,'Введи майбутні дату й час у межах 90 днів: ДД.ММ.РРРР ГГ:ХХ.'); return true;
        }
        if (isset($s['task_id'])) DB::table('calendar_lab_tasks')->where('id',$s['task_id'])->where('profile_id',$p->id)->update(['due_at'=>$due->utc()->toDateTimeString(),'updated_at'=>now()]);
        else DB::table('calendar_lab_tasks')->insert(['profile_id'=>$p->id,'title'=>$s['title'],'due_at'=>$due->utc()->toDateTimeString(),'created_at'=>now(),'updated_at'=>now()]);
        $this->state($p->id,null);
        $this->tg->send($p->id,'Тестову задачу збережено 💜 Вона з’явиться в окремому календарі під час найближчої перевірки.',[[['Тестові плани','lab:list']]]); return true;
    }

    private function state(int $uid, ?array $state): void
    {
        DB::table('bot_profiles')->where('id',$uid)->update(['dialog'=>$state ? json_encode($state,JSON_UNESCAPED_UNICODE) : null]);
    }
}
