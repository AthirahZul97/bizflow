<?php

namespace App\Models;

use App\Enums\BusinessRole;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The tenant. Every customer, product, invoice and expense belongs to exactly one
 * business through business_id, and business data is always queried from here
 * (e.g. $business->invoices()), never from a user.
 */
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable: the business profile.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'registration_number',
        'sst_number',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postcode',
        'country',
    ];

    /**
     * Get the users who belong to the business, with their role.
     *
     * @return BelongsToMany<User, $this, BusinessMembership>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(BusinessMembership::class)
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * Whether the user is a member of this business with the given role.
     */
    public function hasMember(User $user, ?BusinessRole $role = null): bool
    {
        $members = $this->members()->whereKey($user->getKey());

        // Not ->when(): its closure receives the underlying builder, which has no wherePivot().
        if ($role !== null) {
            $members->wherePivot('role', $role->value);
        }

        return $members->exists();
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<ExpenseReceipt, $this>
     */
    public function expenseReceipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class);
    }

    /**
     * @return HasMany<RecurringInvoice, $this>
     */
    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }

    /**
     * The business's whole subscription history, current and past.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one subscription that is current (is_current = 1), if any. Access is not read
     * from it directly: use App\Billing\EntitlementService.
     *
     * @return HasOne<Subscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('is_current', 1);
    }

    /**
     * Get the address as a list of its filled-in lines, formatted like a customer address.
     *
     * @return list<string>
     */
    public function addressLines(): array
    {
        $locality = implode(' ', array_filter([$this->postcode, $this->city]));

        return array_values(array_filter([
            $this->address_line_1,
            $this->address_line_2,
            implode(', ', array_filter([$locality, $this->state])),
            $this->country,
        ]));
    }
}
