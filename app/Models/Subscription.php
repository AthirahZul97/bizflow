<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a business's subscription history. Written only by SubscriptionService;
 * the stored status may lag the dates, so access is always derived by AccessResolver.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * Nothing is mass assignable: ownership (business_id), plan, status and dates are all set
     * by SubscriptionService, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'is_current' => 'integer',
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The plan this subscription moves to when its period ends, if a downgrade is scheduled.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * Audit metadata only: who made the change. Never used for authorization.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', 1);
    }

    public function isCurrent(): bool
    {
        return $this->is_current === 1;
    }

    /**
     * Whether this row started a trial. Trial history is read from here, so a business can
     * never get a second trial by changing subscription rows.
     */
    public function isTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }
}
