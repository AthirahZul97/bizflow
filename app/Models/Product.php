<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * user_id is intentionally excluded: ownership is always set through the
     * authenticated user's products() relationship, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'sku',
        'description',
        'unit',
        'selling_price',
        'cost_price',
        'is_active',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Columns matched by the catalogue search.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['name', 'sku', 'description'];

    /**
     * Get the attributes that should be cast.
     *
     * Prices are cast to exact two-decimal strings, never floats.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the product or service.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the invoice lines created from this item. Their existence blocks deleting it.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Filter items whose name, SKU or description contains the term.
     *
     * The conditions are grouped so the ORs can never escape an outer user_id
     * constraint, and LIKE wildcards in the term are matched literally.
     *
     * @param  Builder<Product>  $query
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
     * Filter items by type.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeOfType(Builder $query, ProductType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Filter items by status. Only active items may be offered on new invoices.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query, bool $active = true): void
    {
        $query->where('is_active', $active);
    }

    /**
     * Format a price attribute for display, e.g. "RM 1,500.00".
     *
     * Delegates to the shared formatter; a missing (nullable) price returns null.
     */
    public function money(string $attribute): ?string
    {
        $amount = $this->getAttribute($attribute);

        return $amount === null ? null : Money::format($amount);
    }
}
