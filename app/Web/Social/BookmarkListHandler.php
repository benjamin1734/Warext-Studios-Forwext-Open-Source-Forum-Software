<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Social\Interaction\BookmarkEntry;
use Forwext\Core\Social\Interaction\SocialInteractionException;
use Forwext\Core\Social\Interaction\SocialInteractionService;

final readonly class BookmarkListHandler implements RequestHandlerInterface
{
    public function __construct(
        private SocialInteractionService $service,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }
        $limit = $this->integerQuery($request, 'limit', 50);
        $offset = $this->integerQuery($request, 'offset', 0);
        try {
            $bookmarks = $this->service->bookmarks($actor, $limit, $offset);
        } catch (SocialInteractionException) {
            return Response::json(['error' => 'invalid_pagination'], 400)
                ->withHeader('Cache-Control', 'private, no-store');
        }
        return Response::json([
            'items' => array_map(
                static fn (BookmarkEntry $entry): array => [
                    'post_id' => $entry->postId->value(),
                    'note' => $entry->note,
                ],
                $bookmarks,
            ),
            'limit' => $limit,
            'offset' => $offset,
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    private function integerQuery(Request $request, string $name, int $default): int
    {
        $raw = $request->query()[$name] ?? null;
        if ($raw === null) return $default;
        if (!is_string($raw) || preg_match('/^[0-9]{1,7}$/D', $raw) !== 1) return $default;
        return (int) $raw;
    }
}
