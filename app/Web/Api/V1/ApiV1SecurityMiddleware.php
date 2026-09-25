<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Api\V1\ApiV1EndpointDefinition;
use Forwext\Core\Api\V1\Security\ApiV1AuthenticationException;
use Forwext\Core\Api\V1\Security\ApiV1CredentialResolver;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class ApiV1SecurityMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_PRINCIPAL = 'api_v1.principal';

    public function __construct(
        private ApiV1CredentialResolver $credentials,
        private ApiV1AccountPermissionChecker $accountPermissions,
        private ApiV1EndpointDefinition $endpoint,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $presented = $request->headers()->first('authorization') !== null
            || $request->headers()->first('x-api-key') !== null;

        try {
            $principal = $this->credentials->resolve($request);
        } catch (ApiV1AuthenticationException) {
            return $this->unauthorized('invalid_credential', 'The supplied API credentials are invalid.');
        }

        if ($presented && $principal === null) {
            return $this->unauthorized('invalid_credential', 'The supplied API credentials are invalid.');
        }

        if (!$this->endpoint->public && $principal === null) {
            return $this->unauthorized(
                'authentication_required',
                'This API resource requires an authenticated API context.',
            );
        }

        if (
            !$this->endpoint->public
            && $this->endpoint->scope !== null
            && $principal !== null
            && !$principal->hasScope($this->endpoint->scope)
        ) {
            return ApiV1ErrorResponder::error(
                'insufficient_scope',
                'The API credential does not grant the required scope.',
                403,
                ['required_scope'=>$this->endpoint->scope->value],
            );
        }

        if (
            !$this->endpoint->public
            && $this->endpoint->scope !== null
            && $principal !== null
            && !$this->accountPermissions->allows($principal->userId, $this->endpoint->scope)
        ) {
            return ApiV1ErrorResponder::error(
                'account_permission_denied',
                'The API principal account no longer has permission to access this resource.',
                403,
            );
        }

        if ($principal !== null) {
            $request = $request->withAttribute(self::ATTRIBUTE_PRINCIPAL, $principal);
        }

        return $next->handle($request);
    }

    private function unauthorized(string $code, string $message): Response
    {
        return ApiV1ErrorResponder::error($code, $message, 401)
            ->withHeader('WWW-Authenticate', 'Bearer realm="Forwext API"');
    }
}
