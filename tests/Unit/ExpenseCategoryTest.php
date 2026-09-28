<?php

namespace Tests\Unit;

use App\Enums\ExpenseCategory;
use PHPUnit\Framework\TestCase;

class ExpenseCategoryTest extends TestCase
{
    public function test_stored_values_are_stable(): void
    {
        $this->assertSame(
            ['rent', 'utilities', 'staff', 'materials', 'office', 'equipment', 'software', 'marketing', 'travel', 'meals', 'professional', 'bank_fees', 'other'],
            array_column(ExpenseCategory::cases(), 'value'),
        );
    }

    public function test_every_category_has_the_approved_label(): void
    {
        $labels = [];
        foreach (ExpenseCategory::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        $this->assertSame([
            'rent' => 'Rent',
            'utilities' => 'Utilities & internet',
            'staff' => 'Staff & contractors',
            'materials' => 'Materials & stock purchases',
            'office' => 'Office supplies',
            'equipment' => 'Equipment & repairs',
            'software' => 'Software & subscriptions',
            'marketing' => 'Marketing & advertising',
            'travel' => 'Travel & transport',
            'meals' => 'Meals & entertainment',
            'professional' => 'Professional fees',
            'bank_fees' => 'Bank & payment fees',
            'other' => 'Other',
        ], $labels);
    }

    public function test_every_value_fits_the_category_column(): void
    {
        foreach (ExpenseCategory::cases() as $case) {
            $this->assertLessThanOrEqual(30, strlen($case->value));
        }
    }
}
