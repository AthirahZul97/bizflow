# BizFlow: Development Rules

BizFlow is a Laravel 12 small-business management SaaS (PHP 8.3+, MySQL 8, Bootstrap 5, PHPUnit).
Planned modules: Authentication, Customers, Products / Services, Invoices, Expenses, Dashboard, Reports, REST API.

## Current status

- All MVP modules are complete: Authentication, Customers, Products / Services, Invoices,
  Expenses, Dashboard and Reports.
- Phase 2 so far: invoice PDF download; Phase 2A, the business tenancy foundation with a
  business profile; Phase 2B, emailing invoices (queued, with send history); Phase 2C, recurring
  invoices (scheduled generation of draft invoices); Phase 2D, the SaaS commercial foundation
  (plans, subscriptions, trial, entitlements, read-only enforcement; no payment provider).
- **Phase 2D verification:** application-level checks are complete (full SQLite suite, Pint, build,
  SQLite browser smoke). **MySQL Stage 18 is BLOCKED and has not run**: `bizflow_phase2d_scratch`
  was not created because `bizflow_user@localhost` cannot create databases. The real `bizflow`
  database was not modified and `bizflow_rehearsal` was not used. Never record Stage 18 as passed
  until an administrator provides the scratch database and `tests/Mysql` actually passes; do not
  weaken the `scratch` guard to get around it (details in README "Phase 2D verification status").
- **Phase 2E (receipt OCR capture) is implemented on `feature/expense-ocr` with the demo provider
  only.** `FakeReceiptOcrProvider` returns fixed data; **no real OCR provider has been selected or
  integrated** and no external OCR API may be called without an approved design. Its OCR limits
  (Trial 20, Free 0, Legacy unlimited, paid 100) are development placeholders. The Phase 2E
  migrations have **not** been run against the real `bizflow` database, and MySQL scratch
  verification is still blocked (same blocker as Phase 2D).
- The next phase after that is not yet specified and must be decided separately.
- Do not start new modules or deferred scope (see README "Known limitations and future scope")
  unless a task explicitly asks for it.

## Data isolation (critical)

- **The business is the tenant boundary.** Every business record (customers, products, invoices,
  expenses, recurring invoices) belongs to a Business through a required `business_id` foreign key. Invoice items
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
- **Records owned through a parent** (invoice items, invoice emails, recurring invoice items) have no `business_id`; reach
  them only through an already-authorized parent (`$invoice->emails()`). Audit columns such as
  `invoice_emails.requested_by` follow the `created_by` rule: `NULL` means system-generated, never
  ownership.
- **Migrations that change tenancy or other schema destructively** are rehearsed on a scratch
  database with a fresh backup first; restoring the backup is the recovery of record, not
  `migrate:rollback`.

## Invoices and recurring invoices

- **One way to write invoices:** `InvoiceService`. Anything that creates invoices (recurring
  generation included) calls `saveDraft()`; never write invoices or their lines another way.
- **Generated invoices are ordinary invoices** and are never changed by their recurring invoice.
  Template edits and state changes affect future occurrences only.
- **One invoice per occurrence** is guaranteed by the unique
  `(recurring_invoice_id, recurring_occurrence_on)` key on invoices; generation locks the recurring
  invoice's row first, then re-checks state, creates, links and advances the pointer in one
  transaction. Recurring dates are always calculated from the start date (`RecurringSchedule`).
- Recurring invoices generate drafts only; they never issue, number or email by themselves.

## Subscriptions and entitlements

- **The business is the subscriber.** `subscriptions` belong to a business (history rows; exactly one
  has `is_current = 1`, ended rows have `NULL`; `UNIQUE (business_id, is_current)`). Only
  `SubscriptionService` writes subscriptions: it locks the business row, re-reads the current row,
  validates, and mutates in one transaction. A row is never edited into a different plan: end it
  as `replaced` and start a new one.
- **`plans` is the one table without `business_id`:** it is shared platform catalogue data, not
  business data. A plan row is an immutable version (`UNIQUE (code, version)`): never change the
  commercial terms of a plan a subscription references (new version instead); retire with
  `is_active = false`; never delete a referenced plan.
