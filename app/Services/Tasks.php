<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class Tasks
{
    public function generate(object $task, int $days = 14): void
    {
        if (!$task->active) return;
        $s = json_decode($task->schedule, true);
        $timezone = $s['timezone'] ?? DB::table('bot_profiles')->where('id', $task->profile_id)->value('timezone');
        $start = Time::now($timezone)->startOfDay();
        for ($i = 0; $i <= $days; $i++) {
            $date = $start->addDays($i);
            if ($s['mode'] === 'once' && $date->toDateString() !== $s['date']) continue;
            if ($s['mode'] === 'weekly' && !in_array($date->dayOfWeekIso, $s['weekdays'], true)) continue;
            $time = $s['mode'] === 'weekly' ? ($s['day_times'][$date->dayOfWeekIso] ?? $s['time']) : $s['time'];
            $due = $date->setTimeFromTimeString($time)->utc();
            if ($due->lessThanOrEqualTo(Time::now())) continue;
            if (isset($s['effective_after']) && $due->lessThanOrEqualTo(Time::parse($s['effective_after'], 'UTC'))) continue;
            DB::table('occurrences')->insertOrIgnore([
                'commitment_id' => $task->id, 'profile_id' => $task->profile_id,
                'due_at' => $due->toDateTimeString(), 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function occurrence(int $id, int $owner): ?object
    {
        return DB::table('occurrences')->join('commitments', 'commitments.id', '=', 'occurrences.commitment_id')
            ->where('occurrences.id', $id)->where('occurrences.profile_id', $owner)
            ->select('occurrences.*', 'commitments.title', 'commitments.mandatory', 'commitments.photo_required')->first();
    }

    public function changeAllowed(object $o, ?Time $at = null): bool
    {
        return !$o->mandatory || ($at ?? Time::now())->lessThan(Time::parse($o->due_at, 'UTC')->subHours(3));
    }

    public function confirmationAllowed(object $o, ?Time $at = null): bool
    {
        return in_array($o->status, ['pending', 'rejected'], true)
            && ($at ?? Time::now())->lessThanOrEqualTo(Time::parse($o->due_at, 'UTC')->addMinutes(30));
    }

    public function penalty(object $o): int
    {
        $profile = DB::table('bot_profiles')->where('id', $o->profile_id)->lockForUpdate()->first();
        if (!$o->mandatory) return 0;
        $amount = config('bot.base_penalty') + $profile->violations;
        DB::table('bot_profiles')->where('id', $profile->id)->increment('violations');
        return $amount;
    }

    public function fail(object $o): void
    {
        if (!in_array($o->status, ['pending', 'rejected'], true)) return;
        $amount = $this->penalty($o);
        DB::table('occurrences')->where('id', $o->id)->update([
            'status' => 'missed', 'penalty_amount' => $amount, 'updated_at' => now(),
        ]);
        app(DailyRhythm::class)->notify($o->profile_id, $o->id,
            "Не підтверджено: {$o->title}.".($amount ? "\nРозрахований штраф: {$amount} грн. Можна зробити добровільний донат на схвалений збір; доступ до бота не блокується." : "\nЦе необов’язковий план — штрафу немає."),
            $amount ? [[['Обрати збір / підтвердити донат','donate:'.$o->id]]] : []
        );
    }

    public function tick(): void
    {
        DB::transaction(function () {
            foreach (DB::table('commitments')->where('active', true)->get() as $task) $this->generate($task);
            app(Accountability::class)->syncPauses();
            $tg = app(Telegram::class);
            foreach (DB::table('occurrences')->whereIn('status', ['pending', 'rejected'])->where('due_at', '<', now()->subMinutes(30))->get() as $row) {
                $this->fail($this->occurrence($row->id, $row->profile_id));
            }
            foreach (DB::table('occurrences')->where('status', 'pending')->whereNull('deadline_notice_at')
                ->where('due_at', '<=', now())->where('due_at', '>=', now()->subMinutes(30))->get() as $row) {
                $o = $this->occurrence($row->id, $row->profile_id);
                app(DailyRhythm::class)->notify($row->profile_id, $o->id, "Дедлайн настав: {$o->title}.\nЯкщо виконав вчасно, підтвердь протягом 30 хвилин.", [[['Підтвердити', 'done:'.$o->id]]]);
                DB::table('occurrences')->where('id', $row->id)->update(['deadline_notice_at' => now()]);
            }
            foreach (DB::table('occurrences')->where('status', 'pending')->whereNull('reminded_at')
                ->where('due_at', '>', now())->where('due_at', '<=', now()->addHours(2))->get() as $row) {
                $o = $this->occurrence($row->id, $row->profile_id);
                $tz = DB::table('bot_profiles')->where('id', $row->profile_id)->value('timezone');
                app(DailyRhythm::class)->notify($row->profile_id, $o->id, "Нагадування: {$o->title}\nДедлайн: ".Time::parse($o->due_at, 'UTC')->setTimezone($tz)->format('d.m H:i'), [[['Виконано', 'done:'.$o->id], ['Відкрити', 'view:'.$o->id]]]);
                DB::table('occurrences')->where('id', $row->id)->update(['reminded_at' => now()]);
            }
            foreach (DB::table('bot_profiles')->pluck('id') as $uid) app(Accountability::class)->settleDays($uid);
            app(DailyRhythm::class)->tick();
        });
    }

    public function reviseSingle(int $uid, int $id, string $title): object
    {
        $o = $this->occurrence($id, $uid);
        if (!$o || $o->status !== 'pending' || !$this->changeAllowed($o)) throw new \DomainException('Вікно редагування закрите. Початкові умови залишаються чинними.');
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) throw new \DomainException('Назва має містити від 3 до 180 символів.');
        $tz = DB::table('bot_profiles')->where('id', $uid)->value('timezone');
        $date = Time::parse($o->due_at, 'UTC')->setTimezone($tz);
        $new = DB::table('commitments')->insertGetId([
            'profile_id'=>$uid,'title'=>$title,'mandatory'=>$o->mandatory,'photo_required'=>$o->photo_required,
            'schedule'=>json_encode(['mode'=>'once','date'=>$date->toDateString(),'time'=>$date->format('H:i'),'timezone'=>$tz]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('occurrences')->where('id',$id)->update(['status'=>'superseded','updated_at'=>now()]);
        $oid = DB::table('occurrences')->insertGetId(['profile_id'=>$uid,'commitment_id'=>$new,'due_at'=>$o->due_at,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        app(Accountability::class)->syncPauses();
        return $this->occurrence($oid, $uid);
    }

    public function reviseSeries(int $uid, int $id, string $expected, string $title, array $schedule): object
    {
        $task = DB::table('commitments')->where('id',$id)->where('profile_id',$uid)->where('active',true)->lockForUpdate()->first();
        if (!$task || !hash_equals($expected, hash('sha256',$task->title.$task->schedule))) throw new \DomainException('Цей розклад уже змінився. Відкрий його заново.');
        if (json_decode($task->schedule,true)['mode'] === 'once') throw new \DomainException('Це разова задача.');
        $schedule['effective_after'] = ($task->mandatory ? Time::now()->addHours(3) : Time::now())->toIso8601String();
        DB::table('commitments')->where('id',$id)->update(['active'=>false,'updated_at'=>now()]);
        foreach (DB::table('occurrences')->where('commitment_id',$id)->whereIn('status',['pending','paused','pause_review'])->where('due_at','>',now())->get() as $row) {
            if ($this->changeAllowed($this->occurrence($row->id,$uid))) DB::table('occurrences')->where('id',$row->id)->update(['status'=>'superseded','updated_at'=>now()]);
        }
        $new = DB::table('commitments')->insertGetId([
            'profile_id'=>$uid,'title'=>$title,'mandatory'=>$task->mandatory,'photo_required'=>$task->photo_required,
            'schedule'=>json_encode($schedule),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $next = DB::table('commitments')->find($new); $this->generate($next, $schedule['mode']==='once' ? 367 : 14);
        app(Accountability::class)->syncPauses();
        return $next;
    }
}
