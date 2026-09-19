<?php

declare(strict_types=1);

namespace Forwext\App\Web\EasterEgg;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\EasterEgg\EasterEggAnimation;
use Forwext\Core\EasterEgg\EasterEggDefinition;
use Forwext\Core\EasterEgg\EasterEggService;
use Forwext\Core\EasterEgg\EasterEggTriggerType;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class EasterEggManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private EasterEggService $easterEggs,
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

        try {
            if ($request->method() === HttpMethod::Post) {
                $id = $this->mutate($actor, $request);
                $location = '/admin/easter-eggs?updated=1';
                if ($id !== null) {
                    $location .= '&id=' . rawurlencode($id->value());
                }
                return Response::text('', 303)
                    ->withHeader('Location', $this->basePath->prepend($location))
                    ->withHeader('Cache-Control', 'no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $snapshot = $this->easterEggs->manageList($actor);
            $selected = null;
            $selectedGroups = [];
            $rawId = $request->query()['id'] ?? null;
            if (is_string($rawId) && $rawId !== '') {
                $id = self::id($rawId);
                $selected = $this->easterEggs->definition($actor, $id);
                if ($selected === null) {
                    return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
                }
                $selectedGroups = $this->easterEggs->groups($actor, $id);
            }

            return Response::html(EasterEggHtml::manage(
                $snapshot['enabled'],
                $snapshot['definitions'],
                $selected,
                $selectedGroups,
                $this->easterEggs->availableGroups($actor),
                $this->basePath,
                $token,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): ?EntityId
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Easter egg management action is missing.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($action === 'global_toggle') {
            $this->easterEggs->setGlobalEnabled(
                $actor,
                self::checked($body, 'global_enabled'),
                $now,
                HttpAuditRequestId::fromRequest($request),
            );
            return null;
        }

        if ($action !== 'save') {
            throw new InvalidArgumentException('Unknown easter egg management action.');
        }

        $rawId = self::optional($body, 'easter_egg_id', 32);
        $existing = $rawId === null ? null : $this->easterEggs->definition($actor, self::id($rawId));
        if ($rawId !== null && $existing === null) {
            throw new InvalidArgumentException('Easter egg id does not exist.');
        }

        $groups = [];
        $rawGroups = $body['group_ids'] ?? [];
        if (!is_array($rawGroups)) {
            throw new InvalidArgumentException('Easter egg group selection is invalid.');
        }
        foreach ($rawGroups as $groupId) {
            $groups[] = self::id($groupId);
        }

        $trigger = EasterEggTriggerType::from(self::required($body, 'trigger_type', 24));
        $definition = new EasterEggDefinition(
            $existing?->easterEggId ?? EasterEggDefinition::generateId(),
            strtolower(self::required($body, 'key', 64)),
            self::required($body, 'name', 100),
            self::checked($body, 'enabled'),
            self::integer($body, 'priority', 0, 65535, 100),
            $trigger,
            $trigger === EasterEggTriggerType::QueryToken
                ? self::required($body, 'trigger_value', 64)
                : null,
            self::optional($body, 'route_name', 128),
            self::optional($body, 'path_pattern', 255),
            self::date(self::optional($body, 'starts_at', 32)),
            self::date(self::optional($body, 'ends_at', 32)),
            self::required($body, 'message', 1000),
            EasterEggAnimation::from(self::required($body, 'animation', 16)),
            self::optional($body, 'badge_label', 40),
            $existing?->createdAt ?? $now,
            $now,
        );

        $this->easterEggs->save(
            $actor,
            $definition,
            $groups,
            $now,
            HttpAuditRequestId::fromRequest($request),
        );

        return $definition->easterEggId;
    }

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Easter egg entity id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Easter egg field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Easter egg field is invalid: ' . $key);
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
            throw new InvalidArgumentException('Easter egg optional field is invalid: ' . $key);
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
            throw new InvalidArgumentException('Easter egg integer field is invalid: ' . $key);
        }
        return $value;
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Easter egg date field is invalid.');
        }
        return $date;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === '1'
            || ($body[$key] ?? null) === 1
            || ($body[$key] ?? null) === true;
    }
}
