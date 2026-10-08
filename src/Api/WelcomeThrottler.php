<?php

namespace Ernestdefoe\Greeter\Api;

use Ernestdefoe\Greeter\Greeter;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The welcome is the forum speaking, not the member it is sent as. Flarum
 * 2.0's flarum/messages throttles a member's messages (one every 10 seconds,
 * 10 new conversations an hour), and a sender who is not an admin is held to
 * that, so a second sign-up within 10 seconds, or the eleventh in an hour,
 * went unwelcomed. Only the internal request Greeter itself makes is exempt:
 * nothing from a browser is internal.
 */
class WelcomeThrottler
{
    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (Greeter::sending()
            && RequestUtil::isInternal($request)
            && $request->getAttribute('routeName') === 'dialog-messages.create') {
            return false;
        }

        return null;
    }
}
