<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_outbox', fn (Blueprint $t) => $t->bigInteger('data_owner_id')->nullable()->index());
        foreach (DB::table('bot_outbox')->orderBy('id')->cursor() as $row) {
            $p = json_decode($row->payload, true) ?? [];
            $uid = (int)($p['chat_id'] ?? 0);
            if ($uid <= 0) {
                $text = ($p['caption'] ?? $p['text'] ?? '');
                $uid = 0;
                if (preg_match('/^(?:Перевірка|Оскарження) #\d+\n[^\n]* \((\d+)\)\n/u', $text, $m)
                    || preg_match('/^(?:Квитанція|Запит на екстрену паузу) #[^\n]+\n(?:Статус: [^\n]+\n)?Користувач: (\d+)\n/u', $text, $m)) $uid = (int)$m[1];
            }
            if ($uid > 0) DB::table('bot_outbox')->where('id', $row->id)->update(['data_owner_id'=>$uid]);
        }
    }

    public function down(): void
    {
        Schema::table('bot_outbox', fn (Blueprint $t) => $t->dropColumn('data_owner_id'));
    }
};
