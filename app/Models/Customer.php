<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * user_id is intentionally excluded: ownership is always set through the
     * authenticated user's customers() relationship, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company_name',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postcode',
        'country',
        'notes',
    ];

    /**
     * Columns matched by the customer search.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['name', 'company_name', 'email', 'phone'];

    /**
     * Get the user that owns the customer.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoices billed to the customer. Their existence blocks deleting the customer.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Filter customers whose name, company, email or phone contains the term.
     *
     * The conditions are grouped so the ORs can never escape an outer user_id
     * constraint, and LIKE wildcards in the term are matched literally.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern) {
            foreach (self::SEARCHABLE as $column) {
                $query->orWhereRaw("{$column} like ? escape '!'", [$pattern]);
            }
        });
    }

    /**
     * Get the full address as a list of its filled-in lines.
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
