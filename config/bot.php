<?php

return [
    'username' => 'krokvpered_bot',
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'setup_key' => env('BOT_SETUP_KEY'),
    'base_penalty' => 20,
    // Pilot: accounting only until payment rules are approved.
    'payments_enabled' => false,
];
