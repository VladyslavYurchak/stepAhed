<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $t) {
            $t->string('key')->primary(); $t->text('value');
        });
        Schema::create('bot_profiles', function (Blueprint $t) {
            $t->bigInteger('id')->primary(); $t->string('name');
            $t->string('timezone')->default('Europe/Kyiv'); $t->boolean('onboarded')->default(false);
            $t->text('dialog')->nullable(); $t->unsignedInteger('violations')->default(0); $t->timestamps();
        });
        Schema::create('commitments', function (Blueprint $t) {
            $t->id(); $t->bigInteger('profile_id')->index(); $t->string('title', 180);
            $t->boolean('mandatory'); $t->boolean('photo_required')->default(false);
            $t->text('schedule'); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('occurrences', function (Blueprint $t) {
            $t->id(); $t->foreignId('commitment_id')->constrained();
            $t->bigInteger('profile_id')->index(); $t->dateTime('due_at')->index();
            $t->string('status')->default('pending')->index(); $t->unsignedInteger('penalty_amount')->default(0);
            $t->dateTime('reminded_at')->nullable(); $t->dateTime('deadline_notice_at')->nullable();
            $t->dateTime('cancel_started_at')->nullable(); $t->dateTime('submitted_at')->nullable();
            $t->string('photo_file_id')->nullable(); $t->string('photo_unique_id')->nullable()->index();
            $t->text('review_reason')->nullable(); $t->dateTime('reviewed_at')->nullable();
            $t->timestamps(); $t->unique(['commitment_id', 'due_at']);
        });
        Schema::create('bot_updates', function (Blueprint $t) {
            $t->bigInteger('id')->primary(); $t->timestamp('created_at');
        });
        Schema::create('bot_outbox', function (Blueprint $t) {
            $t->id(); $t->string('method'); $t->text('payload');
            $t->unsignedInteger('attempts')->default(0); $t->dateTime('available_at');
            $t->dateTime('sent_at')->nullable(); $t->string('last_error')->nullable();
        });
    }
    public function down(): void
    {
        foreach (['bot_outbox', 'bot_updates', 'occurrences', 'commitments', 'bot_profiles', 'bot_settings'] as $table) Schema::dropIfExists($table);
    }
};
