<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeZone;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ActivityFeedEntry;
use Forwext\Core\Profile\Activity\ActivityFeedService;
use Forwext\Core\Profile\Activity\ProfileActivityException;

final readonly class ActivityFeedHandler implements RequestHandlerInterface
{
    public function __construct(private ActivityFeedService $service, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        try {
            $items = $this->service->feed($actor, $this->queryInt($request, 'limit', 50), $this->queryInt($request, 'offset', 0));
            return $this->json(['items' => array_map($this->serialize(...), $items)]);
        } catch (ProfileActivityException) {
            return $this->json(['error' => 'invalid_activity_request'], 400);
        }
    }

    /** @return array<string,mixed> */
    private function serialize(ActivityFeedEntry $entry): array
    {
        return [
            'type' => $entry->type->value,
            'actor_user_id' => $entry->actorUserId?->value(),
            'subject_id' => $entry->subjectId->value(),
            'forum_node_id' => $entry->forumNodeId?->value(),
            'profile_owner_user_id' => $entry->profileOwnerUserId?->value(),
            'profile_post_id' => $entry->profilePostId?->value(),
            'occurred_at_utc' => $entry->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'summary' => $entry->summary,
        ];
    }
    private function queryInt(Request $request, string $key, int $default): int { $v = $request->query()[$key] ?? null; return is_string($v) && preg_match('/^[0-9]{1,7}$/D', $v) === 1 ? (int) $v : $default; }
    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
