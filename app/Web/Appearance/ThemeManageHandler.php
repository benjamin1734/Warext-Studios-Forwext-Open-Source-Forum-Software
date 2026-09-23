<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Theme\ThemePayload;
use Forwext\Core\Ui\Theme\ThemeService;
use InvalidArgumentException;
use JsonException;

final readonly class ThemeManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private ThemeService $themes,
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
                return $this->mutate($actor, $request);
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $themeKey = self::optionalString($request->query()['theme'] ?? null, 64);
            $snapshot = $themeKey === null ? null : $this->themes->snapshot($actor, $themeKey);
            $diff = [];

            if ($snapshot !== null) {
                $left = self::optionalId($request->query()['left'] ?? null);
                $right = self::optionalId($request->query()['right'] ?? null);
                if (($left === null) !== ($right === null)) {
                    throw new InvalidArgumentException('Both theme diff revisions are required.');
                }
                if ($left !== null && $right !== null) {
                    $diff = $this->themes->diff($actor, $themeKey, $left, $right);
                }
            }

            $content = ThemeManageHtml::page(
                $this->themes->all($actor),
                $snapshot,
                $diff,
                $this->basePath,
                $csrf,
                $this->themes->canUseAdvanced($actor),
                ($request->query()['saved'] ?? null) === '1',
                ($request->query()['published'] ?? null) === '1',
                ($request->query()['rolled_back'] ?? null) === '1',
            );

            return Response::html(ProfileHtml::page(
                'Temalar',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|JsonException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Theme management action is missing.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $requestId = HttpAuditRequestId::fromRequest($request);
        $themeKey = self::requiredString($body['theme_key'] ?? null, 64);

        if ($action === 'stage') {
            $templates = self::jsonObject($body['templates_json'] ?? null, 'templates');
            $phrases = self::jsonObject($body['phrases_json'] ?? null, 'phrases');
            $payload = new ThemePayload(
                $templates,
                $phrases,
                self::optionalString($body['custom_css'] ?? null, 262_144) ?? '',
                self::optionalString($body['custom_js'] ?? null, 131_072) ?? '',
            );

            $this->themes->stage(
                $actor,
                $themeKey,
                self::requiredString($body['name'] ?? null, 120),
                self::optionalString($body['parent_key'] ?? null, 64),
                $payload,
                $now,
                $requestId,
            );

            return $this->redirect($themeKey, 'saved=1');
        }

        if ($action === 'publish') {
            $this->themes->publish(
                $actor,
                $themeKey,
                self::requiredId($body['revision_id'] ?? null),
                $now,
                $requestId,
            );

            return $this->redirect($themeKey, 'published=1');
        }

        if ($action === 'rollback') {
            $this->themes->rollbackStaging(
                $actor,
                $themeKey,
                self::requiredId($body['revision_id'] ?? null),
                $now,
                $requestId,
            );

            return $this->redirect($themeKey, 'rolled_back=1');
        }

        throw new InvalidArgumentException('Unknown theme management action.');
    }

    private function redirect(string $themeKey, string $flag): Response
    {
        return Response::redirect(
            $this->basePath->prepend('/admin/appearance/themes?theme=' . rawurlencode($themeKey) . '&' . $flag),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed> */
    private static function jsonObject(mixed $value, string $label): array
    {
        if (!is_string($value) || strlen($value) > 1_048_576) {
            throw new InvalidArgumentException('Theme ' . $label . ' JSON is invalid.');
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Theme ' . $label . ' JSON must be an object.');
        }

        return $decoded;
    }

    private static function requiredId(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Theme revision id is invalid.');
        }

        return EntityId::fromString($value);
    }

    private static function optionalId(mixed $value): ?EntityId
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredId($value);
    }

    private static function requiredString(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Required theme field is invalid.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Required theme field is invalid.');
        }

        return $value;
    }

    private static function optionalString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max) {
            throw new InvalidArgumentException('Optional theme field is invalid.');
        }

        return $value;
    }
}
