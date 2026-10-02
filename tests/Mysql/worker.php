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
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = getenv('DB_DATABASE') ?: '';

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
        'insert_current_raw' => (function () use ($args) {
            DB::table('subscriptions')->insert([
                'business_id' => $args['business_id'], 'plan_id' => $args['plan_id'], 'status' => 'active', 'is_current' => 1,
                'started_at' => now(), 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'inserted';
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
