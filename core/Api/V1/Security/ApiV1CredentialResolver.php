<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use Forwext\Core\Http\Request;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class ApiV1CredentialResolver
{
    public function __construct(
        private ApiV1CredentialRepository $repository,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function resolve(Request $request): ?ApiV1Principal
    {
        $bearer = $this->bearer($request);
        $apiKey = $request->headers()->first('x-api-key');
        $apiKey = $apiKey === null ? null : trim($apiKey);

        if ($bearer !== null && $apiKey !== null && $apiKey !== '') {
            throw new ApiV1AuthenticationException('Multiple API credentials were supplied.');
        }

        if ($bearer === null && ($apiKey === null || $apiKey === '')) {
            return null;
        }

        $secret = $bearer ?? $apiKey;
        if (!is_string($secret) || !$this->validSecretShape($secret)) {
            return null;
        }

        $record = $this->repository->findBySecretHash(hash('sha256', $secret));
        if ($record === null || !$record->activeAt($this->clock->now())) {
            return null;
        }

        if ($bearer !== null && !$record->type->acceptsBearer()) {
            return null;
        }
        if ($apiKey !== null && $apiKey !== '' && $record->type !== ApiV1PrincipalType::ApiKey) {
            return null;
        }
        if (!str_starts_with($secret, $record->type->secretPrefix())) {
            return null;
        }

        $this->repository->markUsed($record->credentialId, $this->clock->now());

        return $record->principal();
    }

    private function bearer(Request $request): ?string
    {
        $header = $request->headers()->first('authorization');
        if ($header === null || trim($header) === '') {
            return null;
        }
        if (preg_match('/^Bearer ([A-Za-z0-9_-]{10,120})$/D', trim($header), $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private function validSecretShape(string $secret): bool
    {
        return preg_match('/^(?:fxpat_|fxkey_|fxoauth_)[A-Za-z0-9_-]{43}$/D', $secret) === 1;
    }
}
