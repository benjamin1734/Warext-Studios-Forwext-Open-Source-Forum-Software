<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeZone;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ActivityFeedEntry;
use Forwext\Core\Profile\Activity\ActivityFeedService;
use Forwext\Core\Profile\Activity\ActivityFeedType;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Routing\BasePath;

final readonly class ActivityFeedHandler implements RequestHandlerInterface
{
    /**
     * @param null|list<ActivityFeedType> $types
     */
    public function __construct(
        private ActivityFeedService $service,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private DateTimeZone $timezone,
        private ?array $types = null,
        private string $title = 'Neler yeni?',
        private string $description = 'Erişebildiğin forum ve profil hareketlerini kronolojik olarak takip et.',
        private string $routePath = '/activity',
    ) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        try {
            if ($this->wantsHtml($request)) {
                $page = $this->page($request->query()['page'] ?? null);
                $items = $this->service->feed($actor, 31, ($page - 1) * 30, $this->types);
                $hasMore = count($items) > 30;
                if ($hasMore) {
                    array_pop($items);
                }
                return Response::html(ActivityFeedHtml::page(
                    $items,
                    $page,
                    $hasMore,
                    $this->basePath,
                    $this->timezone,
                    $this->title,
                    $this->description,
                    $this->routePath,
                ))->withHeader('Cache-Control', 'private, no-store')
                    ->withHeader('X-Robots-Tag', 'noindex,nofollow');
            }

            $items = $this->service->feed($actor, $this->queryInt($request, 'limit', 50), $this->queryInt($request, 'offset', 0), $this->types);
            return $this->json(['items' => array_map($this->serialize(...), $items)]);
        } catch (ProfileActivityException) {
            return $this->wantsHtml($request)
                ? Response::text('Bad Request', 400)->withHeader('Cache-Control', 'private, no-store')
                : $this->json(['error' => 'invalid_activity_request'], 400);
        }
    }

    private function wantsHtml(Request $request): bool
    {
        return str_contains(strtolower($request->headers()->line('accept') ?? ''), 'text/html');
    }

    private function page(mixed $raw): int
    {
        if ($raw === null || $raw === '') return 1;
        if (is_int($raw) && $raw >= 1 && $raw <= 10000) return $raw;
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,3}$/D', $raw) === 1) return (int) $raw;
        return 1;
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
