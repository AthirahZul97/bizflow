<?php

namespace App\Ocr;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns what any provider read into values the Expense form can use, and says what is wrong.
 *
 * It never trusts a provider: amounts become exact two-decimal strings inside the Expense
 * amount bounds, dates become real calendar dates inside the Expense date bounds, text is
 * cleaned and truncated to the column sizes, and an unknown category is dropped. Whatever fails
 * becomes a missing value plus a warning, never a guess. Confidence is passed through only when
 * it is a number from 0 to 1; a provider without scores leaves it null, which the UI shows as
 * unverified.
 *
 * Warning codes: total_missing, total_invalid, date_missing, date_invalid, date_ambiguous,
 * date_out_of_range, date_future, total_mismatch, currency_not_myr, low_confidence,
 * provider_warning.
 */
class ReceiptExtractionNormalizer
{
    /**
     * Below this a value is called low confidence.
     */
    public const LOW_CONFIDENCE = 0.6;

    /**
     * How far subtotal + tax may be from the total before they are called inconsistent.
     */
    private const TOTAL_TOLERANCE = '0.02';

    private const TEXT_FIELDS = ['merchant' => 255, 'receipt_number' => 100, 'payment_method' => 50, 'description' => 255];

    private const AMOUNT_FIELDS = ['subtotal', 'tax', 'total'];

    /**
     * Fields whose low confidence is worth a warning (a suggested category or payment method
     * is expected to be a guess).
     */
    private const CRITICAL_FIELDS = ['merchant', 'date', 'subtotal', 'tax', 'total'];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    public function normalize(ReceiptExtraction $extraction, ?CarbonImmutable $today = null): NormalizedReceipt
    {
        $today ??= CarbonImmutable::today();
        $fields = [];
        $warnings = [];

        foreach (['merchant', 'receipt_number', 'date', 'subtotal', 'tax', 'total', 'currency', 'payment_method', 'category', 'description'] as $name) {
            $raw = $extraction->fields[$name]['value'] ?? null;
            $confidence = $this->confidence($extraction->fields[$name]['confidence'] ?? null);

            $value = match (true) {
                isset(self::TEXT_FIELDS[$name]) => $this->text($raw, self::TEXT_FIELDS[$name]),
                in_array($name, self::AMOUNT_FIELDS, true) => $this->amount($raw),
                $name === 'date' => $this->date($raw, $today, $warnings),
                $name === 'currency' => $this->currency($raw),
                default => $this->category($raw),
            };

            if ($value === null && $this->isPresent($raw)) {
                $this->warnAboutDiscarded($name, $warnings);
            }

            if ($value !== null && $confidence !== null && $confidence < self::LOW_CONFIDENCE && in_array($name, self::CRITICAL_FIELDS, true)) {
                $warnings[] = $this->warning('low_confidence', $name, 'The '.$this->label($name).' was hard to read. Please check it.');
            }

            $fields[$name] = ['value' => $value, 'confidence' => $value === null ? null : $confidence];
        }

        if ($fields['total']['value'] === null && ! $this->hasWarning($warnings, 'total_invalid')) {
            $warnings[] = $this->warning('total_missing', 'total', 'No total was found. Enter the amount from the receipt.');
        }

        if ($fields['date']['value'] === null && ! $this->hasWarning($warnings, 'date_invalid', 'date_out_of_range')) {
            $warnings[] = $this->warning('date_missing', 'date', 'No date was found. Enter the date on the receipt.');
        }

        $this->checkTotals($fields, $warnings);

        if ($fields['currency']['value'] !== null && $fields['currency']['value'] !== config('bizflow.currency.code')) {
            $warnings[] = $this->warning('currency_not_myr', 'currency', 'The receipt looks like it is in '.$fields['currency']['value'].'. BizFlow records amounts in '.config('bizflow.currency.code').', so enter the amount in '.config('bizflow.currency.code').'.');
        }

        foreach (array_slice($extraction->warnings, 0, 10) as $note) {
            $text = $this->text($note, 200);

            if ($text !== null) {
                $warnings[] = $this->warning('provider_warning', null, $text);
            }
        }

        return new NormalizedReceipt($fields, $warnings, $extraction->provider, $extraction->model);
    }

