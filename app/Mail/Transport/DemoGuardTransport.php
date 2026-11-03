<?php

namespace App\Mail\Transport;

use App\Support\DemoMode;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps every mail transport the application builds. In demo mode a message is handed to the real
 * transport only if that transport cannot deliver (log, array); for any other it is cancelled here,
 * before the transport sees it: nothing connects to a mail server, starts sendmail or calls an API.
 *
 * It decides per transport, at send time, so it does not matter which mailer the code picked (the
 * default one or `Mail::mailer('smtp')`), or whether demo mode was switched on after the mailer was
 * built. Returning null is Symfony's "not sent", and Laravel's Mailer::send() passes it on as null:
 * callers must treat that as "not sent" (SendInvoiceEmail does).
 */
final class DemoGuardTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly ?string $transportName,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! DemoMode::transportAllowed($this->transportName)) {
            return null;
        }

        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
