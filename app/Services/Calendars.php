<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Calendars
{
    public function link(int $uid, bool $rotate=false): string
    {
        $record=DB::table('bot_calendar_subscriptions')->where('profile_id',$uid)->first();
        if (!$record || $rotate) {
            $token=bin2hex(random_bytes(32));
            DB::table('bot_calendar_subscriptions')->updateOrInsert(['profile_id'=>$uid], [
                'token_hash'=>hash('sha256',$token),'token_encrypted'=>Crypt::encryptString($token),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        } else $token=Crypt::decryptString($record->token_encrypted);
        return rtrim(config('app.url'),'/').'/calendar/feed/'.$token.'.ics?v=2';
    }

    public function revoke(int $uid): void
    {
        DB::table('bot_calendar_subscriptions')->where('profile_id',$uid)->delete();
        // Remove old messages containing the URL from the pending delivery queue.
        foreach (DB::table('bot_outbox')->where('data_owner_id',$uid)->get() as $item) {
            $payload=str_replace('\/','/',$item->payload);
            if (str_contains($payload,'/calendar/feed/') || str_contains($payload,'/calendar/apple/')) {
                DB::table('bot_outbox')->where('id',$item->id)->delete();
            }
        }
    }

    public function button(object $p, string $data, int $messageId): bool
    {
        $tg=app(Telegram::class);
        if ($data==='calendars') {
            DB::table('bot_profiles')->where('id',$p->id)->update(['dialog'=>null]);
            $enabled=DB::table('bot_calendar_subscriptions')->where('profile_id',$p->id)->exists();
            $text="🗓 Твої календарі\n\nЯ можу показувати твої задачі в Google Calendar та календарі iPhone.\n\nПідключення — за особистим посиланням. Календар отримуватиме назви задач, дедлайни та статуси. Кожен, хто матиме це посилання, зможе їх побачити — не пересилай його.\n\nЗадачі змінюй і підтверджуй у боті. Оновлення в календарі можуть надходити із затримкою. Календар показує дедлайни, а не тривалість занять.";
            $buttons=[[['🍎 Підключити календар iPhone','calendarapple']],[[$enabled ? 'Показати моє посилання' : 'Створити особисте посилання','calendarlink']]];
            if ($enabled) {
                $buttons[]=[['Замінити посилання','calendarrotateask']];
                $buttons[]=[['Вимкнути доступ','calendaroff']];
            }
            $buttons[]=[['← Налаштування','settings']];
            $tg->edit($p->id,$messageId,$text,$buttons); return true;
        }
        if ($data==='calendarapple') {
            $url=$this->link($p->id);
            $token=basename(parse_url($url,PHP_URL_PATH),'.ics');
            $tg->enqueue('sendMessage',['chat_id'=>$p->id,'text'=>"🍎 Календар iPhone\n\nВідкрий сторінку нижче, натисни «Відкрити календар iPhone» і підтвердь підписку. Копіювати посилання й шукати налаштування не потрібно.\n\nЯкщо цей календар уже підключений, повторно додавати його не потрібно. Задачі змінюй і підтверджуй у боті.",
                'link_preview_options'=>['is_disabled'=>true],
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'🍎 Підключити календар iPhone','url'=>rtrim(config('app.url'),'/').'/calendar/apple/'.$token]],[['text'=>'← Календарі','callback_data'=>'calendars']]]]],$p->id);
            return true;
        }
        if ($data==='calendarrotateask') {
            $nonce=bin2hex(random_bytes(8));
            DB::table('bot_profiles')->where('id',$p->id)->update(['dialog'=>json_encode([
                'step'=>'calendar_rotate','nonce'=>$nonce,'message_id'=>$messageId,'expires_at'=>now()->addMinutes(10)->timestamp,
            ])]);
            $tg->edit($p->id,$messageId,'Замінити особисте посилання? Старе припинить працювати. Нове потрібно буде додати до календаря заново.',[
                [['Замінити','calendarrotate:'.$nonce]],[['Повернутися','calendars']],
            ]); return true;
        }
        if ($data==='calendaroff') {
            $this->revoke($p->id);
            $tg->edit($p->id,$messageId,'Доступ до календаря вимкнений. Старе посилання більше не працює. Копії подій, уже збережені Google чи iPhone, можна прибрати, видаливши підписку в календарі.',[[['← Календарі','calendars']]]); return true;
        }
        if ($data==='calendarlink' || str_starts_with($data,'calendarrotate:')) {
            if (str_starts_with($data,'calendarrotate:')) {
                $s=json_decode($p->dialog ?? 'null',true);
                if (($s['step'] ?? '')!=='calendar_rotate' || ($s['message_id'] ?? null)!==$messageId
                    || ($s['expires_at'] ?? 0)<now()->timestamp || !hash_equals($s['nonce'] ?? '',substr($data,15))) return true;
                $this->revoke($p->id);
            }
            DB::table('bot_profiles')->where('id',$p->id)->update(['dialog'=>null]);
            $url=$this->link($p->id);
            $tg->enqueue('sendMessage',[
                'chat_id'=>$p->id,'parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true],
                'text'=>"🗓 Підключення календаря\n\nНатисни «Особисте посилання на календар» нижче — я скопіюю його. Потім встав у свій календар.\n\n<b>Google Calendar</b>\nВідкрий календар у браузері на комп’ютері → біля «Інші календарі» натисни «+» → «За URL-адресою» → встав посилання.\n\n<b>iPhone</b>\nУ застосунку «Календар» відкрий список календарів → «Додати календар» → «Додати підписний календар» → встав посилання.\n\nВажливо додати саме підписку, а не просто імпортувати файл — тоді зміни підтягуватимуться автоматично. Частоту оновлення визначає календарний застосунок. Регулярні задачі видно на найближчі 14 днів; цей період щодня продовжується.\n\nНе пересилай посилання іншим людям 💜",
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'📋 Особисте посилання на календар','copy_text'=>['text'=>$url]]],[['text'=>'← Календарі','callback_data'=>'calendars']]]],
            ]); return true;
        }
        return false;
    }

    public function feed(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $subscription=DB::table('bot_calendar_subscriptions')->where('token_hash',hash('sha256',$token))->first();
        if (!$subscription || !DB::table('bot_profiles')->where('id',$subscription->profile_id)->exists()) return null;
        $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Krok Vpered//Tasks Calendar//UK','CALSCALE:GREGORIAN',
            'METHOD:PUBLISH','X-WR-CALNAME:Крок вперед 🌱','X-WR-CALDESC:Дедлайни задач. Змінюй і підтверджуй задачі в Telegram-боті.'];
        $rows=DB::table('occurrences')->join('commitments','commitments.id','=','occurrences.commitment_id')
            ->where('occurrences.profile_id',$subscription->profile_id)->where('commitments.profile_id',$subscription->profile_id)
            ->whereBetween('due_at',[Time::now()->subDays(30),Time::now()->addYear()])
            ->select('occurrences.*','commitments.title')->orderBy('due_at')->cursor();
        $labels=['done'=>'✅ Виконано: ','missed'=>'Пропущено: ','review'=>'На перевірці: ','rejected'=>'Фото відхилено: ',
            'paused'=>'Пауза: ','pause_review'=>'Запит на паузу: ','cancelled'=>'Скасовано: ','superseded'=>'Скасовано: ','excused'=>'Штраф скасовано: '];
        foreach ($rows as $o) {
            $due=Time::parse($o->due_at,'UTC');
            $modified=Time::parse($o->updated_at ?? $o->created_at ?? $o->due_at,'UTC');
            $created=Time::parse($o->created_at ?? $o->due_at,'UTC');
            $cancelled=in_array($o->status,['cancelled','superseded'],true);
            $summary=($labels[$o->status] ?? 'Дедлайн: ').$o->title;
            $description='Це дедлайн, а не початок заняття. Виконання підтверджується в @krokvpered_bot. Зміни в календарі не змінюють задачу в боті.';
            array_push($lines,'BEGIN:VEVENT',
                'UID:'.hash('sha256',config('app.url').'|occurrence|'.$o->id).'@stepahead.korporatsiia-mov.com',
                'DTSTAMP:'.$modified->format('Ymd\THis\Z'),'LAST-MODIFIED:'.$modified->format('Ymd\THis\Z'),
                'SEQUENCE:'.max(0,$modified->timestamp-$created->timestamp),
                'DTSTART:'.$due->format('Ymd\THis\Z'),'DTEND:'.$due->addMinute()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->escape($summary),'DESCRIPTION:'.$this->escape($description),
                'STATUS:'.($cancelled ? 'CANCELLED' : (in_array($o->status,['paused','pause_review'],true) ? 'TENTATIVE' : 'CONFIRMED')),
                // Access is controlled by the bearer URL. PRIVATE can hide transparent events in subscribed calendars.
                'TRANSP:TRANSPARENT','URL:https://t.me/krokvpered_bot','END:VEVENT');
        }
        $lines[]='END:VCALENDAR';
        return implode("\r\n",array_map(fn ($line) => $this->fold($line),$lines))."\r\n";
    }

    private function escape(string $value): string
    {
        $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value);
        return str_replace(["\\","\r\n","\r","\n",';',','],["\\\\","\\n","\\n","\\n",'\\;','\\,'],$value);
    }

    private function fold(string $line): string
    {
        $parts=[]; $limit=75;
        while (strlen($line)>$limit) {
            $part=mb_strcut($line,0,$limit,'UTF-8');
            $parts[]=$part; $line=substr($line,strlen($part)); $limit=74;
        }
        $parts[]=$line;
        return implode("\r\n ",$parts);
    }
}
