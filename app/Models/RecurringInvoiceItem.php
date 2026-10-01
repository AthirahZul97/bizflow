<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a recurring invoice template. Owned through its recurring invoice.
 */
class RecurringInvoiceItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'description',
        'unit',
        'quantity',
        'unit_price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * Amounts are cast to exact decimal strings, never floats.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * The catalogue item the line came from, if any. The line keeps its own values.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Format one of the line's amounts for display.
     */
    public function money(string $attribute): string
    {
        return Money::format($this->getAttribute($attribute));
    }
}
