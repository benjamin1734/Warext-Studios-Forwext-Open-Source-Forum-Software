<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Oversight\ModerationOversightService;
use Forwext\Core\Moderation\Oversight\OversightAnomalySeverity;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class OversightHandler
{
    public function __construct(
        private ModerationOversightService $oversight,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private OversightCapabilities $capabilities,
    ) {
    }

    public function view(Request $request): Response
    {
        $verify = ($request->query()['verify'] ?? null) === '1';
        return $this->secure(Response::html(OversightHtml::page(
            $this->oversight->overview(100, $verify),
            $this->basePath,
            $this->capabilities,
        )));
    }

    public function openCase(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $this->oversight->openCase(
            $this->id($body, 'source_audit_id'),
            $this->string($body, 'summary', 1000),
        );
        return $this->redirect();
    }

    public function resolveCase(Request $request, string $caseId): Response
    {
        $this->requireMutation($request);
        $this->oversight->resolveCase(
            EntityId::fromString($caseId),
            $this->string($request->parsedBody(), 'resolution', 1000),
        );
        return $this->redirect();
    }

    public function flag(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $this->oversight->flag(
            $this->id($body, 'source_audit_id'),
            $this->string($body, 'flag_type', 64),
            OversightAnomalySeverity::from($this->string($body, 'severity', 16)),
            $this->string($body, 'details', 1000),
        );
        return $this->redirect();
    }

    public function resolveFlag(Request $request, string $flagId): Response
    {
        $this->requireMutation($request);
        $this->oversight->resolveFlag(
            EntityId::fromString($flagId),
            $this->string($request->parsedBody(), 'resolution', 1000),
        );
        return $this->redirect();
    }

    private function requireMutation(Request $request): void
    {
        if (!$this->guard->allows($request)) {
            throw new OversightMutationGuardException('Oversight mutation request was rejected.');
        }
    }

    /** @param array<string,mixed> $body */
    private function id(array $body, string $key): EntityId
    {
        $value = $this->string($body, $key, 32);
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Oversight identifier is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Oversight form field is invalid: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Oversight form field is invalid: ' . $key);
        }
        return $value;
    }

    private function redirect(): Response
    {
        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/oversight'), 303));
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
