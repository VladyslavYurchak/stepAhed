<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class GoogleCalendar
{
    public function __construct(private CalendarLab $oauth) {}

    public function button(object $p, string $data, int $messageId): bool
    {
        if (!in_array($data,['googlecalendar','googleconnect'],true)) return false;
        $tg=app(Telegram::class);
        if (!$this->oauth->allowed($p->id) || !$this->oauth->configured() || !config('calendar_lab.main_enabled')) {
            $tg->edit($p->id,$messageId,'Підключення Google Calendar поки доступне власнику. Для інших користувачів готуємо доступ Google.',[[['← Календарі','calendars']]]);
            return true;
        }
        $c=DB::table('calendar_lab_connections')->where('profile_id',$p->id)->first();
        if ($data==='googleconnect' && (!$c || !$c->calendar_id)) {
            $tg->enqueue('sendMessage',['chat_id'=>$p->id,'text'=>'Відкрий сайт, обери Google-акаунт і підтвердь підключення. Посилання діє 10 хвилин.',
                'link_preview_options'=>['is_disabled'=>true],'reply_markup'=>['inline_keyboard'=>[[['text'=>'Підключити Google Calendar','url'=>$this->oauth->connectLink($p->id)]],[['text'=>'← Календарі','callback_data'=>'calendars']]]]],$p->id);
            return true;
        }
        $text="🗓 Google Calendar · Android\n\nТвої задачі та виконання з бота з’являються в календарі «Крок вперед 🌱». Додавай, змінюй і підтверджуй задачі в боті. Зміни в Google не змінюють задачі та штрафи.\n\nОновлення перевіряються щохвилини. На Android відкрий Google Календар із тим самим акаунтом і ввімкни календар «Крок вперед 🌱».";
        $rows=[];
        if ($c && $c->calendar_id) {
            $text.="\n\n✅ Підключено";
            if ($c->last_synced_at) $text.="\nОстання перевірка: ".Time::parse($c->last_synced_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i');
            if ($c->error) $text.="\n⚠️ Остання спроба не завершилась. Якщо помилка повторюється, звернися до адміністратора.";
        } else $rows[]=[['Підключити Google Calendar','googleconnect']];
        $rows[]=[['← Календарі','calendars']];
        $tg->edit($p->id,$messageId,$text,$rows); return true;
    }

    public function run(): void
    {
        if (!config('calendar_lab.main_enabled') || !$this->oauth->configured()) return;
        foreach (DB::table('calendar_lab_connections')->where('sync_mode','main')->where('status','active')->get() as $c) {
            if (!$this->oauth->allowed($c->profile_id)) continue;
            Cache::lock('calendar-lab-'.$c->profile_id,120)->get(function () use ($c) {
                try { $this->sync($c); }
                catch (\Throwable) { DB::table('calendar_lab_connections')->where('profile_id',$c->profile_id)->update(['error'=>'sync_failed']); }
            });
        }
    }

    private function sync(object $c): void
    {
        if (!$c->calendar_id || $c->calendar_id==='primary') return;
        $base='/calendars/'.rawurlencode($c->calendar_id).'/events';
        $rows=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')
            ->where('occurrences.profile_id',$c->profile_id)->where('commitments.profile_id',$c->profile_id)
            ->whereBetween('due_at',[now()->subDays(30),now()->addYear()])
            ->select('occurrences.id','occurrences.status','occurrences.due_at','commitments.title')->orderBy('occurrences.id')->get();
        $started=microtime(true); $writes=0;
        foreach ($rows as $o) {
            if ($writes>=30 || microtime(true)-$started>20) break;
            $hash=hash('sha256',json_encode([$o->title,$o->due_at,$o->status]));
            $map=DB::table('google_calendar_events')->where('profile_id',$c->profile_id)->where('occurrence_id',$o->id)->first();
            if ($map && $map->fingerprint===$hash) continue;
            if (!$map) {
                DB::table('google_calendar_events')->insertOrIgnore(['profile_id'=>$c->profile_id,'occurrence_id'=>$o->id,'event_id'=>'krok'.bin2hex(random_bytes(20))]);
                $map=DB::table('google_calendar_events')->where('profile_id',$c->profile_id)->where('occurrence_id',$o->id)->first();
            }
            $path=$base.'/'.rawurlencode($map->event_id);
            $remote=$this->oauth->api($c->profile_id,'GET',$path);
            if (!$remote->successful() && !in_array($remote->status(),[404,410],true)) throw new \RuntimeException('Event read failed');
            $cancelled=in_array($o->status,['cancelled','superseded'],true);
            if ($cancelled) {
                if ($remote->successful()) {
                    $r=$this->oauth->api($c->profile_id,'DELETE',$path,[],$remote->json('etag'));
                    if ($r->status()===412) continue;
                    if (!$r->successful() && !in_array($r->status(),[404,410],true)) throw new \RuntimeException('Cancellation failed');
                }
            } else {
                $labels=['done'=>'✅ Виконано: ','missed'=>'Пропущено: ','review'=>'На перевірці: ','rejected'=>'Фото відхилено: ','paused'=>'Пауза: ','pause_review'=>'Запит на паузу: ','excused'=>'Штраф скасовано: '];
                $due=Time::parse($o->due_at,'UTC');
                $body=['summary'=>($labels[$o->status] ?? 'Дедлайн: ').$o->title,
                    'description'=>'Дедлайн задачі. Зміни й виконання — у @krokvpered_bot. Зміни в календарі не змінюють задачу.',
                    'start'=>['dateTime'=>$due->toIso8601String()],'end'=>['dateTime'=>$due->addMinute()->toIso8601String()],
                    'reminders'=>['useDefault'=>false],'transparency'=>'transparent'];
                if ($remote->successful() && $remote->json('status')!=='cancelled') $r=$this->oauth->api($c->profile_id,'PATCH',$path,$body,$remote->json('etag'));
                else {
                    if ($map->fingerprint!==null || $remote->status()===410 || $remote->json('status')==='cancelled') {
                        $map->event_id='krok'.bin2hex(random_bytes(20));
                        DB::table('google_calendar_events')->where('id',$map->id)->update(['event_id'=>$map->event_id,'fingerprint'=>null]);
                    }
                    $r=$this->oauth->api($c->profile_id,'POST',$base,$body+['id'=>$map->event_id]);
                }
                if (in_array($r->status(),[409,412],true)) continue;
                if (!$r->successful()) throw new \RuntimeException('Event write failed');
            }
            DB::table('google_calendar_events')->where('id',$map->id)->update(['fingerprint'=>$hash]); $writes++;
        }
        DB::table('calendar_lab_connections')->where('profile_id',$c->profile_id)->update(['last_synced_at'=>now(),'error'=>null]);
    }
}
