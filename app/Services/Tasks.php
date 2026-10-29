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
            $due = $date->setTimeFromTimeString($s['time'])->utc();
            if ($due->lessThanOrEqualTo(Time::now())) continue;
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
        if (in_array($o->status, ['missed', 'done', 'cancelled', 'review'], true)) return;
        $amount = $this->penalty($o);
        DB::table('occurrences')->where('id', $o->id)->update([
            'status' => 'missed', 'penalty_amount' => $amount, 'updated_at' => now(),
        ]);
        app(Telegram::class)->send($o->profile_id,
            "Не підтверджено: {$o->title}.".($amount ? "\nРозрахований штраф: {$amount} грн. Пілотний режим: оплата поки не потрібна." : "\nЦе необов’язковий план — штрафу немає.")
        );
    }

    public function tick(): void
    {
        DB::transaction(function () {
            foreach (DB::table('commitments')->where('active', true)->get() as $task) $this->generate($task);
            $tg = app(Telegram::class);
            foreach (DB::table('occurrences')->whereIn('status', ['pending', 'rejected'])->where('due_at', '<', now()->subMinutes(30))->get() as $row) {
                $this->fail($this->occurrence($row->id, $row->profile_id));
            }
            foreach (DB::table('occurrences')->where('status', 'pending')->whereNull('deadline_notice_at')
                ->where('due_at', '<=', now())->where('due_at', '>=', now()->subMinutes(30))->get() as $row) {
                $o = $this->occurrence($row->id, $row->profile_id);
                $tg->send($row->profile_id, "Дедлайн настав: {$o->title}.\nЯкщо виконав вчасно, підтвердь протягом 30 хвилин.", [[['Підтвердити', 'done:'.$o->id]]]);
                DB::table('occurrences')->where('id', $row->id)->update(['deadline_notice_at' => now()]);
            }
            foreach (DB::table('occurrences')->where('status', 'pending')->whereNull('reminded_at')
                ->where('due_at', '>', now())->where('due_at', '<=', now()->addMinutes(30))->get() as $row) {
                $o = $this->occurrence($row->id, $row->profile_id);
                $tg->send($row->profile_id, "Незабаром дедлайн: {$o->title}", [[['Виконано', 'done:'.$o->id], ['Відкрити', 'view:'.$o->id]]]);
                DB::table('occurrences')->where('id', $row->id)->update(['reminded_at' => now()]);
            }
        });
    }
}
