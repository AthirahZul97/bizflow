# BizFlow: Development Rules

BizFlow is a Laravel 12 small-business management SaaS (PHP 8.4.1+, MySQL 8, Bootstrap 5, PHPUnit).
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
  was not created because `bizflow_user@localhost` cannot create databases. (During that attempt the
  real `bizflow` database was not modified and `bizflow_rehearsal` was not used; the real database
  has since been migrated, see below.) Never record Stage 18 as passed
  until an administrator provides the scratch database and `tests/Mysql` actually passes; do not
  weaken the `scratch` guard to get around it (details in README "Phase 2D verification status").
- **CI runs `tests/Mysql`.** The GitHub Actions job "MySQL (tests/Mysql)" runs them automatically
  against a throwaway MySQL 8.0 service container and a scratch database (`bizflow_ci_scratch`).
  That is **not** the local Stage 18 rehearsal on the project's own MySQL: Stage 18 is still
  unverified. The job passed at `1ff803d`. An earlier run at `df7f662` failed in the same job; its
  cause was never captured, it was not reproduced at `1ff803d`, and it remains unknown.
- **Phase 2E (receipt OCR capture) is implemented on `feature/expense-ocr` with the demo provider
  only.** `FakeReceiptOcrProvider` returns fixed data; **no real OCR provider has been selected or
  integrated** and no external OCR API may be called without an approved design. Its OCR limits
  (Trial 20, Free 0, Legacy unlimited, paid 100) are development placeholders. The Phase 2E
  migrations **have been run** against the real `bizflow` database (migration batch 10, after the
  Phase 2D migrations in batch 9), and a demo receipt created by the fake provider (confirmed, with
  its expense) currently exists there. Local MySQL scratch verification (Stage 18) is still blocked
  (same blocker as Phase 2D).
