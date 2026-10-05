<?php

namespace Tests\Unit\Mysql;

use Tests\Mysql\MysqlTestCase;
use Tests\TestCase;

/**
 * The MySQL worker command line must work on machines that load no php.ini (a bare CI image),
 * and must not hard-code any path. These run without MySQL.
 */
class WorkerCommandTest extends TestCase
{
    public function test_a_loaded_ini_is_passed_on(): void
    {
        $command = MysqlTestCase::workerCommand(['action' => 'x'], '/etc/php/php.ini');

        $this->assertSame([PHP_BINARY, '-c', '/etc/php/php.ini', base_path('tests/Mysql/worker.php'), '{"action":"x"}'], $command);
    }

    public function test_no_loaded_ini_means_no_dash_c(): void
    {
        foreach ([false, '', null] as $ini) {
            $command = MysqlTestCase::workerCommand(['action' => 'x'], $ini);

            $this->assertSame([PHP_BINARY, base_path('tests/Mysql/worker.php'), '{"action":"x"}'], $command);
            $this->assertNotContains('-c', $command);
            $this->assertNotContains('', $command, 'no empty argument');
        }
    }

    public function test_the_payload_stays_a_single_json_argument(): void
    {
        $command = MysqlTestCase::workerCommand(['action' => 'confirm_receipt', 'values' => ['amount' => '1.00']], false);

        $this->assertSame(['action' => 'confirm_receipt', 'values' => ['amount' => '1.00']], json_decode(end($command), true));
    }
}
