# Restaurant System

A single-restaurant POS for small Kenyan restaurant operators. Custom-built (not SaaS), web-based, runs on the restaurant's own hardware.

Handles the floor-to-till flow end-to-end: waiters open customer tabs, kitchen advances orders through cooking states, payment via cash or **real M-Pesa STK push**, automatic recipe-driven inventory deduction, daily reports, expense tracking, and ESC/POS thermal receipt printing.

## Roles

Each role lands on its own dashboard at login. Cross-navigation is available where it makes sense.

| Role | Lands at | Can do |
|---|---|---|
| **admin** | `/admin/dashboard` | everything; staff CRUD; full reports + exports; reaches every other dashboard |
| **manager** | `/manager/dashboard` | reports (P&L), expenses, menu CRUD, inventory restocking, paid-session history, low-stock warnings, read-only staff view (admins ghosted) |
| **waiter** | `/waiter/dashboard` | open sessions, take orders, deliver, collect payment (cash, M-Pesa STK, manual code), today's totals + tab list, print receipts |
| **kitchen** | `/kitchen/dashboard` | live order queue (pending → preparing → ready), history of last 50 cooked |

## Stack

- **Laravel 12** + Sanctum (PHP 8.2+)
- **Alpine.js + Tailwind** via Vite — no React/Vue, ~no build complexity
- **MySQL** in dev/prod; **in-memory SQLite** for tests
- **Daraja API** for M-Pesa STK push (real, with callback)
- **Meta WhatsApp Cloud API** for the daily P&L summary
- **Node.js bridge** + `node-thermal-printer` for ESC/POS receipt printing

## Quick start (dev)

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# edit .env: set DB_CONNECTION=mysql, DB_DATABASE=restaurant_management, etc.
php artisan migrate --seed
composer dev    # server + queue + vite, all in one
```

Then open <http://localhost:8000> and log in as the seeded admin (see [DatabaseSeeder.php](database/seeders/DatabaseSeeder.php) for the demo accounts — change them before going anywhere near production).

## Common commands

```bash
composer dev                # server + queue + vite (Windows-friendly)
composer dev:full           # adds pail (Linux/macOS only — needs pcntl)
composer test               # 110 feature tests, ~8s

php artisan migrate                  # apply pending migrations
php artisan optimize:clear           # after schema/route/config changes in dev
php artisan schedule:work            # local scheduler — needed for stuck-STK + WhatsApp
php artisan mpesa:expire-stuck-stk   # one-shot: fail pending STKs older than 5min
php artisan summary:send-daily       # one-shot: send today's WhatsApp summary
```

## Tests

Feature-test heavy: 110 tests, 331 assertions, ~8s. Covers auth, role middleware, session lifecycle, inventory deduction including the negative-stock guard, payment guards (cash + STK + callback), admin protection (self + primary admin), expenses, reports, M-Pesa service + callback, WhatsApp service + daily summary, print job queue + bridge endpoints, and CSV export.

```bash
composer test
# or to run a single file:
php artisan test --filter=MpesaTest
```

## M-Pesa setup

Daraja sandbox works out of the box with the defaults in [config/mpesa.php](config/mpesa.php). For production you need real consumer key / secret / shortcode / passkey from <https://developer.safaricom.co.ke>. Callback URL must be **publicly reachable HTTPS** — for local dev: `ngrok http 8000`.

See [DEPLOYMENT.md](DEPLOYMENT.md) section 1a for the production approval workflow.

## WhatsApp daily summary

Set the env vars in [config/whatsapp.php](config/whatsapp.php), submit a 6-variable utility template for approval at <https://business.facebook.com>, then schedule fires automatically at 23:59 server-local. Disabled by default (`WHATSAPP_ENABLED=false`).

## Receipt printing

Two-process: PHP queues a `print_jobs` row with the receipt payload as JSON, the Node bridge in [bridge/](bridge/) polls and forwards ESC/POS bytes to the printer. See [bridge/README.md](bridge/README.md) for hardware setup.

## Going to production

[DEPLOYMENT.md](DEPLOYMENT.md) is the step-by-step recipe — server provisioning, nginx + HTTPS, M-Pesa production switch, WhatsApp template approval, cron, backups, smoke testing, rollback. Written for Ubuntu 24.04 + nginx + php-fpm + MySQL on any cheap VPS.

The two slow external dependencies (Daraja approval ~5-10 business days, WhatsApp template review ~1-3 days) are gating, so start them on day 1 of the deployment timeline.

## Working in this codebase

See [CLAUDE.md](CLAUDE.md) — it's the briefing loaded into every Claude Code session, but the conventions section (service classes, role middleware wildcard for admin, the date-cast and JSON-roundtrip traps in tests) is useful reading for any contributor.

Standing rules:

1. **Always explain *why* a change was made**, not just *what* changed — the diff already shows what.
2. **Flag bugs you spot outside the request scope** rather than silently fixing them.
3. **Never silently skip a failing test** — surface it; no `markTestSkipped` to hide failures.
4. **Suggest the simpler solution first** — add complexity only when it earns its keep.
