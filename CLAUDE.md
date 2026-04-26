# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

Despite the directory name `restaurant-system`, this is currently an **unmodified Laravel 12 skeleton**. No restaurant domain exists yet: `app/Models/` contains only `User.php`, `app/Http/Controllers/` has only the empty base `Controller.php`, `routes/web.php` serves a single `welcome` view, and migrations are just the default users/cache/jobs tables. When adding features, you are building the domain from scratch — do not go hunting for existing `Menu`, `Order`, `Reservation`, etc. models; they haven't been written.

The bundled `README.md` is the generic Laravel marketing README and contains no project-specific information.

## Stack

- **PHP 8.2+** with **Laravel 12** (see `composer.json`). Laravel 12 uses the streamlined `bootstrap/app.php` configuration style — there is no `app/Http/Kernel.php`, no `app/Console/Kernel.php`, and no `app/Exceptions/Handler.php`. Middleware, routing, and exception handling are all configured via the fluent builder in `bootstrap/app.php`. Console commands live in `routes/console.php`.
- **Vite 7** + **Tailwind CSS v4** (via `@tailwindcss/vite` plugin, not a PostCSS config) for frontend assets.
- **SQLite** by default — `database/database.sqlite` is committed-adjacent and created on setup. Tests run against `:memory:` SQLite (see `phpunit.xml`).
- **PHPUnit 11** for tests. Pest is *not* installed despite `pestphp/pest-plugin` being in `allow-plugins`.

## Commands

Install / bootstrap from a clean clone:

```bash
composer setup        # composer install, .env, key:generate, migrate, npm install, npm run build
```

Day-to-day development (runs server + queue worker + log tail + Vite concurrently via `concurrently`):

```bash
composer dev
```

Individual pieces when you don't want the full stack:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0    # real-time log tail
npm run dev                     # Vite dev server
npm run build                   # production asset build
```

Tests (the `composer test` script clears config before running, which matters because `phpunit.xml` injects a test-only env):

```bash
composer test                                          # full suite
php artisan test --filter=ExampleTest                  # single test class
php artisan test --filter='ExampleTest::test_that_true_is_true'   # single method
php artisan test tests/Feature/ExampleTest.php         # single file
php artisan test --testsuite=Unit                      # suite only
```

Database:

```bash
php artisan migrate
php artisan migrate:fresh --seed
php artisan make:migration create_foo_table
```

Formatting (Pint is in `require-dev` but not wired into a composer script):

```bash
vendor/bin/pint           # format
vendor/bin/pint --test    # check without writing
```

StyleCI is also configured (`.styleci.yml`, Laravel preset) for CI-side formatting.

## Testing notes

- `phpunit.xml` forces the test environment to in-memory SQLite, `sync` queue, `array` cache/session/mail, and `BCRYPT_ROUNDS=4`. Do not rely on the real `.env` values inside tests.
- Two suites are defined: `Unit` and `Feature`. Use `--testsuite=Unit|Feature` to scope.
- CI (`.github/workflows/tests.yml`) runs the suite against PHP 8.2, 8.3, and 8.4 on every push to `master` and on PRs — keep changes compatible across all three.

## Conventions worth knowing

- PSR-4 roots: `App\` → `app/`, `Database\Factories\` → `database/factories/`, `Database\Seeders\` → `database/seeders/`, `Tests\` → `tests/`.
- Health check endpoint is wired at `/up` via `bootstrap/app.php` (`health: '/up'`).
- When adding middleware, routes, or exception handlers, edit `bootstrap/app.php` — not files under `app/Http/` or `app/Exceptions/`.
