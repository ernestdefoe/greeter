<?php

namespace Ernestdefoe\Greeter\Console;

use Carbon\Carbon;
use Ernestdefoe\Greeter\Greeter;
use Ernestdefoe\Greeter\Job\WelcomeJob;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;

/**
 * Runs on Flarum's scheduler and queues another try for every welcome that
 * failed to send and whose backoff has passed. Reads only members with a
 * failed attempt on record (an indexed column), so a quiet forum costs one
 * empty query every few minutes.
 */
class RetryWelcomesCommand extends AbstractCommand
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Queue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('greeter:retry')
            ->setDescription('Retry welcomes that failed to send.');
    }

    protected function fire(): int
    {
        if (! $this->settings->get('ernestdefoe-greeter.enabled')) {
            return 0;
        }

        User::query()
            ->whereNull('greeter_welcomed_at')
            ->whereBetween('greeter_welcome_attempts', [1, Greeter::MAX_ATTEMPTS - 1])
            ->where('greeter_welcome_last_attempt_at', '<=', Carbon::now()->subMinutes(Greeter::backoffMinutes(1)))
            ->limit(200)
            ->get(['id', 'greeter_welcome_attempts', 'greeter_welcome_last_attempt_at'])
            ->filter(fn (User $user) => Greeter::retryDue((int) $user->getAttribute('greeter_welcome_attempts'), $user->getAttribute('greeter_welcome_last_attempt_at')))
            ->each(fn (User $user) => $this->queue->push(new WelcomeJob((int) $user->id)));

        return 0;
    }
}
