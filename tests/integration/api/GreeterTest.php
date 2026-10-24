<?php

namespace Ernestdefoe\Greeter\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Greeter\Console\RetryWelcomesCommand;
use Ernestdefoe\Greeter\Greeter;
use Flarum\Api\Client as ApiClient;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class GreeterTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** While true, handing the welcome to flarum/messages throws, as a down server would. */
    private static bool $sendFails = false;

    protected function tearDown(): void
    {
        self::$sendFails = false;
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function failSends(): void
    {
        self::$sendFails = true;

        $this->app()->getContainer()->extend(ApiClient::class, function ($client) {
            if (self::$sendFails) {
                throw new \RuntimeException('The message server is down');
            }

            return $client;
        });
    }

    /** What the scheduler does every five minutes. */
    private function retry(): void
    {
        $this->app()->getContainer()->make(RetryWelcomesCommand::class)->run(new ArrayInput([]), new NullOutput());
    }

    /** @return array{0: ?string, 1: int} when the user was welcomed, and how many attempts it took */
    private function state(int $user): array
    {
        $row = $this->database()->table('users')->where('id', $user)->first(['greeter_welcomed_at', 'greeter_welcome_attempts']);

        return [$row->greeter_welcomed_at, (int) $row->greeter_welcome_attempts];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-messages', 'ernestdefoe-greeter');

        $this->setting('ernestdefoe-greeter.enabled', true);
        $this->setting('ernestdefoe-greeter.body', 'Welcome to {forum}, {username}!');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    private function register(bool $confirmed, string $username = 'newcomer'): int
    {
        $response = $this->send($this->request('POST', '/api/users', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'users', 'attributes' => [
                'username' => $username,
                'email' => $username.'@machine.local',
                'password' => 'a-long-enough-password',
                'isEmailConfirmed' => $confirmed,
            ]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) json_decode((string) $response->getBody(), true)['data']['id'];
    }

    /** @return string[] the welcome messages the user has received */
    private function welcomes(int $user): array
    {
        return $this->database()->table('dialog_messages')
            ->join('dialog_user', 'dialog_user.dialog_id', '=', 'dialog_messages.dialog_id')
            ->where('dialog_user.user_id', $user)
            ->where('dialog_messages.user_id', '!=', $user)
            ->pluck('dialog_messages.content')
            ->all();
    }

    private function test(int $actor): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/greeter/test', ['authenticatedAs' => $actor, 'json' => []]));
    }

    #[Test]
    public function a_confirmed_newcomer_is_welcomed_once_by_message()
    {
        $id = $this->register(true);

        $welcomes = $this->welcomes($id);
        $this->assertCount(1, $welcomes);
        $this->assertStringContainsString('newcomer', $welcomes[0]);
        $this->assertNotNull($this->database()->table('users')->where('id', $id)->value('greeter_welcomed_at'));

        // The same event again — a retry, or a second listener — sends nothing.
        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new Registered(User::query()->findOrFail($id), User::query()->findOrFail(1), []));
        $this->assertCount(1, $this->welcomes($id));

        // Two jobs racing past that check: only the one that claims the row sends.
        $result = $this->app()->getContainer()->make(Greeter::class)->welcome(User::query()->findOrFail($id));
        $this->assertSame(['message' => 'already', 'email' => 'already'], $result);
        $this->assertCount(1, $this->welcomes($id));
    }

    #[Test]
    public function an_unconfirmed_newcomer_is_welcomed_when_activated()
    {
        $id = $this->register(false);
        $this->assertSame([], $this->welcomes($id), 'Not before the address is confirmed');

        $response = $this->send($this->request('PATCH', "/api/users/$id", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'users', 'id' => (string) $id, 'attributes' => ['isEmailConfirmed' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCount(1, $this->welcomes($id));
    }

    #[Test]
    public function every_newcomer_is_welcomed_however_fast_they_arrive()
    {
        // A sender who is not an admin, so nothing exempts them from Flarum
        // 2.0's limits on members' messages: one every 10 seconds, and 10 new
        // conversations an hour. A welcome is the forum speaking, not a member,
        // and a busy hour of sign-ups must not silence it.
        $this->setting('ernestdefoe-greeter.sender', 'normal');

        for ($i = 1; $i <= 11; $i++) {
            $id = $this->register(true, "newcomer$i");
            $this->assertCount(1, $this->welcomes($id), "Newcomer $i is welcomed");
        }
    }

    #[Test]
    public function nobody_is_welcomed_while_greeter_is_off()
    {
        $this->setting('ernestdefoe-greeter.enabled', false);

        $id = $this->register(true);

        $this->assertSame([], $this->welcomes($id));
        $this->assertNull($this->database()->table('users')->where('id', $id)->value('greeter_welcomed_at'));
    }

    #[Test]
    public function the_test_send_is_for_admins_only()
    {
        $this->assertSame(403, $this->test(2)->getStatusCode());

        $response = $this->test(1);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('flarum', $body['system']);
        $this->assertSame('admin', $body['sender']);
        $this->assertSame('no_sender', $body['message'], 'An admin is never messaged by themself');
        $this->assertNull($this->database()->table('users')->where('id', 1)->value('greeter_welcomed_at'), 'A test does not use up the real welcome');
    }

    #[Test]
    public function a_welcome_that_fails_to_send_is_retried_later_and_sent_once()
    {
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->failSends();

        $id = $this->register(true);

        $this->assertSame([], $this->welcomes($id));
        $this->assertSame([null, 1], $this->state($id), 'A failed send is not marked as sent');

        // Not before the backoff has passed.
        self::$sendFails = false;
        Carbon::setTestNow('2026-10-24 12:10:00');
        $this->retry();
        $this->assertSame([], $this->welcomes($id));
        $this->assertSame([null, 1], $this->state($id));

        Carbon::setTestNow('2026-10-24 12:16:00');
        $this->retry();
        $this->assertCount(1, $this->welcomes($id), 'The retry delivers the welcome');
        [$welcomedAt, $attempts] = $this->state($id);
        $this->assertNotNull($welcomedAt);
        $this->assertSame(2, $attempts);

        // A welcome that went out is never sent again, by a retry or a trigger.
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->retry();
        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new Registered(User::query()->findOrFail($id), User::query()->findOrFail(1), []));
        $this->assertCount(1, $this->welcomes($id));
    }

    #[Test]
    public function a_welcome_that_keeps_failing_stops_after_five_attempts()
    {
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->failSends();

        $id = $this->register(true);

        // The wait grows: 15 minutes, 1 hour, 4 hours, 16 hours.
        foreach (['12:16', '13:17', '17:18', '2026-10-25 09:19'] as $i => $at) {
            Carbon::setTestNow(str_contains($at, '-') ? $at : "2026-10-24 $at");
            $this->retry();
            $this->assertSame([null, $i + 2], $this->state($id));
        }

        // The fifth failure was the last: no more attempts, even once it would work.
        self::$sendFails = false;
        Carbon::setTestNow('2026-11-24 12:00:00');
        $this->retry();
        $this->assertSame([null, 5], $this->state($id));
        $this->assertSame([], $this->welcomes($id));
    }
}
