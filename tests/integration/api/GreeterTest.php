<?php

namespace Ernestdefoe\Greeter\Tests\integration\api;

use Ernestdefoe\Greeter\Greeter;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class GreeterTest extends TestCase
{
    use RetrievesAuthorizedUsers;

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
}
