<?php

/**
 * A separate PHP process used by the opt-in MySQL concurrency tests (tests/Mysql).
 *
 * It boots the application against the scratch database named in the DB_DATABASE environment
 * variable (set by MysqlTestCase), runs ONE action and prints a single JSON line describing the
 * outcome. Real concurrency needs real separate connections, which one PHPUnit process
 * cannot provide. Never run this by hand.
 *
 * Usage: php worker.php '<json>'   where json = {"action": "...", ...arguments}
 */

use App\Billing\EntitlementGuard;
use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Exceptions\EntitlementException;
use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\ExpenseReceipt;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Services\ExpenseReceiptService;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Mysql\ScratchTarget;
use Tests\Support\ScriptedOcrProvider;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The parent passes the validated target explicitly. Validate it again here, before connecting,
// and ask the server what it actually reached; nothing runs if either check fails. Messages never
// contain credentials.
try {
    $target = ScratchTarget::fromEnvironment(ScratchTarget::processEnvironment());

    if ($target === null) {
        throw new InvalidArgumentException('no scratch target was passed to the worker');
    }

    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => array_merge(config('database.connections.mysql'), $target->connectionConfig()),
    ]);
    DB::purge('mysql');

    $server = DB::connection('mysql')->selectOne('select database() as name, @@port as port');
    $target->assertServerIdentity($server->name ?? null, $server->port ?? null);
} catch (InvalidArgumentException $e) {
    echo json_encode(['status' => 'error', 'message' => 'refusing to run: '.$e->getMessage()]);
    exit(1);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'refusing to run: the scratch target could not be verified ('.$e::class.')']);
    exit(1);
}

$database = $target->database;

// Belt and braces: this process must only ever talk to a scratch database.
if (! str_contains($database, 'scratch') || DB::connection()->getDatabaseName() !== $database) {
    echo json_encode(['status' => 'error', 'message' => 'refusing to run outside a scratch database']);
    exit(1);
}

$args = json_decode($argv[1] ?? '{}', true);
$started = microtime(true);

