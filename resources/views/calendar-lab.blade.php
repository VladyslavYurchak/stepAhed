<!doctype html>
<html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Тест календаря · Крок вперед</title>
<style>body{margin:0;padding:32px 20px;background:#f4f2f9;color:#29203d;font:17px/1.65 system-ui,sans-serif}main{max-width:510px;margin:6vh auto;padding:32px;background:#fff;border-radius:24px;box-shadow:0 12px 50px #38255e0d}small{color:#76698c}h1{font-size:30px;line-height:1.2}a,button{display:inline-block;padding:14px 20px;border:0;border-radius:12px;background:#7452b8;color:#fff;text-decoration:none;font:inherit;cursor:pointer}.note{font-size:14px;color:#76698c}</style></head>
<body><main>@php($main = (bool) config('calendar_lab.main_enabled'))<small>Крок вперед 🌱{{ $main ? '' : ' · Тест лише для власника' }}</small>
@if($state === 'intro')
@if($main)
<h1>Твої плани поруч 🗓</h1><p>Підключи Google — я створю окремий календар <b>«Крок вперед 🌱»</b>.</p>
<p>Твої задачі, дедлайни та виконання з бота з’являтимуться в ньому автоматично. Додавай, змінюй і підтверджуй задачі в боті. Зміни в Google не змінюють задачі та штрафи.</p>
@else
<h1>Твої плани поруч 🗓</h1><p>Підключи Google — я створю окремий календар <b>«Крок вперед · ТЕСТ 🧪»</b>.</p>
<p>Тестові задачі з бота з’являться в ньому, а події з цього календаря — у тестовому розділі бота. Основні плани й штрафи залишаться без змін.</p>
@endif
<p>Доступ потрібен лише до календарів, створених цим застосунком. Інші твої календарі я не читаю.</p>
<form method="post" action="{{ url('/calendar-lab/connect/'.$token) }}">@csrf<button type="submit">Продовжити з Google</button></form>
<p class="note">Обери свій Google-акаунт і підтвердь дозвіл. Якщо Google відмовляється відкриватися всередині Telegram, скористайся Safari або іншим звичайним браузером.</p>
@elseif($state === 'success')
@if($main)
<h1>Підключено 💜</h1><p>Твої задачі з бота з’являтимуться в календарі «Крок вперед 🌱». Оновлення перевіряються щохвилини.</p><p>На Android відкрий Google Календар із цим самим акаунтом. Додавай, змінюй і підтверджуй задачі в боті.</p><a href="https://t.me/krokvpered_bot">Повернутися до бота</a>
@else
<h1>Підключено 💜</h1><p>Окремий тестовий календар готовий. Повернися до бота та відкрий «🧪 Тест Google Calendar».</p><p>Додай тестову задачу або подію в календарі «Крок вперед · ТЕСТ 🧪». Оновлення перевіряються щохвилини.</p><a href="https://t.me/krokvpered_bot">Повернутися до бота</a>
@endif
@elseif($state === 'cancelled')
<h1>Підключення скасоване</h1><p>Дозвіл не отримано. Коли захочеш спробувати — відкрий «Мої календарі» в налаштуваннях бота.</p><a href="https://t.me/krokvpered_bot">До бота</a>
@elseif($state === 'expired')
<h1>Посилання вже не діє</h1><p>Відкрий календарі в боті та отримай нове посилання підключення. Не передавай його іншим людям.</p><a href="https://t.me/krokvpered_bot">До бота</a>
@else
<h1>Ще не підключилися</h1><p>Google-підключення не завершилося. Повернися до календарів у боті. Якщо календар уже з’явився в Google, повідом власника перед повторною спробою.</p><a href="https://t.me/krokvpered_bot">До бота</a>
@endif
</main></body></html>
