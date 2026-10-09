<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One immutable version of a plan: platform catalogue data, shared by all businesses (so it
 * has no business_id and is the one table here that is not business data).
 *
 * The commercial terms (code, version, currency, price, interval, trial length and
 * entitlements) never change once a subscription references the row; change them by
 * inserting a new version. Retire a plan with is_active = false. Referenced rows are never
 * deleted.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * The terms a subscription depends on, frozen once the plan is referenced.
     *
     * @var list<string>
     */
    public const COMMERCIAL_FIELDS = [
        'code',
        'version',
        'currency',
        'price',
        'billing_interval',
        'trial_days',
        'entitlements',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'version',
        'name',
        'description',
        'currency',
        'price',
        'billing_interval',
        'trial_days',
        'entitlements',
        'is_active',
        'sort_order',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'currency' => 'MYR',
        'price' => '0.00',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (Plan $plan) {
            if ($plan->isDirty(self::COMMERCIAL_FIELDS) && $plan->isReferenced()) {
                throw new LogicException(
                    'The commercial terms of a plan that subscriptions use cannot change; create a new version instead.'
                );
            }
        });

        static::deleting(function (Plan $plan) {
            if ($plan->isReferenced()) {
                throw new LogicException('A plan that subscriptions use cannot be deleted; retire it instead.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price' => 'decimal:2',
            'billing_interval' => BillingInterval::class,
            'trial_days' => 'integer',
            'entitlements' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function pendingSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'pending_plan_id');
    }

    /**
     * Whether any subscription uses (or is scheduled to move to) this plan version.
     */
    public function isReferenced(): bool
    {
        return $this->exists && ($this->subscriptions()->exists() || $this->pendingSubscriptions()->exists());
    }

    /**
     * The newest active version of a plan code, or null when the code is unknown or retired.
     */
    public static function latestActive(string $code): ?self
    {
        return static::query()->where('code', $code)->where('is_active', true)->orderByDesc('version')->first();
    }

    /**
     * Only plans that can still be chosen.
     *
     * @param  Builder<Plan>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isFree(): bool
    {
        return bccomp((string) $this->price, '0', 2) === 0;
    }

    public function isPaid(): bool
    {
        return ! $this->isFree();
    }

    public function isLegacy(): bool
    {
        return $this->code === config('billing.plans.legacy');
    }

    public function isTrial(): bool
    {
        return $this->code === config('billing.plans.trial');
    }

    /**
     * The plan's value for an entitlement: an integer limit, null for unlimited, or a
     * boolean flag. A key the plan does not mention is denied, never allowed.
     */
    public function valueOf(Entitlement $entitlement): int|bool|null
    {
        $entitlements = $this->entitlements ?? [];

        if (! array_key_exists($entitlement->value, $entitlements)) {
            return $entitlement->default();
        }

        $value = $entitlements[$entitlement->value];

        if ($entitlement->isFlag()) {
            return $value === true;
        }

        // A limit is an integer or null (unlimited); anything else is malformed and denied.
        return $value === null ? null : (is_int($value) && $value >= 0 ? $value : 0);
    }
}
