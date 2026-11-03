<?php

namespace App\Support;

/**
 * What the deployment profile allows. Every answer comes from configuration (the environment),
 * never from a request: a parameter, header or cookie cannot switch demo mode off, reopen
 * registration or turn email delivery back on.
 *
 * Demo mode only ever takes things away. It has no say in who owns what or in what a plan
 * allows; those stay with the business scoping and EntitlementService.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('bizflow.demo');
    }

    /**
     * Whether people can create their own account. Demo accounts are provisioned, not self-served.
     */
    public static function registrationOpen(): bool
    {
        return ! self::enabled() && config('bizflow.registration') === 'open';
    }

    /**
     * Password reset and email verification only work by sending email, so they go with it.
     */
    public static function emailFlowsEnabled(): bool
    {
        return ! self::enabled();
    }

    /**
     * Whether the default mailer keeps a message inside the application (the MessageSending
     * listener asks this; the per-transport guard asks transportAllowed() for every mailer). In demo mode only the
     * "log" and "array" transports qualify; anything else (smtp, ses, a failover that includes
     * one) could deliver to a real mailbox. Outside demo mode every transport is acceptable here;
     * "bizflow:preflight" is what checks a production transport.
     */
    public static function deliveryAllowed(): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return self::transportAllowed(self::defaultTransport());
    }

    /**
     * Whether a message may be handed to this transport (by its config name: smtp, ses, log...).
     * Used for every mailer, not just the default, by App\Mail\Transport\DemoGuardTransport.
     */
    public static function transportAllowed(?string $transport): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return in_array($transport, (array) config('bizflow.mail.demo_transports'), true);
    }

    /**
     * Whether an invoice email may be queued, claimed or delivered right now.
     */
    public static function invoiceEmailAllowed(): bool
    {
        return ! self::enabled() && self::deliveryAllowed();
    }

    public static function invoiceEmailRefusal(): string
    {
        return 'Emailing invoices is turned off in the demo, so nothing is sent. Download the PDF instead.';
    }

    public static function defaultTransport(): ?string
    {
        $mailer = config('mail.default');

        return is_string($mailer) ? (config("mail.mailers.{$mailer}.transport") ?? null) : null;
    }
}
