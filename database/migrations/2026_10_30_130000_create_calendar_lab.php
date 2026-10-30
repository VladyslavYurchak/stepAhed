<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_lab_links', function (Blueprint $t) {
            $t->string('hash',64)->primary(); $t->bigInteger('profile_id')->index();
            $t->timestamp('expires_at');
        });
        Schema::create('calendar_lab_connections', function (Blueprint $t) {
            $t->bigInteger('profile_id')->primary();
            $t->text('access_token')->nullable(); $t->text('refresh_token')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->string('calendar_id')->nullable();
            $t->string('status')->default('connecting'); $t->string('error')->nullable();
            $t->timestamp('last_synced_at')->nullable(); $t->timestamps();
        });
        Schema::create('calendar_lab_tasks', function (Blueprint $t) {
            $t->id(); $t->bigInteger('profile_id')->index(); $t->string('title',180);
            $t->dateTime('due_at'); $t->string('status')->default('pending');
            $t->unsignedBigInteger('source_occurrence_id')->nullable();
            $t->string('google_event_id',1024)->nullable(); $t->text('google_etag')->nullable();
            $t->unsignedInteger('google_duration_seconds')->default(60);
            $t->string('synced_fingerprint',64)->nullable(); $t->timestamps();
            $t->unique(['profile_id','source_occurrence_id']);
            $t->unique(['profile_id','google_event_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('calendar_lab_tasks');
        Schema::dropIfExists('calendar_lab_connections');
        Schema::dropIfExists('calendar_lab_links');
    }
};
