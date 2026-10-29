<?php

namespace App\Http\Controllers;

use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SetupController extends Controller
{
    private function authorizeKey(string $key, Telegram $tg): void
    {
        $expected=config('bot.setup_key');
        abort_unless($expected && hash_equals($expected,$key) && !$tg->setting('token'),404);
    }

    public function show(string $key, Telegram $tg)
    {
        $this->authorizeKey($key,$tg);
        return response()->view('setup')->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('X-Robots-Tag','noindex, nofollow');
    }

    public function save(string $key, Request $request, Telegram $tg)
    {
        $this->authorizeKey($key,$tg);
        $token=trim((string)$request->input('token'));
        if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{25,60}$/',$token)) return response()->view('setup',['error'=>'Токен має неправильний формат. Скопіюй його з BotFather.'],422);
        return Cache::lock('bot-setup',60)->block(2,function () use ($key,$token,$tg) {
            $this->authorizeKey($key,$tg);
            try {
                $me=$tg->call('getMe',[],$token);
                if (($me['username'] ?? '')!==config('bot.username')) return response()->view('setup',['error'=>'Це токен іншого бота. Потрібен @'.config('bot.username')],422);
                $tg->call('setWebhook',[
                    'url'=>rtrim(config('app.url'),'/').'/telegram/webhook',
                    'secret_token'=>config('bot.webhook_secret'),
                    'allowed_updates'=>['message','callback_query'], 'max_connections'=>1,
                ],$token);
                $bind=bin2hex(random_bytes(16));
                DB::transaction(function () use ($token,$bind) {
                    DB::table('bot_settings')->insert(['key'=>'token','value'=>Crypt::encryptString($token)]);
                    DB::table('bot_settings')->insert(['key'=>'bind_code','value'=>$bind]);
                });
                return response()->view('setup',['connected'=>true,'bind'=>$bind])->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
            } catch (\Throwable) {
                return response()->view('setup',['error'=>'Підключення не завершено. Перевір токен або спробуй пізніше. Токен не відображається в журналі помилок.'],422);
            }
        });
    }
}
