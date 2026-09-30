<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Support\CurrentBusiness;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExpenseController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Authorize every action through ExpensePolicy.
     *
     * Running as middleware means ownership is checked before an ExpenseRequest
     * is validated, so another business's expense returns 404, never validation errors.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Expense::class, only: ['index']),
            new Middleware('can:create,'.Expense::class, only: ['create', 'store']),
            new Middleware('can:view,expense', only: ['show']),
            new Middleware('can:update,expense', only: ['edit', 'update']),
            new Middleware('can:delete,expense', only: ['delete', 'destroy']),
        ];
    }

    /**
     * List the current business's expenses with optional search, category and date filters,
     * plus the count and total of everything matching those filters.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $search = is_string($search) ? Str::limit(trim($search), 100, '') : '';

        $category = $request->query('category');
        $category = is_string($category) ? ExpenseCategory::tryFrom($category) : null;

        $from = $this->dateFilter($request->query('from'));
        $to = $this->dateFilter($request->query('to'));

        $query = $this->currentBusiness->get()->expenses()
            ->search($search)
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($from, fn ($query) => $query->where('expense_date', '>=', $from))
            // "To" is inclusive: compare against the next day so it holds whether the
            // driver stores a bare date (MySQL) or a date with a time (SQLite), and stays index-friendly.
            ->when($to, fn ($query) => $query->where('expense_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $count = (clone $query)->count();
        $total = Money::format((string) BigDecimal::of((string) ((clone $query)->sum('amount') ?: '0'))->toScale(2));

        $expenses = $query
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $filtered = $search !== '' || $category !== null || $from !== null || $to !== null;

        return view('expenses.index', compact('expenses', 'search', 'category', 'from', 'to', 'filtered', 'count', 'total'));
    }

    /**
     * Show the form for recording an expense, dated today by default.
     */
    public function create(): View
    {
        return view('expenses.create', ['expense' => new Expense(['expense_date' => today()])]);
    }

    /**
     * Store an expense owned by the current business, recording who entered it.
     */
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = $this->currentBusiness->get()->expenses()->make($request->validated());
        $expense->forceFill(['created_by' => $request->user()->getKey()])->save();

        return redirect()->route('expenses.show', $expense)
            ->with('status', 'Expense recorded.');
    }

    /**
     * Show an expense.
     */
    public function show(Expense $expense): View
    {
        return view('expenses.show', compact('expense'));
    }

    /**
     * Show the form for editing an expense.
     */
    public function edit(Expense $expense): View
    {
        return view('expenses.edit', compact('expense'));
    }

    /**
     * Update an expense.
     */
    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $expense->update($request->validated());

        return redirect()->route('expenses.show', $expense)
            ->with('status', 'Expense updated.');
    }

    /**
     * Ask for confirmation before deleting an expense. This action never deletes.
     */
    public function delete(Expense $expense): View
    {
        return view('expenses.delete', compact('expense'));
    }

    /**
     * Permanently delete an expense.
     */
    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return redirect()->route('expenses.index')
            ->with('status', 'Expense deleted.');
    }

    /**
     * Accept a real Y-m-d calendar date; anything else is ignored.
     */
    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
