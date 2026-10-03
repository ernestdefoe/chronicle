<?php

namespace Ernestdefoe\Chronicle\Api\Controller;

use Carbon\Carbon;
use Ernestdefoe\Chronicle\Feed\Chronicle;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/chronicle/{id}?before=<iso>&key=<type:id>&types=discussion,reply
 *
 * One page of a member's activity. `before` and `key` are the `next` cursor of
 * the previous page; `before` alone also works (everything strictly older).
 */
class ChronicleController implements RequestHandlerInterface
{
    public function __construct(protected Chronicle $chronicle)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $user = User::query()->whereVisibleTo($actor)->find((int) ($params['id'] ?? 0));

        if (! $user) {
            throw new ModelNotFoundException();
        }

        if ((int) $actor->id !== (int) $user->id && ! $actor->hasPermission('ernestdefoe-chronicle.viewActivity')) {
            throw new PermissionDeniedException();
        }

        return new JsonResponse(
            $this->chronicle->page($actor, $user, $this->types($params['types'] ?? null), $this->cursor($params))
        );
    }

    private function types(mixed $types): ?array
    {
        if (! is_string($types) || trim($types) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $types))));
    }

    private function cursor(array $params): ?array
    {
        $before = $params['before'] ?? null;

        if (! is_string($before) || $before === '') {
            return null;
        }

        try {
            $time = Carbon::parse($before)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }

        $type = null;
        $id = null;

        if (is_string($params['key'] ?? null) && preg_match('/^([a-z_]+):(\d+)$/', $params['key'], $m)) {
            [$type, $id] = [$m[1], (int) $m[2]];
        }

        return [$time, $type, $id];
    }
}
