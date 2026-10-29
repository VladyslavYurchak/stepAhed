<?php

use App\Http\Controllers\SetupController;
use App\Services\Bot;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home'));
Route::get('/setup/{key}', [SetupController::class, 'show'])->name('setup');
Route::post('/setup/{key}', [SetupController::class, 'save'])->middleware('throttle:6,1');
Route::post('/telegram/webhook', function (Request $request, Bot $bot, Telegram $telegram) {
    $secret = config('bot.webhook_secret');
    abort_unless($secret && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
    $data = $request->validate(['update_id' => 'required|integer', 'message' => 'sometimes|array', 'callback_query' => 'sometimes|array']);
    $bot->update($data);
    $telegram->flush();
    return response()->json(['ok' => true]);
})->middleware('throttle:240,1');
