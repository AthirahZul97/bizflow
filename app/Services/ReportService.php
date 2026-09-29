<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Enums\InvoiceStatus;
use App\Models\User;
use App\Support\Money;
use App\Support\ReportingPeriod;
use App\Support\SqlMonth;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Read-only report aggregates. Every query starts from the user's own invoices()
 * or expenses() relationship; nothing here reads another user's rows.
 *
 * The money definitions are the Dashboard's:
 *  - Received:    paid invoices, by paid_at, in the period.
 *  - Invoiced:    issued + paid invoices, by issue_date, in the period.
 *  - Expenses:    expenses, by expense_date, in the period.
 *  - Net cash:    Received − Expenses. An estimate, not accounting profit.
 *  - Outstanding: issued invoices as of today; overdue = due_date before today.
 * Drafts and cancelled invoices never count towards any money figure. Date ranges
 * are half-open (>= start, < day after end); sums are normalised with Money::fromSql().
 */
class ReportService
{
    public const PER_PAGE = 25;

    public const INVOICE_VIEWS = ['invoiced', 'received', 'outstanding'];

    public const INVOICE_STATUS_FILTERS = ['unpaid', 'overdue', 'paid'];

    /**
     * Columns shown in invoice report tables.
     *
     * @var list<string>
     */
    private const INVOICE_COLUMNS = ['id', 'invoice_number', 'status', 'customer_name', 'customer_company_name', 'issue_date', 'due_date', 'paid_at', 'total'];

    /**
     * Period totals, a month-by-month breakdown of the period, and the position as of today.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, ReportingPeriod $period): array
    {
        $received = $this->countAndSum($this->receivedQuery($user, $period), 'total');
        $invoiced = $this->countAndSum($this->invoicedQuery($user, $period), 'total');
        $expenses = $this->countAndSum($this->expensesQuery($user, $period), 'amount');

        $receivedByMonth = $this->monthly($this->receivedQuery($user, $period), 'paid_at', 'total');
        $invoicedByMonth = $this->monthly($this->invoicedQuery($user, $period), 'issue_date', 'total');
        $expensesByMonth = $this->monthly($this->expensesQuery($user, $period), 'expense_date', 'amount');

        $empty = ['count' => 0, 'amount' => '0.00'];
        $months = [];
        for ($month = $period->start->startOfMonth(); $month->lessThanOrEqualTo($period->end); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $from = $month->max($period->start);
            $to = $month->endOfMonth()->startOfDay()->min($period->end);
            $in = $receivedByMonth[$key] ?? $empty;
            $out = $expensesByMonth[$key] ?? $empty;

            $months[] = [
                'key' => $key,
                'label' => $from->isSameDay($month) && $to->isSameDay($month->endOfMonth()->startOfDay())
                    ? $month->format('M Y')
                    : ReportingPeriod::rangeLabel($from, $to),
                'received' => $in,
                'invoiced' => $invoicedByMonth[$key] ?? $empty,
                'expenses' => $out,
                'net' => (string) BigDecimal::of($in['amount'])->minus($out['amount']),
            ];
        }

        return [
            'received' => $received,
            'invoiced' => $invoiced,
            'expenses' => $expenses,
            'netCash' => (string) BigDecimal::of($received['amount'])->minus($expenses['amount']),
            'months' => $months,
            'position' => $this->position($user, today()->toDateString()),
        ];
    }

    /**
     * One row per customer (grouped by customer_id) with invoiced and received
     * amounts for the period and outstanding/overdue amounts as of today.
     *
     * The displayed name is the customer details copied onto that customer's most
     * recent invoice in the result, never the live Customer record.
     *
     * @return array{rows: LengthAwarePaginator, names: Collection, totals: array<string, mixed>}
     */
    public function customers(User $user, ReportingPeriod $period): array
    {
        $today = today()->toDateString();
        [$start, $end] = [$period->startDate(), $period->endExclusive()];

        $sums = 'coalesce(sum(case when issue_date >= ? and issue_date < ? then 1 else 0 end), 0) as invoiced_count,
            coalesce(sum(case when issue_date >= ? and issue_date < ? then total end), 0) as invoiced,
            coalesce(sum(case when status = ? and paid_at >= ? and paid_at < ? then total end), 0) as received,
            coalesce(sum(case when status = ? then total end), 0) as outstanding,
            coalesce(sum(case when status = ? and due_date < ? then total end), 0) as overdue';
        $bindings = [
            $start, $end,
            $start, $end,
            InvoiceStatus::Paid->value, $start, $end,
            InvoiceStatus::Issued->value,
            InvoiceStatus::Issued->value, $today,
        ];

        $rows = $this->customerQuery($user, $start, $end)
            ->selectRaw('customer_id, max(id) as latest_invoice_id, '.$sums, $bindings)
            ->groupBy('customer_id')
            ->havingRaw('invoiced_count > 0 or received <> 0 or outstanding <> 0')
            ->orderByDesc('invoiced')
            ->orderByDesc('received')
            ->orderBy('customer_id')
            ->toBase()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->setCollection($rows->getCollection()->map(fn ($row) => (object) [
            'customer_id' => (int) $row->customer_id,
            'latest_invoice_id' => (int) $row->latest_invoice_id,
            'invoiced_count' => (int) $row->invoiced_count,
            'invoiced' => Money::fromSql($row->invoiced),
            'received' => Money::fromSql($row->received),
            'outstanding' => Money::fromSql($row->outstanding),
            'overdue' => Money::fromSql($row->overdue),
        ]));

        $names = $user->invoices()
            ->whereIn('id', $rows->getCollection()->pluck('latest_invoice_id')->all())
            ->get(['id', 'customer_name', 'customer_company_name'])
            ->keyBy('id');

        $totals = $this->customerQuery($user, $start, $end)->selectRaw($sums, $bindings)->toBase()->first();

        return [
            'rows' => $rows,
            'names' => $names,
            'totals' => [
                'invoiced_count' => (int) $totals->invoiced_count,
                'invoiced' => Money::fromSql($totals->invoiced),
                'received' => Money::fromSql($totals->received),
                'outstanding' => Money::fromSql($totals->outstanding),
                'overdue' => Money::fromSql($totals->overdue),
            ],
        ];
    }

