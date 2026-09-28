<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Support\Money;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * user_id is intentionally excluded: ownership is always set through the
     * authenticated user's expenses() relationship, never from request input.
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
     * Get the user that owns the expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Filter expenses whose description or payee contains the term.
     *
     * The conditions are grouped so the ORs can never escape an outer user_id
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
