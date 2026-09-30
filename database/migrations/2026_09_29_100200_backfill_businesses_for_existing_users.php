<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Give every existing user exactly one business, named after them (the name
     * their invoices already show), with an owner membership. Users who already
     * have a membership are skipped, so running this again changes nothing.
     *
     * Uses the query builder rather than models, so it keeps working as the models change.
     */
    public function up(): void
    {
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) {
            DB::transaction(function () use ($users) {
                $members = DB::table('business_user')
                    ->whereIn('user_id', $users->pluck('id'))
                    ->pluck('user_id')
                    ->all();

                foreach ($users as $user) {
                    if (in_array($user->id, $members)) {
                        continue;
                    }

                    $now = now();

                    $businessId = DB::table('businesses')->insertGetId([
                        'name' => $user->name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('business_user')->insert([
                        'business_id' => $businessId,
                        'user_id' => $user->id,
                        'role' => 'owner',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately does nothing: backfilled businesses cannot be told apart from
     * businesses created at registration, so deleting "the backfilled ones" is not
     * safe. Rolling back further drops the business tables themselves. Production
     * recovery is restoring the pre-migration backup, not rolling back.
     */
    public function down(): void
    {
        //
    }
};
