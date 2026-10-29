<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config('app.url').'/setup/'.config('bot.setup_key').PHP_EOL;
