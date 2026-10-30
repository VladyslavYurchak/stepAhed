<?php

use App\Services\Tasks;
use App\Services\Backups;
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

Artisan::command('bot:backup', function () {
    $this->info('Verified snapshot: '.app(Backups::class)->create());
})->purpose('Create a private consistent SQLite and configuration backup');

Artisan::command('bot:backup-verify {snapshot}', function () {
    $result = app(Backups::class)->verify($this->argument('snapshot'));
    $this->info(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Restore a snapshot into an isolated copy and verify integrity and encryption');

Schedule::call(fn () => Artisan::call('bot:backup'))->name('bot-backup')
    ->dailyAt('03:00')->timezone('Europe/Kyiv')->withoutOverlapping();

Artisan::command('bot:backup-prune', function () {
    $this->info('Expired snapshots removed: '.app(Backups::class)->prune());
})->purpose('Delete private snapshots older than fourteen days');

Schedule::call(fn () => Artisan::call('bot:backup-prune'))->name('bot-backup-prune')
    ->hourly()->withoutOverlapping();
