<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('charity_funds', function (Blueprint $t) {
            $t->id(); $t->string('title',100); $t->string('recipient',140);
            $t->string('purpose',400); $t->string('url',250); $t->string('source',350)->nullable();
            $t->string('status')->index(); $t->unsignedInteger('sort_order')->default(0);
            $t->bigInteger('proposed_by')->nullable(); $t->bigInteger('reviewed_by')->nullable();
            $t->string('review_reason')->nullable(); $t->timestamps();
        });
        Schema::create('donations', function (Blueprint $t) {
            $t->id(); $t->foreignId('occurrence_id')->unique()->constrained();
            $t->bigInteger('profile_id')->index(); $t->foreignId('fund_id')->constrained('charity_funds');
            $t->text('fund_snapshot'); $t->unsignedInteger('expected_amount');
            $t->string('status')->default('awaiting')->index(); $t->timestamps();
        });
        Schema::create('donation_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('donation_id')->constrained(); $t->bigInteger('profile_id')->index();
            $t->text('fund_snapshot'); $t->unsignedInteger('expected_amount');
            $t->string('file_id'); $t->string('file_unique_id')->index();
            $t->string('status')->default('pending')->index(); $t->string('reason')->nullable();
            $t->bigInteger('reviewed_by')->nullable(); $t->dateTime('reviewed_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('donation_receipts'); Schema::dropIfExists('donations'); Schema::dropIfExists('charity_funds');
    }
};
