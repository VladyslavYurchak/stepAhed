<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UserData
{
    public function notice(): string
    {
        return "🔐 Твої дані\n\nЗберігаємо Telegram ID та ім’я, часовий пояс, задачі й розклад, виконання та пропуски, штрафи, паузи й причини, звернення до модератора та квитанції. Для фото зберігаємо Telegram-ідентифікатори, а не окремі файли. Дані потрібні для нагадувань, прогресу та перевірки.\n\nАдміністратор бачить матеріали, надіслані на перевірку. Повідомлення й фото також обробляє та зберігає Telegram. Не надсилай зайвих особистих або банківських даних.\n\nЕкспорт — JSON-файл із твоїми записами, без токенів і даних інших користувачів. Видалення прибирає профіль та пов’язані записи з робочої бази й зупиняє твої нагадування. Повернути їх через бота не можна.\n\nВидалення не прибирає вже надіслані повідомлення й фото з Telegram або групи модерації. Старі резервні копії можуть містити дані: їх автоматичне очищення ще не налаштоване. Для видалення таких копій звернися до адміністратора. Після видалення можна почати заново через /start.";
    }

    public function export(int $uid): string
    {
        $profile = DB::table('bot_profiles')->where('id', $uid)->first();
        $data = ['exported_at'=>now()->utc()->toIso8601String(), 'profile'=>$profile];
        // Dialogs can contain unfinished administrative forms, so export only the user's saved records.
        if ($profile) unset($profile->dialog);
        foreach (['commitments','occurrences','bot_pauses','donations','donation_receipts'] as $table) {
            $data[$table] = DB::table($table)->where('profile_id', $uid)->orderBy('id')->get()->map(function ($row) {
                unset($row->reviewed_by);
                return $row;
            })->all();
        }
        $data['fund_proposals'] = DB::table('charity_funds')->where('proposed_by', $uid)->get()->map(function ($row) {
            unset($row->reviewed_by);
            return $row;
        })->all();
        return json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public function delete(int $uid): void
    {
        $deleted = Cache::lock('telegram-outbox', 120)->get(fn () => DB::transaction(function () use ($uid) {
            // Delete dependent rows first, preserving shared funds and other accounts.
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
