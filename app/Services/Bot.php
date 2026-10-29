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
            DB::table('bot_profiles')->insertOrIgnore([
                'id' => $uid, 'name' => mb_substr($from['first_name'] ?? 'Друже', 0, 100),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $p = DB::table('bot_profiles')->where('id', $uid)->lockForUpdate()->first();
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
        $this->tg->send($uid, "Крок вперед 👣\nЩо плануємо?\nПілотна версія: штрафи обліковуються, оплачувати їх поки не потрібно.", [
            [['＋ Додати задачу', 'new']], [['Сьогодні', 'list:today'], ['Мій тиждень', 'list:week']],
            [['Регулярні заняття', 'series'], ['Прогрес', 'progress']], [['Налаштування', 'settings']],
        ]);
    }

    private function message(object $p, array $m): void
    {
        $text = trim($m['text'] ?? '');
        if (preg_match('~^/(start|menu)(?:@\w+)?(?:\s.*)?$~u', $text)) {
            $this->state($p->id, null);
            if (!$p->onboarded) {
                $this->tg->send($p->id, "Привіт! Я допоможу тримати слово перед собою.\n\n🔒 Зобов’язання — з погодженим штрафом за пропуск.\n🌿 Необов’язкові плани — без штрафів.\n\nЧасовий пояс зараз Europe/Kyiv. Підтвердь або зміни його.", [[['Час Києва підходить', 'onboard']], [['Інший часовий пояс', 'timezone']]]);
            } else $this->home($p->id);
            return;
        }
        if ($text === '/cancel') { $this->state($p->id, null); $this->home($p->id); return; }
        if ($text === '/help') {
            $this->tg->send($p->id, "Додай задачу через меню. Для виходу з діалогу — /cancel.\nВиконати треба до дедлайну, підтвердити — протягом наступних 30 хвилин.\nСкасувати, перенести або зменшити обсяг без штрафу можна раніше ніж за 3 години.\nФото перевіряє адміністратор. Спірне рішення можна оскаржити.\nГолосові, календар та донати ще не підключені."); return;
        }
        $s = json_decode($p->dialog ?? 'null', true);
        if (($s['step'] ?? '') === 'timezone') {
            if (!in_array($text, timezone_identifiers_list(), true)) { $this->tg->send($p->id, 'Введи назву часового поясу, наприклад Europe/Kyiv або Europe/Warsaw.'); return; }
            DB::table('bot_profiles')->where('id', $p->id)->update(['timezone' => $text, 'onboarded' => true]);
            $this->state($p->id, null); $this->home($p->id); return;
        }
        if (!$p->onboarded) { $this->tg->send($p->id, 'Спочатку відкрий /start і підтвердь часовий пояс.'); return; }
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

    private function askTime(int $uid): void { $this->tg->send($uid, 'До котрої години виконати? Введи час у форматі 21:00.'); }

    private function askDayTime(int $uid, int $day): void
    {
        $name = [1=>'Понеділок',2=>'Вівторок',3=>'Середа',4=>'Четвер',5=>'П’ятниця',6=>'Субота',7=>'Неділя'][$day];
        $this->tg->send($uid, "{$name} — до котрої години виконати? Введи час, наприклад 21:00.");
    }

    private function weeklySummary(array $s): string
    {
        $names = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Нд'];
        return implode(' · ', array_map(fn ($d) => $names[$d].' '.$s['day_times'][$d], $s['weekdays']));
    }

    private function preview(object $p, array $s): void
    {
        $schedule = match ($s['mode']) {
            'once' => $s['date'], 'daily' => 'Щодня, без кінцевої дати',
            default => 'Дні тижня: '.implode(', ', array_map(fn ($d) => ['1'=>'Пн','2'=>'Вт','3'=>'Ср','4'=>'Чт','5'=>'Пт','6'=>'Сб','7'=>'Нд'][$d], $s['weekdays'])).', без кінцевої дати',
        };
        $schedule = !empty($s['day_times']) ? $this->weeklySummary($s).', без кінцевої дати' : $schedule.' · до '.$s['time'];
        $amount = config('bot.base_penalty') + $p->violations;
        $text = ($s['mandatory'] ? '🔒 ' : '🌿 ').$s['title']."\n{$schedule}\nЧасовий пояс: {$p->timezone}\n".
            ($s['photo'] ? '📷 Фото обов’язкове. Невідповідне фото — відмова та штраф для зобов’язань.' : 'Підтвердження кнопкою.').
            ($s['mandatory'] ? "\nШтраф за найближче порушення: {$amount} грн, далі +1 грн. Пілот: без оплати.\nЗміни без штрафу — раніше ніж за 3 години до дедлайну." : '\nБез штрафу.');
        if ($s['mode'] === 'once' && $s['mandatory'] && Time::parse($s['date'].' '.$s['time'], $p->timezone)->lessThanOrEqualTo(Time::now()->addHours(3))) $text .= "\n⚠️ До дедлайну не більше 3 годин. Після підтвердження безкоштовне скасування недоступне.";
        $this->tg->send($p->id, $text, [[['Підтвердити', 'save']], [['Почати заново', 'new'], ['Вийти', 'home']]]);
    }

    private function button(object $p, string $data, int $messageId): void
    {
        [$action, $value] = array_pad(explode(':', $data, 2), 2, '');
        $s = json_decode($p->dialog ?? 'null', true);
        if ($action === 'onboard') { DB::table('bot_profiles')->where('id', $p->id)->update(['onboarded' => true]); $this->home($p->id); return; }
        if ($action === 'timezone') { $this->state($p->id, ['step' => 'timezone']); $this->tg->send($p->id, 'Введи часовий пояс, наприклад Europe/Kyiv або Europe/Warsaw. Зміна діятиме для нових задач; існуючі дедлайни збережуться.'); return; }
        if (!$p->onboarded) { $this->tg->send($p->id, 'Спочатку підтвердь часовий пояс через /start.'); return; }
        if ($action === 'home') { $this->state($p->id, null); $this->home($p->id); return; }
        if ($action === 'new') { $this->state($p->id, ['step' => 'title']); $this->tg->send($p->id, 'Що хочеш зробити? Напиши конкретну дію та обсяг, наприклад «30 віджимань». Для виходу — /cancel.'); return; }
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
            $schedule['timezone'] = $p->timezone;
            $id = DB::table('commitments')->insertGetId(['profile_id'=>$p->id,'title'=>$s['title'],'mandatory'=>$s['mandatory'],'photo_required'=>$s['photo'],'schedule'=>json_encode($schedule),'created_at'=>now(),'updated_at'=>now()]);
            $task = DB::table('commitments')->find($id);
            $this->tasks->generate($task, $s['mode'] === 'once' ? 367 : 14);
            $this->state($p->id, null); $this->tg->send($p->id, 'Збережено 👣', [[['Мій тиждень','list:week'],['Меню','home']]]); return;
        }
        if ($action === 'list') { $this->listing($p, $value); return; }
        if ($action === 'series') { $this->series($p); return; }
        if ($action === 'stopseries') { $this->stopSeries($p, (int) $value); return; }
        if ($action === 'progress') {
            $rows = DB::table('occurrences')->where('profile_id',$p->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status');
            $this->tg->send($p->id, 'Виконано: '.($rows['done'] ?? 0)."\nНа перевірці: ".($rows['review'] ?? 0)."\nПропущено: ".($rows['missed'] ?? 0)."\nНаступний розрахований штраф: ".(20+$p->violations).' грн. Пілот: без оплати.'); return;
        }
        if ($action === 'settings') { $this->tg->send($p->id,"Часовий пояс: {$p->timezone}\nНагадування: за 30 хвилин до дедлайну.\nКалендар, паузи та донати — у наступних оновленнях.", [[['Часовий пояс','timezone']]]); return; }
        $o = $this->tasks->occurrence((int) $value, $p->id);
        if (!$o) { $this->tg->send($p->id, 'Ця дія вже неактуальна. Відкрий /menu.'); return; }
        if ($action === 'view') { $this->card($p, $o); return; }
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
            if (!$o->mandatory) { DB::table('occurrences')->where('id',$o->id)->update(['status'=>'cancelled']); $this->tg->send($p->id,'Необов’язковий план скасовано без штрафу.'); return; }
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
            if ($group) { $this->tg->send($group,"Оскарження #{$o->id}\n{$p->name} ({$p->id})\n{$o->title}\nШтраф: {$o->penalty_amount} грн",[[['Скасувати штраф','pardon:'.$o->id]]]); $this->tg->send($p->id,'Запит передано адміністратору.'); }
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

    private function listing(object $p, string $range): void
    {
        $start=Time::now($p->timezone)->startOfDay()->utc();
        $end=$range==='today' ? $start->setTimezone($p->timezone)->endOfDay()->utc() : Time::now($p->timezone)->addDays(7)->endOfDay()->utc();
        $rows=DB::table('occurrences')->where('profile_id',$p->id)->whereBetween('due_at',[$start,$end])->orderBy('due_at')->limit(25)->get();
        $this->tg->send($p->id,$range==='today'?'Твій день 👣':'План на найближчі 7 днів 👣');
        foreach ($rows as $row) $this->card($p,$this->tasks->occurrence($row->id,$p->id));
        if ($rows->isEmpty()) $this->tg->send($p->id,'Поки задач немає.',[[['＋ Додати','new']]]);
    }

    private function card(object $p, object $o): void
    {
        $status=['pending'=>'Очікує виконання','done'=>'✅ Виконано','review'=>'📷 На перевірці','rejected'=>'Фото відхилене — можна замінити до кінця вікна','missed'=>'Пропущено','cancelled'=>'Скасовано','excused'=>'Порушення скасоване адміністратором'][$o->status] ?? $o->status;
        $buttons=[];
        if ($this->tasks->confirmationAllowed($o)) $buttons[]=[['Підтвердити виконання','done:'.$o->id]];
        if ($o->status==='pending') $buttons[]=[['Перенести','move:'.$o->id],['Скасувати','cancel:'.$o->id]];
        if ($o->status==='missed') $buttons[]=[['Оскаржити','appeal:'.$o->id]];
        $this->tg->send($p->id,($o->mandatory?'🔒 ':'🌿 ').$o->title."\nДо ".Time::parse($o->due_at,'UTC')->setTimezone($p->timezone)->format('d.m H:i')."\n{$status}".($o->photo_required?"\n📷 Потрібне фото":'').($o->penalty_amount?"\nРозрахований штраф: {$o->penalty_amount} грн (без оплати в пілоті)":''),$buttons);
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
            $this->tg->send($p->id,"{$task->title}\n{$timing}. Припинення прибере лише майбутні заняття, які ще можна скасувати без штрафу.",[[['Припинити повторення','stopseries:'.$task->id]]]);
        }
        if (!$count) $this->tg->send($p->id,'Регулярних занять поки немає.');
    }

    private function stopSeries(object $p,int $id): void
    {
        $task=DB::table('commitments')->where('id',$id)->where('profile_id',$p->id)->where('active',true)->first();
        if (!$task) return;
        DB::table('commitments')->where('id',$id)->update(['active'=>false]);
        foreach (DB::table('occurrences')->where('commitment_id',$id)->where('status','pending')->get() as $row) {
            $o=$this->tasks->occurrence($row->id,$p->id);
            if ($this->tasks->changeAllowed($o)) DB::table('occurrences')->where('id',$row->id)->update(['status'=>'cancelled']);
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
        $this->tg->enqueue('sendPhoto',['chat_id'=>$chat,'photo'=>$photo['file_id'],'caption'=>$caption,'reply_markup'=>['inline_keyboard'=>[[['text'=>'✅ Підтвердити','callback_data'=>'approve:'.$o->id]],[['text'=>'❌ Не пройшло перевірку','callback_data'=>'reject:'.$o->id]]]]]);
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
        if ($chat!==$this->tg->setting('moderation_chat') || (string)$uid!==$this->tg->setting('admin_id')) return;
        [$action,$id]=array_pad(explode(':',$cb['data'] ?? '',2),2,'');
        $row=DB::table('occurrences')->where('id',(int)$id)->lockForUpdate()->first(); if (!$row) return;
        $o=$this->tasks->occurrence($row->id,$row->profile_id);
        if ($action==='pardon' && $o->status==='missed') {
            DB::table('occurrences')->where('id',$o->id)->update(['status'=>'excused','penalty_amount'=>0,'reviewed_at'=>now()]);
            if ($o->penalty_amount) DB::table('bot_profiles')->where('id',$o->profile_id)->where('violations','>',0)->decrement('violations');
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
}
