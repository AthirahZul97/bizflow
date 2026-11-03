<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's verification email, sent by the queue worker so a slow or failing mail server cannot
 * break the request (registration, or "resend") that asked for it. Its link is signed, so the queued
 * payload is encrypted like the password reset one.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
}
