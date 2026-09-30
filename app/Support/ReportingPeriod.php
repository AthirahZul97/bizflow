<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The date range a dashboard or report covers, in the application's
 * Asia/Kuala_Lumpur timezone.
 *
 * Each page passes the presets it offers and its default, so a preset one page
 * offers (e.g. last_year on Reports) is rejected by another (the Dashboard).
 *
 * Queries use a half-open range: date >= startDate() AND date < endExclusive().
 * That includes both end days whether the driver stores a bare DATE (MySQL) or
 * a date with a time (SQLite), and keeps the (business_id, date) indexes usable.
 */
final class ReportingPeriod
{
    public const THIS_MONTH = 'this_month';

    public const LAST_MONTH = 'last_month';

    public const THIS_YEAR = 'this_year';

    public const LAST_YEAR = 'last_year';

    public const CUSTOM = 'custom';

    /**
     * Earliest date a custom range may start or end on.
     */
    public const EARLIEST_DATE = '2000-01-01';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::THIS_MONTH => 'This month',
        self::LAST_MONTH => 'Last month',
        self::THIS_YEAR => 'This year',
        self::LAST_YEAR => 'Last year',
    ];

    /**
     * @var list<string>
     */
    public const DASHBOARD_PRESETS = [self::THIS_MONTH, self::LAST_MONTH, self::THIS_YEAR];

    /**
     * @var list<string>
     */
    public const REPORT_PRESETS = [self::THIS_MONTH, self::LAST_MONTH, self::THIS_YEAR, self::LAST_YEAR];

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $fellBack = false,
        public readonly string $defaultKey = self::THIS_MONTH,
    ) {}

    /**
     * @param  list<string>  $presets
     */
    public static function fromRequest(Request $request, array $presets = self::DASHBOARD_PRESETS, string $default = self::THIS_MONTH): self
    {
        return self::fromInput($request->query('period'), $request->query('from'), $request->query('to'), $presets, $default);
    }

    /**
     * Build a period from untrusted input. Anything invalid, including a preset
     * the page does not offer, falls back to the page default and sets $fellBack
     * so the page can say so.
     *
     * @param  list<string>  $presets
     */
    public static function fromInput(mixed $period, mixed $from = null, mixed $to = null, array $presets = self::DASHBOARD_PRESETS, string $default = self::THIS_MONTH): self
    {
        $today = today()->toImmutable();

        if ($period === null || $period === '') {
            return self::preset($default, $today, $default);
        }

        if ($period === self::CUSTOM) {
            return self::custom($from, $to, $today, $default);
        }

        if (is_string($period) && in_array($period, $presets, true)) {
            return self::preset($period, $today, $default);
        }

        return self::preset($default, $today, $default, fellBack: true);
    }

    /**
     * First day of the period, as Y-m-d.
     */
    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    /**
     * Last day of the period (inclusive), as Y-m-d.
     */
    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    /**
     * The day after the period, as Y-m-d: the exclusive upper bound for queries.
     */
    public function endExclusive(): string
    {
        return $this->end->addDay()->toDateString();
    }

    /**
     * A readable range, e.g. "1–29 Sep 2026", "1 Aug – 29 Sep 2026" or "15 Dec 2025 – 10 Jan 2026".
     */
    public function label(): string
    {
        return self::rangeLabel($this->start, $this->end);
    }

    /**
     * Label a date range the same way periods are labelled.
     */
    public static function rangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('j M Y');
        }

        if ($start->isSameMonth($end)) {
            return $start->format('j').'–'.$end->format('j M Y');
        }

        if ($start->isSameYear($end)) {
            return $start->format('j M').' – '.$end->format('j M Y');
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    }

    /**
     * The page default in words, e.g. "this month", for the fallback warning.
     */
    public function defaultLabel(): string
    {
        return strtolower(self::LABELS[$this->defaultKey] ?? self::LABELS[self::THIS_MONTH]);
    }

    /**
     * The query parameters that reproduce this period, for links and pagination.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->key === self::CUSTOM
            ? ['period' => self::CUSTOM, 'from' => $this->startDate(), 'to' => $this->endDate()]
            : ['period' => $this->key];
    }

    private static function preset(string $key, CarbonImmutable $today, string $default, bool $fellBack = false): self
    {
        [$start, $end] = match ($key) {
            self::LAST_MONTH => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::THIS_YEAR => [$today->startOfYear(), $today],
            self::LAST_YEAR => [$today->subYearNoOverflow()->startOfYear(), $today->subYearNoOverflow()->endOfYear()->startOfDay()],
            default => [$today->startOfMonth(), $today],
        };

        return new self(array_key_exists($key, self::LABELS) ? $key : self::THIS_MONTH, $start, $end, $fellBack, $default);
    }

    /**
     * A custom range needs two real Y-m-d dates, from <= to, not before 2000-01-01
     * and not after today.
     */
    private static function custom(mixed $from, mixed $to, CarbonImmutable $today, string $default): self
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($start === null || $end === null || $start->isAfter($end) || $end->isAfter($today)
            || $start->toDateString() < self::EARLIEST_DATE) {
            return self::preset($default, $today, $default, fellBack: true);
        }

        return new self(self::CUSTOM, $start, $end, false, $default);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
    }
}
