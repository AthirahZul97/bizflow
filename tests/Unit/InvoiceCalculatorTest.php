<?php

namespace Tests\Unit;

use App\Exceptions\InvoiceCalculationException;
use App\Services\InvoiceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceCalculator;
    }

    /**
     * @return list<array{quantity: string, unit_price: string}>
     */
    private function line(string $quantity, string $unitPrice): array
    {
        return ['quantity' => $quantity, 'unit_price' => $unitPrice];
    }

    public function test_single_line_total_is_quantity_times_price(): void
    {
        $totals = $this->calculator->calculate([$this->line('1', '300.00')]);

        $this->assertSame(['300.00'], $totals->lineTotals);
        $this->assertSame('300.00', $totals->subtotal);
        $this->assertSame('0.00', $totals->discountAmount);
        $this->assertSame('0.00', $totals->taxAmount);
        $this->assertSame('300.00', $totals->total);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function lineRounding(): array
    {
        return [
            'exact' => ['3', '0.10', '0.30'],
            'half rounds up' => ['1.5', '33.33', '50.00'],      // 49.995
            'below half rounds down' => ['0.33', '0.03', '0.01'], // 0.0099
            'tiny half rounds up' => ['0.33', '0.05', '0.02'],    // 0.0165
            'large exact' => ['1000', '9999999999.99', '9999999999990.00'],
        ];
    }

    #[DataProvider('lineRounding')]
    public function test_line_totals_round_half_up_to_two_decimals(string $quantity, string $price, string $expected): void
    {
        $totals = $this->calculator->calculate([$this->line($quantity, $price)]);

        $this->assertSame($expected, $totals->lineTotals[0]);
    }

    public function test_subtotal_is_the_exact_sum_of_many_lines(): void
    {
        $lines = array_fill(0, 100, $this->line('1', '0.01'));

        $totals = $this->calculator->calculate($lines);

        $this->assertCount(100, $totals->lineTotals);
        $this->assertSame('1.00', $totals->subtotal);
        $this->assertSame('1.00', $totals->total);
    }

    public function test_results_do_not_suffer_float_errors(): void
    {
        // In floating point 0.1 + 0.2 === 0.30000000000000004 and 1.025 × 1 rounds to 1.02.
        $totals = $this->calculator->calculate([$this->line('1', '0.10'), $this->line('1', '0.20')]);
        $this->assertSame('0.30', $totals->subtotal);

        $tax = $this->calculator->calculate([$this->line('1', '10.25')], '0', '10');
        $this->assertSame('1.03', $tax->taxAmount);
        $this->assertSame('11.28', $tax->total);
    }

    public function test_discount_is_subtracted_before_tax(): void
    {
        $totals = $this->calculator->calculate([$this->line('2', '500.00')], '100.00', '6');

        $this->assertSame('1000.00', $totals->subtotal);
        $this->assertSame('100.00', $totals->discountAmount);
        $this->assertSame('54.00', $totals->taxAmount); // 6% of 900.00
        $this->assertSame('954.00', $totals->total);
    }

    public function test_tax_rounds_half_up_to_two_decimals(): void
    {
        $down = $this->calculator->calculate([$this->line('1', '10.05')], '0', '6');   // 0.603
        $up = $this->calculator->calculate([$this->line('1', '10.25')], '0', '6');     // 0.615
        $fractional = $this->calculator->calculate([$this->line('1', '99.99')], '0', '8.25'); // 8.249175

        $this->assertSame('0.60', $down->taxAmount);
        $this->assertSame('0.62', $up->taxAmount);
        $this->assertSame('8.25', $fractional->taxAmount);
    }

    public function test_zero_tax_and_null_inputs_default_to_zero(): void
    {
        $totals = $this->calculator->calculate([$this->line('1', '99.99')], null, null);

        $this->assertSame('0.00', $totals->taxAmount);
        $this->assertSame('0.00', $totals->discountAmount);
        $this->assertSame('99.99', $totals->total);
    }

    public function test_discount_equal_to_subtotal_gives_zero_total(): void
    {
        $totals = $this->calculator->calculate([$this->line('1', '250.00')], '250', '10');

        $this->assertSame('0.00', $totals->taxAmount);
        $this->assertSame('0.00', $totals->total);
    }

    public function test_free_lines_are_allowed(): void
    {
        $totals = $this->calculator->calculate([$this->line('1', '0'), $this->line('2', '10')]);

        $this->assertSame(['0.00', '20.00'], $totals->lineTotals);
        $this->assertSame('20.00', $totals->total);
    }

    public function test_maximum_total_that_fits_the_column_is_accepted(): void
    {
        $totals = $this->calculator->calculate([
            $this->line('1000', '9999999999.99'), // 9999999999990.00
            $this->line('1', '9.99'),
        ]);

        $this->assertSame(InvoiceCalculator::MAX_AMOUNT, $totals->total);
    }

    /**
     * @return array<string, array{list<array{quantity: string, unit_price: string}>, string|null, string|null, string}>
     */
    public static function invalidCalculations(): array
    {
        return [
            'line total overflows' => [[['quantity' => '99999999.99', 'unit_price' => '9999999999.99']], '0', '0', 'items.0.unit_price'],
            'subtotal overflows' => [[['quantity' => '1000', 'unit_price' => '9999999999.99'], ['quantity' => '1', 'unit_price' => '10.00']], '0', '0', 'items'],
            'tax pushes total over' => [[['quantity' => '1000', 'unit_price' => '9999999999.99']], '0', '1', 'items'],
            'discount above subtotal' => [[['quantity' => '1', 'unit_price' => '100.00']], '100.01', '0', 'discount_amount'],
            'negative discount' => [[['quantity' => '1', 'unit_price' => '100.00']], '-1', '0', 'discount_amount'],
            'zero quantity' => [[['quantity' => '0', 'unit_price' => '1.00']], '0', '0', 'items.0.quantity'],
            'negative quantity' => [[['quantity' => '-1', 'unit_price' => '1.00']], '0', '0', 'items.0.quantity'],
            'negative price' => [[['quantity' => '1', 'unit_price' => '-0.01']], '0', '0', 'items.0.unit_price'],
            'tax above 100' => [[['quantity' => '1', 'unit_price' => '1.00']], '0', '100.01', 'tax_rate'],
            'negative tax' => [[['quantity' => '1', 'unit_price' => '1.00']], '0', '-1', 'tax_rate'],
            'scientific notation' => [[['quantity' => '1e3', 'unit_price' => '1.00']], '0', '0', 'items.0.quantity'],
            'thousands separator' => [[['quantity' => '1', 'unit_price' => '1,500.00']], '0', '0', 'items.0.unit_price'],
            'second line invalid' => [[['quantity' => '1', 'unit_price' => '1.00'], ['quantity' => '1', 'unit_price' => 'abc']], '0', '0', 'items.1.unit_price'],
        ];
    }

    #[DataProvider('invalidCalculations')]
    public function test_invalid_input_or_overflow_is_rejected_with_the_field(array $lines, ?string $discount, ?string $taxRate, string $field): void
    {
        try {
            $this->calculator->calculate($lines, $discount, $taxRate);
            $this->fail('Expected an InvoiceCalculationException.');
        } catch (InvoiceCalculationException $e) {
            $this->assertSame($field, $e->field);
        }
    }

    public function test_float_input_is_rejected(): void
    {
        $this->expectException(InvoiceCalculationException::class);

        $this->calculator->calculate([['quantity' => 1.5, 'unit_price' => '1.00']]);
    }
}
