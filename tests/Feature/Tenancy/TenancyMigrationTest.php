<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the Phase 2A tenancy migrations against data written in the old
 * user_id-owned schema. Deliberately does not use RefreshDatabase: each test
 * builds the old schema itself, seeds it, then runs the remaining migrations.
 */
class TenancyMigrationTest extends TestCase
{
    /**
     * The migrations that existed before Phase 2A.
     */
    private const LEGACY_MIGRATIONS = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_09_28_150000_create_customers_table.php',
        'database/migrations/2026_09_28_160000_create_products_table.php',
        'database/migrations/2026_09_28_170000_create_invoices_table.php',
        'database/migrations/2026_09_28_170100_create_invoice_items_table.php',
        'database/migrations/2026_09_28_180000_create_expenses_table.php',
    ];

    /**
     * Legacy user IDs seeded by seedLegacyData().
     *
     * @var array<string, int>
     */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--path' => self::LEGACY_MIGRATIONS, '--force' => true]);
        $this->assertFalse(Schema::hasTable('businesses'));
        $this->assertTrue(Schema::hasColumn('customers', 'user_id'));
    }

    public function test_every_existing_user_gets_exactly_one_business_with_an_owner_membership(): void
    {
        $this->seedLegacyData();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(3, DB::table('businesses')->count());
        foreach ($this->users as $name => $userId) {
            $memberships = DB::table('business_user')->where('user_id', $userId)->get();
            $this->assertCount(1, $memberships, "{$name} has exactly one membership");
            $this->assertSame('owner', $memberships[0]->role);
            // The business is named after the user, which is what their invoices showed before.
            $this->assertSame($name, DB::table('businesses')->where('id', $memberships[0]->business_id)->value('name'));
        }
        // Including the user who had no records at all.
        $this->assertNotNull($this->businessIdOf('Carol'));
    }

    public function test_every_record_moves_to_its_owners_business_and_none_are_lost(): void
    {
        $this->seedLegacyData();
        $before = [];
        foreach (['customers', 'products', 'invoices', 'expenses'] as $table) {
            $before[$table] = DB::table($table)->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id')->all();
        }
        $itemsBefore = DB::table('invoice_items')->count();

        Artisan::call('migrate', ['--force' => true]);

        foreach ($before as $table => $countsByUser) {
            $this->assertSame(0, DB::table($table)->whereNull('business_id')->count(), "{$table} has no row without a business");
            foreach ($countsByUser as $userId => $total) {
                $this->assertSame((int) $total, DB::table($table)->where('business_id', $this->businessIdOfUser($userId))->count(), "{$table} rows of user {$userId}");
            }
            $this->assertSame(array_sum($countsByUser), DB::table($table)->count());
        }
        $this->assertSame($itemsBefore, DB::table('invoice_items')->count());
    }

    public function test_created_by_keeps_the_original_creator_and_user_id_is_gone_from_master_data(): void
    {
        $this->seedLegacyData();
        $invoiceCreators = DB::table('invoices')->pluck('user_id', 'id')->all();
        $expenseCreators = DB::table('expenses')->pluck('user_id', 'id')->all();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertEquals($invoiceCreators, DB::table('invoices')->pluck('created_by', 'id')->all());
        $this->assertEquals($expenseCreators, DB::table('expenses')->pluck('created_by', 'id')->all());
        foreach (['customers', 'products', 'invoices', 'expenses'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'user_id'), "{$table}.user_id was removed");
        }
    }

    public function test_invoice_numbers_are_unchanged_and_now_unique_per_business(): void
    {
        $this->seedLegacyData();
        $numbers = DB::table('invoices')->orderBy('id')->get(['id', 'invoice_number', 'invoice_sequence'])->map(fn ($row) => (array) $row)->all();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($numbers, DB::table('invoices')->orderBy('id')->get(['id', 'invoice_number', 'invoice_sequence'])->map(fn ($row) => (array) $row)->all());

        // Alice and Bob both already had INV-00001; that stays valid across businesses...
        $this->assertSame(2, DB::table('invoices')->where('invoice_number', 'INV-00001')->distinct()->count('business_id'));

        // ...but a second INV-00001 in the same business is rejected.
        $alice = DB::table('invoices')->where('business_id', $this->businessIdOf('Alice'))->where('invoice_number', 'INV-00001')->first();
        $this->assertThrows(fn () => DB::table('invoices')->insert(array_merge((array) $alice, ['id' => null, 'invoice_sequence' => 99])), QueryException::class);
        $this->assertThrows(fn () => DB::table('invoices')->insert(array_merge((array) $alice, ['id' => null, 'invoice_number' => 'INV-00099'])), QueryException::class);
    }

    public function test_business_id_is_required_and_sku_uniqueness_is_per_business(): void
    {
        $this->seedLegacyData();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertThrows(fn () => DB::table('customers')->insert(['business_id' => null, 'name' => 'No business']), QueryException::class);

        // Alice and Bob both had SKU WEB-001 before; still fine. Alice cannot have it twice.
        $this->assertSame(2, DB::table('products')->where('sku', 'WEB-001')->count());
        $this->assertThrows(fn () => DB::table('products')->insert([
            'business_id' => $this->businessIdOf('Alice'), 'type' => 'service', 'name' => 'Copy', 'sku' => 'WEB-001', 'selling_price' => '1.00',
        ]), QueryException::class);
        DB::table('products')->insert([
            'business_id' => $this->businessIdOf('Carol'), 'type' => 'service', 'name' => 'Carol Web', 'sku' => 'WEB-001', 'selling_price' => '1.00',
        ]);
        $this->assertSame(3, DB::table('products')->where('sku', 'WEB-001')->count());
    }

    public function test_an_invoice_cannot_reference_another_businesss_customer_at_database_level(): void
    {
        $this->seedLegacyData();

        Artisan::call('migrate', ['--force' => true]);

        $invoice = (array) DB::table('invoices')->where('business_id', $this->businessIdOf('Alice'))->first();
        $bobsCustomer = DB::table('customers')->where('business_id', $this->businessIdOf('Bob'))->value('id');

        $this->assertThrows(fn () => DB::table('invoices')->where('id', $invoice['id'])->update(['customer_id' => $bobsCustomer]), QueryException::class);
        $this->assertThrows(fn () => DB::table('invoices')->insert(array_merge($invoice, [
            'id' => null, 'customer_id' => $bobsCustomer, 'invoice_number' => null, 'invoice_sequence' => null, 'status' => 'draft',
        ])), QueryException::class);
    }

    public function test_deleting_users_no_longer_cascades_to_business_data(): void
    {
        $this->seedLegacyData();

        Artisan::call('migrate', ['--force' => true]);

        // The owner's membership blocks deleting them.
        $this->assertThrows(fn () => DB::table('users')->where('id', $this->users['Alice'])->delete(), QueryException::class);
        $this->assertSame(1, DB::table('users')->where('id', $this->users['Alice'])->count());

        // A creator who is not a member can be deleted; their records stay, created_by is cleared.
        DB::table('business_user')->where('user_id', $this->users['Bob'])->delete();
        $bobsBusiness = DB::table('businesses')->where('name', 'Bob')->value('id');
        $invoices = DB::table('invoices')->where('business_id', $bobsBusiness)->count();
        DB::table('users')->where('id', $this->users['Bob'])->delete();

        $this->assertSame($invoices, DB::table('invoices')->where('business_id', $bobsBusiness)->count());
        $this->assertSame(0, DB::table('invoices')->where('business_id', $bobsBusiness)->whereNotNull('created_by')->count());
        $this->assertSame(0, DB::table('expenses')->where('business_id', $bobsBusiness)->whereNotNull('created_by')->count());
        $this->assertGreaterThan(0, DB::table('customers')->where('business_id', $bobsBusiness)->count());

        // A business that still has data cannot be deleted.
        $this->assertThrows(fn () => DB::table('businesses')->where('id', $bobsBusiness)->delete(), QueryException::class);
    }

    public function test_the_migration_on_an_empty_database_creates_nothing(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, DB::table('businesses')->count());
        $this->assertSame(0, DB::table('business_user')->count());
    }

    public function test_the_backfill_refuses_to_continue_when_a_record_has_no_owner_business(): void
    {
        $this->seedLegacyData();
        $this->migrateTo('2026_09_29_100300_add_business_id_to_owned_tables.php');

        // Break the mapping for one user before the business_id backfill runs.
        DB::table('business_user')->where('user_id', $this->users['Alice'])->delete();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('The backfill should have refused to continue.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tenancy backfill verification failed', $e->getMessage());
            $this->assertStringContainsString('do not have exactly one business membership', $e->getMessage());
            $this->assertStringContainsString('row(s) have no business_id', $e->getMessage());
        }

        // Nothing destructive ran: user_id is still there and business_id is still nullable.
        $this->assertTrue(Schema::hasColumn('customers', 'user_id'));
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_09_29_100500_enforce_business_ownership_on_owned_tables')->exists());
    }

    public function test_the_backfill_of_businesses_can_run_again_without_duplicating(): void
    {
        $this->seedLegacyData();
        $this->migrateTo('2026_09_29_100200_backfill_businesses_for_existing_users.php');

        (require base_path('database/migrations/2026_09_29_100200_backfill_businesses_for_existing_users.php'))->up();

        $this->assertSame(3, DB::table('businesses')->count());
        $this->assertSame(3, DB::table('business_user')->count());
    }

    public function test_the_destructive_migrations_refuse_to_roll_back(): void
    {
        $this->seedLegacyData();
        Artisan::call('migrate', ['--force' => true]);

        try {
            Artisan::call('migrate:rollback', ['--force' => true]);
            $this->fail('Rolling back should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Restore the pre-migration database backup', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('customers', 'user_id'));
    }

    /**
     * Run the Phase 2A migrations up to and including the given file.
     */
    private function migrateTo(string $lastFile): void
    {
        $files = collect(glob(base_path('database/migrations/2026_09_29_*.php')))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->sort()
            ->filter(fn (string $path) => basename($path) <= $lastFile)
            ->values()
            ->all();

        Artisan::call('migrate', ['--path' => $files, '--force' => true]);
    }

    /**
     * Three users in the old schema: Alice and Bob with records (including the
     * same invoice number and SKU), Carol with none.
     */
    private function seedLegacyData(): void
    {
        $now = now();
        foreach (['Alice', 'Bob', 'Carol'] as $name) {
            $this->users[$name] = DB::table('users')->insertGetId([
                'name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (['Alice' => 3, 'Bob' => 2] as $name => $invoiceCount) {
            $userId = $this->users[$name];
            $customerId = DB::table('customers')->insertGetId(['user_id' => $userId, 'name' => "{$name} Customer", 'created_at' => $now, 'updated_at' => $now]);
            DB::table('customers')->insert(['user_id' => $userId, 'name' => "{$name} Second Customer", 'created_at' => $now, 'updated_at' => $now]);
            $productId = DB::table('products')->insertGetId([
                'user_id' => $userId, 'type' => 'service', 'name' => "{$name} Web", 'sku' => 'WEB-001', 'selling_price' => '100.00', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('products')->insert([
                'user_id' => $userId, 'type' => 'product', 'name' => "{$name} Old", 'sku' => null, 'selling_price' => '5.00', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);

            for ($i = 1; $i <= $invoiceCount; $i++) {
                $invoiceId = DB::table('invoices')->insertGetId([
                    'user_id' => $userId, 'customer_id' => $customerId,
                    'invoice_sequence' => $i, 'invoice_number' => sprintf('INV-%05d', $i),
                    'status' => $i === 1 ? 'paid' : 'issued', 'issue_date' => '2026-09-01', 'due_date' => '2026-10-01',
                    'currency_code' => 'MYR', 'customer_name' => "{$name} Customer",
                    'subtotal' => '100.00', 'total' => '100.00', 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('invoice_items')->insert([
                    'invoice_id' => $invoiceId, 'product_id' => $productId, 'name' => "{$name} Web",
                    'quantity' => '1.00', 'unit_price' => '100.00', 'line_total' => '100.00', 'position' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            // A draft has no number.
            DB::table('invoices')->insert([
                'user_id' => $userId, 'customer_id' => $customerId, 'status' => 'draft', 'issue_date' => '2026-09-20', 'due_date' => '2026-10-20',
                'currency_code' => 'MYR', 'customer_name' => "{$name} Customer", 'subtotal' => '0.00', 'total' => '0.00', 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('expenses')->insert([
                ['user_id' => $userId, 'expense_date' => '2026-09-05', 'category' => 'rent', 'description' => "{$name} rent", 'amount' => '1000.00', 'created_at' => $now, 'updated_at' => $now],
                ['user_id' => $userId, 'expense_date' => '2026-09-06', 'category' => 'office', 'description' => "{$name} pens", 'amount' => '3.00', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    private function businessIdOf(string $name): ?int
    {
        return $this->businessIdOfUser($this->users[$name]);
    }

    private function businessIdOfUser(int|string $userId): ?int
    {
        $id = DB::table('business_user')->where('user_id', $userId)->where('role', 'owner')->value('business_id');

        return $id === null ? null : (int) $id;
    }
}
