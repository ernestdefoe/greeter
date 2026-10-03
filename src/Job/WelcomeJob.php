<?php

namespace Ernestdefoe\Greeter\Job;

use Ernestdefoe\Greeter\Greeter;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * The welcome, sent off the request that triggered it, so confirming an email
 * or approving someone never waits on a mail server.
 */
class WelcomeJob extends AbstractJob
{
    public function __construct(private int $userId)
    {
    }

    public function handle(Greeter $greeter): void
    {
        $user = User::query()->find($this->userId);

        if ($user && $greeter->due($user)) {
            $greeter->welcome($user);
        }
    }
}
