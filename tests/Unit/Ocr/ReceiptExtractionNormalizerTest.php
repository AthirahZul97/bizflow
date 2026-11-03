<?php

namespace Tests\Unit\Ocr;

use App\Ocr\NormalizedReceipt;
use App\Ocr\ReceiptExtraction;
use App\Ocr\ReceiptExtractionNormalizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReceiptExtractionNormalizerTest extends TestCase
{
    private ReceiptExtractionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ReceiptExtractionNormalizer;
    }

    /**
     * @param  array<string, mixed>  $values  field => raw value, or [value, confidence]
     */
    private function normalize(array $values, array $warnings = []): NormalizedReceipt
    {
        $fields = [];

        foreach ($values as $name => $value) {
            $fields[$name] = is_array($value) ? ['value' => $value[0], 'confidence' => $value[1]] : ['value' => $value];
        }

        return $this->normalizer->normalize(new ReceiptExtraction($fields, 'test', 'm', $warnings), CarbonImmutable::parse('2026-10-15'));
    }

    private function codes(NormalizedReceipt $result): array
    {
        return array_column($result->warnings, 'code');
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function amounts(): array
    {
        return [
            'plain' => ['42.50', '42.50'],
            'integer string' => ['42', '42.00'],
            'one decimal' => ['42.5', '42.50'],
            'with RM' => ['RM 1,234.50', '1234.50'],
            'with RM glued' => ['RM42.50', '42.50'],
            'thousands separator' => ['1,234,567.89', '1234567.89'],
            'comma decimal' => ['12,50', '12.50'],
            'spaces' => [' 7.00 ', '7.00'],
            'rounds half up' => ['1.005', '1.01'],
            'float' => [42.5, '42.50'],
            'int' => [42, '42.00'],
            'smallest' => ['0.01', '0.01'],
            'largest' => ['9999999999999.99', '9999999999999.99'],
            'zero' => ['0', null],
            'zero decimal' => ['0.00', null],
            'rounds to zero' => ['0.004', null],
            'negative' => ['-5.00', null],
            'too large' => ['10000000000000.00', null],
            'words' => ['forty two', null],
            'two dots' => ['1.2.3', null],
            'garbled thousands' => ['1,23,4', null],
            'empty' => ['', null],
            'null' => [null, null],
            'array' => [['42'], null],
            'exponent' => ['1e3', null],
            'sql' => ["1'; DROP TABLE expenses;--", null],
        ];
    }

    /**
     * @dataProvider amounts
     */
    #[DataProvider('amounts')]
    public function test_amounts_become_exact_two_decimal_strings_or_nothing(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->amount($raw));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function dates(): array
    {
        return [
            'iso' => ['2026-09-20', '2026-09-20'],
            'slashes day first' => ['20/09/2026', '2026-09-20'],
            'dashes day first' => ['20-09-2026', '2026-09-20'],
            'dots' => ['20.09.2026', '2026-09-20'],
            'year first slashes' => ['2026/09/20', '2026-09-20'],
            'single digits' => ['1/2/2026', '2026-02-01'],
            'month name' => ['20 Sep 2026', '2026-09-20'],
            'month name dashed' => ['20-Sep-2026', '2026-09-20'],
            'full month name' => ['20 September 2026', '2026-09-20'],
            'leap day' => ['29/02/2024', '2024-02-29'],
            'today' => ['2026-10-15', '2026-10-15'],
            'future (kept, warned)' => ['2026-12-25', '2026-12-25'],
            'not a leap year' => ['29/02/2026', null],
            'month 13' => ['2026-13-01', null],
            'day 32' => ['32/01/2026', null],
            'two digit year' => ['20/09/26', null],
            'before 2000' => ['31/12/1999', null],
            'words' => ['last tuesday', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates_become_real_calendar_dates_or_nothing(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalize(['total' => '5.00', 'date' => $raw])->fields['date']['value']);
    }

    public function test_an_ambiguous_date_is_read_day_first_and_flagged(): void
    {
        $result = $this->normalize(['total' => '5.00', 'date' => '03/04/2026']);

        $this->assertSame('2026-04-03', $result->fields['date']['value']);
        $this->assertContains('date_ambiguous', $this->codes($result));
    }

    public function test_an_unambiguous_date_is_not_flagged(): void
    {
        $this->assertNotContains('date_ambiguous', $this->codes($this->normalize(['total' => '5.00', 'date' => '25/04/2026'])));
        $this->assertNotContains('date_ambiguous', $this->codes($this->normalize(['total' => '5.00', 'date' => '04/04/2026'])));
        $this->assertNotContains('date_ambiguous', $this->codes($this->normalize(['total' => '5.00', 'date' => '2026-04-03'])));
    }

    public function test_date_problems_are_reported_not_guessed(): void
    {
        $this->assertContains('date_missing', $this->codes($this->normalize(['total' => '5.00'])));
        $this->assertContains('date_invalid', $this->codes($this->normalize(['total' => '5.00', 'date' => '31/02/2026'])));
        $this->assertContains('date_out_of_range', $this->codes($this->normalize(['total' => '5.00', 'date' => '1999-01-01'])));
        $this->assertContains('date_future', $this->codes($this->normalize(['total' => '5.00', 'date' => '2027-01-01'])));
    }

    public function test_a_missing_or_invalid_total_makes_the_receipt_unusable(): void
    {
        $missing = $this->normalize(['merchant' => 'Shop']);
        $invalid = $this->normalize(['total' => 'n/a']);
        $good = $this->normalize(['total' => '1.00']);

        $this->assertFalse($missing->isUsable());
        $this->assertContains('total_missing', $this->codes($missing));
        $this->assertFalse($invalid->isUsable());
        $this->assertContains('total_invalid', $this->codes($invalid));
        $this->assertNotContains('total_missing', $this->codes($invalid), 'one warning, not two');
        $this->assertTrue($good->isUsable());
    }

    public function test_subtotal_and_tax_that_do_not_add_up_are_flagged(): void
    {
        $this->assertContains('total_mismatch', $this->codes($this->normalize(['subtotal' => '10.00', 'tax' => '0.60', 'total' => '12.00'])));
        $this->assertNotContains('total_mismatch', $this->codes($this->normalize(['subtotal' => '10.00', 'tax' => '0.60', 'total' => '10.60'])));
        $this->assertNotContains('total_mismatch', $this->codes($this->normalize(['subtotal' => '10.00', 'tax' => '0.60', 'total' => '10.61'])), 'within the 0.02 rounding tolerance');
        $this->assertNotContains('total_mismatch', $this->codes($this->normalize(['subtotal' => '10.00', 'total' => '12.00'])), 'needs both subtotal and tax');
    }

    public function test_a_foreign_currency_is_flagged_and_rm_means_myr(): void
    {
        $this->assertContains('currency_not_myr', $this->codes($this->normalize(['total' => '5.00', 'currency' => 'USD'])));
        $this->assertSame('MYR', $this->normalize(['total' => '5.00', 'currency' => 'rm'])->fields['currency']['value']);
        $this->assertNotContains('currency_not_myr', $this->codes($this->normalize(['total' => '5.00', 'currency' => 'MYR'])));
        $this->assertNull($this->normalize(['total' => '5.00', 'currency' => 'ringgit'])->fields['currency']['value']);
    }

    public function test_confidence_is_passed_through_only_when_it_is_a_valid_score(): void
    {
        $result = $this->normalize([
            'merchant' => ['Shop', 0.876],
            'date' => ['2026-09-20', null],
            'total' => ['5.00', 1.5],
            'subtotal' => ['4.00', -0.2],
            'tax' => ['1.00', 'high'],
        ]);

        $this->assertSame(0.88, $result->fields['merchant']['confidence']);
        $this->assertNull($result->fields['date']['confidence']);
        $this->assertNull($result->fields['total']['confidence']);
        $this->assertNull($result->fields['subtotal']['confidence']);
        $this->assertNull($result->fields['tax']['confidence']);
    }

    public function test_low_confidence_on_important_fields_is_flagged_but_not_on_guesses(): void
    {
        $low = $this->normalize(['merchant' => ['Shop', 0.4], 'date' => ['2026-09-20', 0.59], 'total' => ['5.00', 0.1], 'category' => ['office', 0.1], 'payment_method' => ['Cash', 0.1]]);
        $fine = $this->normalize(['merchant' => ['Shop', 0.6], 'total' => ['5.00', 0.95]]);

        $fields = array_column(array_filter($low->warnings, fn ($w) => $w['code'] === 'low_confidence'), 'field');
        $this->assertEqualsCanonicalizing(['merchant', 'date', 'total'], $fields);
        $this->assertNotContains('low_confidence', $this->codes($fine));
    }

    public function test_text_is_cleaned_and_truncated_to_the_column_sizes(): void
    {
        $result = $this->normalize([
            'total' => '5.00',
            'merchant' => "  Kedai\t\n  Runcit \x00 Ali  ".str_repeat('x', 400),
            'receipt_number' => str_repeat('9', 300),
            'payment_method' => str_repeat('p', 100),
        ]);

        $this->assertStringStartsWith('Kedai Runcit Ali', $result->fields['merchant']['value']);
        $this->assertSame(255, mb_strlen($result->fields['merchant']['value']));
        $this->assertSame(100, mb_strlen($result->fields['receipt_number']['value']));
        $this->assertSame(50, mb_strlen($result->fields['payment_method']['value']));
        $this->assertStringNotContainsString("\x00", $result->fields['merchant']['value']);
    }

    public function test_an_unknown_category_is_dropped_and_a_known_one_kept(): void
    {
        $this->assertSame('meals', $this->normalize(['total' => '5.00', 'category' => ' meals '])->fields['category']['value']);
        $this->assertNull($this->normalize(['total' => '5.00', 'category' => 'yachts'])->fields['category']['value']);
        $odd = new ReceiptExtraction(['total' => ['value' => '5.00'], 'category' => ['value' => ['meals']]], 'test');
        $this->assertNull($this->normalizer->normalize($odd)->fields['category']['value']);
    }

    public function test_provider_warnings_are_cleaned_and_limited(): void
    {
        $result = $this->normalize(['total' => '5.00'], array_merge(["  Blurry\x00 corner  ", str_repeat('w', 500), ''], array_fill(0, 20, 'again')));

        $providerWarnings = array_filter($result->warnings, fn ($w) => $w['code'] === 'provider_warning');
        $this->assertLessThanOrEqual(10, count($providerWarnings));
        $this->assertSame('Blurry corner', array_values($providerWarnings)[0]['message']);
        foreach ($providerWarnings as $warning) {
            $this->assertLessThanOrEqual(200, mb_strlen($warning['message']));
        }
    }

    public function test_every_field_is_always_present_and_an_empty_extraction_is_unusable(): void
    {
        $result = $this->normalizer->normalize(new ReceiptExtraction([], 'test'));

        $this->assertSame(['merchant', 'receipt_number', 'date', 'subtotal', 'tax', 'total', 'currency', 'payment_method', 'category', 'description'], array_keys($result->fields));
        $this->assertFalse($result->isUsable());
        $this->assertSame(['fields', 'warnings', 'manual'], array_keys($result->toArray()));
        $this->assertFalse($result->toArray()['manual']);
        foreach ($result->fields as $field) {
            $this->assertNull($field['value']);
            $this->assertNull($field['confidence']);
        }
    }

    public function test_the_result_remembers_the_provider_and_model(): void
    {
        $result = $this->normalize(['total' => '5.00']);

        $this->assertSame('test', $result->provider);
        $this->assertSame('m', $result->model);
    }
}
