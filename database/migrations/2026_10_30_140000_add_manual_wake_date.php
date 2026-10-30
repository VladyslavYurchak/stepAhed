<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_profiles',fn (Blueprint $t) => $t->date('awake_last_date')->nullable());
    }

    public function down(): void
    {
        Schema::table('bot_profiles',fn (Blueprint $t) => $t->dropColumn('awake_last_date'));
    }
};