    /**
     * Invoices issued in the period, paid in the period, or outstanding now,
     * with a total for the whole filtered set and, for outstanding, ageing buckets.
     *
     * @return array<string, mixed>
     */
    public function invoices(User $user, ReportingPeriod $period, string $view, ?string $status): array
    {
        $today = today()->toImmutable();

        $query = match ($view) {
            'received' => $this->receivedQuery($user, $period),
            'outstanding' => $user->invoices()->where('status', InvoiceStatus::Issued),
            default => $this->invoicedQuery($user, $period)->when($status, fn ($query) => match ($status) {
                'unpaid' => $query->where('status', InvoiceStatus::Issued),
                'overdue' => $query->filterStatus('overdue'),
                'paid' => $query->where('status', InvoiceStatus::Paid),
            }),
        };

        $totals = $this->countAndSum(clone $query, 'total');
        $ageing = $view === 'outstanding' ? $this->ageing($user, $today) : null;

        $invoices = match ($view) {
            'received' => $query->orderByDesc('paid_at')->orderByDesc('id'),
            'outstanding' => $query->orderBy('due_date')->orderBy('id'),
            default => $query->orderByDesc('issue_date')->orderByDesc('id'),
        };

        return [
            'invoices' => $invoices->paginate(self::PER_PAGE, self::INVOICE_COLUMNS)->withQueryString(),
            'totals' => $totals,
            'ageing' => $ageing,
        ];
    }

    /**
     * Period totals and all 13 categories in enum order, with count, amount and share.
     *
     * @return array<string, mixed>
     */
    public function expenses(User $user, ReportingPeriod $period): array
    {
        $totals = $this->countAndSum($this->expensesQuery($user, $period), 'amount');
        $total = BigDecimal::of($totals['amount']);

        $byCategory = $this->expensesQuery($user, $period)
            ->groupBy('category')
            ->selectRaw('category, count(*) as aggregate_count, coalesce(sum(amount), 0) as aggregate_amount')
            ->toBase()
            ->get()
            ->keyBy('category');

        $categories = array_map(function (ExpenseCategory $category) use ($byCategory, $total) {
            $row = $byCategory->get($category->value);
            $amount = Money::fromSql($row->aggregate_amount ?? null);
            $positive = BigDecimal::of($amount)->isPositive() && $total->isPositive();

            return [
                'category' => $category,
                'count' => (int) ($row->aggregate_count ?? 0),
                'amount' => $amount,
                'share' => $positive
                    ? BigDecimal::of($amount)->multipliedBy(100)->dividedBy($total, 0, RoundingMode::HALF_UP)->toInt()
                    : null,
            ];
        }, ExpenseCategory::cases());

        return ['totals' => $totals, 'categories' => $categories];
    }

    private function receivedQuery(User $user, ReportingPeriod $period): HasMany
    {
        return $user->invoices()
            ->where('status', InvoiceStatus::Paid)
            ->where('paid_at', '>=', $period->startDate())
            ->where('paid_at', '<', $period->endExclusive());
    }

