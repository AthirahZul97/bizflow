<?php

namespace Tests\Unit;

use App\Support\MailFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

class MailFailureTest extends TestCase
{
    /**
     * @return array<string, array{Throwable, bool}>
     */
    public static function failures(): array
    {
        return [
            'invalid address (RFC)' => [new RfcComplianceException('Email "x@" does not comply with addr-spec of RFC 2822.'), true],
            '550 mailbox unavailable' => [new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550), true],
            '551 user not local' => [new UnexpectedResponseException('got code "551"', 551), true],
            '553 mailbox name not allowed' => [new UnexpectedResponseException('got code "553"', 553), true],
            '554 transaction failed (often a policy block)' => [new UnexpectedResponseException('got code "554"', 554), false],
            '552 storage exceeded' => [new UnexpectedResponseException('got code "552"', 552), false],
            '450 mailbox busy' => [new UnexpectedResponseException('got code "450"', 450), false],
            '421 service not available' => [new UnexpectedResponseException('got code "421"', 421), false],
            'empty reply code' => [new UnexpectedResponseException('got empty code', 0), false],
            'authentication failed (535)' => [new TransportException('Failed to authenticate on SMTP server', 535), false],
            'connection refused' => [new TransportException('Connection could not be established with host "smtp.test:587"'), false],
            'unknown error' => [new RuntimeException('Something else went wrong'), false],
        ];
    }

    #[DataProvider('failures')]
    public function test_only_certainly_permanent_failures_are_permanent(Throwable $e, bool $permanent): void
    {
        $this->assertSame($permanent, MailFailure::isPermanent($e));
    }

    public function test_a_wrapped_permanent_failure_is_found(): void
    {
        $wrapped = new RuntimeException('Mailer failed', 0, new UnexpectedResponseException('got code "550"', 550));

        $this->assertTrue(MailFailure::isPermanent($wrapped));
    }

    public function test_the_message_is_one_short_line(): void
    {
        $message = MailFailure::message(new TransportException("Line one\r\n  line two\t".str_repeat('x', 800)));

        $this->assertStringStartsWith('Line one line two ', $message);
        $this->assertStringNotContainsString("\n", $message);
        $this->assertLessThanOrEqual(500, mb_strlen($message));
    }

    public function test_an_empty_message_falls_back_to_the_exception_name(): void
    {
        $this->assertSame('TransportException', MailFailure::message(new TransportException('')));
    }
}
