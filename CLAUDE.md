# BizFlow: Development Rules

BizFlow is a Laravel 12 small-business management SaaS (PHP 8.3+, MySQL 8, Bootstrap 5, PHPUnit).
Planned modules: Authentication, Customers, Products / Services, Invoices, Expenses, Dashboard, Reports, REST API.

## Current status

- All MVP modules are complete: Authentication, Customers, Products / Services, Invoices,
  Expenses, Dashboard and Reports.
- Phase 2 so far: invoice PDF download; Phase 2A, the business tenancy foundation with a
  business profile; Phase 2B, emailing invoices (queued, with send history). Next approved
  phases: 2C Recurring Invoices, 2D Commercial SaaS.
- Do not start new modules or deferred scope (see README "Known limitations and future scope")
  unless a task explicitly asks for it.

## Data isolation (critical)

- **The business is the tenant boundary.** Every business record (customers, products, invoices,
  expenses) belongs to a Business through a required `business_id` foreign key. Invoice items
  belong to their invoice. Users belong to businesses through `business_user` memberships.
- **Ownership:** `business_id` alone determines ownership. `created_by` (invoices, expenses) is
  audit metadata only: `created_by = NULL` means the record was system-generated or has no
  associated person, and it must never be read as tenant ownership. Never use `created_by` for
  authorization or tenant filtering.
- **Current business:** HTTP code (controllers, form requests, policies) gets it only from
  `App\Support\CurrentBusiness`. Services, jobs and commands receive a `Business` explicitly and
  never resolve it themselves.
- **Queries:** every query on business data starts from the business
  (`$business->invoices()`), never `Model::query()`, `Model::all()` or an unscoped
  `Model::find()`. No global scopes or automatic tenant filtering. Users have no direct
  customer/product/invoice/expense relationships; don't add them back.
- **Never trust a request-supplied `business_id` or `created_by`.** Neither is fillable or
  validated. Set ownership through the relationship, e.g.
  `$business->customers()->create($validated)`.
- **Policies** enforce membership of the current business plus record ownership, and return 404
  for another business's records.
- **Form Requests:** every `exists` / `unique` rule on business data is scoped by the current
  business's `business_id`. Services re-check that referenced customers and products belong to
  the business.
- **Invoice numbering** is per business: the generator locks the business row, and the unique
  `(business_id, invoice_number)` / `(business_id, invoice_sequence)` indexes back it up.
- **Tests:** every module includes cross-business isolation tests (list, view, update, delete,
  forged IDs and forged `business_id`).
- **Records owned through a parent** (invoice items, invoice emails) have no `business_id`; reach
  them only through an already-authorized parent (`$invoice->emails()`). Audit columns such as
  `invoice_emails.requested_by` follow the `created_by` rule: `NULL` means system-generated, never
  ownership.
- **Migrations that change tenancy or other schema destructively** are rehearsed on a scratch
  database with a fresh backup first; restoring the backup is the recovery of record, not
  `migrate:rollback`.

## Queued work

- Use Laravel's database queue; no Redis/Horizon. Jobs carry only IDs, re-read and re-check state
  when they run, and implement `ShouldQueueAfterCommit` when dispatched inside a transaction.
- Every side effect has a tracking row whose state changes are conditional updates
  (`where status = ...`), so a retried or duplicated job can never repeat the side effect.
- Retry only failures that may be temporary; when unsure, retry rather than fail.

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
