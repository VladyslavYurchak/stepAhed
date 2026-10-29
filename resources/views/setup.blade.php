<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Підключення бота — Крок вперед</title><style>body{font-family:system-ui;background:#f3f1e9;color:#193b30;margin:0;padding:24px}main{max-width:540px;margin:8vh auto;background:#fff;border-radius:24px;padding:32px}h1{font-size:28px}p{line-height:1.7}input{box-sizing:border-box;width:100%;font:inherit;padding:14px;border:1px solid #acbda9;border-radius:10px}button{background:#193b30;color:white;border:0;border-radius:10px;padding:15px 20px;font:inherit;margin-top:15px;cursor:pointer}.error{color:#a32b2b}code{display:block;overflow-wrap:anywhere;background:#edf2e7;padding:16px;border-radius:10px}</style></head><body><main>
@if($connected ?? false)
<h1>Бота підключено ✅</h1><p>У групі «Крок вперед — модерація» надішли від свого акаунта цю команду. Ти маєш бути адміністратором групи.</p><code>/bind {{ $bind }}</code><p>Після повідомлення про успішне підключення напиши боту <b>/start</b> в особистому чаті.</p><p>Сторінка підключення вже закрита для повторного використання. Збережи команду до прив’язки групи.</p>
@else
<h1>Підключити @krokvpered_bot</h1><p>Встав токен, який видав BotFather. Він буде збережений на сервері в зашифрованому вигляді. Після підключення ця сторінка закриється.</p>
@if(isset($error))<p class="error">{{ $error }}</p>@endif
<form method="post" autocomplete="off">@csrf<label for="token">Токен бота</label><input id="token" name="token" type="password" required autocomplete="new-password" maxlength="90" spellcheck="false"><button type="submit">Підключити бота</button></form>
@endif
</main></body></html>
