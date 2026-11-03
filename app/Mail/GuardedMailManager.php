<?php

namespace App\Mail;

use App\Mail\Transport\DemoGuardTransport;
use Illuminate\Mail\MailManager;

/**
 * Laravel's mail manager, with every transport it creates wrapped in DemoGuardTransport. Every
 * mailer goes through createSymfonyTransport() (named ones, the default one, the children of a
 * failover or round-robin mailer, and custom `Mail::extend()` drivers), so no mailer can be selected
 * that skips the demo-mode guard.
 */
class GuardedMailManager extends MailManager
{
    public function createSymfonyTransport(array $config)
    {
        return new DemoGuardTransport(parent::createSymfonyTransport($config), $config['transport'] ?? null);
    }
}
