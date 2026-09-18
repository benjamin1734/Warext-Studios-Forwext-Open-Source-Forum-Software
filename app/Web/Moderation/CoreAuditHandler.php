<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\CoreAuditService;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class CoreAuditHandler
{
    public function __construct(
        private CoreAuditService $audit,
        private BasePath $basePath,
    ) {
    }

    public function view(Request $request): Response
    {
        $query = $request->query();
        $actor = self::optionalString($query, 'actor');
        $requestId = self::optionalString($query, 'request_id');

        if ($actor !== null && $requestId !== null) {
            throw new InvalidArgumentException('Audit stream accepts one filter at a time.');
        }

        $events = match (true) {
            $actor !== null => $this->audit->forActor(EntityId::fromString($actor), 100),
            $requestId !== null => $this->audit->forRequest(AuditRequestId::fromString($requestId), 100),
            default => $this->audit->recent(100),
        };

        return Response::html(CoreAuditHtml::page(
            $events,
            $this->basePath,
            $actor,
            $requestId,
        ))->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @param array<string,mixed> $query */
    private static function optionalString(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 191) {
            throw new InvalidArgumentException('Audit filter is invalid.');
        }
        return trim($value);
    }
}
