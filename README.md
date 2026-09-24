# BizFlow

BizFlow is a small-business management SaaS built with Laravel.

Planned modules: Authentication, Customers, Products / Services, Invoices, Expenses, Dashboard, Reports and a REST API.
Each user's business data is isolated by ownership (`user_id`) of the authenticated user.

> **Status:** Sprint 1 (project foundation). No business modules are implemented yet.

## Stack

- PHP 8.3+ and Laravel 12
- MySQL 8 (application database)
- Bootstrap 5 (via npm + Vite)
- PHPUnit (Laravel's standard test setup; tests run on in-memory SQLite)

## Requirements

- PHP 8.3+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`
- Composer 2
- Node.js 20+ and npm
- MySQL 8

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### Database

1. Create a MySQL database and a user for it (the example values are `bizflow` / `bizflow_user`).
2. Put the credentials in `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
   Never commit `.env`; `.env.example` must contain only placeholders.
3. Run the migrations:

```bash
php artisan migrate
```

Sessions and cache use the `file` driver, so the app can boot before the database is ready.
You can switch them to `database` after migrating if you prefer.

## Running

```bash
npm run build      # or `npm run dev` for hot reload
php artisan serve
```

- App: http://127.0.0.1:8000
- System status page (app + database check): http://127.0.0.1:8000/health
  Returns `200` when healthy and `503` when the database is unreachable.
- Laravel's built-in liveness endpoint: http://127.0.0.1:8000/up

## Testing

```bash
php artisan test
```

Tests use an in-memory SQLite database (see `phpunit.xml`), so they never touch the MySQL database.

## Development rules

See [CLAUDE.md](CLAUDE.md).