- **Never write `if ($plan === 'pro')` or compare a subscription's `status` to decide anything.**
  Ask `EntitlementService::for($business)` (`access()`, `allows()`, `check()`, `limit()`, `used()`,
  `remaining()`). Access is derived from dates by `AccessResolver`; the stored status may lag.
  An entitlement a plan does not mention is denied. Add a new one as an `Entitlement` case (+ a
  `UsageMeter` for a limit), enforce it where it is used, and put a value in a new plan version.
- **Enforcement has four layers and all four must hold:** services (authoritative; they re-check
  with `EntitlementService::fresh()` after taking the business row lock, never the memo),
  `EntitlementGuard::create()` for records without a service (customers, products), policies
  (ownership first = 404, then subscription = 403), and the fail-closed `subscription.writable`
  middleware (unsafe methods refused while read-only except the three allow-listed `billing.*`
  routes, running before route-model binding). New business write routes go inside the `business`
  route group; do not allow-list them.
- **Read-only never deletes or mutates business data.** Reads, existing PDFs, reports, billing and
  logout keep working. Recurring schedules are skipped untouched and catch up later.
- **Downgrades** apply at period end through `subscriptions.pending_plan_id` (only a Free plan in
  self-service); access only switches to it once its period has begun.
- **Self-service never grants a paid plan.** Only `billing:assign` (operator) or, later, a payment
  provider through `SubscriptionService` starts a paid plan. Do not build checkout or touch a
  provider without an approved design.
- **SaaS billing is not customer invoicing.** Never reuse the `invoices` tables, `InvoiceService`
  or invoice numbering for subscription charges. Provider-specific code lives only in
  `app/Billing/Providers/<Name>` behind `BillingProvider`.
- The payment, billing-event and billing-history tables, billing emails and SST/tax on BizFlow's own
  charges are deferred; the SST decision must be made before the first paying customer. Placeholder
  prices and limits (the Trial and Free plans, `PlanSeeder`) are not approved commercial terms.
- Run the commercial tests with `php artisan test tests/Feature/Billing tests/Unit/Billing`; the
  MySQL concurrency tests in `tests/Mysql` are opt-in (`BIZFLOW_MYSQL_SCRATCH`, a scratch database).

## Receipt OCR (Phase 2E)

- **OCR never creates an expense.** Only `ExpenseReceiptService::confirm()` does, for a receipt in
  `review`, with the values the user submitted, in one transaction under the receipt's row lock.
  `expense_receipts.expense_id` is unique. Keep `ExpenseController` independent of it.
- `expense_receipts` is business data (`business_id` required, never fillable); `uploaded_by` and
  `confirmed_by` are audit metadata only. Only `ExpenseReceiptService` writes it; query it from
  `$business->expenseReceipts()`.
- Providers sit behind `App\Ocr\ReceiptOcrProvider`; whatever they return goes through
  `ReceiptExtractionNormalizer`. Never store raw provider responses or OCR text, never log
  receipt contents, and never put the client filename in a path.
- Files are on the private `receipts` disk and are only served by the policy-checked file route.
- The monthly OCR allowance is the `Entitlement::ReceiptOcr` limit, counted by
  `MonthlyReceiptOcrMeter` from `expense_receipts.counted_at`. A unit is taken at upload (and when
  retrying a failed receipt) under the business row lock, and released by a technical failure or a
  discard before extraction. Confirming uses none.

## Scheduled work

- Scheduled commands are system tasks: they have no current user or request, never use
  `CurrentBusiness`, and walk `Business` rows, handing each to a service explicitly.
- They must be safe to run at any time, more than once, and concurrently: decide what is due from
  data, take a row lock, and rely on a database constraint as the final guarantee.
- Subscriptions need **no scheduled job**: access is derived from dates on every check. A scheduled
  command that does business work (recurring generation) must skip, not fail, a business whose
  subscription does not allow it, and must leave that business's records untouched.

## Queued work

- Use Laravel's database queue; no Redis/Horizon. Jobs carry only IDs, re-read and re-check state
  when they run, and implement `ShouldQueueAfterCommit` when dispatched inside a transaction.
- A job that needs an entitlement (invoice email) re-checks it when it claims its row and, if it
  has been lost, fails the row for good rather than retrying.
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
