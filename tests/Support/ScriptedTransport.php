<?php

namespace Tests\Support;

use Closure;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Throwable;

/**
 * A mail transport for tests. Each send takes the next scripted step:
 * a Throwable is thrown, a Closure runs (and the message is then accepted),
 * and anything else (or no step left) simply accepts the message.
 * Accepted messages are kept, like Laravel's array transport.
 */
class ScriptedTransport extends AbstractTransport
{
    /**
     * @var list<SentMessage>
     */
    public array $sent = [];

    public int $calls = 0;

    /**
     * @param  list<Throwable|Closure|null>  $steps
     */
    public function __construct(private array $steps = [])
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->calls++;
        $step = array_shift($this->steps);

        if ($step instanceof Throwable) {
            throw $step;
        }

        if ($step instanceof Closure) {
            $step($message);
        }

        $this->sent[] = $message;
    }

    public function __toString(): string
    {
        return 'scripted://';
    }
}
