# BizFlow: Development Rules

BizFlow is a Laravel 12 small-business management SaaS (PHP 8.3+, MySQL 8, Bootstrap 5, PHPUnit).
Planned modules: Authentication, Customers, Products / Services, Invoices, Expenses, Dashboard, Reports, REST API.

## Current status

- All MVP modules are complete: Authentication, Customers, Products / Services, Invoices,
  Expenses, Dashboard and Reports.
- Final MVP polish is being completed; the current stage is final MVP verification.
- Do not start new modules or deferred scope (see README "Known limitations and future scope")
  unless a task explicitly asks for it.

## Data isolation (critical)

- Every business record belongs to a user through a `user_id` foreign key.
- **Never trust `user_id` from request input.** Set it from the authenticated user,
  e.g. `$request->user()->customers()->create($validated)`.
- Always scope queries to the authenticated user, preferably through relationships
  (`$request->user()->invoices()`), never `Model::all()` / unscoped `Model::find()` for business data.
- Authorize access to individual records with Policies. Another user's record must never be readable or writable.
- Add feature tests proving that one user cannot see or change another user's records.

## Conventions

- Follow Laravel conventions (resource controllers, route names, migrations, factories, seeders).
- Validate with Form Requests, not inline `$request->validate()` in controllers.
- Authorize with Policies (`$this->authorize()`, `can` middleware, or `Gate`).
- Prefer Eloquent models and relationships; avoid unnecessary raw SQL.
- Views use Blade + Bootstrap 5 and extend `layouts.app`.
- Don't add Composer/npm dependencies without a stated justification.

## Scope discipline

- Before implementing a feature, inspect the existing architecture and follow it.
- Keep changes focused; don't modify unrelated files.
- Don't remove existing functionality without explicit approval.
- Don't implement future modules early.

## Environment and secrets

- Configuration lives in `.env`, which is git-ignored and must never be committed.
- `.env.example` holds only non-sensitive placeholders.
- Never hardcode credentials in source code.

## Testing

- Run `php artisan test` after changes; all tests must pass.
- Tests use in-memory SQLite (`phpunit.xml`); use `RefreshDatabase` in tests that need tables.
- Frontend assets: `npm run build` must succeed after changes to `resources/css` or `resources/js`.
