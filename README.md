# BizFlow

BizFlow is a small-business management MVP built with Laravel. A business owner can keep
their customers and their catalogue of products and services, create and track invoices,
record expenses, and follow the business through a dashboard and period reports.

Every business record belongs to one **business** (the tenant). A user works in their business
and only ever sees and changes that business's data.

> **Status:** MVP complete: Authentication, Customers, Products / Services, Invoices,
> Expenses, Dashboard and Reports. Phase 2 so far: invoice PDF download, the business
> tenancy foundation with a business profile, emailing invoices, recurring invoices, and the
> commercial foundation (plans, subscriptions, trial and usage limits; no payment provider yet),
> and receipt scanning for expenses (Phase 2E: **demo OCR only, no real OCR provider yet**).
> See [Known limitations and future scope](#known-limitations-and-future-scope).

## Tech stack

| Component | Version verified | Notes |
|---|---|---|
| Laravel | 12 | |
| PHP | **8.4.26** | `composer.json` allows `php ^8.2`; only 8.4.26 has been tested. |
| MySQL | **8.0.46** | Application database. |
| Bootstrap | 5.3 | Bundled with Vite; no other UI or chart library. |
| Node.js / npm | **24.18.1 / 11.16.0** | Only needed to build the front-end assets. |
| Vite | 7 | Via `laravel-vite-plugin`. |
| PHPUnit | 11.5 | Laravel's standard test setup (not Pest). |
| `brick/math` | 0.14 | Exact decimal arithmetic for money. |
| `barryvdh/laravel-dompdf` / `dompdf/dompdf` | 3.1.2 / **3.1.6** | Invoice PDF download. DomPDF is pinned to `^3.1.6`, which fixes the security advisories affecting 3.1.5 and earlier. |

Other versions may work but have not been verified.

## Features

### Authentication
- Registration, login and logout (logout is a POST request).
- Passwords are hashed by the model's `hashed` cast; "remember me" is supported.
- Login is limited to 5 failed attempts per email and IP; registration to 6 requests a minute.
- The session is regenerated after login and registration.
- Registration asks for a **business name** and creates the user, their business and their
  owner membership in one transaction: if any part fails, nothing is kept.

### Business profile
- Each account has one business, with the user as its owner. Only the name is required; address,
  registration number, SST number, email and phone are optional.
- The profile (user menu → **Business profile**) is shown as the seller on invoices and PDFs.
  Seller details are read live from the profile, so editing it also changes the seller block of
  existing invoices; customer and line details stay as copied onto each invoice.

### Customers
- Create, view, edit and delete customers, with search and pagination.
- A customer who has invoices cannot be deleted (their invoices keep referring to them).

### Products / Services
- One catalogue with a **product** or **service** type, optional SKU (unique per business,
  stored upper-case), unit, selling price and an internal, optional cost price.
- Items can be marked inactive: they stay on existing invoices but cannot be added to new ones.
- An item used on any invoice cannot be deleted (mark it inactive instead).
- Search by name, SKU or description; filter by type and status.

### Invoices
- Lifecycle: **draft → issued → paid**, **issued → cancelled**, and **paid → issued**
  ("mark as unpaid", to correct a mistaken payment). Nothing else is allowed.
- Only drafts can be edited or deleted. Issued invoices are fixed; to correct one, cancel it
  and create a new invoice. Cancelled invoices are kept.
- Numbers such as `INV-00001` are assigned per business **when an invoice is issued**, so drafts use
  no number and issued numbers have no gaps and never change.
- Lines can come from the catalogue or be typed in manually. A fixed discount and one
  invoice-level tax rate (with an optional label such as "SST") are supported; no tax rules are built in.
- "Paid" is a manual, full-payment flag with a payment date. There are no payment records.
- Overdue is calculated (issued and past the due date), never stored.
- Printable invoice view (browser print).
- **Download PDF** for issued, paid and cancelled invoices (`GET /invoices/{invoice}/pdf`, saved as
  e.g. `INV-00001.pdf`). Drafts have no PDF; cancelled PDFs are clearly marked `CANCELLED`. The PDF
  uses the details copied onto the invoice plus the business profile, and is rendered by DomPDF with remote access, PHP and
  JavaScript disabled and file access limited to its bundled fonts.
- **Email invoice** for issued and paid invoices (drafts and cancelled invoices cannot be emailed).
  The user picks the address: the one copied onto the invoice, or the customer's current address
  when it has changed since. There is no free-text address or message. The email is queued and sent
  by the queue worker with the invoice PDF (rendered at send time) attached. It comes from the
  platform address (`MAIL_FROM_ADDRESS`) with the business name as the display name, and replies go
  to the business email when the profile has one. Every send is kept in the invoice's **email
  history** (queued, sending, sent, failed or not sent, with the reason), and the invoice list shows
  when an invoice was last emailed. Emailing never changes the invoice.

### Recurring invoices
- A recurring invoice is a schedule plus an invoice template for one customer: lines, discount, tax,
  notes and payment terms. On each date of the schedule it creates an ordinary **draft** invoice,
  dated on that date and due after the payment terms, for you to review, issue and email as usual.
  Nothing is issued, numbered or emailed automatically.
- Schedules repeat **weekly, monthly or yearly** from a first invoice date (today or later). Dates
  are always worked out from that first date, so a schedule starting on the 31st falls on the last
  day of shorter months (31 Jan, 28 or 29 Feb, 31 Mar, 30 Apr) and one starting on 29 February
  falls on 28 February in other years. An optional last possible date is inclusive.
- **Active**, **paused** or **cancelled**. Pausing stops generation; resuming continues from the
  first date on or after today, skipping the dates missed while paused. Cancelling is final. A
  schedule past its last possible date shows as **Finished**.
- Missed dates (for example after downtime) are caught up oldest first, up to 12 per schedule per
  run; the schedule's page shows how many invoices are due. **Generate now** creates the invoice
  that is due today or earlier straight away. It never creates one early.
- Editing a recurring invoice only affects invoices generated afterwards. Invoices already
  generated are never changed; they are normal invoices and link back to their schedule. The first
  invoice date and frequency are fixed once an invoice has been generated.
- The template keeps its own prices: a product's price change does not change it. The customer's
  current details are copied onto each invoice when it is generated.
- If an invoice cannot be generated (for example a product on the template has been made inactive),
  nothing is created, the reason is shown on the schedule, and it is tried again every hour.
- A schedule that has generated an invoice cannot be deleted, only cancelled. A customer or product
  used by a recurring invoice cannot be deleted.

### Subscriptions and billing
- Every business has a subscription history and exactly one **current** subscription, on a plan.
  The owner sees it at **Billing** (`/billing`): current plan, access state, trial end or renewal
  date, usage against limits, and the history of every plan the business has been on.
  **Compare plans** (`/billing/plans`) lists the plans and what each one's button does.
- **New businesses get one 14-day trial.** When it ends the account is read-only until the owner
  chooses a plan. A business only ever gets one trial.
- **Existing businesses were grandfathered** onto the *Legacy* plan by the migration: no expiry, no
  limits, no payment, no trial. Nothing changed for them. Only an operator can move a business off
  Legacy.
- **Free plan:** the owner can switch to it at any time (from a paid period it takes effect when
  that period ends). **Paid plans cannot be bought yet**: the plan page says "Contact us to
  upgrade" and an operator assigns them (see [Billing operator command](#billing-operator-command)).
- The owner can cancel a paid subscription (full access to the end of the period, then read-only)
  and undo that before it takes effect. Only the owner can manage billing; any member can look.

**When a subscription is not in good standing** the business is never deleted from or changed:

| State | What the business can do |
|---|---|
| Full access (active, trial, or no end date) | Everything its plan allows |
| Grace (7 days after a paid period ends without renewal, or after a failed payment) | Everything its plan allows, with a visible warning |
| Read-only (trial ended, expired, grace over, or no subscription) | View everything, download existing invoice PDFs, see reports, manage billing, log out. Every other write is refused: creating, editing and deleting records, issuing, marking paid, cancelling, emailing, recurring generation and editing the business profile. |

Recurring schedules are left exactly as they are while a business is read-only (or its plan has
no recurring invoices); when access returns they catch up as usual (at most 12 occurrences per
run, drafts only, never issued or emailed automatically).

### Expenses
- Create, view, edit and delete expenses with a date, one of 13 fixed categories,
  description, amount, optional payee and notes.
- Search by description or payee; filter by category and date range.
- The list shows the exact count and total of everything matching the filters.

### Receipt scanning (Phase 2E)
- Upload a JPG, PNG or PDF receipt, review what was read, edit any field and **explicitly confirm**
  to create the expense. OCR never creates an expense by itself; nothing is saved as an expense
  until the user confirms.
- **Demo OCR only.** The only provider is `FakeReceiptOcrProvider`: it reads nothing from the file
  and returns the same made-up merchant, date and amounts for every receipt. Every receipt page
  says so. **No real OCR provider has been selected or integrated, and no receipt leaves the
  application.** Real OCR is a separate, later decision (provider, cost, privacy).
- States: `queued` -> `processing` -> `review` -> `confirmed`, with `unreadable` (nothing usable
  found) and `failed` (technical failure), both of which can be retried or filled in by hand, and
  `discarded`. Duplicate files and look-alike expenses produce warnings, never blocks.
- The monthly number of scans is a plan limit (`expenses.ocr_monthly_max`). **The values are
  development placeholders, not final commercial terms:** Legacy unlimited, Trial 20 a month,
  Free 0, development paid plans 100.
- Receipts nobody confirms are discarded, and their files deleted, after 30 days
  (`php artisan receipts:prune`, scheduled daily). Confirmed receipts keep their file.

### Dashboard
For a selected period (this month by default, last month, this year or a custom range):

| Figure | Definition |
|---|---|
| **Received** | Paid invoices, by payment date (`paid_at`) |
| **Invoiced** | Issued and paid invoices, by issue date (`issue_date`) |
| **Expenses** | Expenses, by `expense_date` |
| **Net cash (estimate)** | Received − Expenses |
| **Outstanding** | All issued invoices, as of today (not affected by the period) |
| **Overdue** | Issued invoices with `due_date` before today |

Draft and cancelled invoices are never counted. Invoice amounts are totals after discount and
including tax. **Net cash is a simple estimate, not accounting profit.**

The dashboard also shows items needing attention (overdue, due within 7 days, unissued drafts),
invoices by status, expenses by category, a 6-month trend and recent activity.

### Reports
Four pages under `/reports`, using the same definitions as the dashboard:

- **Summary:** period totals plus a month-by-month breakdown (partial first and last months are
  labelled), and outstanding/overdue as of today.
- **Customers:** invoiced and received for the period, outstanding and overdue as of today,
  grouped per customer.
- **Invoices:** issued in the period, paid in the period, or outstanding now with ageing
  (not yet due, 1–30, 31–60, 61–90 and over 90 days overdue).
- **Expenses:** all 13 categories with count, amount and share of the period total.

Periods: this month, last month, this year (default), last year or a custom range from
1 January 2000 onwards. Tables are paginated where they can grow (25 rows per page), with totals
covering every page. Reports can be printed from the browser.

## Design decisions

### Money
- Amounts are stored in MySQL `DECIMAL` columns (e.g. `DECIMAL(15,2)` for invoice totals and
  expenses) and cast to exact strings in PHP, never floats.
- Invoice arithmetic uses `brick/math` `BigDecimal`: line totals and tax are rounded half-up to
  2 decimal places, and totals that would not fit the column are rejected before saving.
- SQL `SUM` results are normalised to exact two-decimal strings (`Money::fromSql()`).
- The invoice form's live totals are for display only and are calculated in whole cents with
  JavaScript `BigInt`; the server always recalculates.

### Invoices keep their own copy of customer and item details
When an invoice is saved (and again when it is issued), the customer's billing details are
copied onto the invoice, and each line keeps its own name, description, unit and price. Editing
or renaming a customer or product later never changes an existing invoice, and a product's
cost price is never copied. Reports group customers by `customer_id` but display the name as
billed on that customer's most recent invoice.

### Tenant isolation
- The **business** is the tenant. `customers`, `products`, `invoices` and `expenses` each have a
  required `business_id`; invoice lines belong to their invoice. Users belong to businesses
  through `business_user` memberships with a role (only `owner` exists for now).
- The HTTP layer gets the business from `App\Support\CurrentBusiness` (resolved once per request
  from the user's membership). Queries start from it (`$business->invoices()`, …), never from
  unscoped model queries; there are no global scopes. Services receive the `Business` explicitly.
  Users have no direct customer/product/invoice/expense relationships.
- Policies check membership of the current business and record ownership as route middleware,
  **before** form validation. Another business's record returns **404**, so record IDs cannot be probed.
- `business_id` is never mass-assignable or validated from input. Submitted customer and product
  IDs must belong to the current business: validation rejects them, the invoice service refuses
  them, and a composite foreign key `invoices (business_id, customer_id) → customers (business_id, id)`
  makes a cross-business customer impossible in the database.
- `invoices.created_by` and `expenses.created_by` record who created the record. They are audit
  metadata only (`NULL` means system-generated or no associated person) and are never used for
  access or filtering. Deleting that user clears the column; it never deletes the record.
- Deleting a user who owns a business, or a business that still has records, is refused by the
  database.

The move from per-user to per-business ownership is a staged migration (`2026_09_29_1000xx` to
`1006xx`): businesses are backfilled one per existing user, `business_id` is backfilled and
verified before any destructive step, and then the keys are rebuilt. The last two migrations
cannot be rolled back; the recovery path is restoring a backup taken before migrating.

### Invoice emails
- One row per send request in `invoice_emails`, owned through the invoice (no `business_id`).
  `requested_by` is audit metadata only; `NULL` means system-generated.
- The request is recorded and the `SendInvoiceEmail` job queued in one transaction; the job
  (`ShouldQueueAfterCommit`) is only queued if that transaction commits, and carries only the row ID.
- Only one send per invoice can be in flight. A send only happens when the job moves its row from
  `queued` to `sending` with a conditional update, so a row is never sent twice, and the job re-checks
  that the invoice is still issued or paid (a send for an invoice cancelled in the meantime is
  recorded as not sent). A send still queued after 15 minutes (for example because no worker is
  running) can be replaced: it is first marked "Superseded" in the same way, so its job can never
  send. A send that is already `sending` is never replaced.
- Failures: an invalid address or an SMTP 550/551/553 rejection fails at once; everything else is
  retried (3 attempts, after 1 and 5 minutes) before being marked failed. A send interrupted mid-way
  is marked failed rather than retried, because it may already have been delivered. Failed sends can
  be sent again.
- Limits: 6 send requests a minute per user, 30 invoice emails an hour per business, and 5 a day
  (at most one a minute) per invoice.

### Recurring invoice generation
- `recurring_invoices` (owned by `business_id`) and `recurring_invoice_items` (owned through it) hold
  the template. Generation calls `InvoiceService::saveDraft()`, the same code that saves any draft,
  so validation of ownership, `InvoiceCalculator` totals and the copied customer details are
  identical. There is no second way of writing invoices.
- One occurrence is the pair (recurring invoice, date). `invoices.recurring_invoice_id` and
  `invoices.recurring_occurrence_on` record it, and a unique key on the pair makes one invoice per
  occurrence a database guarantee. Both are `NULL` on ordinary invoices, which never conflict. A
  composite foreign key `invoices (business_id, recurring_invoice_id)` means an invoice can only
  link to a schedule of its own business.
- Each occurrence is generated in its own transaction: lock the recurring invoice's row, re-check
  that it is active and due, create the draft, link it, advance `next_occurrence_on`. The row lock
  serializes the scheduler, overlapping runs and **Generate now**; deadlocks are retried (3
  attempts); a failure rolls everything back and is recorded on the schedule.
- The scheduler runs `invoices:generate-recurring` hourly. What is due depends only on dates, so
  running it more often, twice at once, or after downtime never creates extra invoices.

### Subscriptions, plans and entitlements
- **The business is the subscriber.** `subscriptions` rows belong to a business (`business_id`,
  restricted on delete). A plan change ends the old row as `replaced` and starts a new one; a row
  is never edited into a different plan, so history stays true. `UNIQUE (business_id, is_current)`
  allows one current row (`is_current = 1`); ended rows have `is_current NULL` (MySQL allows many
  NULLs in a unique index). `created_by` is audit metadata only, as elsewhere.
- **`plans` is shared catalogue data**, not business data, so it has no `business_id` (the one
  deliberate exception to the business-ownership rule). A plan row is an immutable, versioned set
  of commercial terms: `UNIQUE (code, version)`. Price, currency, interval, trial length and
  entitlements never change once a subscription references the row (the model refuses, and the
  foreign keys refuse deletion); change terms by inserting a new version. Retire a plan with
  `is_active = false`. The migration seeds only three plans: **Legacy**, **Trial** and **Free**.
  Their Trial and Free limits are placeholders. `php artisan db:seed --class=PlanSeeder` adds two
  development paid plans whose **prices are placeholders, not approved commercial pricing**.
- **Entitlements** are a JSON map on the plan keyed by `App\Enums\Entitlement`
  (`customers.max`, `products.max`, `invoices.monthly_max`, `recurring_invoices.max`,
  `team.seats`, `invoices.email`): an integer is a limit, `null` is unlimited, `0` is not
  included, a boolean is a flag, and **an absent key is denied**. A new entitlement is one enum
  case (plus a usage meter if it is a limit), enforced where it is used, and a value in a new
  plan version.
- **Access is derived from dates, never from the stored `status`** (which may lag):
  `App\Billing\AccessResolver` returns Full, Grace or ReadOnly from the current subscription. Ask
  `EntitlementService::for($business)` (memoized for one request only, no other cache); never
  compare a subscription's status or plan name in a controller or view.
- **Usage is counted live** from the business's own data: customers; products (inactive ones
  still count; deleting frees capacity); invoices **issued** this calendar month in
  `Asia/Kuala_Lumpur` by the system `issued_at` timestamp (cancelled ones count because their
  number was consumed; drafts, including recurring-generated drafts, do not); active and paused
  recurring schedules; team members. A business over a limit after a downgrade keeps every record
  and cannot add more until it is back under the limit.
- **Four enforcement layers.** Services are authoritative (`InvoiceService::issue`,
  `RecurringInvoiceService::save` and `generateDue`, `InvoiceEmailService::queue` and `claim`).
  `EntitlementGuard::create` wraps customer and product inserts: it locks the business row, counts,
  then inserts, so two requests at a limit cannot both succeed. Policies check ownership first
  (404 for another business) and the subscription second (403 with an upgrade message). The
  `subscription.writable` middleware refuses every unsafe HTTP method while read-only, except the
  allow-listed `billing.change`, `billing.cancel` and `billing.resume`; it is deny-by-default
  and runs before route-model binding so a forged ID and a missing one look the same.
- **Downgrades take effect at the end of the paid period.** `subscriptions.pending_plan_id` records
  the plan to move to; the current row stays current until `current_period_ends_at`, and only then
  does `SubscriptionService` settle it into a new row that starts exactly at that moment. Until
  then access still comes from the current row's dates, and the pending plan only substitutes once
  its period has begun. Only a Free plan can be pending in self-service.
- **Self-service rules:** the owner may switch to Free; they may never choose a paid plan, the
  Trial plan or Legacy, and never leave Legacy. Starting any paid plan is `billing:assign`.
- **SaaS billing is separate from customer invoices.** A customer invoice is a business billing its
  own customer; a subscription charge is BizFlow billing a business, so the roles are reversed.
  They share no tables, services or numbering, and subscription charges will never use the
  `invoices` tables.
- **Payment-provider boundary.** `App\Billing\BillingProvider` (checkout, webhook parsing, cancel,
  refund) is the only seam a real provider will plug into. Only `ManualBillingProvider` exists,
  and it never takes or fakes a payment. A future adapter lives in
  `app/Billing/Providers/<Name>` and calls `SubscriptionService`, which stays the only writer.
  **Deferred, not built:** the payment, billing-event (webhook) and billing-history tables, checkout
  and webhook routes, refunds, billing emails, and SST/tax handling for BizFlow's own charges.

### Receipt scanning
- **One table, `expense_receipts`**, owned by a business through a required `business_id`. It holds
  the private file path, the OCR state, the normalized extraction (JSON), the names of fields the
  user edited, audit timestamps and the created expense (`expense_id`, `UNIQUE`, so a receipt can
  never create two expenses). Raw provider responses and OCR text are never stored, and there is
  no attempt history: a retry replaces the extraction.
- **Provider seam:** `App\Ocr\ReceiptOcrProvider`. `ReceiptExtractionNormalizer` validates and
  cleans whatever a provider returns (amounts, dates, text, category) and produces warnings and
  confidence; a provider without confidence scores shows as "unverified".
- **Files** live on the private `receipts` disk (`storage/app/receipts`), outside every served
  disk root, under a generated `{business}/{ulid}.{ext}` name; the client filename is only a
  cleaned display label. Uploads are accepted by their bytes (finfo type, magic bytes, matching
  extension, a decodable image within a pixel limit, a complete unencrypted PDF with no active
  content), never by what the client claims. The only way to read a file is the authenticated,
  policy-checked route, with `nosniff`, a sandboxing CSP and no caching. Images are not
  re-encoded and EXIF data is not stripped yet.
- **Quota:** a receipt takes one unit of the monthly allowance when uploaded, under the business
  row lock; a technical failure or a discard before it was read gives the unit back, and retrying
  a read receipt or confirming uses no more.
- **Confirming** locks the receipt row, requires `review`, re-checks write access, validates with
  the Expense rules, and creates the expense and links it in one transaction.

### Invoice numbering
Each business has its own sequence starting at `INV-00001`. Numbers are assigned inside a
database transaction that locks the business's row, so two invoices issued at the same moment
in one business cannot get the same number. Unique `(business_id, invoice_number)` and
`(business_id, invoice_sequence)` indexes back this up. An invoice that already has a number is
never renumbered, and issuing re-checks that the invoice is still a draft under the lock.

### Dates
- The application timezone is `Asia/Kuala_Lumpur`; "today" always means today in that timezone.
- Date ranges are half-open (`date >= start AND date < day after end`). Both end days are
  included whether the database stores a plain date or a date with time, and no function is
  wrapped around indexed date columns.
- Overdue means issued with `due_date` before today, so an invoice due today is not overdue.

### Testing strategy
- The automated tests (PHPUnit) run on an in-memory SQLite database.
- Behaviour that differs on MySQL — exact `DECIMAL` storage and sums near the column limits,
  foreign-key restrictions, index use (`EXPLAIN`) and `ONLY_FULL_GROUP_BY` — was verified
  separately against MySQL 8.0.46, inside transactions that were rolled back.
- Browser smoke tests on a throwaway SQLite database covered the main flows, mobile width and printing.
- The default automated suite (`php artisan test`) does **not** run against MySQL. The commercial
  layer's concurrency and MySQL-specific tests (`tests/Mysql`) are skipped locally unless
  `BIZFLOW_MYSQL_SCRATCH` names a scratch database, but GitHub Actions runs them automatically in
  the "MySQL (tests/Mysql)" job against a throwaway MySQL 8.0 container (see "Phase 2D verification
  status").

## Setup

These steps assume PHP 8.4 with the `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl` and
`fileinfo` extensions, Composer 2, Node.js with npm, and MySQL 8.

1. **Clone the repository**
   ```bash
   git clone <repository-url> bizflow
   cd bizflow
   ```
2. **Install PHP dependencies**
   ```bash
   composer install
   ```
3. **Install front-end dependencies**
   ```bash
   npm install
   ```
4. **Create `.env`**
   ```bash
   cp .env.example .env
   ```
5. **Create the MySQL database and user** (as a MySQL administrator; choose your own password)
   ```sql
   CREATE DATABASE bizflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bizflow_user'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT ALL PRIVILEGES ON bizflow.* TO 'bizflow_user'@'localhost';
   ```
6. **Configure the database in `.env`**: set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.
   Never commit `.env`; `.env.example` holds placeholders only.
7. **Generate the application key**
   ```bash
   php artisan key:generate
   ```
8. **Run the migrations**
   ```bash
   php artisan migrate
   ```
9. **Build the front-end assets**
   ```bash
   npm run build      # or `npm run dev` for hot reload while developing
   ```
10. **Start the development server**
    ```bash
    php artisan serve
    ```
    Open http://127.0.0.1:8000 and register an account.
    `/health` shows an application and database status page (`200` healthy, `503` if the database is unreachable).
11. **Run the queue worker** (needed for invoice emails; leave it running in its own terminal)
    ```bash
    php artisan queue:work --tries=3 --timeout=60
    ```
    Invoice emails stay "Queued" until a worker picks them up. With the default `MAIL_MAILER=log`
    nothing is delivered: each email, including its base64-encoded PDF (customer data), is written
    to `storage/logs/laravel.log`. Set the `MAIL_*` SMTP settings in `.env` to send real email.
12. **Run the scheduler** (needed for recurring invoices; leave it running in its own terminal)
    ```bash
    php artisan schedule:work
    ```
    It runs `invoices:generate-recurring` every hour. To generate what is due straight away, run
    `php artisan invoices:generate-recurring` yourself; it is safe to run at any time.
13. **Billing settings** (optional): `BILLING_PROVIDER=manual` (the only provider) and
    `BILLING_CONTACT_EMAIL` (the address behind "Contact us to upgrade"). The grace length (7
    days) and the billing timezone are in `config/billing.php`.
14. **Run the tests** (see below).

### Billing operator command
Until a payment provider exists, an operator starts paid plans (and moves a business on or off
Legacy) from the command line:

```bash
php artisan billing:assign {business-id} {plan-code}          # newest active version
php artisan billing:assign {business-id} {plan-code}:{version}
```

It goes through `SubscriptionService`: the old subscription ends as `replaced`, history is kept,
and a paid plan starts a billing period now. It refuses a retired plan, the plan the business is
already on and a second trial. There is no automatic renewal: when a paid period ends with no new
assignment the business gets the 7-day grace and then becomes read-only. **No new scheduled job is
needed**: access is judged from the dates every time, so expiry and grace need no job.

If `php` on your PATH is not PHP 8.4, call the PHP 8.4 binary directly. For example, on the
Windows machine this project was developed on: `C:\php84\php.exe artisan test`. That path is a
local example, not a requirement.

### Production notes
For a real deployment, set at least `APP_ENV=production`, `APP_DEBUG=false`, the correct
`APP_URL`, and `SESSION_SECURE_COOKIE=true` when serving over HTTPS.

Invoice emails need a real mailer (`MAIL_MAILER=smtp` and its settings), a `MAIL_FROM_ADDRESS` on
a domain you control with SPF, DKIM and DMARC set up, and a queue worker kept running by a process
supervisor (`php artisan queue:work`, restarted with `php artisan queue:restart` on every deploy).
On Windows the worker cannot enforce its timeout (no `pcntl`), so an interrupted send is only
detected when the job is retried after `DB_QUEUE_RETRY_AFTER` (90 seconds).

Receipt scanning reuses the queue worker. PHP's `upload_max_filesize` and `post_max_size` must be
at least `ocr.max_upload_kb` (8 MB by default); PHP's own default of 2 MB rejects larger phone
photos before BizFlow sees them. `OCR_DRIVER` is `fake` (demo data) or `none` (scanning off). The
`receipts:prune` command runs from the same scheduler.

Recurring invoices need the scheduler: run `php artisan schedule:run` every minute from cron (or
Windows Task Scheduler). If it stops, nothing is lost: missed invoices are generated, oldest first,
once it runs again.

## Testing

```bash
php artisan test          # PHPUnit, in-memory SQLite
vendor/bin/pint --test    # code style check (no changes made)
npm run build             # front-end build
```

Run only the commercial-layer tests with:

```bash
php artisan test tests/Feature/Billing tests/Unit/Billing
```

Concurrency and MySQL-specific behaviour of the commercial layer (the unique `NULL` key, foreign
keys, two requests racing at a limit, concurrent plan changes) is in `tests/Mysql`. Those tests
are **skipped** locally unless `BIZFLOW_MYSQL_SCRATCH` names a scratch database (its name must
contain `scratch`, and it is rebuilt with `migrate:fresh`); they never run against the `bizflow`
database. GitHub Actions runs them automatically in its own MySQL 8.0 service container.

`php artisan test` never touches the MySQL database. Because SQLite and MySQL differ in
`DECIMAL` handling, foreign-key enforcement, query planning and SQL modes such as
`ONLY_FULL_GROUP_BY`, database-specific behaviour is verified separately against MySQL.

### Phase 2E verification status

Application-level verification: the full suite (1,575 tests, 6,148 assertions) passes on in-memory
SQLite, Pint passes, and the receipt tests (`tests/Feature/ExpenseReceipts`,
`tests/Unit/Ocr`) run on in-memory SQLite with a faked disk and queue, and a browser smoke test
at 375px passed against a throwaway SQLite database (upload, review, confirm, double submit,
private file headers). **Local MySQL scratch verification (Stage 18) is still BLOCKED and has not
run** (see below). The MySQL tests for the receipt migrations, the unique `expense_id`, the foreign
keys and the row locks that stop two requests passing the monthly limit or confirming twice are
part of `tests/Mysql`, which CI runs (see below), but they have not been run in the local rehearsal.
The Phase 2E migrations **have been run** against the real `bizflow` database (migration batch 10,
after Phase 2D's batch 9), and a demo receipt created by the fake OCR provider (confirmed, with its
expense) currently exists there.

### Phase 2D verification status

**Application-level verification: completed.** 1,317 tests (5,140 assertions) pass on in-memory
SQLite, Pint and `npm run build` pass, and a browser smoke test (desktop and 375px) passed against
a throwaway SQLite database. None of this touched the BizFlow MySQL database. A fresh backup
(`bizflow-pre-2d-20261002-083956.sql`) was taken before any MySQL work.

**MySQL scratch verification (Stage 18): BLOCKED, not executed.** MySQL scratch database
unavailable due to current MySQL account privileges.
- The rehearsal needs a database named `bizflow_phase2d_scratch`. It was **not created**.
- The application account, `bizflow_user@localhost`, has privileges only on `bizflow.*` and
  `bizflow_rehearsal.*` and cannot create a new database. Laravel's normal BizFlow MySQL
  connection itself was verified to work.
- During that attempt `bizflow` was **not modified**, and `bizflow_rehearsal` was **not used** (the test guard
  rejects any name without `scratch`, and that guard was left unchanged).
- At the time of that attempt, the 22 tests in `tests/Mysql` were written but had **never run**, so
  nothing in this list was verified on MySQL: the unique `NULL` key semantics, foreign-key
  restrictions, the backfill's `INSERT IGNORE`, rollback and re-migration, concurrent
  customer/product/invoice limits, concurrent plan changes, lock ordering and deadlocks, and
  one-trial-ever under concurrency. They have since been added to CI (next section); the local
  Stage 18 rehearsal itself has **not** been done.
- **To resume:** an authorized MySQL administrator creates `bizflow_phase2d_scratch`
  (utf8mb4) and grants `bizflow_user` privileges on that database only. Then run
  `BIZFLOW_MYSQL_SCRATCH=bizflow_phase2d_scratch php artisan test tests/Mysql`.

**MySQL tests in GitHub Actions.** The "MySQL (tests/Mysql)" job in `.github/workflows/ci.yml`
runs `tests/Mysql` automatically on pull requests and on pushes to `main`, against a throwaway
`mysql:8.0` service container with a scratch database (`bizflow_ci_scratch`) and CI-only
credentials; it never touches the `bizflow` database. This is **not** the local Stage 18
rehearsal: it uses a different MySQL instance and account, so it does not verify the project's own
MySQL 8.0.46 install or its privileges, and Stage 18 must not be recorded as passed because of it.
On PR #1 the job passed at commit `1ff803d`. An earlier run at `df7f662` failed in the same job
with an unknown cause (no per-test output was captured). That failure was not reproduced at
`1ff803d`, but its cause remains unknown, so the job's stability is not established.

The real `bizflow` database has since been migrated for Phase 2D (migration batch 9) and Phase 2E
(batch 10) even though Stage 18 had not been run locally.

Future database-changing phases should not be applied to the real development database until the
corresponding local verification/rehearsal gate has been completed, unless explicitly reviewed and
approved.

The next phase is not yet specified and must be decided separately.

## Known limitations and future scope

These are outside the MVP by design, not bugs:

- Inventory / stock, payroll, HR, point of sale
- Double-entry accounting, formal financial statements (P&L, balance sheet, cash flow), reconciliation
- Tax filing, e-invoicing, payment gateways (customer payments and BizFlow's own), partial payments
- CSV / Excel exports, PDF reports, emailed reports, payment reminders, scheduled reports
- Recurring invoices that issue or email themselves, every-N-days or custom schedules, skipping or
  changing a single occurrence, proration, recurring expenses
- Multi-currency (amounts are recorded in MYR)
- REST API, mobile app, WhatsApp API, AI chatbot
- A real OCR provider, line-item extraction, supplier matching, batch or e-mailed receipts,
  tax / currency / receipt-number columns on expenses, OCR attempt history

Current limitations:

- There is no account deletion feature. Deleting a business owner, or a business that has
  records, is intentionally blocked by the database.
- One business per user, with a single owner. There are no invitations, other roles or business
  switching yet.
- Seller details are not copied onto invoices, so editing the business profile also changes the
  seller block of existing invoices and their PDFs.
- "Paid" means marked as paid in full by the user; there are no payment records.
- Recurring invoices generate drafts only; each one still has to be issued and emailed by hand.
  Deleting a generated draft does not bring it back: its date has passed for the schedule.
- "Sent" means the mail server accepted the email; delivery, bounces and opens are not tracked.
  Like any queue, a send is at-least-once: if the mail server accepts an email and the worker dies
  before recording it, the send is marked failed and a duplicate is possible if it is sent again.
- Reports and the dashboard show invoice totals including tax; tax is not reported separately.
- **No payment provider yet.** Paid plans are assigned by an operator; there is no checkout, no
  automatic renewal, no refunds, no billing history of payments and no billing emails.
- **SST / tax on BizFlow's own subscription charges is undecided** and must be resolved (legal
  entity, whether SST applies, what document the customer receives) **before the first paying
  customer**. Nothing in the application calculates or prints tax for a subscription.
- **No private plans:** every active plan is listed to every business, so a negotiated plan would
  be visible on the comparison page. Hiding one needs a visibility flag on `plans`.
- Team roles, invitations and seat limits are not built: the `team.seats` entitlement exists
  and counts members, but every business has one owner.
- The development paid plans from `PlanSeeder` and the Trial and Free limits are placeholders,
  not approved commercial pricing or policy.
- A read-only business cannot edit anything, including marking an invoice paid or editing the
  business profile (a deliberate, strict policy).
- **Receipt scanning is demo-only.** `FakeReceiptOcrProvider` returns fixed data; do not rely on it
  for real bookkeeping. Its limits (Trial 20, Free 0, Legacy unlimited, paid 100) are
  placeholders. Subtotal, tax, receipt number, currency and payment method have no expense
  column: they are prefilled into the editable notes. Receipt files are kept with their expense,
  and a deleted expense leaves its receipt (discard it separately). A business that is read-only
  keeps its unconfirmed receipts until it can write again.
- Trial abuse by registering several businesses is not detected: one trial is enforced per
  business only.

## Development rules

See [CLAUDE.md](CLAUDE.md).
