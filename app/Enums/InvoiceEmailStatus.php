<?php

namespace App\Enums;

/**
 * Where one invoice email send request is.
 *
 * queued -> sending -> sent
 *                   -> queued  (transient failure, will be retried)
 *                   -> failed  (permanent failure, retries used up, or interrupted)
 * queued -> skipped            (invoice no longer sendable, or superseded)
 *
 * Only the queued -> sending change sends an email, and it is made with a
 * conditional update, so a row can never be sent twice.
 */
enum InvoiceEmailStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Not sent',
        };
    }

    /**
     * The Bootstrap badge colour for the status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued => 'text-bg-secondary',
            self::Sending => 'text-bg-primary',
            self::Sent => 'text-bg-success',
            self::Failed => 'text-bg-danger',
            self::Skipped => 'text-bg-warning',
        };
    }

    /**
     * Whether the send is still in progress (queued or being sent).
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Sending;
    }

    /**
     * Whether nothing more will happen to the send.
     */
    public function isFinal(): bool
    {
        return ! $this->isActive();
    }
}
