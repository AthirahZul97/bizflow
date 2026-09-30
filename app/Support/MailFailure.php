<?php

namespace App\Support;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Decides whether a failed send is worth retrying, and produces a short,
 * safe error message for the send history.
 *
 * Based on what the installed Symfony Mailer (7.4) actually throws:
 * - Symfony\Component\Mime\Exception\RfcComplianceException for an address that
 *   is not valid RFC 2822: retrying can never help.
 * - Symfony\Component\Mailer\Exception\UnexpectedResponseException for an SMTP
 *   reply other than the expected one, with the SMTP reply code as the exception
 *   code (SmtpTransport::assertResponseCode()).
 * - Symfony\Component\Mailer\Exception\TransportException for everything else
 *   (connection, TLS, authentication), with no reliable reply code.
 *
 * Only failures that are certainly permanent are treated as permanent: an invalid
 * address, or the SMTP replies RFC 5321 defines as permanent rejections of the
 * mailbox or address (550, 551, 553). Everything else, including other 5xx replies
 * such as 554 (often a temporary policy block) and authentication failures, is
 * retried; the queue's retry limit still ends it as failed.
 */
final class MailFailure
{
    /**
     * SMTP reply codes that permanently reject the mailbox or address.
     */
    private const PERMANENT_SMTP_CODES = [550, 551, 553];

    /**
     * Longest error message stored in the send history (matches the column).
     */
    private const MAX_MESSAGE = 500;

    public static function isPermanent(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RfcComplianceException) {
                return true;
            }

            if ($current instanceof UnexpectedResponseException
                && in_array((int) $current->getCode(), self::PERMANENT_SMTP_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A one-line, length-limited description of the failure, for showing to the user.
     * Mail transport messages hold server replies, never our credentials.
     */
    public static function message(Throwable $e): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));

        return mb_strimwidth($message !== '' ? $message : class_basename($e), 0, self::MAX_MESSAGE, '…');
    }
}
