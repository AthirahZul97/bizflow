<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Support\Money;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * Largest value the DECIMAL(15,2) amount column can hold.
     */
    public const MAX_AMOUNT = '9999999999999.99';

    /**
     * The attributes that are mass assignable.
     *
     * business_id and created_by are intentionally excluded: ownership is always set
     * through the current business's expenses() relationship and created_by by the
     * controller, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'expense_date',
        'category',
        'description',
        'amount',
        'payee',
        'notes',
    ];

    /**
     * Columns matched by the expense search.
     *
     * @var list<string>
     */
    private const SEARCHABLE = ['description', 'payee'];

    /**
     * Get the attributes that should be cast.
     *
     * The amount is cast to an exact two-decimal string, never a float.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Get the business that owns the expense.
     *
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the person who recorded the expense. Audit metadata only: NULL means it was
     * system-generated or has no associated person. Never use it for authorization
     * or tenant filtering; ownership is business_id.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The receipt this expense was created from, if it came from a scanned receipt.
     *
     * @return HasOne<ExpenseReceipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(ExpenseReceipt::class);
    }

    /**
     * Filter expenses whose description or payee contains the term.
     *
     * The conditions are grouped so the ORs can never escape an outer business_id
     * constraint, and LIKE wildcards in the term are matched literally.
     *
     * @param  Builder<Expense>  $query
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
     * Format the amount for display, e.g. "RM 1,500.00".
     */
    public function money(): string
    {
        return Money::format($this->amount);
    }
}