try {
    $business = isset($args['business_id']) ? Business::query()->findOrFail($args['business_id']) : null;

    $result = match ($args['action']) {
        'create_customer' => (function () use ($business, $args) {
            if ($args['memo_first'] ?? false) {
                // Memoize an "allowed" answer before waiting for the lock.
                app(EntitlementService::class)->for($business)->check(Entitlement::Customers);
            }

            app(EntitlementGuard::class)->create($business, Entitlement::Customers, fn () => $business->customers()->create(['name' => $args['name']]));

            return 'created';
        })(),
        'create_product' => (function () use ($business, $args) {
            app(EntitlementGuard::class)->create($business, Entitlement::Products, fn () => $business->products()->create([
                'name' => $args['name'], 'type' => 'product', 'selling_price' => '1.00', 'is_active' => true,
            ]));

            return 'created';
        })(),
        'issue_invoice' => (function () use ($args) {
            app(InvoiceService::class)->issue(Invoice::query()->findOrFail($args['invoice_id']));

            return 'issued';
        })(),
        'activate' => (function () use ($business, $args) {
            app(SubscriptionService::class)->activate($business, Plan::query()->findOrFail($args['plan_id']));

            return 'activated';
        })(),
        'change_plan' => (function () use ($business, $args) {
            app(SubscriptionService::class)->changePlan($business, Plan::query()->findOrFail($args['plan_id']));

            return 'changed';
        })(),
        'renew' => (function () use ($business, $args) {
            app(SubscriptionService::class)->renew($business, Subscription::query()->findOrFail($args['subscription_id']));

            return 'renewed';
        })(),
        'upload_receipt' => (function () use ($business, $args) {
            // Receipt files go to a temporary directory the test names (never the real storage
            // directory), and the OCR job is discarded: only the upload and its quota accounting run.
            config(['queue.default' => 'null', 'filesystems.disks.receipts.root' => $args['disk_root']]);
            Storage::forgetDisk('receipts');

            // A distinct, valid 1x1 PNG each time: the bytes are fixed, never passed as an argument.
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==').random_bytes(8);
            $temporary = tempnam(sys_get_temp_dir(), 'bizflow-upload-');
            file_put_contents($temporary, $png);

            try {
                app(ExpenseReceiptService::class)->upload(
                    $business,
                    User::query()->findOrFail($args['user_id']),
                    new UploadedFile($temporary, 'receipt.png', 'image/png', null, true),
                );
            } finally {
                @unlink($temporary);
            }

            return 'created';
        })(),
        'confirm_receipt' => (function () use ($business, $args) {
            [$expense, $created] = app(ExpenseReceiptService::class)->confirm(
                $business,
                $business->expenseReceipts()->findOrFail($args['receipt_id']),
                User::query()->findOrFail($args['user_id']),
                $args['values'],
            );

            return $created ? 'created' : 'existing';
        })(),
        'insert_current_raw' => (function () use ($args) {
            DB::table('subscriptions')->insert([
                'business_id' => $args['business_id'], 'plan_id' => $args['plan_id'], 'status' => 'active', 'is_current' => 1,
                'started_at' => now(), 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'inserted';
        })(),
        'recover_receipts' => (function () {
            // The sweeper (receipts:recover) as its own process; the dispatched jobs are discarded,
            // so only its database work runs. The outcome is "recovered:<redispatched>:<failed>:<errors>".
            config(['queue.default' => 'null']);

            $result = app(ExpenseReceiptService::class)->recoverStuck();

            return "recovered:{$result['redispatched']}:{$result['failed']}:{$result['dispatch_errors']}";
        })(),
        'claim_receipt' => (function () use ($args) {
            // A worker's claim() under the receipt's row lock; no provider is called.
            $claimed = app(ExpenseReceiptService::class)->claim((int) $args['receipt_id']);

            return $claimed === null ? 'not_claimed' : "claimed:{$claimed->attempts}";
        })(),
        'extract_receipt' => (function () use ($args) {
            // A worker that claimed earlier (the attempts and start the test passes, as claim()
            // returned them) and now saves its result, possibly after a sweep revoked the claim.
            $receipt = new ExpenseReceipt;
            $receipt->forceFill([
                'id' => (int) $args['receipt_id'],
                'attempts' => (int) $args['attempts'],
                'processing_started_at' => Carbon::parse($args['processing_started_at']),
            ]);

            $normalized = (new ReceiptExtractionNormalizer)->normalize(ScriptedOcrProvider::extraction());

            return app(ExpenseReceiptService::class)->markExtracted($receipt, $normalized) ? 'extracted' : 'ignored';
        })(),
        'reset_password' => (function () use ($args, $app) {
            // A password-reset submission as the real HTTP request it is: through the kernel, the web
            // middleware and the form request into NewPasswordController. (CSRF is skipped because this
            // process runs with APP_ENV=testing in the console, as in any feature test.) The outcome is
            // read from where the response sends the browser: /login when the password was reset, back
            // to the reset form when the link was refused.
            $request = Request::create('http://localhost/reset-password', 'POST', [
                'token' => $args['token'],
                'email' => $args['email'],
                'password' => $args['password'],
                'password_confirmation' => $args['password'],
            ]);
            $request->headers->set('Referer', 'http://localhost/reset-password/'.$args['token']);

            $response = $app->make(HttpKernel::class)->handle($request);
            $location = (string) $response->headers->get('Location');

            return match (true) {
                $response->getStatusCode() === 302 && str_ends_with($location, '/login') => 'reset',
                $response->getStatusCode() === 302 && str_contains($location, '/reset-password/') => 'rejected',
                default => 'unexpected:'.$response->getStatusCode().' '.$location,
            };
        })(),
        default => throw new InvalidArgumentException('unknown action'),
    };

    $outcome = ['status' => $result];
} catch (EntitlementException $e) {
    $outcome = ['status' => 'denied', 'message' => $e->getMessage()];
} catch (SubscriptionException $e) {
    $outcome = ['status' => 'refused', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    $outcome = ['status' => 'error', 'class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()];
}

echo json_encode($outcome + ['seconds' => round(microtime(true) - $started, 2)]);
