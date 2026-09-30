# BizFlow

BizFlow is a small-business management MVP built with Laravel. A business owner can keep
their customers and their catalogue of products and services, create and track invoices,
record expenses, and follow the business through a dashboard and period reports.

Every business record belongs to one **business** (the tenant). A user works in their business
and only ever sees and changes that business's data.

> **Status:** MVP complete: Authentication, Customers, Products / Services, Invoices,
> Expenses, Dashboard and Reports. Phase 2 so far: invoice PDF download, the business
> tenancy foundation with a business profile, and emailing invoices. See [Known limitations and future scope](#known-limitations-and-future-scope).

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

### Expenses
- Create, view, edit and delete expenses with a date, one of 13 fixed categories,
  description, amount, optional payee and notes.
- Search by description or payee; filter by category and date range.
- The list shows the exact count and total of everything matching the filters.

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
- The automated suite does **not** run against MySQL.

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
12. **Run the tests** (see below).

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

## Testing

```bash
php artisan test          # PHPUnit, in-memory SQLite
vendor/bin/pint --test    # code style check (no changes made)
npm run build             # front-end build
```

`php artisan test` never touches the MySQL database. Because SQLite and MySQL differ in
`DECIMAL` handling, foreign-key enforcement, query planning and SQL modes such as
`ONLY_FULL_GROUP_BY`, database-specific behaviour is verified separately against MySQL.

## Known limitations and future scope

These are outside the MVP by design, not bugs:

- Inventory / stock, payroll, HR, point of sale
- Double-entry accounting, formal financial statements (P&L, balance sheet, cash flow), reconciliation
- Tax filing, e-invoicing, payment gateways, partial payments
- CSV / Excel exports, PDF reports, emailed reports, payment reminders, scheduled reports, recurring invoices
- Multi-currency (amounts are recorded in MYR)
- REST API, mobile app, WhatsApp API, AI chatbot

Current limitations:

- There is no account deletion feature. Deleting a business owner, or a business that has
  records, is intentionally blocked by the database.
- One business per user, with a single owner. There are no invitations, other roles or business
  switching yet.
- Seller details are not copied onto invoices, so editing the business profile also changes the
  seller block of existing invoices and their PDFs.
- "Paid" means marked as paid in full by the user; there are no payment records.
- "Sent" means the mail server accepted the email; delivery, bounces and opens are not tracked.
  Like any queue, a send is at-least-once: if the mail server accepts an email and the worker dies
  before recording it, the send is marked failed and a duplicate is possible if it is sent again.
- Reports and the dashboard show invoice totals including tax; tax is not reported separately.

## Development rules

See [CLAUDE.md](CLAUDE.md).
