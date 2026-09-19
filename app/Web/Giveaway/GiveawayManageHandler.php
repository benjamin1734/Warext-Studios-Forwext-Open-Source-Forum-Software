<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Giveaway\Giveaway;
use Forwext\Core\Giveaway\GiveawayException;
use Forwext\Core\Giveaway\GiveawayPrize;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Giveaway\GiveawayState;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class GiveawayManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private GiveawayService $giveaways,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $this->giveaways->syncDue($now, 100);

            if ($request->method() === HttpMethod::Post) {
                $giveawayId = $this->mutate($actor, $request, $now);
                $location = '/giveaways/manage?updated=1';
                if ($giveawayId !== null) {
                    $location .= '&giveaway=' . rawurlencode($giveawayId->value());
                }
                return Response::text('', 303)
                    ->withHeader('Location', $this->basePath->prepend($location))
                    ->withHeader('Cache-Control', 'no-store');
            }

            if (!$this->giveaways->canCreate($actor) && !$this->giveaways->canManage($actor)) {
                return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
            }

            $selected = null;
            $queryId = $request->query()['giveaway'] ?? null;
            if (is_string($queryId) && $queryId !== '') {
                $selected = $this->giveaways->find($actor, self::id($queryId));
                if (!$this->giveaways->canManageGiveaway($actor, $selected)) {
                    return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
                }
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(GiveawayHtml::manage(
                $this->giveaways->manageable($actor, 100),
                $selected,
                $this->basePath,
                $token,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (GiveawayException|InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request, DateTimeImmutable $now): ?EntityId
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Giveaway action is missing.');
        }

        if ($action === 'sync_due') {
            $this->giveaways->syncDue($now, 100);
            return null;
        }

        if ($action === 'publish') {
            return $this->giveaways->publish(
                $actor,
                self::id($body['giveaway_id'] ?? null),
                $now,
                HttpAuditRequestId::fromRequest($request),
            )->giveawayId;
        }

        if ($action === 'cancel') {
            return $this->giveaways->cancel(
                $actor,
                self::id($body['giveaway_id'] ?? null),
                $now,
                HttpAuditRequestId::fromRequest($request),
            )->giveawayId;
        }

        if ($action !== 'save') {
            throw new InvalidArgumentException('Giveaway action is invalid.');
        }

        $rawId = self::optional($body, 'giveaway_id', 32);
        $existing = $rawId === null ? null : $this->giveaways->find($actor, self::id($rawId));
        if ($existing !== null && !$this->giveaways->canManageGiveaway($actor, $existing)) {
            throw new GiveawayException('Giveaway cannot be managed by this actor.');
        }

        $candidate = new Giveaway(
            $existing?->giveawayId ?? Giveaway::generateId(),
            $existing?->ownerUserId ?? $actor,
            strtolower(self::required($body, 'slug', 160)),
            self::required($body, 'title', 180),
            self::required($body, 'description', 100000),
            new GiveawayPrize(
                self::required($body, 'prize_title', 180),
                self::optional($body, 'prize_description', 2000) ?? '',
                self::integer($body, 'prize_quantity', 1, 1_000_000, 1),
            ),
            self::required($body, 'participation_terms', 20000),
            self::date(self::required($body, 'starts_at', 32)),
            self::date(self::required($body, 'ends_at', 32)),
            self::integer($body, 'entries_per_user', 1, 1000, 1),
            self::nullableInteger($body, 'max_participants', 1, 100_000_000),
            GiveawayState::Draft,
            $existing?->createdAt ?? $now,
            $now,
        );

        return $this->giveaways->saveDraft(
            $actor,
            $candidate,
            $now,
            HttpAuditRequestId::fromRequest($request),
        )->giveawayId;
    }

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Giveaway id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Giveaway field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Giveaway field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body, string $key, int $max): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max) {
            throw new InvalidArgumentException('Giveaway optional field is invalid: ' . $key);
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return $default;
        }
        $value = filter_var($body[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Giveaway integer field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function nullableInteger(array $body, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return null;
        }
        return self::integer($body, $key, $min, $max, $min);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Giveaway date field is invalid.');
        }
        return $date;
    }
}
