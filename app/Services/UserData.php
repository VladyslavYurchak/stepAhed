<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UserData
{
    public function notice(): string
    {
        return "🔐 Твої дані\n\nЯ пам’ятаю твоє ім’я, задачі, розклад і прогрес, щоб нагадувати про справи. Фото та квитанції, які ти надсилаєш на перевірку, бачить адміністратор.\n\n📄 Хочеш зберегти свої записи? Натисни «Експортувати мої дані» — я надішлю їх файлом.\n\n🗑 Хочеш почати з чистого аркуша? Я видалю твої задачі, прогрес і налаштування з бота та вимкну нагадування. Скасувати це не вийде. Дані в резервних копіях очищаються автоматично через 14 днів.\n\nІсторію чату можна видалити в Telegram. А повернутися до мене — через /start.";
    }

    public function export(int $uid): string
    {
        $profile = DB::table('bot_profiles')->where('id', $uid)->first();
        $data = ['exported_at'=>now()->utc()->toIso8601String(), 'profile'=>$profile];
        // Dialogs can contain unfinished administrative forms, so export only the user's saved records.
        if ($profile) unset($profile->dialog);
        foreach (['commitments','occurrences','bot_pauses','donations','donation_receipts','bot_deferred_notifications'] as $table) {
            $data[$table] = DB::table($table)->where('profile_id', $uid)->orderBy('id')->get()->map(function ($row) {
                unset($row->reviewed_by);
                return $row;
            })->all();
        }
        $data['fund_proposals'] = DB::table('charity_funds')->where('proposed_by', $uid)->get()->map(function ($row) {
            unset($row->reviewed_by);
            return $row;
        })->all();
        $data['calendar_subscription'] = DB::table('bot_calendar_subscriptions')->where('profile_id',$uid)->first(['created_at','updated_at']);
        $data['calendar_lab_tasks'] = DB::table('calendar_lab_tasks')->where('profile_id',$uid)->get();
        $data['calendar_lab_connection'] = DB::table('calendar_lab_connections')->where('profile_id',$uid)->first(['status','last_synced_at','created_at']);
        return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public function delete(int $uid): void
    {
        $deleted = Cache::lock('telegram-outbox', 120)->get(fn () => DB::transaction(function () use ($uid) {
            // Delete dependent rows first, preserving shared funds and other accounts.
            app(CalendarLab::class)->forget($uid);
            DB::table('bot_calendar_subscriptions')->where('profile_id',$uid)->delete();
            DB::table('bot_deferred_notifications')->where('profile_id', $uid)->delete();
            DB::table('donation_receipts')->where('profile_id', $uid)->delete();
            DB::table('donations')->where('profile_id', $uid)->delete();
            DB::table('occurrences')->where('profile_id', $uid)->delete();
            DB::table('commitments')->where('profile_id', $uid)->delete();
            DB::table('bot_pauses')->where('profile_id', $uid)->delete();
            DB::table('charity_funds')->where('proposed_by', $uid)->update(['proposed_by'=>null]);
            DB::table('charity_funds')->where('reviewed_by', $uid)->update(['reviewed_by'=>null]);
            DB::table('donation_receipts')->where('reviewed_by', $uid)->update(['reviewed_by'=>null]);
            DB::table('bot_settings')->whereIn('key', ['navigation_keyboard_'.$uid, 'penalty_reset_through_'.$uid])->delete();
            DB::table('bot_outbox')->where('data_owner_id', $uid)->delete();
            DB::table('bot_profiles')->where('id', $uid)->delete();
            return true;
        }));
        if (!$deleted) throw new \DomainException('Зараз надсилаються повідомлення. Спробуй підтвердити видалення ще раз за кілька секунд.');
    }
}
