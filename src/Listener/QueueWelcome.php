<?php

namespace Ernestdefoe\Greeter\Listener;

use Ernestdefoe\Greeter\Greeter;
use Ernestdefoe\Greeter\Job\WelcomeJob;
use Illuminate\Contracts\Queue\Queue;

/**
 * Every way a member becomes a member ends here:
 *
 *   - Activated: they confirmed their email (the usual case);
 *   - Registered: they signed up already confirmed (a social login);
 *   - Gatehouse's Approved: an admin let a held applicant in, who may have
 *     confirmed their email long before.
 *
 * Greeter::due() decides; this only notices.
 */
class QueueWelcome
{
    public function __construct(private Greeter $greeter, private Queue $queue)
    {
    }

    public function handle(object $event): void
    {
        $user = $event->user ?? null;

        if ($user && $this->greeter->due($user)) {
            $this->queue->push(new WelcomeJob((int) $user->id));
        }
    }
}
