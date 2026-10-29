<?php
// SSH-only initial configuration. Never expose this script through the web server.
$root=__DIR__;
if (PHP_SAPI!=='cli') exit(1);
$path=$root.'/.env';
$env=file_get_contents($path);
$values=[
    'APP_NAME'=>'"Крок вперед"', 'APP_ENV'=>'production', 'APP_DEBUG'=>'false',
    'APP_URL'=>'https://stepahead.korporatsiia-mov.com', 'APP_LOCALE'=>'uk',
    'DB_CONNECTION'=>'sqlite', 'DB_DATABASE'=>$root.'/database/database.sqlite',
    'SESSION_DRIVER'=>'file', 'CACHE_STORE'=>'file', 'QUEUE_CONNECTION'=>'sync',
    'SESSION_SECURE_COOKIE'=>'true', 'SESSION_COOKIE'=>'stepahead_session',
    'TELEGRAM_WEBHOOK_SECRET'=>bin2hex(random_bytes(32)), 'BOT_SETUP_KEY'=>bin2hex(random_bytes(32)),
];
foreach ($values as $key=>$value) {
    if (in_array($key,['TELEGRAM_WEBHOOK_SECRET','BOT_SETUP_KEY']) && preg_match('/^'.$key.'=.+$/m',$env)) continue;
    $env=preg_match('/^'.$key.'=/m',$env) ? preg_replace('/^'.$key.'=.*$/m',$key.'='.$value,$env) : $env."\n{$key}={$value}\n";
}
file_put_contents($path,$env); chmod($path,0600);
if (!file_exists($values['DB_DATABASE'])) touch($values['DB_DATABASE']);
chmod($values['DB_DATABASE'],0600);
echo "Private application configuration prepared.\n";
