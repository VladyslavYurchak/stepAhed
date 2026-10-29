<?php

namespace App\Services;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;

class Accountability
{
    public function requestPause(int $uid, int $days, string $reason): object
    {
        if ($days < 1 || $days > 3 || mb_strlen($reason) < 3 || mb_strlen($reason) > 300) throw new \InvalidArgumentException('Invalid pause');
        $existing = DB::table('bot_pauses')->where('profile_id', $uid)->where('status', 'pending')->first();
        if ($existing) return $existing;
        $active = DB::table('bot_pauses')->where('profile_id', $uid)->where('status', 'approved')->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
        $recent = DB::table('bot_pauses')->where('profile_id', $uid)->where('automatic', true)
            ->whereIn('status', ['approved', 'ended'])->where('created_at', '>', now()->subDays(30))->exists();
        $automatic = !$recent && !$active;
        if (!$automatic && !app(Telegram::class)->setting('moderation_chat')) throw new \RuntimeException('Moderation not configured');
        $start = $active ? Time::parse($active->ends_at, 'UTC') : Time::now();
        $id = DB::table('bot_pauses')->insertGetId([
            'profile_id'=>$uid, 'starts_at'=>$start->toDateTimeString(), 'ends_at'=>$start->addDays($days)->toDateTimeString(),
            'status'=>$automatic ? 'approved' : 'pending', 'automatic'=>$automatic, 'reason'=>$reason,
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $pause = DB::table('bot_pauses')->find($id);
        $this->applyPause($pause);
        $tg = app(Telegram::class);
        if ($automatic) $tg->send($uid, 'Екстрену паузу ввімкнено на '.$days.' діб. У цей період нагадування й штрафи призупинено. Попередні пропуски залишаються.');
        else {
            $tg->send($uid, 'Запит на паузу передано адміністратору. Відповідні заняття очікуватимуть рішення; після відмови їхні початкові умови залишаться чинними.');
            $tz = DB::table('bot_profiles')->where('id', $uid)->value('timezone');
            $tg->send($tg->setting('moderation_chat'), "Запит на екстрену паузу #{$id}\nКористувач: {$uid}\n".
                Time::parse($pause->starts_at, 'UTC')->setTimezone($tz)->format('d.m H:i').' — '.Time::parse($pause->ends_at, 'UTC')->setTimezone($tz)->format('d.m H:i')."\nПричина: {$reason}",
                [[['Схвалити', 'pauseapprove:'.$id], ['Відхилити', 'pausereject:'.$id]]]);
        }
        return $pause;
    }

    public function applyPause(object $pause): void
    {
        DB::table('occurrences')->where('profile_id', $pause->profile_id)->where('status', 'pending')
            ->where('due_at', '>=', $pause->starts_at)->where('due_at', '<', $pause->ends_at)
            ->update(['status'=>$pause->status === 'approved' ? 'paused' : 'pause_review', 'pause_id'=>$pause->id, 'updated_at'=>now()]);
    }

    public function syncPauses(): void
    {
        foreach (DB::table('bot_pauses')->whereIn('status', ['approved', 'pending'])->get() as $pause) $this->applyPause($pause);
    }

    public function moderatePause(int $id, bool $approve): void
    {
        $pause = DB::table('bot_pauses')->where('id', $id)->lockForUpdate()->first();
        if (!$pause || $pause->status !== 'pending') return;
        DB::table('bot_pauses')->where('id', $id)->update(['status'=>$approve ? 'approved' : 'rejected', 'updated_at'=>now()]);
        DB::table('occurrences')->where('pause_id', $id)->where('status', 'pause_review')
            ->update(['status'=>$approve ? 'paused' : 'pending', 'pause_id'=>$approve ? $id : null, 'updated_at'=>now()]);
        app(Telegram::class)->send($pause->profile_id, $approve ? 'Адміністратор схвалив екстрену паузу.' : 'Адміністратор відхилив запит на паузу. Початкові дедлайни знову чинні; пропущені заняття будуть перевірені за звичайними правилами.');
    }

    public function resume(int $uid): void
    {
        $ids = DB::table('bot_pauses')->where('profile_id', $uid)->where('status', 'approved')->where('ends_at', '>', now())->pluck('id');
        if ($ids->isEmpty()) return;
        DB::table('bot_pauses')->whereIn('id', $ids)->update(['status'=>'ended', 'ends_at'=>now(), 'updated_at'=>now()]);
        DB::table('occurrences')->whereIn('pause_id', $ids)->where('status', 'paused')->where('due_at', '>', now())
            ->update(['status'=>'pending', 'pause_id'=>null, 'updated_at'=>now()]);
        app(Telegram::class)->send($uid, 'Повертаємося до плану. Майбутні заняття та нагадування відновлено.');
    }

    public function withdrawPause(int $uid): void
    {
        $ids=DB::table('bot_pauses')->where('profile_id',$uid)->where('status','pending')->pluck('id');
        DB::table('bot_pauses')->whereIn('id',$ids)->update(['status'=>'withdrawn','updated_at'=>now()]);
        DB::table('occurrences')->whereIn('pause_id',$ids)->where('status','pause_review')->update(['status'=>'pending','pause_id'=>null,'updated_at'=>now()]);
        app(Telegram::class)->send($uid,'Запит на паузу відкликано. Початкові умови занять залишаються чинними.');
    }

    public function settleDays(int $uid): void
    {
        $p = DB::table('bot_profiles')->where('id', $uid)->lockForUpdate()->first();
        $today = Time::now($p->timezone)->toDateString();
        $rows = DB::table('occurrences')->join('commitments', 'commitments.id', '=', 'occurrences.commitment_id')
            ->where('occurrences.profile_id', $uid)->where('mandatory', true)
            ->where('due_at', '<', Time::now($p->timezone)->startOfDay()->utc())->orderBy('due_at')
            ->select('occurrences.*')->get()->groupBy(fn ($o) => Time::parse($o->due_at, 'UTC')->setTimezone($p->timezone)->toDateString());
        $streak = $p->success_streak; $cursor = $p->streak_cursor;
        foreach ($rows as $date => $day) {
            if (($cursor && $date <= $cursor) || $date >= $today) continue;
            // Wait for the entire local day, the grace window and all moderation decisions.
            if ($day->contains(fn ($o) => in_array($o->status, ['pending','review','rejected','pause_review'], true))) break;
            if ($day->contains(fn ($o) => $o->status === 'missed')) $streak = 0;
            elseif (!$day->contains(fn ($o) => $o->status === 'paused') && $day->contains(fn ($o) => $o->status === 'done')) $streak++;
            $cursor = $date;
            if ($streak >= 10) {
                // Do not erase violations belonging to a later day while settling history.
                $boundary = Time::parse($date, $p->timezone)->endOfDay()->utc();
                $later = DB::table('occurrences')->where('profile_id', $uid)->where('status','missed')->where('penalty_amount','>',0)->where('due_at','>',$boundary)->count();
                DB::table('bot_profiles')->where('id',$uid)->update(['violations'=>$later]);
                DB::table('bot_settings')->updateOrInsert(['key'=>'penalty_reset_through_'.$uid],['value'=>$boundary->toDateTimeString()]);
                $streak = 0;
                app(Telegram::class)->send($uid, '10 активних успішних днів 👣 Базовий штраф повернувся до 20 грн. Раніше нараховані штрафи залишаються; пізніші пропуски враховуються окремо.');
            }
        }
        DB::table('bot_profiles')->where('id',$uid)->update(['success_streak'=>$streak,'streak_cursor'=>$cursor]);
    }
}
