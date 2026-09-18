<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Abuse\AbuseAction;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use Forwext\Core\Moderation\Abuse\AbuseModerationService;
use Forwext\Core\Moderation\Abuse\AbuseRule;
use Forwext\Core\Moderation\Abuse\AbuseSignal;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AbuseHandler
{
    public function __construct(
        private AbuseModerationService $abuse,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private AbuseCapabilities $capabilities,
    ) {
    }

    public function view(): Response
    {
        return $this->secure(Response::html(AbuseHtml::page(
            $this->abuse->overview(),
            $this->basePath,
            $this->capabilities,
        )));
    }

    public function saveRule(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $rule = new AbuseRule(
            strtolower($this->stringField($body, 'rule_key')),
            $this->stringField($body, 'label'),
            AbuseEventType::from($this->stringField($body, 'event_type')),
            AbuseSignal::from($this->stringField($body, 'signal_key')),
            $this->intField($body, 'hit_limit', 1, 100000),
            $this->intField($body, 'window_seconds', 1, 604800),
            AbuseAction::from($this->stringField($body, 'action')),
            $this->stringField($body, 'active') === '1',
            $this->intField($body, 'priority', 0, 65535),
        );
        $this->abuse->saveRule($rule, $this->requestId($request));
        return $this->redirect();
    }

    public function bulk(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $action = $this->stringField($body, 'action');
        $eventIds = $this->eventIds($body['events'] ?? null);
        $reason = ModerationReasonCode::fromString($this->stringField($body, 'reason_code'));
        $requestId = $this->requestId($request);

        match ($action) {
            'cleanup' => $this->abuse->cleanup($eventIds, $reason, $requestId),
            'dismiss' => $this->abuse->dismiss($eventIds, $reason, $requestId),
            default => throw new InvalidArgumentException('Anti-abuse bulk action is invalid.'),
        };
        return $this->redirect();
    }

    private function requireMutation(Request $request): void
    {
        if (!$this->guard->allows($request)) {
            throw new AbuseMutationGuardException('Anti-abuse mutation request was rejected.');
        }
    }

    /** @return list<EntityId> */
    private function eventIds(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            throw new InvalidArgumentException('Anti-abuse event selection is invalid.');
        }
        $ids = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/^[a-f0-9]{32}$/D', $item) !== 1) {
                throw new InvalidArgumentException('Anti-abuse event identifier is invalid.');
            }
            $ids[] = EntityId::fromString($item);
        }
        return $ids;
    }

    /** @param array<string,mixed> $body */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Anti-abuse form field is invalid: ' . $key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private function intField(array $body, string $key, int $minimum, int $maximum): int
    {
        $value = $this->stringField($body, $key);
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('Anti-abuse numeric field is invalid: ' . $key);
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException('Anti-abuse numeric field is outside the supported range: ' . $key);
        }
        return $number;
    }

    private function requestId(Request $request): ModerationRequestId
    {
        $requestId = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
        return is_string($requestId)
            ? ModerationRequestId::fromString($requestId)
            : ModerationRequestId::generate();
    }

    private function redirect(): Response
    {
        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/abuse'), 303));
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
