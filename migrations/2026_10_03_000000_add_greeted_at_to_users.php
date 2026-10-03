<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * When Greeter welcomed a member. A member is welcomed once, however many of
 * the triggers fire for them (confirming their email, being approved).
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'greeter_welcomed_at')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dateTime('greeter_welcomed_at')->nullable();
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn('greeter_welcomed_at');
        });
    },
];
