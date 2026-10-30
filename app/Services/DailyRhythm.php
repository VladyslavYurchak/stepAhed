<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class DailyRhythm
{
    public function quiet(object $p, ?Time $at = null): bool
    {
        $at ??= Time::now();
        if (!$p->sleep_at || !$p->wake_at) return false;
        if ($p->awake_until && $at->lessThan(Time::parse($p->awake_until, 'UTC'))) return false;
        $time = $at->setTimezone($p->timezone)->format('H:i');
        return $p->sleep_at > $p->wake_at
            ? $time >= $p->sleep_at || $time < $p->wake_at
            : $time >= $p->sleep_at && $time < $p->wake_at;
    }

    public function start(int $uid): void
    {
        $this->ask($uid, ['step'=>'rhythm_sleep']);
    }

    private function ask(int $uid, array $s): void
    {
        $s['nonce'] = bin2hex(random_bytes(6));
        DB::table('bot_profiles')->where('id',$uid)->update(['dialog'=>json_encode($s)]);
        [$text,$times] = match ($s['step']) {
            'rhythm_sleep'=>['Щоб я не заважав тобі відпочивати та спати, підкажи: о котрій ти зазвичай лягаєш? 🌙',['22:00','22:30','23:00','23:30','00:00','00:30']],
            'rhythm_wake'=>['А о котрій прокидаєшся? ☀️',['06:00','06:30','07:00','07:30','08:00','08:30','09:00','10:00']],
            default=>['О котрій побажати тобі доброго ранку? ☀️',['07:00','08:00','09:00','10:00']],
        };
        $buttons = array_chunk(array_map(fn ($t) => [$t,'rhythmtime:'.$s['nonce'].':'.$t],$times),4);
        $buttons[] = [['Налаштую пізніше','home']];
        app(Telegram::class)->send($uid,$text."\nОбери кнопку або введи свій час, наприклад 07:15.",$buttons);
    }

    public function message(object $p, string $text): bool
    {
        $s=json_decode($p->dialog ?? 'null',true);
        if (!in_array($s['step'] ?? '',['rhythm_sleep','rhythm_wake','rhythm_morning'],true)) return false;
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$text)) {
            app(Telegram::class)->send($p->id,'Введи час у форматі ГГ:ХХ, наприклад 07:15.'); return true;
        }
        if ($s['step']==='rhythm_sleep') {
            $s['sleep']=$text; $s['step']='rhythm_wake'; $this->ask($p->id,$s);
        } elseif ($s['step']==='rhythm_wake') {
            if ($text===$s['sleep']) { app(Telegram::class)->send($p->id,'Час підйому має відрізнятися від часу сну. Обери інший.'); return true; }
            $s['wake']=$text; $s['step']='rhythm_choice'; $s['nonce']=bin2hex(random_bytes(6));
            DB::table('bot_profiles')->where('id',$p->id)->update(['dialog'=>json_encode($s)]);
            app(Telegram::class)->send($p->id,'Коли побажати тобі доброго ранку й показати плани на день? 💜',[
                [['Коли прокидаюся · '.$text,'rhythmmode:'.$s['nonce'].':wake']],
                [['Обрати інший час','rhythmmode:'.$s['nonce'].':custom']],
                [['Без ранкового повідомлення','rhythmmode:'.$s['nonce'].':off']],
            ]);
        } else {
            $draft=(object)['sleep_at'=>$s['sleep'],'wake_at'=>$s['wake'],'timezone'=>$p->timezone,'awake_until'=>null];
            if ($this->quiet($draft,Time::now($p->timezone)->setTimeFromTimeString($text))) {
                app(Telegram::class)->send($p->id,'У цей час ти відпочиваєш 🌙 Обери час поза годинами сну.'); return true;
            }
            $this->save($p->id,$s,'custom',$text);
        }
        return true;
    }

    public function button(object $p, string $data): bool
    {
        if ($data==='rhythm') { $this->start($p->id); return true; }
        if ($data==='awake') {
            $local=Time::now($p->timezone);
            if ($p->sleep_at) {
                $until=$local->setTimeFromTimeString($p->sleep_at);
                if ($until->lessThanOrEqualTo($local)) $until=$until->addDay();
                DB::table('bot_profiles')->where('id',$p->id)->update(['awake_until'=>$until->utc()->toDateTimeString()]);
            }
            $this->morning($p,true);
            $fresh=DB::table('bot_profiles')->find($p->id);
            $this->digest($fresh);
            return true;
        }
        if (!str_starts_with($data,'rhythmtime:') && !str_starts_with($data,'rhythmmode:')) return false;
        [$action,$nonce,$value]=array_pad(explode(':',$data,3),3,'');
        $s=json_decode($p->dialog ?? 'null',true);
        if (!isset($s['nonce']) || !hash_equals($s['nonce'],$nonce)) return true;
        if ($action==='rhythmtime') { $this->message($p,$value); return true; }
        if (($s['step'] ?? '')!=='rhythm_choice' || !in_array($value,['wake','custom','off'],true)) return true;
        if ($value==='custom') { $s['step']='rhythm_morning'; $this->ask($p->id,$s); }
        else $this->save($p->id,$s,$value,$value==='wake' ? $s['wake'] : null);
        return true;
    }

    private function save(int $uid, array $s, string $mode, ?string $time): void
    {
        DB::table('bot_profiles')->where('id',$uid)->update(['sleep_at'=>$s['sleep'],'wake_at'=>$s['wake'],
            'morning_mode'=>$mode,'morning_at'=>$time,'awake_until'=>null,'dialog'=>null]);
        app(Telegram::class)->send($uid,'Домовилися 💜 Не турбуватиму тебе з '.$s['sleep'].' до '.$s['wake']."\n".
            ($time ? 'Ранкові плани надсилатиму о '.$time.'.' : 'Ранкове повідомлення вимкнене.').
            "\nНічні нагадування зберу в одне повідомлення після пробудження. Дедлайни не переносяться. Змінити час можна в налаштуваннях.",[[['Повернутися в меню','home']]]);
    }

    public function defer(int $uid, int $occurrence): void
    {
        if (DB::table('occurrences')->where('id',$occurrence)->where('profile_id',$uid)->exists()) {
            DB::table('bot_deferred_notifications')->insertOrIgnore(['profile_id'=>$uid,'occurrence_id'=>$occurrence]);
        }
    }

    public function notify(int $uid, int $occurrence, string $text, array $buttons=[]): void
    {
        $p=DB::table('bot_profiles')->find($uid);
        if (!$p) return;
        if ($this->quiet($p)) $this->defer($uid,$occurrence);
        else app(Telegram::class)->send($uid,$text,$buttons,null,null,$occurrence);
    }

    public function tick(): void
    {
        foreach (DB::table('bot_profiles')->whereNotNull('sleep_at')->where('onboarded',true)->get() as $p) {
            if ($this->quiet($p)) continue;
            $local=Time::now($p->timezone);
            if ($p->morning_mode!=='off' && $p->morning_at) {
                $target=$local->setTimeFromTimeString($p->morning_at);
                if ($local->greaterThanOrEqualTo($target) && $local->lessThan($target->addHours(3))) $this->morning($p);
            }
            $this->digest($p);
        }
    }

    public function morning(object $p, bool $manual=false): void
    {
        $date=Time::now($p->timezone)->toDateString();
        $claimed=DB::table('bot_profiles')->where('id',$p->id)->where(fn ($q) => $q->whereNull('morning_last_date')->orWhere('morning_last_date','!=',$date))
            ->update(['morning_last_date'=>$date]);
        if (!$claimed) {
            if ($manual) app(Telegram::class)->send($p->id,'Ти вже зробив сьогоднішній крок до нового дня ☀️ Переглянемо плани?',[[['☀️ Сьогодні','list:today'],['＋ Додати задачу','new']]]);
            return;
        }
        $names=['Владислав'=>'Владиславе','Володимир'=>'Володимире','Олександр'=>'Олександре','Олександра'=>'Олександро','Дарина'=>'Дарино','Анна'=>'Анно','Марія'=>'Маріє'];
        $name=htmlspecialchars($names[$p->name] ?? $p->name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $start=Time::now($p->timezone)->startOfDay();
        $query=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')
            ->where('occurrences.profile_id',$p->id)->whereIn('occurrences.status',['pending','rejected','review','paused','pause_review'])
            ->whereBetween('due_at',[$start->utc(),$start->addDay()->utc()->subSecond()]);
        $total=(clone $query)->count();
        $text='<b>Доброго раночку, '.$name.' ☀️</b>';
        if ($total) {
            $text.="\n\nСьогодні зробимо ще один крок вперед 🌱\n\nОсь твої плани на сьогодні:";
            $shown=0;
            foreach ($query->orderBy('due_at')->limit(10)->select('due_at','title')->get() as $o) {
                $line="\n".Time::parse($o->due_at,'UTC')->setTimezone($p->timezone)->format('H:i').' — '.htmlspecialchars(mb_strimwidth($o->title,0,120,'…'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                if (mb_strlen($text.$line)>3200) break;
                $text.=$line; $shown++;
            }
            if ($shown<$total) $text.="\nЩе ".($total-$shown).' задач — у «Сьогодні».';
            $text.="\n\nЗаплануємо щось іще? 💜";
            $buttons=[[['＋ Додати задачу','new'],['☀️ Сьогодні','list:today']]];
        } else {
            $text.="\n\nНовий день — новий крок вперед 🌱\nНа сьогодні ще немає планів. З чого почнемо? 💜";
            $buttons=[[['＋ Додати задачу','new']]];
        }
        app(Telegram::class)->send($p->id,$text,$buttons,'HTML',null,null,!$manual,$date);
    }

    private function digest(object $p): void
    {
        $rows=DB::table('bot_deferred_notifications')->join('occurrences','occurrences.id','=','bot_deferred_notifications.occurrence_id')
            ->join('commitments','commitments.id','=','occurrences.commitment_id')->where('bot_deferred_notifications.profile_id',$p->id)
            ->select('bot_deferred_notifications.id as deferred_id','title','due_at','occurrences.status','penalty_amount')->orderBy('due_at')->get();
        if ($rows->isEmpty()) return;
        $text="Поки ти відпочивав 🌙\nДедлайни залишилися чинними. Ось що змінилося:"; $shown=0;
        $statuses=['pending'=>'Ще очікує виконання','rejected'=>'Фото відхилено','missed'=>'Не підтверджено вчасно','review'=>'На перевірці','paused'=>'Пауза','pause_review'=>'Очікує рішення про паузу'];
        foreach ($rows as $row) {
            if (!isset($statuses[$row->status])) continue;
            $line="\n".Time::parse($row->due_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i').' — '.mb_strimwidth($row->title,0,100,'…').' · '.$statuses[$row->status];
            if ($row->status==='missed' && $row->penalty_amount) $line.=' · '.$row->penalty_amount.' грн (облік)';
            if (mb_strlen($text.$line)>3200) break;
            $text.=$line; $shown++;
        }
        if ($shown && $shown < $rows->whereIn('status',array_keys($statuses))->count()) $text.="\nРешту змін можна переглянути в задачах і прогресі.";
        if ($shown) app(Telegram::class)->send($p->id,$text,[[['☀️ Сьогодні','list:today'],['📈 Мій прогрес','progress']]],null,null,null,true);
        DB::table('bot_deferred_notifications')->whereIn('id',$rows->pluck('deferred_id'))->delete();
    }
}