    private function invoicedQuery(User $user, ReportingPeriod $period): HasMany
    {
        return $user->invoices()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Paid])
            ->where('issue_date', '>=', $period->startDate())
            ->where('issue_date', '<', $period->endExclusive());
    }

    private function expensesQuery(User $user, ReportingPeriod $period): HasMany
    {
        return $user->expenses()
            ->where('expense_date', '>=', $period->startDate())
            ->where('expense_date', '<', $period->endExclusive());
    }

    /**
     * Issued and paid invoices that can contribute to a customer row: issued in the
     * period, paid in the period, or still outstanding.
     */
    private function customerQuery(User $user, string $start, string $end): HasMany
    {
        return $user->invoices()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Paid])
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('issue_date', '>=', $start)->where('issue_date', '<', $end))
                ->orWhere(fn ($q) => $q->where('paid_at', '>=', $start)->where('paid_at', '<', $end))
                ->orWhere('status', InvoiceStatus::Issued));
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function countAndSum(HasMany $query, string $column): array
    {
        $row = $query->toBase()->selectRaw("count(*) as aggregate_count, coalesce(sum({$column}), 0) as aggregate_amount")->first();

        return ['count' => (int) $row->aggregate_count, 'amount' => Money::fromSql($row->aggregate_amount)];
    }

    /**
     * Count and sum per calendar month, keyed "Y-m". $dateColumn and $amountColumn
     * are internal constants, never request input.
     *
     * @return array<string, array{count: int, amount: string}>
     */
    private function monthly(HasMany $query, string $dateColumn, string $amountColumn): array
    {
        $month = SqlMonth::expression($query->getConnection()->getDriverName(), $dateColumn);

        return $query
            ->groupByRaw($month)
            ->selectRaw("{$month} as aggregate_month, count(*) as aggregate_count, coalesce(sum({$amountColumn}), 0) as aggregate_amount")
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->aggregate_month => [
                'count' => (int) $row->aggregate_count,
                'amount' => Money::fromSql($row->aggregate_amount),
            ]])
            ->all();
    }

    /**
     * Outstanding and overdue as of today, in one query.
     *
     * @return array{outstandingCount: int, outstandingAmount: string, overdueCount: int, overdueAmount: string}
     */
    private function position(User $user, string $today): array
    {
        $row = $user->invoices()
            ->where('status', InvoiceStatus::Issued)
            ->toBase()
            ->selectRaw(
                'count(*) as outstanding_count,
                 coalesce(sum(total), 0) as outstanding_amount,
                 coalesce(sum(case when due_date < ? then 1 else 0 end), 0) as overdue_count,
                 coalesce(sum(case when due_date < ? then total end), 0) as overdue_amount',
                [$today, $today],
            )
            ->first();

        return [
            'outstandingCount' => (int) $row->outstanding_count,
            'outstandingAmount' => Money::fromSql($row->outstanding_amount),
            'overdueCount' => (int) $row->overdue_count,
            'overdueAmount' => Money::fromSql($row->overdue_amount),
        ];
    }

    /**
     * Outstanding invoices by how long they have been overdue, as of today:
     * not yet due (due today or later), 1–30, 31–60, 61–90 and over 90 days.
     *
     * @return list<array{label: string, count: int, amount: string}>
     */
    private function ageing(User $user, CarbonImmutable $today): array
    {
        $t = $today->toDateString();
        $d30 = $today->subDays(30)->toDateString();
        $d60 = $today->subDays(60)->toDateString();
        $d90 = $today->subDays(90)->toDateString();

        $buckets = [
            'current' => ['Not yet due', 'due_date >= ?', [$t]],
            'days_1_30' => ['1–30 days overdue', 'due_date < ? and due_date >= ?', [$t, $d30]],
            'days_31_60' => ['31–60 days overdue', 'due_date < ? and due_date >= ?', [$d30, $d60]],
            'days_61_90' => ['61–90 days overdue', 'due_date < ? and due_date >= ?', [$d60, $d90]],
            'days_over_90' => ['Over 90 days overdue', 'due_date < ?', [$d90]],
        ];

        $select = [];
        $bindings = [];
        foreach ($buckets as $key => [, $condition, $params]) {
            $select[] = "coalesce(sum(case when {$condition} then 1 else 0 end), 0) as {$key}_count";
            $select[] = "coalesce(sum(case when {$condition} then total end), 0) as {$key}_amount";
            array_push($bindings, ...$params, ...$params);
        }

        $row = $user->invoices()
            ->where('status', InvoiceStatus::Issued)
            ->toBase()
            ->selectRaw(implode(', ', $select), $bindings)
            ->first();

        return array_values(array_map(fn ($key, $bucket) => [
            'key' => $key,
            'label' => $bucket[0],
            'count' => (int) $row->{$key.'_count'},
            'amount' => Money::fromSql($row->{$key.'_amount'}),
        ], array_keys($buckets), $buckets));
    }
}
