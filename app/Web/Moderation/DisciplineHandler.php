<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineRestrictionKey;
use Forwext\Core\Moderation\Discipline\DisciplineService;
use Forwext\Core\Moderation\Discipline\WarningDefinition;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class DisciplineHandler
{
    public function __construct(
        private DisciplineService $discipline,
        private UserRepository $users,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private DisciplineCapabilities $capabilities,
    ) {
    }

    public function view(): Response
    {
        $overview = $this->discipline->overview();
        $usernames = [];
        foreach ($overview->actions as $action) {
            $key = $action->userId->value();
            if (isset($usernames[$key])) {
                continue;
            }
            $user = $this->users->find($action->userId);
            $usernames[$key] = $user?->username()->display() ?? $key;
        }

        return $this->secure(Response::html(DisciplineHtml::page(
            $overview,
            $usernames,
            $this->basePath,
            $this->capabilities,
        )));
    }

    public function issue(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $username = Username::fromString($this->stringField($body, 'username'));
        $target = $this->users->findByUsername($username)
            ?? throw new InvalidArgumentException('Discipline target user was not found.');
        $type = DisciplineActionType::from($this->stringField($body, 'action_type'));
        $reason = ModerationReasonCode::fromString($this->stringField($body, 'reason_code'));
        $reasonText = $this->stringField($body, 'reason_text');
        $requestId = $this->requestId($request);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        match ($type) {
            DisciplineActionType::Warning => $this->discipline->issueWarning(
                $target->id(),
                $this->stringField($body, 'warning_definition'),
                $reason,
                $reasonText,
                $requestId,
                $now,
            ),
            DisciplineActionType::Restriction => $this->discipline->restrict(
                $target->id(),
                DisciplineRestrictionKey::from($this->stringField($body, 'restriction_key')),
                $reason,
                $reasonText,
                $requestId,
                $this->expiry($body, $now, false),
                $now,
            ),
            DisciplineActionType::Suspension => $this->discipline->suspend(
                $target->id(),
                $reason,
                $reasonText,
                $requestId,
                $this->expiry($body, $now, true)
                    ?? throw new InvalidArgumentException('Suspension duration is required.'),
                $now,
            ),
            DisciplineActionType::Ban => $this->discipline->ban(
                $target->id(),
                $reason,
                $reasonText,
                $requestId,
                $this->expiry($body, $now, false),
                $now,
            ),
        };

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/discipline'), 303));
    }

    public function saveWarningDefinition(Request $request): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $expiry = $this->optionalIntField($body, 'expiry_days', 1, 3650);
        $definition = new WarningDefinition(
            strtolower($this->stringField($body, 'definition_key')),
            $this->stringField($body, 'label'),
            $this->optionalStringField($body, 'description') ?? '',
            $this->intField($body, 'points', 0, 1000),
            $expiry,
            $this->stringField($body, 'active') === '1',
            $this->intField($body, 'sort_order', 0, 65535),
        );

        $this->discipline->saveWarningDefinition($definition, $this->requestId($request));

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/discipline'), 303));
    }

    public function revoke(Request $request, string $actionId): Response
    {
        $this->requireMutation($request);
        $this->discipline->revoke(
            \Forwext\Core\Domain\Entity\EntityId::fromString($actionId),
            $this->stringField($request->parsedBody(), 'reason'),
            $this->requestId($request),
        );

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/discipline'), 303));
    }

    private function requireMutation(Request $request): void
    {
        if (!$this->guard->allows($request)) {
            throw new DisciplineMutationGuardException('Discipline mutation request was rejected.');
        }
    }

    /** @param array<string,mixed> $body */
    private function expiry(array $body, DateTimeImmutable $now, bool $required): ?DateTimeImmutable
    {
        $hours = $this->optionalIntField($body, 'duration_hours', 1, 87600);
        if ($hours === null) {
            if ($required) {
                throw new InvalidArgumentException('Discipline duration is required.');
            }
            return null;
        }
        return $now->add(new DateInterval('PT' . $hours . 'H'));
    }

    /** @param array<string,mixed> $body */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Discipline form field is invalid: ' . $key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private function optionalStringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Discipline form field is invalid: ' . $key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $body */
    private function intField(array $body, string $key, int $minimum, int $maximum): int
    {
        $value = $this->stringField($body, $key);
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('Discipline numeric field is invalid: ' . $key);
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException('Discipline numeric field is outside the supported range: ' . $key);
        }
        return $number;
    }

    /** @param array<string,mixed> $body */
    private function optionalIntField(array $body, string $key, int $minimum, int $maximum): ?int
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            throw new InvalidArgumentException('Discipline numeric field is invalid: ' . $key);
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException('Discipline numeric field is outside the supported range: ' . $key);
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

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
