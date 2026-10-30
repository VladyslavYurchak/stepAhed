<?php

use App\Http\Controllers\SetupController;
use App\Http\Controllers\CalendarLabController;
use App\Services\Bot;
use App\Services\Telegram;
use App\Services\Calendars;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home'));
Route::get('/calendar/apple/{token}', function (string $token) {
    $subscription=\Illuminate\Support\Facades\DB::table('bot_calendar_subscriptions')
        ->join('bot_profiles','bot_profiles.id','=','bot_calendar_subscriptions.profile_id')
        ->where('token_hash',hash('sha256',$token))->exists();
    abort_unless($subscription,404);
    $feed=rtrim(config('app.url'),'/').'/calendar/feed/'.$token.'.ics?v=2';
    return response()->view('calendar-apple',['webcal'=>preg_replace('~^https?://~','webcal://',$feed)])->withHeaders([
        'Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow',
        'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
    ]);
})->where('token','[a-f0-9]{64}')->middleware('throttle:30,1');
Route::get('/calendar-lab/connect/{token}',[CalendarLabController::class,'show'])->where('token','[a-f0-9]{64}')->middleware('throttle:20,1');
Route::post('/calendar-lab/connect/{token}',[CalendarLabController::class,'start'])->where('token','[a-f0-9]{64}')->middleware('throttle:10,1');
Route::get('/calendar-lab/callback',[CalendarLabController::class,'callback'])->middleware('throttle:20,1');
Route::get('/calendar/feed/{token}.ics', function (string $token, Calendars $calendars) {
    $feed=$calendars->feed($token);
    abort_if($feed===null,404);
    return response($feed)->withHeaders([
        'Content-Type'=>'text/calendar; charset=utf-8',
        'Content-Disposition'=>'inline; filename="krok-vpered.ics"',
        'Cache-Control'=>'private, no-store, max-age=0',
        'Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow',
        'X-Content-Type-Options'=>'nosniff',
    ]);
})->where('token','[a-f0-9]{64}')->middleware('throttle:120,1');
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
