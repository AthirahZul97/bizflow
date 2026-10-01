<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringInvoiceStatus;
use App\Support\RecurringSchedule;
use Carbon\CarbonInterface;
use Database\Factories\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A schedule and template that generates ordinary draft invoices. It is not an
 * invoice itself, and changing it never changes invoices it already generated.
 */
class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * Only the plain template fields. Ownership (business_id), created_by, the
     * customer, status, schedule and occurrence pointers are set by
     * RecurringInvoiceService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'payment_terms_days',
        'discount_amount',
        'tax_label',
        'tax_rate',
        'notes',
    ];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_amount' => '0.00',
        'tax_rate' => '0.00',
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
            'status' => RecurringInvoiceStatus::class,
            'frequency' => RecurringFrequency::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'next_occurrence_on' => 'date',
            'last_occurrence_on' => 'date',
            'payment_terms_days' => 'integer',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'last_generated_at' => 'datetime',
            'last_generation_failed_at' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the business that owns the recurring invoice.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The customer billed. Their current details are copied onto each generated invoice.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the person who created the schedule. Audit metadata only: NULL means it
     * has no associated person. Never use it for authorization or tenant filtering.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RecurringInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceItem::class)->orderBy('position');
    }

    /**
     * The invoices generated from this schedule.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Active schedules with an occurrence due on or before the given date and not
     * past their (inclusive) end date.
     *
     * Uses "before the next day" so it matches whether the driver stores a bare
     * DATE (MySQL) or a date with a time (SQLite).
     *
     * @param  Builder<RecurringInvoice>  $query
     */
    public function scopeDue(Builder $query, CarbonInterface $today): void
    {
        $query->where('status', RecurringInvoiceStatus::Active)
            ->where('next_occurrence_on', '<', $today->copy()->addDay()->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('end_date')
                ->orWhereColumn('next_occurrence_on', '<=', 'end_date'));
    }

    public function schedule(): RecurringSchedule
    {
        return new RecurringSchedule($this->frequency, $this->start_date->toImmutable());
    }

    public function isActive(): bool
    {
        return $this->status === RecurringInvoiceStatus::Active;
    }

    public function isPaused(): bool
    {
        return $this->status === RecurringInvoiceStatus::Paused;
    }

    public function isCancelled(): bool
    {
        return $this->status === RecurringInvoiceStatus::Cancelled;
    }

    /**
     * Whether every occurrence up to the (inclusive) end date has been generated.
     */
    public function isFinished(): bool
    {
        return $this->end_date !== null && $this->next_occurrence_on->gt($this->end_date);
    }

    /**
     * Whether any occurrence has ever been generated. Once it has, the start date
     * and frequency are fixed and the schedule can only be cancelled, not deleted.
     */
    public function hasGenerated(): bool
    {
        return $this->last_occurrence_on !== null;
    }

    /**
     * How many occurrences are due as of the given date (0 unless active).
     */
    public function dueCount(CarbonInterface $today): int
    {
        if (! $this->isActive() || $this->isFinished()) {
            return 0;
        }

        $until = $this->end_date !== null && $this->end_date->lt($today) ? $this->end_date : $today;

        return $this->schedule()->countBetween($this->next_occurrence_on, $until);
    }

    /**
     * Whether an occurrence is due as of the given date.
     */
    public function isDue(CarbonInterface $today): bool
    {
        return $this->dueCount($today) > 0;
    }
}
