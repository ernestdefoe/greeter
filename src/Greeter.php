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

    /**
     * Send the welcome. Marked as sent FIRST, so two triggers arriving together
     * cannot welcome somebody twice; a delivery failure is logged, not retried
     * into a second message.
     *
     * @return array{message: string, email: string} what happened to each channel
     */
    public function welcome(User $user, bool $test = false): array
    {
        if (! $test) {
            $claimed = User::query()
                ->where('id', $user->id)
                ->whereNull('greeter_welcomed_at')
                ->update(['greeter_welcomed_at' => Carbon::now()]);

            if (! $claimed) {
                return ['message' => 'already', 'email' => 'already'];
            }
        }

        $channel = (string) ($this->setting('channel') ?: 'message');
        $result = ['message' => 'off', 'email' => 'off'];

        if (in_array($channel, ['message', 'both'], true)) {
            $result['message'] = $this->sendMessage($user);
        }

        if (in_array($channel, ['email', 'both'], true)) {
            $result['email'] = $this->sendEmail($user);
        }

        return $result;
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
