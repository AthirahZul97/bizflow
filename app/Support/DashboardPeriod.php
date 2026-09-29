<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The date range the dashboard reports on, in the application's
 * Asia/Kuala_Lumpur timezone.
 *
 * Queries use a half-open range: date >= startDate() AND date < endExclusive().
 * That includes both end days whether the driver stores a bare DATE (MySQL) or
 * a date with a time (SQLite), and keeps the (user_id, date) indexes usable.
 */
final class DashboardPeriod
{
    public const THIS_MONTH = 'this_month';

    public const LAST_MONTH = 'last_month';

    public const THIS_YEAR = 'this_year';

    public const CUSTOM = 'custom';

    /**
     * @var array<string, string>
     */
    public const OPTIONS = [
        self::THIS_MONTH => 'This month',
        self::LAST_MONTH => 'Last month',
        self::THIS_YEAR => 'This year',
    ];

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $fellBack = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromInput($request->query('period'), $request->query('from'), $request->query('to'));
    }

    /**
     * Build a period from untrusted input. Anything invalid falls back to this
     * month and sets $fellBack so the page can say so.
     */
    public static function fromInput(mixed $period, mixed $from = null, mixed $to = null): self
    {
        $today = today()->toImmutable();

        return match ($period) {
            null, '', self::THIS_MONTH => self::thisMonth($today),
            self::LAST_MONTH => new self(self::LAST_MONTH, $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            self::THIS_YEAR => new self(self::THIS_YEAR, $today->startOfYear(), $today),
            self::CUSTOM => self::custom($from, $to, $today),
            default => self::thisMonth($today, fellBack: true),
        };
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
        if ($this->start->isSameDay($this->end)) {
            return $this->start->format('j M Y');
        }

        if ($this->start->isSameMonth($this->end)) {
            return $this->start->format('j').'–'.$this->end->format('j M Y');
        }

        if ($this->start->isSameYear($this->end)) {
            return $this->start->format('j M').' – '.$this->end->format('j M Y');
        }

        return $this->start->format('j M Y').' – '.$this->end->format('j M Y');
    }

    private static function thisMonth(CarbonImmutable $today, bool $fellBack = false): self
    {
        return new self(self::THIS_MONTH, $today->startOfMonth(), $today, $fellBack);
    }

    /**
     * A custom range needs two real Y-m-d dates, from <= to, and to not after today.
     */
    private static function custom(mixed $from, mixed $to, CarbonImmutable $today): self
    {
        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($start === null || $end === null || $start->isAfter($end) || $end->isAfter($today)) {
            return self::thisMonth($today, fellBack: true);
        }

        return new self(self::CUSTOM, $start, $end);
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
