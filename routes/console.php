<?php

use App\Services\Tasks;
use App\Services\Telegram;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bot:tick', function () {
    Cache::lock('bot-tick',120)->get(fn () => app(Tasks::class)->tick());
    app(Telegram::class)->flush();
})->purpose('Generate occurrences, check deadlines, send reminders and outbox');

// Shared Hostinger disables proc_open; run in-process instead of spawning PHP.
Schedule::call(fn () => Artisan::call('bot:tick'))->name('bot-tick')->everyMinute()->withoutOverlapping();
