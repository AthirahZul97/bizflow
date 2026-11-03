# Deploying BizFlow

This describes how to run BizFlow as a **low-cost portfolio demo** and what a later commercial
deployment adds. Nothing here is automated: it is the checklist and the configuration the code
expects. Keep it current when the code changes.

BizFlow is not ready for a commercial launch (no real OCR provider, no payment provider, no approved
pricing, the SST decision is open). The demo profile is deliberately restrictive; see
[Demo mode](#demo-mode).

## Contents

1. [Runtime requirements](#1-runtime-requirements)
2. [Configuration profiles and the preflight check](#2-configuration-profiles-and-the-preflight-check)
3. [HTTPS, reverse proxy and HSTS](#3-https-reverse-proxy-and-hsts)
4. [Web server](#4-web-server)
5. [Build and release](#5-build-and-release)
6. [Queue worker and scheduler](#6-queue-worker-and-scheduler)
7. [Files, permissions and private receipt storage](#7-files-permissions-and-private-receipt-storage)
8. [Database and least privilege](#8-database-and-least-privilege)
9. [Backup and restore](#9-backup-and-restore)
10. [Health checks](#10-health-checks)
11. [Demo mode](#demo-mode) and the mandatory gates before it goes public
12. [Rollback](#12-rollback)
13. [Search engines and `robots.txt`](#13-search-engines-and-robotstxt)

## 1. Runtime requirements

- **PHP 8.4.1 or newer**, run as **PHP-FPM** behind the web server. `composer.json` requires
  `^8.4.1` because the locked Symfony 8 packages do; `composer check-platform-reqs --lock` is part
  of CI. An older PHP cannot install the lock.
- PHP extensions (from the lock file): `bcmath`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`,
  `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xml`,
  `xmlwriter` and `pdo_mysql`. `opcache` is recommended in production.
- **MySQL 8**. Node 22 is needed to build the frontend (not at runtime).
- **Upload limits.** `upload_max_filesize` and `post_max_size` must be at least the receipt limit,
  `ocr.max_upload_kb` (8 MB), or phone photos are rejected before BizFlow sees them. Set
  `post_max_size` a little higher than `upload_max_filesize`.
- `memory_limit` of at least 128M for the web workers (PDF rendering is the heaviest request).

## 2. Configuration profiles and the preflight check

Three profiles exist. Start from the matching example and fill the blanks; never commit the real `.env`.

| Profile | Example file | Purpose |
|---|---|---|
| local | `.env.example` | development; registration open, mail goes to the log |
| demo | `.env.demo.example` | public portfolio demo; restrictive by design |
| production | `.env.production.example` | commercial deployment (not usable yet) |

Run the check before every deploy and stop on a non-zero exit:

```bash
php artisan bizflow:preflight            # profile is detected; or --profile=demo|production|local
```

It is read-only: it reads configuration, never opens the database, and prints check names with fixed
messages, **never configured values**. It fails on, among others: `APP_DEBUG=true`, a non-HTTPS
`APP_URL`, session cookies that are not `Secure`, a mail transport the profile does not allow, the
demo OCR provider outside the demo profile, a missing app key and a profile that disagrees with
`BIZFLOW_DEMO_MODE`. Warnings (no HSTS yet, debug-level logging, an empty proxy list) do not fail it.
A test keeps both example files passing their own profile, so they cannot drift.

Always `php artisan config:cache` after deploying; `.env` is read only when the cache is built.

## 3. HTTPS, reverse proxy and HSTS

- Serve **only over HTTPS**, and redirect HTTP to HTTPS at the proxy or web server.
- **Trusted proxies.** The application trusts **nobody** by default, so a client cannot choose its
  own IP address, scheme or host with `X-Forwarded-*` headers. If a reverse proxy or load balancer
  sits in front, list it in `TRUSTED_PROXIES` (comma-separated IPs or CIDR ranges). Without that,
  the client IP is the proxy's, every visitor shares one login-throttle bucket, and the app does not
  see the request as HTTPS. Entries must be plain IP addresses or CIDR ranges of plausible size
  (IPv4 /8 or narrower, IPv6 /7 or narrower). Every spelling of "trust everyone" is dropped and
  reported, never trusted: `*`, `REMOTE_ADDR`, `0.0.0.0/0`, `::/0`, `0.0.0.0`, `::` and
  similar. A bare `*` only takes effect together with `TRUSTED_PROXIES_ALLOW_ALL=true`, and the demo and
  production preflight checks reject that as well (and any dropped entry): use it only if the
  application can be reached through the proxy alone. Only `X-Forwarded-For/Host/Port/Proto` are honoured.
- **Trusted host.** Outside the local environment only the host of `APP_URL` is accepted as the
  request host, which keeps absolute URLs (password-reset links) from being poisoned with a forged
  `Host` header. Monitoring that calls the server by IP address will be refused with a 400 error: call it by name.
- **HSTS** (`HSTS_MAX_AGE`) is sent only on a request that is verifiably HTTPS. Start with a short
  value (300 in the demo example, 86400 in the production example), confirm every page works, then
  raise it. Do not add `includeSubDomains` or `preload` until every subdomain is HTTPS.
- **Security headers** are added by the application to every response (nosniff, frame denial,
  referrer policy, permissions policy). The **Content-Security-Policy is report-only**: browsers
  log what it would block in the developer console and block nothing. Check the console on every
  main page in the browsers you care about before anyone enforces it. The receipt download keeps its own
  stricter headers.
- **What the headers cover, exactly.** They are added by the outermost middleware, so every response
  Laravel renders has them: pages, 404/405/419/429/500, an oversized POST (413) and the maintenance
  response. They do **not** cover anything produced before Laravel runs. Add the same headers at the web
  server with `add_header ... always` (nginx) or `Header always set` (Apache) for: a 413 from
  `client_max_body_size`, errors the web server itself answers (502/504 from PHP-FPM), and the
  **pre-rendered maintenance page** (`php artisan down --render=...`, `--redirect`, `--secret`), which is
  served by `public/index.php` before the framework boots. A header the application also sets must not be
  set twice: let the web server add it only when the response has none (nginx `add_header` does not
  merge with upstream headers, so mirror the application's values exactly).
- **Sessions after a password change.** Resetting (or changing) a password ends the account's other
  signed-in sessions: each session remembers a keyed hash of the password it signed in with and is logged
  out on the next request once it no longer matches. It works the same for the `file`, `database` and
  `array` session drivers; nothing is deleted per driver. A session cookie that is already stolen stops
  working at the next request after the reset.
- **First deploy of this protection.** A session that was signed in before it has no password hash to
  check, and is treated as untrustworthy rather than trusted: **every existing signed-in user is
  signed out once** and signs in again (the login page works normally, and the new session is
  protected from the start). Plan the deploy for a quiet moment; nothing is lost but the session.

## 4. Web server

Point the document root at `public/` and send everything else to `index.php`. Never expose the
project root, `.env`, `storage/` or `vendor/`.

Nginx (sketch):

```nginx
server {
    listen 443 ssl http2;
    server_name demo.example.com;
    root /var/www/bizflow/public;
    index index.php;

    client_max_body_size 10m;            # a little above the 8 MB receipt limit

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }   # .env, .git, ...
}
```

Apache: use the `public/.htaccess` that ships with the project, `AllowOverride All` on `public/`
only, and `Require all denied` for everything above it.

## 5. Build and release

`public/build` is **not** in git: build on every deploy.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci && npm run build
php artisan bizflow:preflight
php artisan migrate --force          # after a backup (see 9), with the migration user (see 8)
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Do **not** run `php artisan storage:link`: nothing is served from `storage/app/public`, and
receipts must never be reachable by URL.

## 6. Queue worker and scheduler

Both are required, not optional.

- **Queue worker**, kept running by a supervisor (systemd or supervisord): `php artisan queue:work
  --tries=3 --timeout=60`, restarted with `queue:restart` on every deploy. It runs receipt reading
  and invoice email jobs (and the account emails when email is on).
- **Scheduler**: one cron entry, every minute: `* * * * * cd /var/www/bizflow && php artisan schedule:run`.
  It runs `receipts:recover` (re-dispatches lost receipt jobs, fails exhausted ones), `receipts:prune`,
  `invoices:generate-recurring`, and `queue:prune-failed --hours=168` (deletes only old rows of the
  `failed_jobs` log; business records are never touched). Without the scheduler a receipt whose
  worker died stays in `processing`.

## 7. Files, permissions and private receipt storage

- The web user must write `storage/` and `bootstrap/cache/`, and nothing else. Application code
  should not be writable by the web user.
- Receipts live on the private `receipts` disk, `storage/app/receipts/{business}/{ulid}.{ext}`. They
  are only ever returned by the authenticated, policy-checked file route. Keep that directory
  outside the document root and include it in backups.
- Logs: `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=warning` (in the examples) keep the log
  from growing without limit and from recording debug detail.

## 8. Database and least privilege

Use two database accounts, neither of them `root`:

- **Application user**: `SELECT, INSERT, UPDATE, DELETE` on the BizFlow schema only. No `CREATE`,
  `ALTER`, `DROP`, `INDEX` or `GRANT`. This is the account in `.env`.
- **Migration user**: the extra schema privileges (`CREATE`, `ALTER`, `INDEX`, `DROP`, `REFERENCES`) on
  the same schema, used only while running `php artisan migrate --force` (supply its credentials
  for that command, not in the long-lived `.env`).

Template (adapt names and host; nothing here is executed by the project):

```sql
CREATE USER 'bizflow_app'@'10.0.0.%' IDENTIFIED BY '<long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON bizflow.* TO 'bizflow_app'@'10.0.0.%';
CREATE USER 'bizflow_migrate'@'10.0.0.%' IDENTIFIED BY '<another long random password>';
GRANT ALL PRIVILEGES ON bizflow.* TO 'bizflow_migrate'@'10.0.0.%';
```

Never reuse the demo database for anything else, and never point a demo or test at the real `bizflow`
database. Use TLS to the database if it is not on the same host.

## 9. Backup and restore

- Take a dump before **every** migration, and keep the dump and `storage/app/receipts/` together
  (a restored database without its receipt files has dangling receipts).
- **Restoring the backup is the recovery of record**; `migrate:rollback` is not a recovery plan.
- Test a restore into a scratch database before trusting a backup.

## 10. Health checks

- `/up`: the framework's liveness page. Does not touch the database.
- `/health`: readiness for a load balancer or uptime monitor. Returns `{"status":"ok"}` (200) or
  `{"status":"unavailable"}` (503) and **nothing else**: no environment, version, database or error
  text. The reason for a failure is in the log. Call it by the site's own host name. It runs outside
  the web middleware group, so it starts no session and sets no cookie, and it does not use the database
  or the default cache store: if database-backed sessions or cache are what failed, it still answers.
  It is limited to 60 requests a minute per IP (`HEALTH_LIMITER_STORE`, a local `file` store by default);
  over the limit it answers the same 503 body with `Retry-After`, so a monitor should not poll faster than
  once a few seconds.
- For operator diagnostics use `php artisan bizflow:preflight` and `php artisan about` on the server.

## Demo mode

`BIZFLOW_DEMO_MODE=true` makes the application strictly more restrictive. It is decided from the
environment and **never from a request**:

- self-service registration is closed (404);
- no invoice email is queued, claimed by the worker or delivered: refused before queueing, ended
  `skipped` if a row was queued earlier, and a final guard cancels any message on a real transport.
  A cancelled message is never recorded as sent;
- password reset and email verification are off (404) because they work by email;
- receipt reading uses the labelled demo placeholder (it does **not** read the file) and every page shows
  a demo banner; every response carries `X-Robots-Tag: noindex, nofollow`;
- subscription rules, tenant isolation and read-only enforcement are **unchanged**. Demo accounts can write
  within their own business and see nothing of any other.

### Mandatory gates before the demo is public

None of these exist in the code yet. **Do not make the demo reachable until each is done and written down:**

1. **Provisioning.** Demo accounts are created by the operator, not by visitors. Decide how (a one-off
   seeder or an operator command, through `BusinessRegistration`, so each gets its own business, owner
   membership and trial subscription). One business per account, never a shared one; credentials are
   random and handed out individually. No default or documented password.
2. **Isolation check.** After provisioning, log in as two demo accounts and confirm neither can open
   the other's customers, invoices, expenses or receipts (all 404). The automated cross-business suite
   must pass on the deployed commit.
3. **Cleanup.** Decide how long demo data lives. Receipts older than the retention period are
   pruned by `receipts:prune`; everything else stays until reset. Decide whether uploaded receipt files
   in `storage/app/receipts` are cleared with it.
4. **Safe reset.** A reset must act on an explicit list of demo businesses only, never by pattern and
   never from request input, after a backup, and must also remove their receipt files. It needs its own
   review and tests before it exists. Until it does, rebuild the demo database from a backup.
5. **Content.** Seed only invented data. The banner already tells visitors not to enter real
   information; do not rely on it alone.

### Demo limitations

Emails are not sent, receipts are not read, billing is manual, and data may be reset. It is a portfolio
exhibit, not a service.

## 12. Rollback

1. Put the previous release's code back (keep the last release directory), run `composer install`
   there if needed and `php artisan config:cache`, then `php artisan queue:restart`.
2. If the release ran a migration, **restore the pre-migration database backup**; do not rely on
   `migrate:rollback`. Restore the receipt files from the same point.
3. Re-run `php artisan bizflow:preflight` on the rolled-back release.
4. Configuration-only problems (a wrong `.env`) are fixed by restoring the previous `.env` and
   rebuilding the config cache.

## 13. Search engines and `robots.txt`

`public/robots.txt` says `Disallow: /`, and the demo sends `X-Robots-Tag: noindex, nofollow`. Both are
requests that well-behaved crawlers honour. **They are not access control**: anything reachable by
URL can still be fetched. What actually protects the data is authentication, tenant scoping and the
policies, not these files.
