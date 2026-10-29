<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $t) {
            $t->unsignedInteger('success_streak')->default(0);
            $t->date('streak_cursor')->nullable();
        });
        Schema::create('bot_pauses', function (Blueprint $t) {
            $t->id(); $t->bigInteger('profile_id')->index();
            $t->dateTime('starts_at'); $t->dateTime('ends_at');
            $t->string('status')->index(); $t->boolean('automatic')->default(false);
            $t->string('reason', 300); $t->timestamps();
        });
        Schema::table('occurrences', function (Blueprint $t) {
            $t->unsignedBigInteger('pause_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('occurrences', fn (Blueprint $t) => $t->dropColumn('pause_id'));
        Schema::dropIfExists('bot_pauses');
        Schema::table('bot_profiles', fn (Blueprint $t) => $t->dropColumn(['success_streak', 'streak_cursor']));
    }
};
