<?php

namespace Ernestdefoe\Greeter;

use Carbon\Carbon;
use Flarum\Api\Client as ApiClient;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Mail\Job\SendInformationalEmailJob;
use Flarum\Mail\SafeSubstitution;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Writes and delivers the welcome. One place decides whether a member gets
 * one, so the three ways in — confirming an email, being approved, signing up
 * already confirmed — cannot disagree.
 */
class Greeter
{
    /**
     * Set only while the welcome is being handed to flarum/messages, so
     * {@see Api\WelcomeThrottler} can exempt that one internal request.
     */
    private static bool $sending = false;

    public static function sending(): bool
    {
        return self::$sending;
    }

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
        private TranslatorInterface $translator,
        private UrlGenerator $url,
        private Queue $queue,
        private Container $container,
        private LoggerInterface $log,
    ) {
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->get('ernestdefoe-greeter.'.$key, $default);
    }

    /**
     * Whether this member should be welcomed now.
     *
     * 🚨 Not before they are actually in. A welcome at sign-up reaches every
     * bot that fills the form, and greets an applicant Gatehouse is still
     * holding — "welcome!" to someone who cannot post yet.
     */
    public function due(User $user): bool
    {
        return (bool) $this->setting('enabled')
            && $user->getAttribute('greeter_welcomed_at') === null
            && $user->is_email_confirmed
            && ! in_array($user->getAttribute('gatehouse_status'), ['pending', 'declined'], true);
    }

    /** A welcome that keeps failing is tried this many times, then left alone. */
    public const MAX_ATTEMPTS = 5;

    /** How long to wait after failed attempt $attempt: 15 minutes, 1 hour, 4 hours, 16 hours. */
    public static function backoffMinutes(int $attempt): int
    {
        return 15 * 4 ** max(0, $attempt - 1);
    }

    /** Whether a member with $attempts failed attempts, the last at $last, may be tried again now. */
    public static function retryDue(int $attempts, mixed $last): bool
    {
        if ($attempts === 0) {
            return true;
        }

        if ($attempts >= self::MAX_ATTEMPTS || $last === null) {
            return false;
        }

        return Carbon::parse($last)->addMinutes(self::backoffMinutes($attempts))->lte(Carbon::now());
    }

    /**
     * Send the welcome.
     *
     * 🚨 Marked as sent only AFTER a channel has delivered it — the moment the
     * send call returns, before anything else can fail — so a failed send is
     * retried ({@see Console\RetryWelcomesCommand}) and a successful one is never
     * repeated. Two triggers arriving together cannot both send: each attempt
     * first claims itself by bumping the attempt count from the value it read,
     * and only one of them can.
     *
     * @return array{message: string, email: string} what happened to each channel
     */
    public function welcome(User $user, bool $test = false): array
    {
        $attempts = 0;

        if (! $test) {
            // A stale $user is safe: the claim below then matches no row.
            if ($user->getAttribute('greeter_welcomed_at') !== null) {
                return ['message' => 'already', 'email' => 'already'];
            }

            $attempts = (int) $user->getAttribute('greeter_welcome_attempts');

            if (! self::retryDue($attempts, $user->getAttribute('greeter_welcome_last_attempt_at'))) {
                return ['message' => 'later', 'email' => 'later'];
            }

            $claimed = User::query()
                ->whereKey($user->id)
                ->whereNull('greeter_welcomed_at')
                ->where('greeter_welcome_attempts', $attempts)
                ->update([
                    'greeter_welcome_attempts' => $attempts + 1,
                    'greeter_welcome_last_attempt_at' => Carbon::now(),
                ]);

            if (! $claimed) {
                return ['message' => 'already', 'email' => 'already'];
            }
        }

        $channel = (string) ($this->setting('channel') ?: 'message');
        $result = ['message' => 'off', 'email' => 'off'];
        $marked = false;

        foreach (['message', 'email'] as $kind) {
            if (! in_array($channel, [$kind, 'both'], true)) {
                continue;
            }

            $result[$kind] = $kind === 'message' ? $this->sendMessage($user) : $this->sendEmail($user);

            if (! $test && ! $marked && $result[$kind] === 'sent') {
                $this->markWelcomed($user);
                $marked = true;
            }
        }

        if (! $test && ! $marked) {
            if (in_array('failed', $result, true)) {
                $this->log->warning('[greeter] the welcome to '.$user->id.' failed (attempt '.($attempts + 1).' of '.self::MAX_ATTEMPTS.')'
                    .($attempts + 1 < self::MAX_ATTEMPTS ? '; it will be retried' : '; giving up'));
            } else {
                // Nothing to deliver it through (no message system, no sender):
                // retrying cannot help, so it counts as done, as it always has.
                $this->markWelcomed($user);
            }
        }

        return $result;
    }

    private function markWelcomed(User $user): void
    {
        User::query()->whereKey($user->id)->update(['greeter_welcomed_at' => Carbon::now()]);
    }

    /** The welcome text with its placeholders filled. */
    public function text(User $user, string $kind): string
    {
        $raw = trim((string) $this->setting($kind));

        if ($raw === '') {
            $raw = (string) $this->translator->trans('ernestdefoe-greeter.lib.default_'.$kind);
        }

        return strtr($raw, [
            '{username}' => (string) $user->username,
            '{display_name}' => (string) $user->display_name,
            '{forum}' => (string) $this->settings->get('forum_title'),
            '{url}' => $this->url->to('forum')->base(),
        ]);
    }

    /** The account the welcome comes from: the one named in settings, else the first admin. */
    public function sender(): ?User
    {
        $name = trim((string) $this->setting('sender'));

        if ($name !== '') {
            $named = User::query()->where('username', $name)->first();

            if ($named) {
                return $named;
            }
        }

        return User::query()
            ->whereHas('groups', fn ($q) => $q->where('id', Group::ADMINISTRATOR_ID))
            ->orderBy('id')
            ->first();
    }

    /** Which private-message system will carry it, or null if none is enabled. */
    public function messageSystem(): ?string
    {
        if ($this->extensions->isEnabled('ernestdefoe-parley')) {
            return 'parley';
        }

        if ($this->extensions->isEnabled('flarum-messages')) {
            return 'flarum';
        }

        return null;
    }

    private function sendMessage(User $user): string
    {
        $system = $this->messageSystem();
        $sender = $this->sender();

        if ($system === null) {
            return 'no_messages';
        }

        if (! $sender || $sender->id === $user->id) {
            return 'no_sender';
        }

        $body = $this->text($user, 'body');

        try {
            if ($system === 'parley') {
                $conversations = $this->container->make(\Ernestdefoe\Parley\Conversations::class);
                $conversation = $conversations->pair($sender, $user);
                $conversations->send($conversation, $sender, 'text', $body);
            } else {
                self::$sending = true;

                try {
                    $response = $this->container->make(ApiClient::class)
                        ->withActor($sender)
                        ->withBody(['data' => [
                            'type' => 'dialog-messages',
                            'attributes' => [
                                'content' => $body,
                                'users' => [['type' => 'users', 'id' => (string) $user->id]],
                            ],
                        ]])
                        ->post('/dialog-messages');
                } finally {
                    self::$sending = false;
                }

                // 🚨 The API client does not throw on a refusal; it returns it.
                if ($response->getStatusCode() >= 300) {
                    $this->log->warning('[greeter] the welcome message to '.$user->id.' was refused: '.substr((string) $response->getBody(), 0, 300));

                    return 'failed';
                }
            }

            return 'sent';
        } catch (\Throwable $e) {
            $this->log->warning('[greeter] could not send the welcome message to '.$user->id.': '.$e->getMessage());

            return 'failed';
        }
    }

    private function sendEmail(User $user): string
    {
        try {
            $forum = (string) $this->settings->get('forum_title');

            /*
             * 🚨 The admin's text goes in as ONE safe-substitution marker. Core's
             * email layout escapes the body and the finished message is escaped
             * again, so plain text arrives double-escaped: "Don't" became
             * "Don&amp;#039;t". A marked value is restored once, escaped once.
             */
            $body = SafeSubstitution::mark(['body' => $this->text($user, 'body')])['body'];

            $this->queue->push(new SendInformationalEmailJob(
                email: $user->email,
                displayName: $user->display_name,
                subject: $this->text($user, 'subject'),
                body: $body,
                forumTitle: $forum,
                locale: $user->getPreference('locale') ?? $this->settings->get('default_locale'),
            ));

            return 'sent';
        } catch (\Throwable $e) {
            $this->log->warning('[greeter] could not email the welcome to '.$user->id.': '.$e->getMessage());

            return 'failed';
        }
    }
}
