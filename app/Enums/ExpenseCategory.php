<?php

namespace App\Enums;

/**
 * Fixed expense categories. Values are stored and must stay stable;
 * labels can be reworded freely.
 */
enum ExpenseCategory: string
{
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Staff = 'staff';
    case Materials = 'materials';
    case Office = 'office';
    case Equipment = 'equipment';
    case Software = 'software';
    case Marketing = 'marketing';
    case Travel = 'travel';
    case Meals = 'meals';
    case Professional = 'professional';
    case BankFees = 'bank_fees';
    case Other = 'other';

    /**
     * Get the human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Utilities => 'Utilities & internet',
            self::Staff => 'Staff & contractors',
            self::Materials => 'Materials & stock purchases',
            self::Office => 'Office supplies',
            self::Equipment => 'Equipment & repairs',
            self::Software => 'Software & subscriptions',
            self::Marketing => 'Marketing & advertising',
            self::Travel => 'Travel & transport',
            self::Meals => 'Meals & entertainment',
            self::Professional => 'Professional fees',
            self::BankFees => 'Bank & payment fees',
            self::Other => 'Other',
        };
    }
}
