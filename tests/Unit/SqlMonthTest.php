<?php

namespace Tests\Unit;

use App\Support\SqlMonth;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SqlMonthTest extends TestCase
{
    public function test_mysql_expression(): void
    {
        $this->assertSame("DATE_FORMAT(paid_at, '%Y-%m')", SqlMonth::expression('mysql', 'paid_at'));
    }

    public function test_sqlite_expression(): void
    {
        $this->assertSame("strftime('%Y-%m', expense_date)", SqlMonth::expression('sqlite', 'expense_date'));
    }

    public function test_unknown_driver_falls_back_to_the_mysql_expression(): void
    {
        $this->assertSame("DATE_FORMAT(issue_date, '%Y-%m')", SqlMonth::expression('mariadb', 'issue_date'));
    }

    public function test_only_plain_column_names_are_accepted(): void
    {
        foreach (['paid_at; drop table users', 'paid_at)', "paid_at'", 'Paid At', ''] as $column) {
            try {
                SqlMonth::expression('mysql', $column);
                $this->fail("Accepted unsafe column name: {$column}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
