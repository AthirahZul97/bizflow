<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Raised when invoice amounts are invalid or would not fit the database columns.
 *
 * The field names the request input the problem belongs to, so validation can
 * report it next to the right form field.
 */
class InvoiceCalculationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
