<?php

/*
 * Greeter — welcome every new member, once they are actually in.
 */

use Ernestdefoe\Greeter\Api\TestController;
use Ernestdefoe\Greeter\Listener\QueueWelcome;
use Flarum\Extend;
use Flarum\User\Event\Activated;
use Flarum\User\Event\Registered;

$extenders = [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('ernestdefoe-greeter.enabled', false)
        ->default('ernestdefoe-greeter.channel', 'message'),

    (new Extend\Event())
        ->listen(Activated::class, QueueWelcome::class)
        ->listen(Registered::class, QueueWelcome::class),

    (new Extend\Routes('api'))
        ->post('/greeter/test', 'greeter.test', TestController::class),
];

/*
 * 🚨 Only when Gatehouse is installed: naming an event class that is not there
 * is a fatal at boot for the whole forum, not a skipped listener.
 */
if (class_exists(\Ernestdefoe\Gatehouse\Event\Approved::class)) {
    $extenders[] = (new Extend\Event())
        ->listen(\Ernestdefoe\Gatehouse\Event\Approved::class, QueueWelcome::class);
}

return $extenders;
