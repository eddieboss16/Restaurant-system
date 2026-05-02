# Changelog

All notable changes to the restaurant POS, since the initial Laravel 12 skeleton. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

Everything below is on the `master` branch awaiting first deploy. Tag as `v1.0.0` when the live launch happens.

### Added

#### Domain

- Customer-session lifecycle (`open → ordered → served → paid`) with free-text customer labels.
- Recipe-driven inventory: orders auto-deduct ingredient quantities from `resources` inside a row-locked transaction. Orders are rejected (422) with the exact shortage when stock is insufficient.
- Cancellation logging with mandatory 5-char reason; cancelled orders are blocked after delivery.
- Four roles with separate dashboards: **admin**, **manager**, **waiter**, **kitchen**. Admin is a wildcard that passes every `role:` middleware.
- Primary-admin (lowest-id admin) protection: secondary admins cannot demote or deactivate the owner.

#### Floor & kitchen

- Waiter dashboard: open sessions, add items, mark delivered, collect payment (cash, M-Pesa STK, manual M-Pesa code).
- Waiter "Today" header strip + expandable list of paid sessions for the day.
- Kitchen queue with one-click status flips (`pending → preparing → ready`), 8-second auto-refresh.
- Kitchen history view: last 50 delivered orders.
- Manual "Print receipt" button on each paid session row.

#### Manager dashboard

- P&L reports tab with Today / This-month toggle, last-period delta, payment-method split, top items.
- Expense ledger with categories (`supplies`, `salaries`, `utilities`, `rent`, `transport`, `other`), CRUD with manager-edits-own / admin-edits-any / admin-only-deletes.
- Menu CRUD (was admin-only before).
- Inventory restocking with reason-logged transactions (was admin-only before).
- Read-only Staff view with admins ghosted (managers see floor + kitchen, never admins).
- Paid sessions history (latest 100) with date filtering.
- Low-stock warning banner.

#### Admin dashboard

- Same Reports tab as manager, plus full Staff CRUD (hire, deactivate, demote, reset password).
- Online-now indicator on each staff row, derived from Sanctum `last_used_at`.
- Cancellation log viewer (last 100).

#### M-Pesa STK push (real Daraja integration)

- `POST /api/sessions/{id}/payment/stk` triggers an STK push to the customer's phone.
- Public callback at `POST /api/mpesa/callback` (no auth) marks the payment completed and closes the session. Idempotent against retries.
- Manual code entry remains as a fallback when STK fails or the customer paid via paybill outside the app.
- Stuck-STK auto-cleanup: pending STK pushes are auto-failed after 5 minutes via `php artisan mpesa:expire-stuck-stk` (scheduled every minute).

#### WhatsApp daily summary (Meta Cloud API)

- `php artisan summary:send-daily` sends today's P&L snapshot to the owner's WhatsApp at 23:59 server-local. Disabled by default (`WHATSAPP_ENABLED=false`).
- Supports plain-text (24h-window only) or pre-approved templates with 6 body variables.

#### Receipt printing (ESC/POS via Node bridge)

- PHP enqueues `print_jobs` rows with the receipt as JSON; Node bridge in [`bridge/`](bridge/) polls and forwards ESC/POS to the printer.
- Bridge auth via shared `X-Bridge-Token` header (constant-time `hash_equals`).
- **Auto-print on payment**: cash, manual M-Pesa code, and STK callback success all auto-queue a receipt. Toggleable via `PRINT_AUTO_QUEUE`.
- Stuck-printing-job sweep: jobs in `printing` status for >2 minutes are reset to `pending` so the next bridge cycle picks them up. Scheduled every minute.

#### Reports + exports

- Today + this-month snapshots: revenue (current + previous), expenses, net, sessions paid, payment-method split, top items, expenses-by-category, cancellation count.
- CSV export endpoints for paid sessions and expenses, manager + admin, with date-range query params. Streamed via `streamDownload()` so large exports don't OOM.

#### Documentation

- [README.md](README.md): project description, quick start, links to other docs.
- [CLAUDE.md](CLAUDE.md): briefing loaded into every Claude Code session — stack, conventions, known traps.
- [DEPLOYMENT.md](DEPLOYMENT.md): step-by-step path to production (Daraja, WhatsApp, server provisioning, nginx + HTTPS, cron, backups, smoke testing, rollback).
- [bridge/README.md](bridge/README.md): printer bridge setup and hardware notes.

#### Tests

- 116 feature tests, 345 assertions, runs in ~8 seconds against in-memory SQLite.
- `SeedsRestaurantData` trait + `User::factory()->{role}()` state methods for fixture setup.

### Changed

- Reports response shape unified to `{ period, label, revenue.{current,previous}, ... }` so daily and monthly views render from one template.
- Manager elevated in `CustomerSessionPolicy` to act on any non-paid session (not just their own).
- Login redirect routes each role to its own dashboard instead of dumping everyone on `/waiter/dashboard`.
- Date columns cast as `'date:Y-m-d'` (not just `'date'`) so SQLite tests and MySQL prod produce identical strings for date-range comparisons.

### Fixed

- `AuthController::logout` no longer 500s for session-authenticated callers (`TransientToken` has no `delete()`).
- Inventory deduction holds a `lockForUpdate()` row lock so concurrent orders can't both pass the stock check and push inventory negative.
- `Route::view(...)->middleware(...)` ordering — chaining `->view()` after `Route::middleware()` is invalid in Laravel 12.
- Cron-required tasks (stuck-STK cleanup, daily summary, stuck-print sweep) now register correctly via `Schedule::command()` in `routes/console.php`.

### Removed

- `users.pin` column — was seeded for waiters but never used by any flow. Reversible migration; rebuild as a deliberate shift-switcher feature if the use case comes back.
- Stock Laravel marketing README and CHANGELOG (this file).

[Unreleased]: https://example.com/restaurant-system/compare/initial...HEAD
