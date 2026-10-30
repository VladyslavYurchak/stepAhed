<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class Bot
{
    public function __construct(private Telegram $tg, private Tasks $tasks) {}

    public function update(array $update): void
    {
        DB::transaction(function () use ($update) {
            if (!DB::table('bot_updates')->insertOrIgnore(['id' => $update['update_id'], 'created_at' => now()])) return;
            $cb = $update['callback_query'] ?? null;
            $m = $cb['message'] ?? $update['message'] ?? null;
            $from = $cb['from'] ?? $m['from'] ?? null;
            if (!$m || !$from || ($from['is_bot'] ?? false)) return;
            $uid = (int) $from['id'];
            if (($m['chat']['type'] ?? '') !== 'private') {
                $this->group($uid, $m, $cb);
                return;
            }
            if ($cb && !DB::table('bot_profiles')->where('id', $uid)->exists()) {
                $this->tg->enqueue('answerCallbackQuery', ['callback_query_id'=>$cb['id'], 'text'=>'Почни заново через /start.']);
                return;
            }
            DB::table('bot_profiles')->insertOrIgnore([
                'id' => $uid, 'name' => mb_substr($from['first_name'] ?? 'Друже', 0, 100),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $p = DB::table('bot_profiles')->where('id', $uid)->lockForUpdate()->first();
            $this->tg->ensureNavigation($uid);
            if ($cb) {
                $this->tg->enqueue('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
                $this->button($p, (string) ($cb['data'] ?? ''), (int) $m['message_id']);
            } else {
                $this->message($p, $m);
            }
        });
    }

    private function state(int $uid, ?array $state): void
    {
        DB::table('bot_profiles')->where('id', $uid)->update(['dialog' => $state ? json_encode($state, JSON_UNESCAPED_UNICODE) : null]);
    }

    private function home(int $uid): void
    {
        $buttons = [
            [['＋ Додати задачу', 'new']], [['☀️ Сьогодні', 'list:today'], ['🗓 Мій тиждень', 'list:week']],
            [['📈 Мій прогрес', 'progress']], [['⚙️ Налаштування', 'settings']],
            [['☀️ Я прокинувся', 'awake']],
            [['⏸ Екстрена пауза', 'pause']],
        ];
        if (app(Funds::class)->isAdmin($uid)) {
            $buttons[] = [['💛 Благодійні збори', 'funds']];
            $buttons[] = [['🛠 Адміністрування', 'a:menu']];
            if (config('calendar_lab.enabled')) $buttons[] = [['🧪 Тест Google Calendar', 'lab']];
        }
        $p = DB::table('bot_profiles')->where('id', $uid)->first();
        $start = Time::now($p->timezone)->startOfDay();
        $today = DB::table('occurrences')->join('commitments', 'commitments.id', '=', 'occurrences.commitment_id')
            ->where('occurrences.profile_id', $uid)
            ->whereBetween('occurrences.due_at', [$start->utc(), $start->addDay()->utc()->subSecond()]);
        $remaining = (clone $today)->whereIn('occurrences.status', ['pending','rejected','review','paused','pause_review']);
        $total = (clone $remaining)->count();
        $text = '<b>Сьогодні зробимо ще один &quot;Крок вперед 🌱&quot;</b>';
        if ($total) {
            $text .= "\n\nТвій календарик на сьогодні:";
            $shown = 0;
            foreach ($remaining->orderBy('occurrences.due_at')->orderBy('occurrences.id')->limit(10)
                ->select('occurrences.due_at','occurrences.status','commitments.title')->get() as $task) {
                $title = htmlspecialchars(mb_strimwidth($task->title, 0, 120, '…'), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
                $status = match ($task->status) {
                    'review'=>' · 📷 На перевірці', 'paused'=>' · ⏸ Пауза',
                    'pause_review'=>' · ⏳ Запит на паузу', 'rejected'=>' · Фото відхилено', default=>'',
                };
                $line = "\n".Time::parse($task->due_at, 'UTC')->setTimezone($p->timezone)->format('H:i').' — '.$title.$status;
                if (mb_strlen($text.$line) > 3200) break;
                $text .= $line;
                $shown++;
            }
            if ($shown < $total) $text .= "\nЩе ".($total-$shown).' задач — у «Сьогодні».';
        } else {
            $text .= (clone $today)->where('occurrences.status','done')->exists()
                ? "\n\nНа сьогодні більше немає невиконаних задач 💜"
                : "\n\nУ тебе на сьогодні ще нічого не заплановано, саме час запланувати сьогоднішній день.";
        }
        $text .= "\n\n<b>Пам’ятай:</b> Великі зміни починаються з маленьких дій.";
        if (!$total) $text .= "\n\nЩо сьогодні зробимо для тебе майбутнього? 💜";
        $this->tg->send($uid, $text, $buttons, 'HTML');
    }

    private function message(object $p, array $m): void
    {
        $text = trim($m['text'] ?? '');
        if (in_array($text,['Повернутися в меню','🏠 Повернутися в меню','Меню'],true)) {
            $this->state($p->id,null);
            if ($p->onboarded) $this->home($p->id);
            else $this->timezoneMenu($p);
            return;
        }
        if (preg_match('~^/(start|menu)(?:@\w+)?(?:\s.*)?$~u', $text)) {
            $this->state($p->id, null);
            if (!$p->onboarded) {
                $this->tg->send($p->id, "Привіт! Я допоможу тримати слово перед собою.\n\n🔒 Зобов’язання — з погодженим штрафом за пропуск.\n🌿 Необов’язкові плани — без штрафів.\n\nПілотна версія: штрафи обліковуються. Донати на схвалені збори добровільні; доступ до бота не блокується.");
                $this->tg->send($p->id, app(UserData::class)->notice());
                $this->timezoneMenu($p);
            } else $this->home($p->id);
            return;
        }
        if ($text === '/cancel') { $this->state($p->id, null); $this->home($p->id); return; }
        if ($text === '/help') {
            $this->tg->send($p->id, "Додай задачу через меню. Для виходу з діалогу — /cancel.\nВиконати треба до дедлайну, підтвердити — протягом наступних 30 хвилин.\nСкасувати, перенести або зменшити обсяг без штрафу можна раніше ніж за 3 години.\nФото перевіряє адміністратор. Спірне рішення можна оскаржити.\nГолосові додамо пізніше. Підписка на календар — у налаштуваннях."); return;
        }
        $s = json_decode($p->dialog ?? 'null', true);
        if (($s['step'] ?? '') === 'timezone') {
            if (!in_array($text, timezone_identifiers_list(), true)) { $this->tg->send($p->id, 'Введи назву часового поясу, наприклад Europe/Kyiv або Europe/Warsaw.'); return; }
            DB::table('bot_profiles')->where('id', $p->id)->update(['timezone' => $text, 'onboarded' => true]);
            if (!$p->onboarded) { app(DailyRhythm::class)->start($p->id); return; }
            $this->state($p->id, null); $this->home($p->id); return;
        }
        if (!$p->onboarded) { $this->tg->send($p->id, 'Спочатку відкрий /start і підтвердь часовий пояс.'); return; }
        if (app(DailyRhythm::class)->message($p,$text)) return;
        if (app(CalendarLabBot::class)->message($p,$text)) return;
        if (app(Funds::class)->message($p,$m)) return;
        if (in_array($s['step'] ?? '', ['edit_single_title','edit_series_title'], true)) {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 180) { $this->tg->send($p->id,'Введи нову дію та обсяг: від 3 до 180 символів.'); return; }
            $s['title'] = $text;
            if ($s['step'] === 'edit_single_title') {
                $s['step'] = 'edit_single_confirm'; $this->state($p->id,$s);
                $this->tg->send($p->id,"Нова умова: {$text}\nЗміниться лише це заняття. Дедлайн та вимога фото залишаються. Правило трьох годин перевіримо ще раз при збереженні.",[[['Зберегти зміни','editsave:'.$s['id']],['Вийти','home']]]);
            } else { $s['step']='confirm'; $this->state($p->id,$s); $this->preview($p,$s); }
            return;
        }
        if (($s['step'] ?? '') === 'pause_reason') {
            if (mb_strlen($text)<3 || mb_strlen($text)>300) { $this->tg->send($p->id,'Коротко опиши причину: від 3 до 300 символів. Довідки та медичні фото не потрібні.'); return; }
            $s['reason']=$text; $s['step']='pause_confirm'; $this->state($p->id,$s);
            $this->tg->send($p->id,"Екстрена пауза: {$s['days']} діб.\nПричина: {$text}\nПерша пауза за 30 днів — без погодження. Повторна або продовження — через модератора. Попередні пропуски не скасовуються.",[[['Підтвердити паузу','pauseconfirm'],['Вийти','home']]]); return;
        }
        if (($s['step'] ?? '') === 'photo') { $this->photo($p, $m, $s); return; }
        if (($s['step'] ?? '') === 'title') {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 180) { $this->tg->send($p->id, 'Опиши задачу текстом від 3 до 180 символів. Голосові додамо пізніше.'); return; }
            $s['title'] = $text; $s['step'] = 'kind'; $this->state($p->id, $s);
            $this->tg->send($p->id, "Це обіцянка собі чи необов’язковий план?", [[['🔒 Зобов’язання', 'kind:1']], [['🌿 Необов’язковий план', 'kind:0']]]); return;
        }
        if (($s['step'] ?? '') === 'date') {
            $date = $this->parseDate($text, $p->timezone);
            if (!$date || $date->startOfDay()->lessThan(Time::now($p->timezone)->startOfDay()) || $date->greaterThan(Time::now($p->timezone)->addYear())) {
                $this->tg->send($p->id, 'Введи майбутню дату у форматі ДД.ММ.РРРР, не пізніше ніж через рік.'); return;
            }
            $s['date'] = $date->toDateString(); $s['step'] = 'time'; $this->state($p->id, $s); $this->askTime($p->id); return;
        }
        if (($s['step'] ?? '') === 'time') {
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $text)) { $this->askTime($p->id); return; }
            if ($s['mode'] === 'once' && Time::parse($s['date'].' '.$text, $p->timezone)->lessThanOrEqualTo(Time::now())) { $this->tg->send($p->id, 'Цей час уже минув. Введи майбутній час або /cancel.'); return; }
            $s['time'] = $text; $s['step'] = 'confirm'; $this->state($p->id, $s);
            $this->preview($p, $s); return;
        }
        if (($s['step'] ?? '') === 'daytime') {
            $index = $s['time_index'];
            $day = $s['weekdays'][$index];
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $text)) {
                $this->askDayTime($p->id, $day); return;
            }
            $s['day_times'][$day] = $text;
            $s['time_index']++;
            if ($s['time_index'] < count($s['weekdays'])) {
                $this->state($p->id, $s);
                $this->askDayTime($p->id, $s['weekdays'][$s['time_index']]);
            } else {
                $s['time'] = $s['day_times'][$s['weekdays'][0]];
                $s['step'] = 'confirm'; $this->state($p->id, $s); $this->preview($p, $s);
            }
            return;
        }
        if (($s['step'] ?? '') === 'move') {
            $o = $this->tasks->occurrence($s['id'], $p->id);
            $date = $this->parseDate($text, $p->timezone, true);
            if (!$date || $date->lessThanOrEqualTo(Time::now()) || $date->greaterThan(Time::now()->addYear())) { $this->tg->send($p->id, 'Введи майбутні дату й час: ДД.ММ.РРРР ГГ:ХХ.'); return; }
            if (!$o || $o->status !== 'pending' || !$this->tasks->changeAllowed($o)) { $this->state($p->id, null); $this->tg->send($p->id, 'Час для перенесення без штрафу вже минув. Початковий дедлайн залишається чинним.'); return; }
            $exists = DB::table('occurrences')->where('commitment_id', $o->commitment_id)->where('due_at', $date->utc()->toDateTimeString())->exists();
            if ($exists) { $this->tg->send($p->id, 'На цей час уже є повторення цього зобов’язання. Обери інший.'); return; }
            // Keep the cancelled slot, otherwise recurrence generation would recreate it.
            DB::table('occurrences')->where('id', $o->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('occurrences')->insert(['commitment_id' => $o->commitment_id, 'profile_id' => $p->id, 'due_at' => $date->utc()->toDateTimeString(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->state($p->id, null); $this->tg->send($p->id, 'Це заняття перенесено. Інші повторення залишаються за розкладом.'); return;
        }
        $this->tg->send($p->id, 'Для створення задачі натисни «Додати». Меню — /menu.', [[['＋ Додати', 'new']]]);
    }

    private function parseDate(string $text, string $tz, bool $withTime = false): ?Time
    {
        $format = $withTime ? 'd.m.Y H:i' : 'd.m.Y';
        try { $d = Time::createFromFormat('!'.$format, $text, $tz); return $d && $d->format($format) === $text ? $d : null; }
        catch (\Throwable) { return null; }
    }

    private function askTime(int $uid): void
    {
        $this->tg->send($uid, 'До котрої години виконати? Обери час або введи свій у форматі 21:00.', $this->timeButtons($uid));
    }

    private function timeButtons(int $uid): array
    {
        $p = DB::table('bot_profiles')->where('id', $uid)->first();
        $s = json_decode($p->dialog, true);
        $s['time_picker_token'] = bin2hex(random_bytes(6));
        $this->state($uid, $s);
        $localNow = Time::now($s['timezone'] ?? $p->timezone);
        $startHour = $localNow->hour + 1;
        $buttons = [];
        for ($minute = $startHour * 60; $minute <= 23 * 60; $minute += 30) {
            $label = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $buttons[] = [$label, 'picktime:'.$s['time_picker_token'].':'.$label];
        }
        return array_chunk($buttons, 4);
    }

    private function askDayTime(int $uid, int $day): void
    {
        $name = [1=>'Понеділок',2=>'Вівторок',3=>'Середа',4=>'Четвер',5=>'П’ятниця',6=>'Субота',7=>'Неділя'][$day];
        $this->tg->send($uid, "{$name} — до котрої години виконати? Обери час або введи свій, наприклад 21:00.", $this->timeButtons($uid));
    }

    private function weeklySummary(array $s): string
    {
        $names = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Нд'];
        return implode(' · ', array_map(fn ($d) => $names[$d].' '.$s['day_times'][$d], $s['weekdays']));
    }

    private function preview(object $p, array $s): void
    {
        $displayTimezone = $s['timezone'] ?? $p->timezone;
        $schedule = match ($s['mode']) {
            'once' => $s['date'], 'daily' => 'Щодня, без кінцевої дати',
            default => 'Дні тижня: '.implode(', ', array_map(fn ($d) => ['1'=>'Пн','2'=>'Вт','3'=>'Ср','4'=>'Чт','5'=>'Пт','6'=>'Сб','7'=>'Нд'][$d], $s['weekdays'])).', без кінцевої дати',
        };
        $schedule = !empty($s['day_times']) ? $this->weeklySummary($s).', без кінцевої дати' : $schedule.' · до '.$s['time'];
        $amount = config('bot.base_penalty') + $p->violations;
        $text = ($s['mandatory'] ? '🔒 ' : '🌿 ').$s['title']."\n{$schedule}\nЧасовий пояс: {$displayTimezone}\n".
            ($s['photo'] ? '📷 Фото обов’язкове. Невідповідне фото — відмова та штраф для зобов’язань.' : 'Підтвердження кнопкою.').
            ($s['mandatory'] ? "\nШтраф за найближче порушення: {$amount} грн, далі +1 грн. Пілот: без оплати.\nЗміни без штрафу — раніше ніж за 3 години до дедлайну." : "\nБез штрафу.");
        if ($s['mode'] === 'once' && $s['mandatory'] && Time::parse($s['date'].' '.$s['time'], $p->timezone)->lessThanOrEqualTo(Time::now()->addHours(3))) $text .= "\n⚠️ До дедлайну не більше 3 годин. Після підтвердження безкоштовне скасування недоступне.";
        if ($p->sleep_at && $p->wake_at) {
            $times = !empty($s['day_times']) ? array_values($s['day_times']) : [$s['time']];
            foreach ($times as $time) {
                $at = $s['mode']==='once' ? Time::parse($s['date'].' '.$time,$displayTimezone) : Time::now($displayTimezone)->setTimeFromTimeString($time);
                $sleepProfile=clone $p; $sleepProfile->awake_until=null;
                if (app(DailyRhythm::class)->quiet($sleepProfile,$at)) {
                    $text .= "\n🌙 Дедлайн потрапляє на твої години сну. Нагадування надійде після пробудження; дедлайн і правила підтвердження не змінюються.";
                    break;
                }
            }
        }
        if (isset($s['editing_series_id'])) $text .= "\nЗміняться майбутні заняття, до яких більше 3 годин. Найближчі та вже виконані заняття збережуть початкові умови.";
        $this->tg->send($p->id, $text, [[['Підтвердити', 'save']], [['Почати заново', 'new'], ['Вийти', 'home']]]);
    }

    private function button(object $p, string $data, int $messageId): void
    {
        [$action, $value] = array_pad(explode(':', $data, 2), 2, '');
        $s = json_decode($p->dialog ?? 'null', true);
        if ($action === 'picktime') {
            [$token, $time] = array_pad(explode(':', $value, 2), 2, '');
            if (!in_array($s['step'] ?? '', ['time', 'daytime'], true)
                || !isset($s['time_picker_token']) || !hash_equals($s['time_picker_token'], $token)
                || !preg_match('/^(?:[01]\d|2[0-2]):(?:00|30)$|^23:00$/', $time)) return;
            $this->message($p, ['text' => $time]);
            $this->tg->edit($p->id, $messageId, 'Обраний час: '.$time);
            return;
        }
        if ($action === 'onboard') { DB::table('bot_profiles')->where('id', $p->id)->update(['onboarded' => true]); $this->home($p->id); return; }
        if ($action === 'timezone') { $this->timezoneMenu($p, $messageId); return; }
        if ($action === 'tzcustom' && ($s['step'] ?? '') === 'timezone') {
            $this->tg->edit($p->id,$messageId,'Введи часовий пояс, наприклад Europe/Vilnius або America/Chicago. Або повернися до списку міст.',[[['← Обрати місто','timezone']]]); return;
        }
        if ($action === 'tzpick' && ($s['step'] ?? '') === 'timezone') {
            $city=$this->timezoneCities()[$value] ?? null; if (!$city) return;
            DB::table('bot_profiles')->where('id',$p->id)->update(['timezone'=>$city[1],'onboarded'=>true]);
            if (!$p->onboarded) { app(DailyRhythm::class)->start($p->id); return; }
            $this->state($p->id,null);
            $this->tg->edit($p->id,$messageId,'✅ Час налаштовано: '.$city[0]."\nЗараз там ".Time::now($city[1])->format('H:i').".\nНові задачі створюватимуться за цим часом. Уже погоджені дедлайни збережуться.",[[['Відкрити меню','home']],[['Обрати інше місто','timezone']]]); return;
        }
        if (!$p->onboarded) { $this->tg->send($p->id, 'Спочатку підтвердь часовий пояс через /start.'); return; }
        if (app(DailyRhythm::class)->button($p,$data)) return;
        if (app(CalendarLabBot::class)->button($p,$data,$messageId)) return;
        if (app(Calendars::class)->button($p,$data,$messageId)) return;
        if (app(Funds::class)->button($p->id,$data,$p->id,$messageId)) return;
        if ($action === 'home') { $this->state($p->id, null); $this->home($p->id); return; }
        if ($action === 'new') { $this->state($p->id, ['step' => 'title']); $this->tg->send($p->id, 'Що хочеш зробити? Напиши конкретну дію та обсяг, наприклад «30 віджимань». Для виходу — /cancel.'); return; }
        if ($action === 'pause') { $this->pauseMenu($p); return; }
        if ($action === 'pausedays') {
            $days=(int)$value; if ($days<1 || $days>3) return;
            $this->state($p->id,['step'=>'pause_reason','days'=>$days]);
            $this->tg->send($p->id,'Коротко напиши причину паузи. Без медичних документів або фото.'); return;
        }
        if ($action === 'pauseconfirm' && ($s['step'] ?? '') === 'pause_confirm') {
            try { app(Accountability::class)->requestPause($p->id,$s['days'],$s['reason']); }
            catch (\RuntimeException) { $this->tg->send($p->id,'Для погодження повторної паузи спочатку потрібно підключити групу модерації.'); }
            $this->state($p->id,null); return;
        }
        if ($action === 'pauseresume') { app(Accountability::class)->resume($p->id); $this->home($p->id); return; }
        if ($action === 'pausewithdraw') { app(Accountability::class)->withdrawPause($p->id); return; }
        if ($action === 'seriesedit' || $action === 'seriesname' || $action === 'seriesschedule') {
            $task=DB::table('commitments')->where('id',(int)$value)->where('profile_id',$p->id)->where('active',true)->first();
            if (!$task || json_decode($task->schedule,true)['mode']==='once') { $this->tg->send($p->id,'Цей регулярний розклад уже неактивний.'); return; }
            if ($action==='seriesedit') {
                $this->tg->edit($p->id,$messageId,"Редагування: {$task->title}\nЗміни стосуватимуться майбутніх занять. Якщо до найближчого не більше 3 годин — його умови збережуться.",[
                    [['Назва / обсяг','seriesname:'.$task->id]],[['Дні та години','seriesschedule:'.$task->id]],[['Меню','home']],
                ]); return;
            }
            $draft=json_decode($task->schedule,true);
            $draft['title']=$task->title; $draft['mandatory']=(bool)$task->mandatory; $draft['photo']=(bool)$task->photo_required;
            $draft['editing_series_id']=$task->id; $draft['editing_expected']=hash('sha256',$task->title.$task->schedule);
            if ($action==='seriesname') {
                $draft['step']='edit_series_title'; $this->state($p->id,$draft);
                $this->tg->send($p->id,'Напиши нову назву та обсяг для майбутніх занять. Зараз: '.$task->title);
            } else {
                $draft['step']='schedule'; unset($draft['day_times'],$draft['picker_message_id']); $this->state($p->id,$draft);
                $this->tg->edit($p->id,$messageId,'Обери новий регулярний розклад.',[[['Щодня','schedule:daily']],[['Обрати дні тижня','schedule:weekly']]]);
            }
            return;
        }
        if ($action === 'kind' && ($s['step'] ?? '') === 'kind') {
            $s['mandatory'] = $value === '1'; $s['step'] = 'photo'; $this->state($p->id, $s);
            $this->tg->send($p->id, 'Вимагати фото? Обирай це лише для результату, який можна побачити на фото. Фото перевірятиме адміністратор.', [[['📷 Так', 'photo:1'], ['Без фото', 'photo:0']]]); return;
        }
        if ($action === 'photo' && ($s['step'] ?? '') === 'photo') {
            $s['photo'] = $value === '1'; $s['step'] = 'schedule'; $this->state($p->id, $s);
            $this->tg->send($p->id, 'Коли виконувати?', [[['Сьогодні', 'schedule:today'], ['Обрати дату', 'schedule:date']], [['Щодня', 'schedule:daily'], ['Обрати дні тижня', 'schedule:weekly']]]); return;
        }
        if ($action === 'schedule' && ($s['step'] ?? '') === 'schedule') {
            if (!in_array($value, ['today','date','daily','weekly'], true)) return;
            $s['mode'] = in_array($value, ['today','date']) ? 'once' : $value;
            $s['step'] = match ($value) {'date'=>'date', 'weekly'=>'weekdays', default=>'time'};
            if ($value === 'today') $s['date'] = Time::now($p->timezone)->toDateString();
            $s['weekdays'] = []; $this->state($p->id, $s);
            if ($value === 'date') $this->tg->send($p->id, 'Введи дату у форматі ДД.ММ.РРРР.');
            elseif ($value === 'weekly') $this->weekdays($p->id, $s);
            else $this->askTime($p->id);
            return;
        }
        if ($action === 'day' && ($s['step'] ?? '') === 'weekdays') {
            if (isset($s['picker_message_id']) && $s['picker_message_id'] !== $messageId) return;
            $s['picker_message_id'] = $messageId;
            $d = (int) $value; if ($d < 1 || $d > 7) return;
            $s['weekdays'] = in_array($d, $s['weekdays'], true) ? array_values(array_diff($s['weekdays'], [$d])) : [...$s['weekdays'], $d];
            sort($s['weekdays']); $this->state($p->id, $s); $this->weekdays($p->id, $s, $messageId); return;
        }
        if ($action === 'daysdone' && ($s['step'] ?? '') === 'weekdays') {
            if (isset($s['picker_message_id']) && $s['picker_message_id'] !== $messageId) return;
            if (!$s['weekdays']) { $this->weekdays($p->id, $s, $messageId, 'Обери хоча б один день тижня.'); return; }
            $s['step'] = 'timechoice'; $s['picker_message_id'] = $messageId; $this->state($p->id, $s);
            $this->tg->edit($p->id, $messageId, 'Дні обрано. Як налаштувати час?', [
                [['Однаковий час для всіх днів', 'timechoice:common']],
                [['Окремий час для кожного дня', 'timechoice:separate']],
            ]); return;
        }
        if ($action === 'timechoice' && ($s['step'] ?? '') === 'timechoice') {
            if ($s['picker_message_id'] !== $messageId || !in_array($value, ['common', 'separate'], true)) return;
            if ($value === 'common') {
                $s['step'] = 'time'; unset($s['day_times']);
                $this->state($p->id, $s); $this->askTime($p->id);
            } else {
                $s['step'] = 'daytime'; $s['day_times'] = []; $s['time_index'] = 0;
                $this->state($p->id, $s); $this->askDayTime($p->id, $s['weekdays'][0]);
            }
            $this->tg->edit($p->id, $messageId, 'Дні обрано. '.($value === 'common' ? 'Однаковий час для всіх днів.' : 'Окремий час для кожного дня.'));
            return;
        }
        if ($action === 'save' && ($s['step'] ?? '') === 'confirm') {
            if ($s['mode'] === 'once' && Time::parse($s['date'].' '.$s['time'], $p->timezone)->lessThanOrEqualTo(Time::now())) { $this->state($p->id, null); $this->tg->send($p->id, 'Час задачі минув під час налаштування. Створи її з новим дедлайном.'); return; }
            $schedule = array_intersect_key($s, array_flip(['mode','date','time','weekdays','day_times']));
            $schedule['timezone'] = $s['timezone'] ?? $p->timezone;
            if (isset($s['editing_series_id'])) {
                try { $this->tasks->reviseSeries($p->id,$s['editing_series_id'],$s['editing_expected'],$s['title'],$schedule); }
                catch (\DomainException $e) { $this->state($p->id,null); $this->tg->send($p->id,$e->getMessage()); return; }
            } else {
                $id = DB::table('commitments')->insertGetId(['profile_id'=>$p->id,'title'=>$s['title'],'mandatory'=>$s['mandatory'],'photo_required'=>$s['photo'],'schedule'=>json_encode($schedule),'created_at'=>now(),'updated_at'=>now()]);
                $task = DB::table('commitments')->find($id);
                $this->tasks->generate($task, $s['mode'] === 'once' ? 367 : 14);
                app(Accountability::class)->syncPauses();
            }
            $this->state($p->id, null); $this->tg->send($p->id, 'Збережено 👣', [[['Мій тиждень','list:week'],['Меню','home']]]); return;
        }
        if ($action === 'list') { $this->listing($p, $value, $messageId); return; }
        if ($action === 'detail') {
            [$id, $range, $page] = array_pad(explode(',', $value, 3), 3, '');
            $o = $this->tasks->occurrence((int) $id, $p->id);
            if (!$o) return;
            $back = 'list:'.($range === 'today' ? 'today' : 'week').','.max(0, (int) $page);
            $this->card($p, $o, $messageId, $back); return;
        }
        if ($action === 'series') { $this->series($p); return; }
        if ($action === 'stopseries') { $this->stopSeries($p, (int) $value); return; }
        if ($action === 'progress') {
            $rows = DB::table('occurrences')->where('profile_id',$p->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status');
            $this->tg->send($p->id, 'Виконано: '.($rows['done'] ?? 0)."\nНа перевірці: ".($rows['review'] ?? 0)."\nПропущено: ".($rows['missed'] ?? 0)."\nДо скидання штрафу: {$p->success_streak}/10 успішних днів. Рахуються завершені дні зі зобов’язаннями; дні пауз і без задач пропускаються.\nНаступний розрахований штраф: ".(20+$p->violations).' грн. Пілот: без оплати.'); return;
        }
        if ($action === 'settings') {
            $this->state($p->id, null);
            $buttons=[[['Часовий пояс','timezone']],[['Повторювані задачі','series']],[['Екстрена пауза','pause']]];
            $buttons[]=[['🌙 Сон і ранкові плани','rhythm']];
            $buttons[]=[['🗓 Мої календарі','calendars']];
            if (app(Funds::class)->isAdmin($p->id)) $buttons[]=[['Благодійні збори','funds']];
            $buttons[]=[['🔐 Мої дані','privacy']];
            $buttons[]=[['Дізнайся про мене більше💜','guide']]; $buttons[]=[['Повернутися в меню','home']];
            $rhythm = $p->sleep_at
                ? "\n🌙 Сон: {$p->sleep_at} — {$p->wake_at}.\n☀️ Ранкові плани: ".($p->morning_mode==='off' ? 'вимкнені' : $p->morning_at).'.'
                : "\n🌙 Час сну та ранкові плани ще не налаштовані.";
            $this->tg->edit($p->id,$messageId,"Часовий пояс: {$p->timezone}\nНагадування: за 2 години до дедлайну.".$rhythm."\nЕкстрена пауза: до 3 діб раз на 30 днів; далі через модерацію.\nДобровільні донати — на схвалені збори. Підписка на календар — у «Мої календарі».",$buttons); return;
        }
        if ($action === 'privacy') {
            $this->state($p->id, null);
            $this->tg->edit($p->id, $messageId, app(UserData::class)->notice(), [
                [['📄 Експортувати мої дані','dataexport']],
                [['🗑 Видалити мій акаунт','dataerase']],
                [['← Налаштування','settings']],
            ]); return;
        }
        if ($action === 'dataexport') {
            $this->state($p->id, null);
            $this->tg->enqueue('sendDocument', ['chat_id'=>$p->id, 'document_json'=>app(UserData::class)->export($p->id),
                'caption'=>'Твої дані у форматі JSON. Збережи файл у приватному місці.']); return;
        }
        if ($action === 'dataerase') {
            $token = bin2hex(random_bytes(8));
            $this->state($p->id, ['step'=>'data_erase_confirm','token'=>$token,'expires_at'=>now()->addMinutes(10)->timestamp,'message_id'=>$messageId]);
            $this->tg->edit($p->id, $messageId, "Видалити твої дані?\n\nЯ видалю твої задачі, розклад, прогрес, підтвердження та квитанції зі своїх записів і вимкну нагадування. Повернути ці записи не вийде.\n\nРезервні копії очищаються автоматично через 14 днів. Історію чату можна видалити в Telegram.", [
                [['Так, видалити назавжди','dataeraseconfirm:'.$token]],
                [['Ні, повернутися','privacy']],
            ]); return;
        }
        if ($action === 'dataeraseconfirm') {
            if (($s['step'] ?? '') !== 'data_erase_confirm' || ($s['message_id'] ?? null) !== $messageId
                || ($s['expires_at'] ?? 0) < now()->timestamp || !hash_equals($s['token'] ?? '', $value)) return;
            try { app(UserData::class)->delete($p->id); }
            catch (\DomainException $e) { $this->tg->send($p->id, $e->getMessage()); return; }
            $this->tg->edit($p->id, $messageId, 'Профіль і пов’язані записи видалені з робочої бази. Нагадування зупинені. Для нового початку — /start.'); return;
        }
        if ($action === 'guide') { $this->guide($p->id,$messageId); return; }
        $o = $this->tasks->occurrence((int) $value, $p->id);
        if (!$o) { $this->tg->send($p->id, 'Ця дія вже неактуальна. Відкрий /menu.'); return; }
        if ($action === 'view') { $this->card($p, $o); return; }
        if ($action === 'editsingle') {
            if ($o->status!=='pending' || !$this->tasks->changeAllowed($o)) { $this->tg->send($p->id,'Змінити обсяг без штрафу вже не можна. Початкові умови залишаються.'); return; }
            $this->state($p->id,['step'=>'edit_single_title','id'=>$o->id]);
            $this->tg->send($p->id,'Напиши нову дію та обсяг лише для цього заняття. Зараз: '.$o->title); return;
        }
        if ($action === 'editsave' && ($s['step'] ?? '')==='edit_single_confirm' && $s['id']===$o->id) {
            try { $this->tasks->reviseSingle($p->id,$o->id,$s['title']); $this->tg->send($p->id,'Умови цього заняття оновлено. Решта розкладу без змін.',[[['Мій тиждень','list:week']]]); }
            catch (\DomainException $e) { $this->tg->send($p->id,$e->getMessage()); }
            $this->state($p->id,null); return;
        }
        if ($action === 'done') {
            if (!$this->tasks->confirmationAllowed($o)) { $this->tg->send($p->id, 'Підтвердження вже закрите або задача очікує перевірки.'); return; }
            if ($o->photo_required) {
                if (!$this->tg->setting('moderation_chat')) { $this->tg->send($p->id, 'Група перевірки ще не підключена. Повідом адміністратора; штраф за технічну проблему можна оскаржити.'); return; }
                $this->state($p->id, ['step'=>'photo','id'=>$o->id]); $this->tg->send($p->id, "Надішли фото для «{$o->title}» звичайним зображенням у цей чат. Підтверджуй лише виконане до дедлайну.");
            } else { DB::table('occurrences')->where('id',$o->id)->update(['status'=>'done','submitted_at'=>now(),'updated_at'=>now()]); $this->tg->send($p->id,'Виконання підтверджено ✅'); }
            return;
        }
        if ($action === 'move' && $o->status === 'pending') {
            if (!$this->tasks->changeAllowed($o)) { $this->tg->send($p->id, 'До дедлайну не більше трьох годин. Перенести без штрафу вже не можна.'); return; }
            $this->state($p->id,['step'=>'move','id'=>$o->id]); $this->tg->send($p->id,'Введи нові дату й час: ДД.ММ.РРРР ГГ:ХХ.'); return;
        }
        if ($action === 'cancel' && $o->status === 'pending') {
            if (!$this->tasks->changeAllowed($o)) { $this->tg->send($p->id,'Вікно скасування без штрафу закрите. Зобов’язання залишається чинним.'); return; }
            if (!$o->mandatory) { DB::table('occurrences')->where('id',$o->id)->update(['status'=>'cancelled','updated_at'=>now()]); $this->tg->send($p->id,'Необов’язковий план скасовано без штрафу.'); return; }
            DB::table('occurrences')->where('id',$o->id)->update(['cancel_started_at'=>now()]);
            $this->state($p->id,['step'=>'cancel','id'=>$o->id,'question'=>0,'started_at'=>now()->toIso8601String()]);
            $this->cancelQuestion($p->id,$o->id,0); return;
        }
        if ($action === 'cancelnext' && ($s['step'] ?? '') === 'cancel' && $s['id'] === $o->id && $o->status === 'pending') {
            if (Time::parse($s['started_at'])->addMinutes(15)->lessThan(Time::now())) { $this->state($p->id,null); $this->tg->send($p->id,'Діалог скасування завершився через 15 хвилин. Почни заново.'); return; }
            $q = $s['question'] + 1;
            if ($q >= 3) {
                DB::table('occurrences')->where('id',$o->id)->update(['status'=>'cancelled','updated_at'=>now()]);
                $this->state($p->id,null); $this->tg->send($p->id,'Це заняття скасоване без штрафу. Інші повторення залишаються.');
            } else { $s['question']=$q; $this->state($p->id,$s); $this->cancelQuestion($p->id,$o->id,$q); } return;
        }
        if ($action === 'appeal' && $o->status === 'missed') {
            $group=$this->tg->setting('moderation_chat');
            if ($group) { $this->tg->send($group,"Оскарження #{$o->id}\n{$p->name} ({$p->id})\n{$o->title}\nШтраф: {$o->penalty_amount} грн",[[['Скасувати штраф','pardon:'.$o->id]]], null, $p->id); $this->tg->send($p->id,'Запит передано адміністратору.'); }
            return;
        }
        $this->tg->send($p->id,'Ця кнопка вже неактуальна. Відкрий задачу з меню.');
    }

    private function weekdays(int $uid, array $s, ?int $messageId = null, string $text = 'Обери дні тижня. Можна кілька.'): void
    {
        $buttons=[]; foreach ([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Нд'] as $d=>$name) $buttons[]=[(in_array($d,$s['weekdays'],true)?'✓ ':'').$name,'day:'.$d];
        $rows = [...array_chunk($buttons,4),[['Далі','daysdone']]];
        if ($messageId) $this->tg->edit($uid, $messageId, $text, $rows);
        else $this->tg->send($uid, $text, $rows);
    }

    private function listing(object $p, string $value, ?int $messageId = null): void
    {
        [$range, $requestedPage] = array_pad(explode(',', $value, 2), 2, '0');
        $range = $range === 'today' ? 'today' : 'week';
        $localStart = Time::now($p->timezone)->startOfDay();
        $end = $range === 'today' ? $localStart->endOfDay() : $localStart->addDays(6)->endOfDay();
        $query = DB::table('occurrences')->join('commitments', 'commitments.id', '=', 'occurrences.commitment_id')
            ->where('occurrences.profile_id', $p->id)->where('occurrences.status','!=','superseded')->whereBetween('due_at', [$localStart->utc(), $end->utc()]);
        $total = (clone $query)->count(); $size = 8;
        $pages = max(1, (int) ceil($total / $size));
        $page = min($pages - 1, max(0, (int) $requestedPage));
        $rows = $query->orderBy('due_at')->orderBy('occurrences.id')->offset($page * $size)->limit($size)
            ->select('occurrences.*', 'commitments.title', 'commitments.mandatory', 'commitments.photo_required')->get();
        $text = ($range === 'today' ? 'Сьогодні 👣' : 'План на 7 днів 👣')."\n".$localStart->format('d.m').($range === 'week' ? ' — '.$end->format('d.m') : '');
        $buttons = []; $lastDate = null;
        $days = [1=>'Понеділок',2=>'Вівторок',3=>'Середа',4=>'Четвер',5=>'П’ятниця',6=>'Субота',7=>'Неділя'];
        $statuses = ['pending'=>'Очікує','done'=>'✅ Виконано','review'=>'📷 На перевірці','rejected'=>'Фото відхилено','missed'=>'❌ Пропущено','cancelled'=>'Скасовано','excused'=>'Штраф скасовано','paused'=>'⏸ Пауза','pause_review'=>'⏳ Запит на паузу'];
        foreach ($rows as $i => $o) {
            $date = Time::parse($o->due_at, 'UTC')->setTimezone($p->timezone);
            if ($lastDate !== $date->toDateString()) {
                $text .= "\n\n".$days[$date->dayOfWeekIso].' · '.$date->format('d.m');
                $lastDate = $date->toDateString();
            }
            $number = $page * $size + $i + 1;
            $text .= "\n{$number}. ".$date->format('H:i').' '.($o->mandatory ? '🔒 ' : '🌿 ').$o->title.
                "\n    ".($statuses[$o->status] ?? $o->status).($o->photo_required && $o->status === 'pending' ? ' · 📷 Фото' : '');
            $buttons[] = [[$number.'. '.mb_strimwidth($o->title, 0, 34, '…'), 'detail:'.$o->id.','.$range.','.$page]];
        }
        if (!$total) $text .= "\n\nПоки задач немає.";
        else $text .= "\n\nОбери задачу нижче, щоб підтвердити, перенести або скасувати.";
        if ($pages > 1) {
            $text .= "\nСторінка ".($page + 1)." із {$pages} · задач: {$total}";
            $nav = [];
            if ($page > 0) $nav[] = ['← Назад', 'list:'.$range.','.($page - 1)];
            if ($page + 1 < $pages) $nav[] = ['Далі →', 'list:'.$range.','.($page + 1)];
            $buttons[] = $nav;
        }
        $buttons[] = [['＋ Додати', 'new'], ['Меню', 'home']];
        if ($messageId) $this->tg->edit($p->id, $messageId, $text, $buttons);
        else $this->tg->send($p->id, $text, $buttons);
    }

    private function card(object $p, object $o, ?int $messageId = null, ?string $back = null): void
    {
        $status=['pending'=>'Очікує виконання','done'=>'✅ Виконано','review'=>'📷 На перевірці','rejected'=>'Фото відхилене — можна замінити до кінця вікна','missed'=>'Пропущено','cancelled'=>'Скасовано','excused'=>'Порушення скасоване адміністратором','paused'=>'⏸ Екстрена пауза','pause_review'=>'⏳ Очікує погодження паузи','superseded'=>'Замінено новою версією'][$o->status] ?? $o->status;
        $buttons=[];
        if ($this->tasks->confirmationAllowed($o)) $buttons[]=[['Підтвердити виконання','done:'.$o->id]];
        if ($o->status==='pending') $buttons[]=[['Перенести','move:'.$o->id],['Скасувати','cancel:'.$o->id]];
        if ($o->status==='pending') $buttons[]=[['Змінити назву / обсяг','editsingle:'.$o->id]];
        $task=DB::table('commitments')->find($o->commitment_id);
        if ($task && $task->active && json_decode($task->schedule,true)['mode']!=='once') $buttons[]=[['Редагувати регулярний розклад','seriesedit:'.$task->id]];
        if ($o->status==='missed') $buttons[]=[['Оскаржити','appeal:'.$o->id]];
        if ($o->status==='missed' && $o->penalty_amount>0) {
            $donation=DB::table('donations')->where('occurrence_id',$o->id)->first();
            $label=match ($donation->status ?? '') { 'pending'=>'Квитанція на перевірці','approved'=>'✅ Донат підтверджений',default=>'Обрати збір / підтвердити донат' };
            $buttons[]=[[$label,'donate:'.$o->id]];
        }
        if ($back) $buttons[] = [['← До списку', $back]];
        $text = ($o->mandatory?'🔒 ':'🌿 ').$o->title."\nДо ".Time::parse($o->due_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i')."\n{$status}".($o->photo_required?"\n📷 Потрібне фото":'').($o->penalty_amount?"\nРозрахований штраф: {$o->penalty_amount} грн (без оплати в пілоті)":'');
        if ($messageId) $this->tg->edit($p->id, $messageId, $text, $buttons);
        else $this->tg->send($p->id, $text, $buttons);
    }

    private function cancelQuestion(int $uid,int $id,int $q): void
    {
        $groups=[
            ['Точно хочеш скасувати? Можливо, зараз просто важко почати?','Ціль змінилася чи сьогодні бракує настрою?','Якщо почати лише на дві хвилини — спробуємо?','Це стало неможливим чи потрібен перший маленький крок?'],
            ['Згадай, навіщо ти обрав це зобов’язання. Ціль ще важлива?','Можливо, сьогодні варто дати собі шанс виконати обіцяне?','Який маленький крок допоможе розпочати саме зараз?','Що ти відчуєш увечері, якщо все ж виконаєш план?'],
            ['Друже, це була обіцянка собі. Останній раз питаю: точно скасовуємо?','Остаточне рішення за тобою. Залишаємо план чи скасовуємо?','Можемо залишити зобов’язання й почати з першого кроку. Точно скасувати?','Ще є можливість тримати слово перед собою. Яке твоє рішення?'],
        ];
        $key="cancel_phrase_{$q}_{$uid}"; $last=$this->tg->setting($key);
        $pool=array_values(array_diff($groups[$q],[$last])); $phrase=$pool[random_int(0,count($pool)-1)];
        DB::table('bot_settings')->updateOrInsert(['key'=>$key],['value'=>$phrase]);
        $this->tg->send($uid,($q+1)."/3 · {$phrase}",[[['💪 Залишити зобов’язання','home']],[[$q===2?'Скасувати без штрафу':'Продовжити скасування','cancelnext:'.$id]]]);
    }

    private function series(object $p): void
    {
        $count=0;
        foreach (DB::table('commitments')->where('profile_id',$p->id)->where('active',true)->get() as $task) {
            $s=json_decode($task->schedule,true); if ($s['mode']==='once') continue; $count++;
            $timing = !empty($s['day_times']) ? $this->weeklySummary($s) : 'Регулярно до '.$s['time'];
            $this->tg->send($p->id,"{$task->title}\n{$timing}. Припинення прибере лише майбутні заняття, які ще можна скасувати без штрафу.",[[['Редагувати','seriesedit:'.$task->id]],[['Припинити повторення','stopseries:'.$task->id]]]);
        }
        if (!$count) $this->tg->send($p->id,'Регулярних занять поки немає.');
    }

    private function stopSeries(object $p,int $id): void
    {
        $task=DB::table('commitments')->where('id',$id)->where('profile_id',$p->id)->where('active',true)->first();
        if (!$task) return;
        DB::table('commitments')->where('id',$id)->update(['active'=>false]);
        foreach (DB::table('occurrences')->where('commitment_id',$id)->whereIn('status',['pending','paused','pause_review'])->where('due_at','>',now())->get() as $row) {
            $o=$this->tasks->occurrence($row->id,$p->id);
            if ($this->tasks->changeAllowed($o)) DB::table('occurrences')->where('id',$row->id)->update(['status'=>'cancelled','updated_at'=>now()]);
        }
        $this->tg->send($p->id,'Нові повторення вимкнено. Найближчі зобов’язання, до яких не більше 3 годин, залишаються чинними.');
    }

    private function photo(object $p,array $m,array $s): void
    {
        if (!isset($s['id'])) return;
        $o=$this->tasks->occurrence($s['id'],$p->id);
        if (!$o || !$this->tasks->confirmationAllowed($o)) { $this->state($p->id,null); $this->tg->send($p->id,'Вікно підтвердження закрите.'); return; }
        $photos=$m['photo'] ?? []; $photo=end($photos);
        if (!$photo) { $this->tg->send($p->id,'Надішли звичайне фото, не файл. Для виходу — /cancel.'); return; }
        $chat=$this->tg->setting('moderation_chat');
        if (!$chat) { $this->tg->send($p->id,'Модерація ще не підключена.'); return; }
        $duplicate=DB::table('occurrences')->where('photo_unique_id',$photo['file_unique_id'])->where('id','!=',$o->id)->exists();
        DB::table('occurrences')->where('id',$o->id)->update(['status'=>'review','submitted_at'=>now(),'photo_file_id'=>$photo['file_id'],'photo_unique_id'=>$photo['file_unique_id'],'updated_at'=>now()]);
        $caption="Перевірка #{$o->id}\n{$p->name} ({$p->id})\n{$o->title}\nДедлайн: ".Time::parse($o->due_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i')."\nНадіслано: ".Time::now($p->timezone)->format('d.m H:i').($duplicate?"\n⚠️ Це фото вже надсилали для іншої задачі.":'');
        $this->tg->enqueue('sendPhoto',['chat_id'=>$chat,'photo'=>$photo['file_id'],'caption'=>$caption,'reply_markup'=>['inline_keyboard'=>[[['text'=>'✅ Підтвердити','callback_data'=>'approve:'.$o->id]],[['text'=>'❌ Не пройшло перевірку','callback_data'=>'reject:'.$o->id]]]]], $p->id);
        $this->state($p->id,null); $this->tg->send($p->id,'Фото отримано 📷 Очікує ручної перевірки. Час надсилання зафіксовано.');
    }

    private function group(int $uid,array $m,?array $cb): void
    {
        $chat=(string)$m['chat']['id']; $text=$m['text'] ?? '';
        if (preg_match('~^/bind(?:@\w+)?\s+([a-f0-9]{32})$~',$text,$match) && !$this->tg->setting('moderation_chat')) {
            $expected=$this->tg->setting('bind_code');
            if (!$expected || !hash_equals($expected,$match[1])) return;
            $member=$this->tg->call('getChatMember',['chat_id'=>$chat,'user_id'=>$uid]);
            if (!in_array($member['status'] ?? '',['creator','administrator'],true)) return;
            DB::table('bot_settings')->updateOrInsert(['key'=>'moderation_chat'],['value'=>$chat]);
            DB::table('bot_settings')->updateOrInsert(['key'=>'admin_id'],['value'=>(string)$uid]);
            DB::table('bot_settings')->where('key','bind_code')->delete();
            $this->tg->send($chat,'Групу модерації підключено ✅ Рішення може приймати власник, який підключив групу.'); return;
        }
        if (!$cb) return;
        $this->tg->enqueue('answerCallbackQuery',['callback_query_id'=>$cb['id']]);
        if (str_starts_with($cb['data'] ?? '', 'a:')) { app(Funds::class)->button($uid,$cb['data'],(int)$chat,(int)$m['message_id']); return; }
        if ($chat!==$this->tg->setting('moderation_chat') || (string)$uid!==$this->tg->setting('admin_id')) return;
        [$action,$id]=array_pad(explode(':',$cb['data'] ?? '',2),2,'');
        if (in_array($action,['pauseapprove','pausereject'],true)) {
            app(Accountability::class)->moderatePause((int)$id,$action==='pauseapprove');
            $this->tg->send($chat,'Рішення щодо паузи #'.(int)$id.' збережено.'); return;
        }
        $row=DB::table('occurrences')->where('id',(int)$id)->lockForUpdate()->first(); if (!$row) return;
        $o=$this->tasks->occurrence($row->id,$row->profile_id);
        if ($action==='pardon' && $o->status==='missed') {
            DB::table('occurrences')->where('id',$o->id)->update(['status'=>'excused','penalty_amount'=>0,'reviewed_at'=>now(),'updated_at'=>now()]);
            $resetThrough=$this->tg->setting('penalty_reset_through_'.$o->profile_id);
            if ($o->penalty_amount && (!$resetThrough || $o->due_at>$resetThrough)) DB::table('bot_profiles')->where('id',$o->profile_id)->where('violations','>',0)->decrement('violations');
            $this->tg->send($o->profile_id,"Оскарження прийняте. Штраф за «{$o->title}» скасовано."); $this->tg->send($chat,"Штраф #{$o->id} скасовано."); return;
        }
        if ($o->status!=='review') { $this->tg->send($chat,'Це фото вже перевірено.'); return; }
        if ($action==='reject') {
            $this->tg->send($chat,"Причина відмови #{$o->id}:",[[['Не видно результату','reason:'.$o->id.',result']],[['Не відповідає задачі','reason:'.$o->id.',wrong']],[['Повторне фото','reason:'.$o->id.',duplicate']]]); return;
        }
        if ($action==='approve') {
            DB::table('occurrences')->where('id',$o->id)->update(['status'=>'done','reviewed_at'=>now(),'updated_at'=>now()]);
            $this->tg->send($o->profile_id,"Фото прийнято ✅\n{$o->title} — виконано."); $this->tg->send($chat,"Підтверджено #{$o->id}."); return;
        }
        if ($action==='reason') {
            [, $code]=array_pad(explode(',',$id,2),2,'');
            $reason=['result'=>'На фото не видно результату','wrong'=>'Фото не відповідає задачі','duplicate'=>'Повторне використання фото'][$code] ?? null;
            if (!$reason) return;
            DB::table('occurrences')->where('id',$o->id)->update(['status'=>'rejected','review_reason'=>$reason,'reviewed_at'=>now(),'updated_at'=>now()]);
            $this->tg->send($o->profile_id,"Ваше фото не пройшло перевірку. Задача не підтверджена.\nПричина: {$reason}");
            $o=$this->tasks->occurrence($o->id,$o->profile_id);
            if (Time::now()->greaterThan(Time::parse($o->due_at,'UTC')->addMinutes(30))) $this->tasks->fail($o);
            else $this->tg->send($o->profile_id,'До кінця вікна підтвердження можна надіслати інше фото.',[[['Надіслати фото','done:'.$o->id]]]);
            $this->tg->send($chat,"Відхилено #{$o->id}: {$reason}.");
        }
    }

    private function pauseMenu(object $p): void
    {
        $active=DB::table('bot_pauses')->where('profile_id',$p->id)->where('status','approved')->where('ends_at','>',now())->orderByDesc('ends_at')->first();
        $text='Екстрена пауза для хвороби або надзвичайної ситуації. До 3 діб раз на 30 днів без погодження. Повторення та продовження — через модератора. Попередні пропуски не скасовуються.';
        if ($active) $text.="\nПауза до ".Time::parse($active->ends_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i').'. Новий запит продовжить її після цього часу.';
        $rows=[[['1 доба','pausedays:1'],['2 доби','pausedays:2'],['3 доби','pausedays:3']]];
        if ($active) { $text.="\nПри достроковому завершенні майбутні заняття одразу знову стануть чинними."; $rows[]=[['Завершити паузу та відновити план','pauseresume']]; }
        if (DB::table('bot_pauses')->where('profile_id',$p->id)->where('status','pending')->exists()) { $text.="\nЄ запит на паузу, який очікує модерації."; $rows[]=[['Відкликати запит','pausewithdraw']]; }
        $rows[]=[['Меню','home']]; $this->tg->send($p->id,$text,$rows);
    }

    private function timezoneCities(): array
    {
        return [
            'kyiv'=>['🇺🇦 Київ','Europe/Kyiv'], 'warsaw'=>['🇵🇱 Варшава','Europe/Warsaw'],
            'berlin'=>['🇩🇪 Берлін','Europe/Berlin'], 'prague'=>['🇨🇿 Прага','Europe/Prague'],
            'london'=>['🇬🇧 Лондон','Europe/London'], 'paris'=>['🇫🇷 Париж','Europe/Paris'],
            'madrid'=>['🇪🇸 Мадрид','Europe/Madrid'], 'rome'=>['🇮🇹 Рим','Europe/Rome'],
            'lisbon'=>['🇵🇹 Лісабон','Europe/Lisbon'], 'chisinau'=>['🇲🇩 Кишинів','Europe/Chisinau'],
            'toronto'=>['🇨🇦 Торонто','America/Toronto'], 'newyork'=>['🇺🇸 Нью-Йорк','America/New_York'],
        ];
    }

    private function timezoneMenu(object $p, ?int $messageId = null): void
    {
        $this->state($p->id,['step'=>'timezone']); $buttons=[];
        foreach ($this->timezoneCities() as $key=>$city) $buttons[]=[$city[0].' · '.Time::now($city[1])->format('H:i'),'tzpick:'.$key];
        $rows=array_chunk($buttons,2); $rows[]=[['Інше місто','tzcustom']];
        $text='Обери місто, за часом якого плануватимемо задачі. Якщо твого міста немає, можна обрати інше з таким самим часом.';
        if ($p->onboarded) $text.="\nЗміна діятиме для нових задач; уже погоджені дедлайни збережуться.";
        if ($messageId) $this->tg->edit($p->id,$messageId,$text,$rows);
        else $this->tg->send($p->id,$text,$rows);
    }

    private function guide(int $uid,int $messageId): void
    {
        $text = <<<'GUIDE'
Дізнайся про мене більше💜

Я — «Крок вперед» 👣 Допомагаю планувати справи й тримати слово перед собою.

Пілотна версія: штрафи обліковуються. Донати на схвалені збори добровільні; доступ до бота не блокується.

＋ Як створити задачу
Натисни «Додати задачу», опиши конкретну дію та обсяг, обери тип і розклад. Перед збереженням перевір умови та підтвердь їх.

🔒 Зобов’язання
Обіцянка собі з дедлайном і погодженим штрафом за пропуск. Скасувати, перенести або змінити обсяг без штрафу можна раніше ніж за 3 години до дедлайну. Для скасування поставлю три запитання, щоб допомогти переглянути рішення.

🌿 Необов’язковий план
Справи, які добре було б зробити. За невиконання штрафу немає.

🗓 Розклад і нагадування
Є разові задачі, щоденні заняття та обрані дні тижня. Для різних днів можна встановити різні години. Нагадаю за 2 години до дедлайну. «Сьогодні» та «Мій тиждень» показують план одним списком; натисни задачу, щоб відкрити її дії.

✅ Підтвердження виконання
Виконай задачу до дедлайну. Потім є ще 30 хвилин, щоб підтвердити виконання кнопкою або надіслати фото, якщо воно обов’язкове. Фото перевіряє адміністратор; очікування перевірки не спричиняє штрафу. Відмову можна оскаржити.

💛 Штрафи та донати
Початковий штраф — 20 грн, кожне порушення додає 1 грн до наступного. Після 10 активних успішних днів сума повертається до 20 грн; старі штрафи залишаються. Для пропуску можна обрати схвалений благодійний збір і підтвердити добровільний донат скріншотом. Доступ до бота не блокується. Переліком банок керує адміністратор.

⏸ Екстрена пауза
Для хвороби чи надзвичайної ситуації — до 3 діб раз на 30 днів без погодження. Повторна пауза або продовження — через адміністратора. Дні пауз і без зобов’язань не додаються до серії успіхів; попередні пропуски не скасовуються.

🏠 Як повернутися
Постійна кнопка «Повернутися в меню» закриває незавершений діалог. Уже збережені зобов’язання залишаються чинними.

🎬 Відеоінструкцію додамо згодом.
GUIDE;
        $this->tg->edit($uid,$messageId,$text,[[['← До налаштувань','settings']],[['Повернутися в меню','home']]]);
    }
}
