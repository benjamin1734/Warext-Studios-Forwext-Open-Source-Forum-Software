<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Editor\CrossThreadQuoteService;
use Forwext\Core\Forum\Editor\QuoteUnavailableException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use InvalidArgumentException;

final readonly class EditorQuoteHandler implements RequestHandlerInterface
{
    public function __construct(
        private CrossThreadQuoteService $quotes,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }
        $raw = $request->query()['post_id'] ?? null;
        if (!is_string($raw) || preg_match('/\A[a-f0-9]{32}\z/D', $raw) !== 1) {
            return Response::json(['error' => 'invalid_post'], 422)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $quote = $this->quotes->quote(
                EntityId::fromString($raw),
                new PermissionGate($this->authorizer, $actor),
            );
        } catch (InvalidArgumentException|QuoteUnavailableException|PermissionDeniedException) {
            return Response::json(['error' => 'quote_unavailable'], 404)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json([
            'post_id' => $quote->postId->value(),
            'thread_id' => $quote->threadId->value(),
            'author' => $quote->authorLabel,
            'thread_title' => $quote->threadTitle,
            'position' => $quote->postPosition,
            'bbcode' => $quote->bbCode,
        ])->withHeader('Cache-Control', 'private, no-store');
    }
}
