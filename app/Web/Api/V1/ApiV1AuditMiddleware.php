<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use DateTimeZone;
use Forwext\Core\Api\V1\ApiV1EndpointDefinition;
use Forwext\Core\Api\V1\Security\ApiV1Principal;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Response;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class ApiV1AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditRecorder $audit,
        private ApiV1EndpointDefinition $endpoint,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $response = $next->handle($request);
        $principal = $request->attribute(ApiV1SecurityMiddleware::ATTRIBUTE_PRINCIPAL);
        if (!$principal instanceof ApiV1Principal) {
            return $response;
        }

        $requestIdRaw = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
        $requestId = is_string($requestIdRaw) && $requestIdRaw !== ''
            ? AuditRequestId::fromString($requestIdRaw)
            : AuditRequestId::generate();

        try {
            $this->audit->append(new AuditEvent(
                AuditEvent::generateId(),
                AuditScope::Api,
                $principal->userId,
                AuditAction::fromString('api.request'),
                'api_request',
                $this->endpoint->routeName,
                null,
                'http.' . $response->status(),
                $requestId,
                [],
                [
                    'route'=>$this->endpoint->routeName,
                    'resource'=>$this->endpoint->resource?->value,
                    'scope'=>$this->endpoint->scope?->value,
                    'status'=>$response->status(),
                    'credential_id'=>$principal->credentialId->value(),
                    'credential_type'=>$principal->type->value,
                ],
                $this->clock->now()->setTimezone(new DateTimeZone('UTC')),
            ));
        } catch (\Throwable) {
            return ApiV1ErrorResponder::error(
                'audit_unavailable',
                'The authenticated API request could not be recorded.',
                503,
            );
        }

        return $response;
    }
}