- **Phase 2F-2 (portfolio demo security baseline)** is implemented on `feature/security-baseline`:
  PHP `^8.4.1`, demo mode, preflight, minimal `/health`, trusted proxies and security headers (CSP
  report-only), login IP throttle, password reset and email verification, housekeeping and audits,
  the route inventory test and `docs/deployment.md`. Demo accounts are **not** seeded and there is no
  reset job: provisioning, isolation checks, cleanup and safe reset are mandatory gates before a
  public deployment. Not yet merged; CI verification pending.
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
  MySQL concurrency tests in `tests/Mysql` are opt-in: locally they need the five dedicated
  `BIZFLOW_MYSQL_SCRATCH*` process variables for a scratch instance on 127.0.0.1:3308 (never in
  `.env`, never the app's `DB_*`; see README "Testing" and `tests/Mysql/ScratchTarget.php`); CI
  runs them against its own container. Do not loosen those rules.

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
- **Recovery** (`receipts:recover`, `ExpenseReceiptService::recoverStuck()`): a claim is the
  receipt's `attempts` plus the `processing_started_at` it wrote; every worker write requires
  both to match, and recovery revokes a stale owner by clearing the start in the same conditional
  update that takes the right to dispatch. Only `claim()` increments `attempts`. The sweep is the
  one deliberately global read (a system maintenance exception: it finds rows by state, reads no
  receipt contents) and every write it makes is pinned to the row's `id` and `business_id`.
  Laravel gives `failed()` a fresh job instance, so that hook never knows its claim: it may only
  end a receipt that has used all its attempts and is not being run; a job holding a claim on its
  last delivery ends the receipt itself. Entitlement lost at claim time fails the receipt for
  good and releases its unit (deliberate; do not change to skip-and-retry).

## Security baseline and demo mode (Phase 2F-2)

- **Demo mode is configuration, never request input.** `App\Support\DemoMode` reads
  `config('bizflow.demo')` / `bizflow.registration`; nothing in a request, header or cookie may
  change it. It only removes capabilities and never grants an entitlement or touches `business_id`.
- **Outbound mail has three guards and all three must hold:** `InvoiceEmailService::queue()` refuses
  before any row or job exists; `claim()` ends a queued row `skipped` when email is off; and the
  `MessageSending` listener cancels delivery on a real transport. A cancelled message makes `send()`
  return `null`; `SendInvoiceEmail` must never record that as sent.
- **Routes fall into categories** (public, guest-only, authenticated without business, business,
  framework). Only business routes take `business` + `subscription.writable`; guest and recovery routes
  must not. `tests/Feature/Security/RouteInventoryTest.php` fails for a route that fits no category.
  Keep a new route's category and middleware deliberate.
- **Trust no proxy by default.** `config/trustedproxy.php` lists proxies (`TRUSTED_PROXIES`); `*` needs
  `TRUSTED_PROXIES_ALLOW_ALL`. The login throttle keys on `$request->ip()`, so a wrong proxy setting
  breaks it.
- **Throttle middleware shares a counter per user (or IP) unless given a prefix**
  (`throttle:10,1,billing`). New limits use their own prefix. (The older `throttle:N,M` routes without a
  prefix share one counter; leave them unless asked.)
- **Security headers are global middleware** and never overwrite a header a response already has (the
  receipt download's stricter CSP must survive). The CSP stays **report-only** until verified in real
  browsers. Views carry no inline script or event-handler attributes (`resources/js/page-behaviours.js`),
  so the policy has no nonce and no `'unsafe-inline'` for scripts; add a nonce only if one is ever needed.
- **`/health` says only ok or unavailable** (200 / 503) and lives OUTSIDE the web group (no session, cookie,
  CSRF or database-backed throttle), so a down database-backed session or cache cannot break it; its own
  limiter uses `HEALTH_LIMITER_STORE`, and an unexpected failure is rendered as the same 503 by the exception
  handler. No diagnostics page; use `bizflow:preflight` and logs.
- **A password change signs out the account's other sessions** through `AuthenticateSession` (web group)
  plus the `Login` listener that records the password hash at sign-in. Do not remove either. The
  middleware is `RequirePasswordFingerprint`, which FAILS CLOSED: a signed-in session with no hash is
  logged out, never given one (so sessions from before the protection end once, at deploy). Tests sign
  in through `actingAs()`, which seeds the hash (see `Tests\TestCase::be()`). The single-use reset is
  proven on MySQL by `tests/Mysql/PasswordResetMysqlTest.php` (CI only; never run locally).
- **Every mail transport is wrapped** by `GuardedMailManager` / `DemoGuardTransport`, so demo mode stops
  delivery on any mailer, default or named. `SecurityHeaders` is the OUTERMOST global middleware so early
  refusals (413, maintenance) carry the headers.
- **Proxy lists are validated** (`App\Support\TrustedProxies`): wildcards, `REMOTE_ADDR`, `/0` and other
  trust-everyone spellings are dropped and reported, never trusted.
- **Audit the whole npm graph** (`npm audit`, not `--omit=dev`): axios is a devDependency in the bundle.
- **Do not seed demo accounts, add a reset job, or enable email transport** without an approved design.
- Run `php artisan bizflow:preflight` against `.env.demo.example` / `.env.production.example` rules
  (`tests/Feature/Security/PreflightTest.php` keeps the examples valid).
## Scheduled work

- Scheduled commands are system tasks: they have no current user or request, never use
  `CurrentBusiness`, and walk `Business` rows, handing each to a service explicitly.
- They must be safe to run at any time, more than once, and concurrently: decide what is due from
  data, take a row lock, and rely on a database constraint as the final guarantee.
- Subscriptions need **no scheduled job**: access is derived from dates on every check. A scheduled
  command that does business work (recurring generation) must skip, not fail, a business whose
  subscription does not allow it, and must leave that business's records untouched.

## Queued work

- Use Laravel's database queue; no Redis/Horizon. Business jobs carry only IDs, re-read and re-check state
  when they run, and implement `ShouldQueueAfterCommit` when dispatched inside a transaction.
  The exception is the account emails (`QueuedResetPassword`, `QueuedVerifyEmail`): a notification
  carries its notifiable and its bearer token or signed link, so they implement `ShouldBeEncrypted` and
  the `jobs` / `failed_jobs` payload is an encrypted envelope. Anything else that has to carry a secret
  must do the same.
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
