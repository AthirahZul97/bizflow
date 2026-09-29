# BizFlow

BizFlow is a small-business management MVP built with Laravel. A business owner can keep
their customers and their catalogue of products and services, create and track invoices,
record expenses, and follow the business through a dashboard and period reports.

Every business record belongs to one user. Users only ever see and change their own data.

> **Status:** MVP complete: Authentication, Customers, Products / Services, Invoices,
> Expenses, Dashboard and Reports. See [Known limitations and future scope](#known-limitations-and-future-scope).

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

Other versions may work but have not been verified.

## Features

### Authentication
- Registration, login and logout (logout is a POST request).
- Passwords are hashed by the model's `hashed` cast; "remember me" is supported.
- Login is limited to 5 failed attempts per email and IP; registration to 6 requests a minute.
- The session is regenerated after login and registration.

### Customers
- Create, view, edit and delete customers, with search and pagination.
- A customer who has invoices cannot be deleted (their invoices keep referring to them).

### Products / Services
- One catalogue with a **product** or **service** type, optional SKU (unique per user,
  stored upper-case), unit, selling price and an internal, optional cost price.
- Items can be marked inactive: they stay on existing invoices but cannot be added to new ones.
- An item used on any invoice cannot be deleted (mark it inactive instead).
- Search by name, SKU or description; filter by type and status.

### Invoices
- Lifecycle: **draft → issued → paid**, **issued → cancelled**, and **paid → issued**
  ("mark as unpaid", to correct a mistaken payment). Nothing else is allowed.
- Only drafts can be edited or deleted. Issued invoices are fixed; to correct one, cancel it
  and create a new invoice. Cancelled invoices are kept.
- Numbers such as `INV-00001` are assigned per user **when an invoice is issued**, so drafts use
  no number and issued numbers have no gaps and never change.
- Lines can come from the catalogue or be typed in manually. A fixed discount and one
  invoice-level tax rate (with an optional label such as "SST") are supported; no tax rules are built in.
- "Paid" is a manual, full-payment flag with a payment date. There are no payment records.
- Overdue is calculated (issued and past the due date), never stored.
- Printable invoice view (browser print).

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
- Every business table has a `user_id`. Queries start from the signed-in user's relationships
  (`$user->invoices()`, `$user->customers()`, …), never from unscoped model queries.
- Policies check ownership as route middleware, **before** form validation. Another user's
  record returns **404**, so record IDs cannot be probed.
- `user_id` is never mass-assignable or validated from input. Submitted customer and product IDs
  must belong to the signed-in user; otherwise validation fails.

### Invoice numbering
Numbers are assigned inside a database transaction that locks the user's row, so two invoices
issued at the same moment cannot get the same number. Unique `(user_id, invoice_number)` and
`(user_id, invoice_sequence)` indexes back this up. An invoice that already has a number is
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
11. **Run the tests** (see below).

If `php` on your PATH is not PHP 8.4, call the PHP 8.4 binary directly. For example, on the
Windows machine this project was developed on: `C:\php84\php.exe artisan test`. That path is a
local example, not a requirement.

### Production notes
For a real deployment, set at least `APP_ENV=production`, `APP_DEBUG=false`, the correct
`APP_URL`, and `SESSION_SECURE_COOKIE=true` when serving over HTTPS.

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
- CSV / Excel exports, PDF generation, emailed or scheduled reports, recurring invoices
- Multi-currency (amounts are recorded in MYR)
- REST API, mobile app, WhatsApp API, AI chatbot

Current limitations:

- There is no account deletion feature. Deleting a user who has invoices is intentionally
  blocked by the database: invoices restrict deleting the customers and products they reference.
- "Paid" means marked as paid in full by the user; there are no payment records.
- Reports and the dashboard show invoice totals including tax; tax is not reported separately.

## Development rules

See [CLAUDE.md](CLAUDE.md).
