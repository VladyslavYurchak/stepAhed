<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class Telegram
{
    public function setting(string $key): ?string
    {
        return DB::table('bot_settings')->where('key', $key)->value('value');
    }

    public function call(string $method, array $payload = [], ?string $token = null): mixed
    {
        $encrypted = $this->setting('token');
        $token ??= $encrypted ? Crypt::decryptString($encrypted) : '';
        if (!$token) throw new \RuntimeException('Telegram token is not configured');
        try {
            $request = Http::connectTimeout(4)->timeout(12);
            if ($method === 'sendDocument' && isset($payload['document_json'])) {
                $request = $request->attach('document', $payload['document_json'], 'krok-vpered-data.json');
                unset($payload['document_json']);
            }
            $response = $request->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (\Throwable) {
            throw new \RuntimeException('Telegram network error');
        }
        $body = $response->json();
        if (str_starts_with($method, 'editMessage') && str_contains($body['description'] ?? '', 'message is not modified')) return true;
        if (!$response->successful() || !($body['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API error '.($body['error_code'] ?? $response->status()));
        }
        return $body['result'] ?? [];
    }

    public function enqueue(string $method, array $payload, ?int $dataOwner = null): void
    {
        DB::table('bot_outbox')->insert([
            'method' => $method, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'available_at' => now(),
            'data_owner_id' => $dataOwner ?? ((int)($payload['chat_id'] ?? 0) > 0 ? (int)$payload['chat_id'] : null),
        ]);
    }

    public function send(int|string $chat, string $text, array $rows = [], ?string $parseMode = null, ?int $dataOwner = null): void
    {
        $payload = ['chat_id' => $chat, 'text' => $text];
        if ($parseMode !== null) $payload['parse_mode'] = $parseMode;
        if ($rows) $payload['reply_markup'] = ['inline_keyboard' => array_map(
            fn ($row) => array_map(fn ($b) => ['text' => $b[0], 'callback_data' => $b[1]], $row), $rows
        )];
        elseif ((int) $chat > 0) {
            $payload['reply_markup'] = [
                'keyboard' => [[['text'=>'Повернутися в меню']]],
                'is_persistent' => true, 'resize_keyboard' => true, 'one_time_keyboard' => false,
                'input_field_placeholder' => 'Напиши відповідь або повернися в меню',
            ];
            DB::table('bot_settings')->updateOrInsert(['key'=>'navigation_keyboard_'.$chat],['value'=>'1']);
        }
        $this->enqueue('sendMessage', $payload, $dataOwner);
    }

    public function ensureNavigation(int $chat): void
    {
        if (!$this->setting('navigation_keyboard_'.$chat)) {
            $this->send($chat,'Кнопка «Повернутися в меню» завжди доступна під полем введення. Вона закриває поточне налаштування без зміни збережених задач.');
        }
    }

    public function edit(int|string $chat, int $messageId, string $text, array $rows = []): void
    {
        $this->enqueue('editMessageText', [
            'chat_id' => $chat, 'message_id' => $messageId, 'text' => $text,
            'reply_markup' => ['inline_keyboard' => array_map(
                fn ($row) => array_map(fn ($b) => ['text' => $b[0], 'callback_data' => $b[1]], $row), $rows
            )],
        ]);
    }

    public function flush(): void
    {
        Cache::lock('telegram-outbox', 120)->get(function () {
            foreach (DB::table('bot_outbox')->whereNull('sent_at')->where('available_at', '<=', now())->orderBy('id')->limit(6)->get() as $item) {
                try {
                    $this->call($item->method, json_decode($item->payload, true));
                    DB::table('bot_outbox')->where('id', $item->id)->update(['sent_at' => now(), 'last_error' => null]);
                } catch (\Throwable $e) {
                    DB::table('bot_outbox')->where('id', $item->id)->update([
                        'attempts' => $item->attempts + 1,
                        'available_at' => now()->addSeconds(min(3600, 30 * 2 ** min($item->attempts, 7))),
                        'last_error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
