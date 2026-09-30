<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Support\Money;
use App\Support\ReportingPeriod;
use App\Support\SqlMonth;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only aggregates for the dashboard. Every query starts from the business's own
 * invoices() or expenses() relationship; nothing here reads another business's rows.
 *
 * Definitions (see the design):
 *  - Received:    paid invoices, by paid_at, in the period.
 *  - Invoiced:    issued + paid invoices, by issue_date, in the period.
 *  - Expenses:    expenses, by expense_date, in the period.
 *  - Net cash:    Received − Expenses. An estimate, not accounting profit.
 *  - Outstanding: all issued invoices as of today; overdue = due_date before today.
 * Drafts and cancelled invoices never count towards any money figure.
 *
 * Amounts are exact two-decimal strings; SQL SUMs are normalised with BigDecimal.
 */
class DashboardService
{
    /**
     * Rows shown in each recent-activity list and in the overdue list.
     */
    public const LIST_LIMIT = 5;

    /**
     * Months shown in the trend table, including the current month.
     */
    public const TREND_MONTHS = 6;

    /**
     * Invoices due from today through this many days ahead count as "due soon".
     */
    public const DUE_SOON_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function summary(Business $business, ReportingPeriod $period): array
    {
        $today = today()->toImmutable();
        $todayDate = $today->toDateString();

        $received = $this->countAndSum($business->invoices()
            ->where('status', InvoiceStatus::Paid)
            ->where('paid_at', '>=', $period->startDate())
            ->where('paid_at', '<', $period->endExclusive()), 'total');

        $expenses = $this->countAndSum($business->expenses()
            ->where('expense_date', '>=', $period->startDate())
            ->where('expense_date', '<', $period->endExclusive()), 'amount');

        $invoiced = $this->countAndSum($business->invoices()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Paid])
            ->where('issue_date', '>=', $period->startDate())
            ->where('issue_date', '<', $period->endExclusive()), 'total');

        $statuses = $this->statusSummary($business);
        $due = $this->overdueAndDueSoon($business, $todayDate, $today->addDays(self::DUE_SOON_DAYS)->toDateString());

        $issuedCount = $statuses['issued']['count'];
        $issuedAmount = $statuses['issued']['amount'];

        $recentInvoices = $business->invoices()
            ->latest()
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'invoice_number', 'status', 'customer_name', 'due_date', 'total', 'created_at']);

        $recentExpenses = $business->expenses()
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'expense_date', 'category', 'description', 'amount']);

        $isNewAccount = $recentInvoices->isEmpty()
            && $recentExpenses->isEmpty()
            && ! $business->customers()->exists();

        return [
            'received' => $received,
            'expenses' => $expenses,
            'invoiced' => $invoiced,
            'netCash' => (string) BigDecimal::of($received['amount'])->minus($expenses['amount']),
            'outstanding' => [
                'count' => $issuedCount,
                'amount' => $issuedAmount,
                'overdueCount' => $due['overdueCount'],
                'overdueAmount' => $due['overdueAmount'],
            ],
            'dueSoon' => ['count' => $due['soonCount'], 'amount' => $due['soonAmount']],
            'statusRows' => [
                ['label' => 'Draft', 'filter' => 'draft', 'count' => $statuses['draft']['count'], 'amount' => $statuses['draft']['amount']],
                ['label' => 'Issued, not yet due', 'filter' => 'issued', 'count' => $issuedCount - $due['overdueCount'],
                    'amount' => (string) BigDecimal::of($issuedAmount)->minus($due['overdueAmount'])],
                ['label' => 'Overdue', 'filter' => 'overdue', 'count' => $due['overdueCount'], 'amount' => $due['overdueAmount']],
                ['label' => 'Paid', 'filter' => 'paid', 'count' => $statuses['paid']['count'], 'amount' => $statuses['paid']['amount']],
                ['label' => 'Cancelled', 'filter' => 'cancelled', 'count' => $statuses['cancelled']['count'], 'amount' => $statuses['cancelled']['amount']],
            ],
            'draftCount' => $statuses['draft']['count'],
            'overdueInvoices' => $business->invoices()
                ->where('status', InvoiceStatus::Issued)
                ->where('due_date', '<', $todayDate)
                ->orderBy('due_date')
                ->orderBy('id')
                ->limit(self::LIST_LIMIT)
                ->get(['id', 'invoice_number', 'status', 'customer_name', 'due_date', 'total']),
            'categories' => $this->categoryBreakdown($business, $period, $expenses['amount']),
            'trend' => $this->trend($business, $today),
            'recentInvoices' => $recentInvoices,
            'recentExpenses' => $recentExpenses,
            'isNewAccount' => $isNewAccount,
        ];
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function countAndSum(HasMany $query, string $column): array
    {
        $row = $query->selectRaw("count(*) as aggregate_count, coalesce(sum({$column}), 0) as aggregate_amount")->first();

        return ['count' => (int) $row->aggregate_count, 'amount' => $this->decimal($row->aggregate_amount)];
    }

    /**
     * All-time count and total per stored status, with zeroes for statuses the business has none of.
     *
     * @return array<string, array{count: int, amount: string}>
     */
    private function statusSummary(Business $business): array
    {
        $summary = [];
        foreach (InvoiceStatus::cases() as $status) {
            $summary[$status->value] = ['count' => 0, 'amount' => '0.00'];
        }

        $rows = $business->invoices()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate_count, coalesce(sum(total), 0) as aggregate_amount')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $summary[$row->status] = ['count' => (int) $row->aggregate_count, 'amount' => $this->decimal($row->aggregate_amount)];
        }

        return $summary;
    }

    /**
     * Issued invoices past due (due_date before today) and due soon (today up to,
     * but not including, $soonEnd), in one query.
     *
     * @return array{overdueCount: int, overdueAmount: string, soonCount: int, soonAmount: string}
     */
    private function overdueAndDueSoon(Business $business, string $today, string $soonEnd): array
    {
        $row = $business->invoices()
            ->where('status', InvoiceStatus::Issued)
            ->selectRaw(
                'coalesce(sum(case when due_date < ? then 1 else 0 end), 0) as overdue_count,
                 coalesce(sum(case when due_date < ? then total end), 0) as overdue_amount,
                 coalesce(sum(case when due_date >= ? and due_date < ? then 1 else 0 end), 0) as soon_count,
                 coalesce(sum(case when due_date >= ? and due_date < ? then total end), 0) as soon_amount',
                [$today, $today, $today, $soonEnd, $today, $soonEnd],
            )
            ->toBase()
            ->first();

        return [
            'overdueCount' => (int) $row->overdue_count,
            'overdueAmount' => $this->decimal($row->overdue_amount),
            'soonCount' => (int) $row->soon_count,
            'soonAmount' => $this->decimal($row->soon_amount),
        ];
    }

    /**
     * Expense totals per category for the period, largest first, with each
     * category's share of the period total as a whole percentage.
     *
     * @return list<array{category: ExpenseCategory, amount: string, percent: int}>
     */
    private function categoryBreakdown(Business $business, ReportingPeriod $period, string $periodTotal): array
    {
        $total = BigDecimal::of($periodTotal);

        $rows = $business->expenses()
            ->where('expense_date', '>=', $period->startDate())
            ->where('expense_date', '<', $period->endExclusive())
            ->groupBy('category')
            ->selectRaw('category, coalesce(sum(amount), 0) as aggregate_amount')
            ->toBase()
            ->get();

        return $rows
            ->map(fn ($row) => [
                'category' => ExpenseCategory::from($row->category),
                'amount' => $this->decimal($row->aggregate_amount),
            ])
            ->filter(fn ($row) => BigDecimal::of($row['amount'])->isPositive())
            ->sort(fn ($a, $b) => BigDecimal::of($b['amount'])->compareTo($a['amount']) ?: strcmp($a['category']->label(), $b['category']->label()))
            ->map(fn ($row) => $row + [
                'percent' => $total->isPositive()
                    ? BigDecimal::of($row['amount'])->multipliedBy(100)->dividedBy($total, 0, RoundingMode::HALF_UP)->toInt()
                    : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Received, expenses and net cash per month for the last TREND_MONTHS months, newest first.
     * Months without data show zero.
     *
     * @return list<array{month: CarbonImmutable, current: bool, received: string, expenses: string, net: string}>
     */
    private function trend(Business $business, CarbonImmutable $today): array
    {
        $firstMonth = $today->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $end = $today->startOfMonth()->addMonthNoOverflow()->toDateString();

        $received = $this->monthlySums($business->invoices()->where('status', InvoiceStatus::Paid), 'paid_at', 'total', $firstMonth->toDateString(), $end);
        $expenses = $this->monthlySums($business->expenses(), 'expense_date', 'amount', $firstMonth->toDateString(), $end);

        $rows = [];
        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $month = $firstMonth->addMonthsNoOverflow($i);
            $key = $month->format('Y-m');
            $in = $received[$key] ?? '0.00';
            $out = $expenses[$key] ?? '0.00';

            $rows[] = [
                'month' => $month,
                'current' => $i === self::TREND_MONTHS - 1,
                'received' => $in,
                'expenses' => $out,
                'net' => (string) BigDecimal::of($in)->minus($out),
            ];
        }

        return $rows;
    }

    /**
     * Sum a column per calendar month (keyed "Y-m") in [$start, $end).
     *
     * The driver-specific month expression comes from SqlMonth; $dateColumn is an
     * internal constant, never request input.
     *
     * @return array<string, string>
     */
    private function monthlySums(HasMany $query, string $dateColumn, string $amountColumn, string $start, string $end): array
    {
        $month = SqlMonth::expression($query->getConnection()->getDriverName(), $dateColumn);

        return $query
            ->where($dateColumn, '>=', $start)
            ->where($dateColumn, '<', $end)
            ->groupByRaw($month)
            ->selectRaw("{$month} as aggregate_month, coalesce(sum({$amountColumn}), 0) as aggregate_amount")
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->aggregate_month => $this->decimal($row->aggregate_amount)])
            ->all();
    }

    /**
     * Normalise a SQL SUM to an exact two-decimal string (see Money::fromSql()).
     */
    private function decimal(mixed $value): string
    {
        return Money::fromSql($value);
    }
}
