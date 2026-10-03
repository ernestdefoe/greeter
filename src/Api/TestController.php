<?php

namespace Ernestdefoe\Greeter\Api;

use Ernestdefoe\Greeter\Greeter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/greeter/test: send the welcome to the admin pressing the button,
 * exactly as a new member would get it — from the saved settings — and say what
 * happened to each channel. Never marks them as welcomed.
 */
class TestController implements RequestHandlerInterface
{
    public function __construct(private Greeter $greeter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        return new JsonResponse($this->greeter->welcome($actor, true) + [
            'system' => $this->greeter->messageSystem(),
            'sender' => $this->greeter->sender()?->username,
        ]);
    }
}
