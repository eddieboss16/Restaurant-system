# Restaurant System

Single-restaurant POS targeting Kenyan small-restaurant operators. Custom-built (not SaaS), web-based, intended to run on the restaurant's own hardware.

## Stack

- **Laravel 12** (PHP 8.2+), Sanctum for API tokens
- **Alpine.js + Tailwind** via Vite for the dashboards (no React/Vue)
- **MySQL** in dev/prod (DB_CONNECTION=mysql, DB_DATABASE=restaurant_management). Tests use **in-memory SQLite** (phpunit.xml).
- **Daraja API** integration for M-Pesa STK push
- Windows is the primary dev environment — beware Unix-only PHP extensions like pcntl (Pail won't run; `composer dev` was edited to drop it; `composer dev:full` is the Linux/macOS variant).

## Roles and dashboards

Four roles, each lands on its own dashboard at login. Admin is a wildcard — passes every `role:` middleware via [EnsureRole](app/Http/Middleware/EnsureRole.php).

| Role | Lands at | Can do |
|---|---|---|
| `admin` | `/admin/dashboard` | everything; staff/menu/inventory CRUD; reports; reaches every other dashboard via cross-nav |
| `manager` | `/manager/dashboard` | reports (P&L), expense ledger CRUD, low-stock warnings; can also reach floor + kitchen views |
| `waiter` | `/waiter/dashboard` | open sessions, add orders, deliver, collect payment (cash + STK + manual mpesa code); sees own day's totals |
| `kitchen` | `/kitchen/dashboard` | queue (pending → preparing → ready), history of last 50 delivered |

Primary admin (lowest-id user with role=admin) is protected — other admins cannot demote or deactivate them.

## Domain flow

A customer = a `customer_session`. Lifecycle: `open → ordered → served → paid`.

1. Waiter opens a session (free-text customer label like "lady in red")
2. Adds menu items → orders are `pending`. [InventoryService](app/Services/InventoryService.php) deducts recipe ingredients from `resources` inside a `lockForUpdate` transaction. Order is rejected (422) with a specific message if any ingredient is short.
3. Kitchen flips status: `pending → preparing → ready`
4. Waiter marks `delivered`. When all orders are delivered or cancelled, the session flips to `served`.
5. Payment: cash (instant), manual mpesa code (instant), or STK push (async — see below). Session flips to `paid`, disappears from active lists.

Cancellations require a 5-char reason and are logged to `cancellation_logs`.

## M-Pesa STK push

Real Daraja integration. Setup needed in `.env`:
```
MPESA_ENV=sandbox|production
MPESA_CONSUMER_KEY=...
MPESA_CONSUMER_SECRET=...
MPESA_SHORTCODE=174379               # 174379 = Safaricom sandbox paybill
MPESA_PASSKEY=...                    # sandbox default in config/mpesa.php
MPESA_CALLBACK_URL=https://.../api/mpesa/callback
```

Callback URL must be publicly reachable. For local dev: `ngrok http 8000`. The route `/api/mpesa/callback` is public (no auth) — Daraja calls it directly.

Stuck STK pushes (no callback received) are auto-failed after 5 minutes by `php artisan mpesa:expire-stuck-stk` (scheduled every minute in `routes/console.php`).

## Conventions

- **Service classes** for non-trivial domain logic: [InventoryService](app/Services/InventoryService.php), [SessionService](app/Services/SessionService.php), [ReportService](app/Services/ReportService.php), [MpesaService](app/Services/MpesaService.php). Controllers stay thin.
- **Policies** for session-level auth: [CustomerSessionPolicy](app/Policies/CustomerSessionPolicy.php). Manager and admin both pass elevated checks (act on any non-paid session). Waiter only acts on their own.
- **Role middleware** always lists the explicit allowed roles; admin wildcards via [EnsureRole](app/Http/Middleware/EnsureRole.php). Reports/expenses/low-stock live in the `role:manager` group (admin still passes).
- **Reports return a unified shape** (`period`, `label`, `revenue.{current,previous}`, `expenses.{current,previous}`, `net`, `by_method`, `top_items`, `cancellations`, `expenses_by_category`) so daily and monthly views share one template. See [ReportService](app/Services/ReportService.php).
- **Tests use [RefreshDatabase](https://laravel.com/docs/12.x/database-testing#resetting-the-database-after-each-test) + a `SeedsRestaurantData` trait** in [tests/Concerns/](tests/Concerns/). Pin time via `Carbon::setTestNow()` for date-sensitive tests.
- **JSON roundtrip turns integer-valued floats into `int`** — use `assertEquals` (loose) not `assertSame` for numeric values pulled from `.json()` calls in tests.
- **Date columns** must be cast as `'date:Y-m-d'` not just `'date'` — SQLite stores TEXT and the default cast preserves the time portion, breaking date-range string comparisons.

## Common commands

```bash
composer setup                   # first-time install + migrate + npm install + build
composer dev                     # server + queue + vite (windows-friendly, no pail)
composer dev:full                # adds pail (Linux/macOS only -- needs pcntl)
composer test                    # config:clear + phpunit (~22s for 86 tests)
php artisan migrate              # apply pending migrations
php artisan optimize:clear       # clear cached config/routes/views (run after schema or route changes)
php artisan schedule:work        # local scheduler -- needed for stuck-STK cleanup
```

## Working on this codebase

- **Default to one commit per logical change.** Mixed commits (feature + unrelated cleanup) are hard to revert.
- **Flag bugs you spot outside the request scope rather than silently fixing them.** Drive-by fixes balloon the change surface.
- **Suggest the simpler solution first.** Add complexity only when it earns its keep.
- **Never silently skip a failing test.** Surface it. No `markTestSkipped` / `xit` / `--no-verify` to hide failures.
- **Explain *why* in commits, not just what** — the diff already shows what.

## Known gaps (deliberate non-scope, not bugs)

- No receipt printing (Bluetooth ESC/POS would close this).
- No daily WhatsApp/SMS summary to the owner.
- No paid-session history view (sessions disappear once `paid`).
- Single-tenant only — no `restaurant_id` scoping on models.
