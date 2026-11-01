<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_profiles',function (Blueprint $t) {
            $t->boolean('evening_enabled')->default(true);
            $t->date('evening_last_date')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('bot_profiles',fn (Blueprint $t) => $t->dropColumn(['evening_enabled','evening_last_date']));
    }
};
