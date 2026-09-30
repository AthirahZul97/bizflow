<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The tables whose rows belong to a business.
     */
    private const TABLES = ['customers', 'products', 'invoices', 'expenses'];

    /**
     * Run the migrations.
     *
     * Stage 2-3 of the tenancy migration: copy each row's owner onto business_id
     * using the user -> business owner mapping, then verify the result. Any failed
     * check throws before a destructive step runs, leaving only additive changes.
     */
    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                // The subquery reads business_user, never the table being updated, so
                // it is valid on MySQL. It errors if a user owned more than one business.
                DB::table($table)->whereNull('business_id')->update([
                    'business_id' => DB::raw(
                        "(select business_user.business_id from business_user where business_user.user_id = {$table}.user_id and business_user.role = 'owner')"
                    ),
                ]);
            }
        });

        $this->verify();
    }

    /**
     * Check that every row landed in its owner's business and that the keys that
     * become unique per business have no duplicates.
     */
    private function verify(): void
    {
        $failures = [];

        $usersWithoutOneMembership = DB::table('users')
            ->whereRaw('(select count(*) from business_user where business_user.user_id = users.id) <> 1')
            ->count();
        if ($usersWithoutOneMembership > 0) {
            $failures[] = "{$usersWithoutOneMembership} user(s) do not have exactly one business membership";
        }

        $businessesWithoutOneOwner = DB::table('businesses')
            ->whereRaw("(select count(*) from business_user where business_user.business_id = businesses.id and business_user.role = 'owner') <> 1")
            ->count();
        if ($businessesWithoutOneOwner > 0) {
            $failures[] = "{$businessesWithoutOneOwner} business(es) do not have exactly one owner";
        }

        foreach (self::TABLES as $table) {
            $missing = DB::table($table)->whereNull('business_id')->count();
            if ($missing > 0) {
                $failures[] = "{$table}: {$missing} row(s) have no business_id";
            }

            $orphans = DB::table($table)
                ->whereNotNull('business_id')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('businesses')
                    ->whereColumn('businesses.id', "{$table}.business_id"))
                ->count();
            if ($orphans > 0) {
                $failures[] = "{$table}: {$orphans} row(s) point to a business that does not exist";
            }

            $misplaced = DB::table($table)
                ->whereNotNull('business_id')
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('business_user')
                    ->whereColumn('business_user.business_id', "{$table}.business_id")
                    ->whereColumn('business_user.user_id', "{$table}.user_id")
                    ->where('business_user.role', 'owner'))
                ->count();
            if ($misplaced > 0) {
                $failures[] = "{$table}: {$misplaced} row(s) are not in their owner's business";
            }
        }

        foreach ([['products', 'sku'], ['invoices', 'invoice_number'], ['invoices', 'invoice_sequence']] as [$table, $column]) {
            $duplicates = DB::table($table)
                ->whereNotNull($column)
                ->select('business_id', $column)
                ->groupBy('business_id', $column)
                ->havingRaw('count(*) > 1')
                ->get()
                ->count();
            if ($duplicates > 0) {
                $failures[] = "{$table}: {$duplicates} duplicate {$column} value(s) within a business";
            }
        }

        if ($failures !== []) {
            throw new RuntimeException("Tenancy backfill verification failed:\n- ".implode("\n- ", $failures));
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately does nothing: the backfilled values live in the business_id
     * columns, which rolling back one step further drops. Production recovery is
     * restoring the pre-migration backup, not rolling back.
     */
    public function down(): void
    {
        //
    }
};
