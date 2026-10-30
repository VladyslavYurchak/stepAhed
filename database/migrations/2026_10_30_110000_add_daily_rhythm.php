<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $t) {
            $t->string('sleep_at',5)->nullable();
            $t->string('wake_at',5)->nullable();
            $t->string('morning_mode')->default('off');
            $t->string('morning_at',5)->nullable();
            $t->date('morning_last_date')->nullable();
            $t->dateTime('awake_until')->nullable();
        });
        Schema::create('bot_deferred_notifications', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('profile_id')->index();
            $t->foreignId('occurrence_id')->unique()->constrained();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_deferred_notifications');
        Schema::table('bot_profiles', fn (Blueprint $t) => $t->dropColumn(['sleep_at','wake_at','morning_mode','morning_at','morning_last_date','awake_until']));
    }
};
