<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bot_calendar_subscriptions', function (Blueprint $t) {
            $t->bigInteger('profile_id')->primary();
            $t->string('token_hash',64)->unique();
            $t->text('token_encrypted');
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('bot_calendar_subscriptions'); }
};