    /**
     * A plain two-decimal amount string within the Expense amount bounds, or null.
     */
    public function amount(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = is_float($raw) ? rtrim(rtrim(sprintf('%.6F', $raw), '0'), '.') : (string) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        // A currency marker is only accepted in front of or behind the number ("RM 5.00", "5.00 MYR").
        $text = preg_replace('/^\s*(?:RM|MYR)|(?:RM|MYR)\s*$|[\s$]/iu', '', $raw) ?? '';

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $text) === 1) {
            $text = str_replace(',', '', $text);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $text) === 1) {
            $text = str_replace(',', '.', $text);
        }

        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        try {
            $amount = BigDecimal::of($text)->toScale(2, RoundingMode::HALF_UP);
        } catch (Throwable) {
            return null;
        }

        if ($amount->isLessThan('0.01') || $amount->isGreaterThan(Expense::MAX_AMOUNT)) {
            return null;
        }

        return (string) $amount;
    }

    /**
     * @param  list<array{code: string, field: string|null, message: string}>  $warnings
     */
    private function date(mixed $raw, CarbonImmutable $today, array &$warnings): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $text = trim($raw);
        $parts = null;

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m) === 1) {
            $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $m) === 1) {
            // Malaysian receipts are day first. Say so when month first would also be a real date.
            $parts = [(int) $m[3], (int) $m[2], (int) $m[1]];

            if ((int) $m[1] <= 12 && (int) $m[2] <= 12 && $m[1] !== $m[2]) {
                $warnings[] = $this->warning('date_ambiguous', 'date', 'The date could be read as day/month or month/day. It was read as day/month; please check it.');
            }
        } elseif (preg_match('/^(\d{1,2})[\s-]([A-Za-z]{3})[A-Za-z]*[\s,-]+(\d{4})$/', $text, $m) === 1 && isset(self::MONTHS[strtolower($m[2])])) {
            $parts = [(int) $m[3], self::MONTHS[strtolower($m[2])], (int) $m[1]];
        }

        if ($parts === null || ! checkdate($parts[1], $parts[2], $parts[0])) {
            $warnings[] = $this->warning('date_invalid', 'date', 'The date on the receipt could not be understood. Enter it by hand.');

            return null;
        }

        $date = CarbonImmutable::create($parts[0], $parts[1], $parts[2])->startOfDay();

        if ($date->lt(CarbonImmutable::create(2000, 1, 1))) {
            $warnings[] = $this->warning('date_out_of_range', 'date', 'The date is before 1 January 2000, which BizFlow does not accept. Enter it by hand.');

            return null;
        }

        if ($date->gt($today)) {
            $warnings[] = $this->warning('date_future', 'date', 'The date is in the future. Please check it.');
        }

        return $date->toDateString();
    }

    private function text(mixed $raw, int $max): ?string
    {
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        $text = preg_replace('/[\p{C}\s]+/u', ' ', (string) $raw);
        $text = trim($text ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function currency(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $code = strtoupper(trim($raw));

        if ($code === 'RM') {
            return 'MYR';
        }

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }

    private function category(mixed $raw): ?string
    {
        return is_string($raw) ? ExpenseCategory::tryFrom(trim($raw))?->value : null;
    }

    private function confidence(mixed $raw): ?float
    {
        if (! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        return $raw >= 0 && $raw <= 1 ? round((float) $raw, 2) : null;
    }

    private function isPresent(mixed $raw): bool
    {
        return $raw !== null && $raw !== '' && $raw !== [];
    }

    /**
     * @param  array<string, array{value: string|null, confidence: float|null}>  $fields
     * @param  list<array{code: string, field: string|null, message: string}>  $warnings
     */
    private function checkTotals(array $fields, array &$warnings): void
    {
        [$subtotal, $tax, $total] = [$fields['subtotal']['value'], $fields['tax']['value'], $fields['total']['value']];

        if ($subtotal === null || $tax === null || $total === null) {
            return;
        }

        $difference = BigDecimal::of($subtotal)->plus($tax)->minus($total)->abs();

        if ($difference->isGreaterThan(self::TOTAL_TOLERANCE)) {
            $warnings[] = $this->warning('total_mismatch', 'total', 'The subtotal and tax do not add up to the total. Please check the amounts.');
        }
    }

    /**
     * @param  list<array{code: string, field: string|null, message: string}>  $warnings
     */
    private function warnAboutDiscarded(string $name, array &$warnings): void
    {
        if ($name === 'total') {
            $warnings[] = $this->warning('total_invalid', 'total', 'The total could not be read as a valid amount. Enter it by hand.');
        }
    }

    /**
     * @param  list<array{code: string, field: string|null, message: string}>  $warnings
     */
    private function hasWarning(array $warnings, string ...$codes): bool
    {
        foreach ($warnings as $warning) {
            if (in_array($warning['code'], $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, field: string|null, message: string}
     */
    private function warning(string $code, ?string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }

    private function label(string $field): string
    {
        return match ($field) {
            'receipt_number' => 'receipt number',
            'payment_method' => 'payment method',
            'tax' => 'tax amount',
            default => $field,
        };
    }
}
