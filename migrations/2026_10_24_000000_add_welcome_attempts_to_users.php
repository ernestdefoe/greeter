<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * How many times Greeter has tried to welcome a member, and when it last
 * tried, so a welcome that failed to send is retried later instead of lost.
 * Indexed so the retry sweep reads only the handful still waiting.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'greeter_welcome_attempts')) {
            $schema->table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('greeter_welcome_attempts')->default(0)->index();
                $table->dateTime('greeter_welcome_last_attempt_at')->nullable();
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropIndex(['greeter_welcome_attempts']);
            $table->dropColumn(['greeter_welcome_attempts', 'greeter_welcome_last_attempt_at']);
        });
    },
];
