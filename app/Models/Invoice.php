<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * Only the fields a user edits on a draft. Ownership, status, numbering,
     * currency, totals and the copied customer details are set by InvoiceService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'issue_date',
        'due_date',
        'discount_amount',
        'tax_label',
        'tax_rate',
        'notes',
    ];

    /**
     * Columns matched by the invoice search.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['invoice_number', 'customer_name', 'customer_company_name'];

    /**
     * Status filters offered on the invoice list; "overdue" is derived, not stored.
     *
     * @var list<string>
     */
    public const STATUS_FILTERS = ['draft', 'issued', 'overdue', 'paid', 'cancelled'];

    /**
     * Get the attributes that should be cast.
     *
     * Money is cast to exact two-decimal strings, never floats.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_sequence' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The original customer. Display the copied customer_* fields instead.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * An issued invoice past its due date. Derived from the dates, never stored.
     */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Issued && $this->due_date->isBefore(today());
    }

    /**
     * Filter invoices whose number, customer name or company contains the term.
     *
     * The conditions are grouped so the ORs can never escape an outer user_id
     * constraint, and LIKE wildcards in the term are matched literally.
     *
     * @param  Builder<Invoice>  $query
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
     * Filter by one of STATUS_FILTERS. "overdue" means issued and past the due date.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeFilterStatus(Builder $query, string $filter): void
    {
        if ($filter === 'overdue') {
            $query->where('status', InvoiceStatus::Issued)
                ->where('due_date', '<', today()->toDateString());

            return;
        }

        $query->where('status', InvoiceStatus::from($filter));
    }

    /**
     * Format a money attribute for display, e.g. "RM 1,500.00".
     */
    public function money(string $attribute): string
    {
        return Money::format($this->getAttribute($attribute));
    }

    /**
     * Get the copied billing address as a list of its filled-in lines.
     *
     * @return list<string>
     */
    public function customerAddressLines(): array
    {
        $locality = implode(' ', array_filter([$this->customer_postcode, $this->customer_city]));

        return array_values(array_filter([
            $this->customer_address_line_1,
            $this->customer_address_line_2,
            implode(', ', array_filter([$locality, $this->customer_state])),
            $this->customer_country,
        ]));
    }
}
