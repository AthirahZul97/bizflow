<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's reset-password email, sent by the queue worker instead of during the request. The
 * request then takes the same time whether or not the address belongs to an account, so the
 * "forgot password" form does not tell a stranger which addresses are registered.
 *
 * The notification holds the reset token, which is a bearer credential, so the queued payload is
 * encrypted (ShouldBeEncrypted): neither the jobs table nor a failed_jobs row contains it in the clear.
 */
class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
}
