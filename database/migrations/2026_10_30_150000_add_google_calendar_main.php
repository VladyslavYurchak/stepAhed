<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_lab_connections',fn (Blueprint $t) => $t->string('sync_mode')->default('lab'));
        Schema::create('google_calendar_events',function (Blueprint $t) {
            $t->id(); $t->bigInteger('profile_id')->index(); $t->unsignedBigInteger('occurrence_id');
            $t->string('event_id'); $t->string('fingerprint',64)->nullable();
            $t->unique(['profile_id','occurrence_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('google_calendar_events');
        Schema::table('calendar_lab_connections',fn (Blueprint $t) => $t->dropColumn('sync_mode'));
    }
};
