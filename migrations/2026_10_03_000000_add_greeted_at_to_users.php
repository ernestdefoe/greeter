<?php

use Flarum\Database\Migration;

/*
 * When Greeter welcomed a member. A member is welcomed once, however many of
 * the triggers fire for them (confirming their email, being approved).
 */
return Migration::addColumns('users', [
    'greeter_welcomed_at' => ['dateTime', 'nullable' => true],
]);
