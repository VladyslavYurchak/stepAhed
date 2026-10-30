# Крок вперед 👣

Telegram accountability assistant: @krokvpered_bot. PHP 8.3 / Laravel 12.

Product rules, approved scope and unresolved questions: [docs/CONCEPT.md](docs/CONCEPT.md).

## Local setup

1. `composer install`
2. Copy `.env.example` to `.env`, then `php artisan key:generate`.
3. Configure `DB_CONNECTION=sqlite`, create `database/database.sqlite`.
4. Set `APP_URL`, `TELEGRAM_WEBHOOK_SECRET` and a random `BOT_SETUP_KEY` (each secret at least 32 random bytes hex).
5. `php artisan migrate` and `php artisan serve`.
6. For a public HTTPS deployment, visit `/setup/{BOT_SETUP_KEY}` once. Enter the token directly there, not in chat or Git. The page verifies the bot username, registers the secret-protected webhook, encrypts the token and disables further setup.
7. Copy the displayed `/bind ...` command into your private moderation group as its administrator. Only that Telegram user can moderate in the bound group.

Telegram requires a public HTTPS URL to deliver webhook updates; a plain localhost address is insufficient.

## Scheduler

Every minute: `/opt/alt/php83/usr/bin/php /home/u253935843/stepahead-app/artisan schedule:run`

`bot:tick` generates recurring occurrences, sends reminders, handles expired confirmation windows and drains the retryable outbox. Webhook responses also drain up to six outgoing messages. A large backlog may need extra `bot:tick` invocations.

## Personal data

Personal data controls: Settings → «🔐 Мої дані». Owner-only JSON export and confirmed deletion of live account records are implemented; Telegram messages and historical backups are not automatically deleted. See [docs/USER_DATA.md](docs/USER_DATA.md).

## Backups

Daily at 03:00 Europe/Kyiv, the existing scheduler creates a private SQLite snapshot and configuration backup. Manual: `php artisan bot:backup`. Isolated restore verification: `php artisan bot:backup-verify SNAPSHOT_ID`. See [docs/BACKUPS.md](docs/BACKUPS.md) for recovery steps and same-server limitations. Backups are retained without automatic deletion until a retention policy is agreed.

## Tests

`php vendor/bin/phpunit`

Tests use in-memory SQLite. Deadline boundaries, ownership, mandatory photos, deduplication, penalties, cancellation, moderation, schedule timezone and setup are covered.

## Operational notes

- Serve ONLY Laravel `public/`. Keep `.env`, SQLite, logs and source outside webroot. Production: `APP_DEBUG=false`.
- Credentials and user data must not be committed. Back up `.env` and the private database securely; the app key is required to decrypt the bot token.
- Pilot: no commercial payments or blocking. Voluntary direct charitable donations use administrator-approved monobank jars, with proposals, manual receipt moderation and immutable receipt snapshots. No bank settlement verification is implied. Admin UI is available only to the bound admin ID, in private chat or the bound moderation group. Reminder two hours before deadline. Single-occurrence editing and recurring schedule versions respect the three-hour cutoff. Emergency pauses: up to three days once per 30 days; repeat/extension moderated. Escalation resets after ten completed successful active days. Penalty cap, voice/AI, calendars and subscriptions remain unimplemented.
- Outgoing delivery is at least once: a crash after Telegram accepts a message and before marking it sent can repeat that notification. Task transitions and assessed penalties are transactional and idempotent.
- Moderation does not prove real-world exercise completion. Photos remain in Telegram; file identifiers and review status are stored privately.
- Public launch still needs privacy/deletion/export flows, retention, downtime reconciliation, quiet hours, load controls and finalized product rules.
