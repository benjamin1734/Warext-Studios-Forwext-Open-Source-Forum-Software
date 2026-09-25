<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Integration\IntegrationSection;
use Forwext\Core\Admin\Integration\SystemIntegrationService;
use Forwext\Core\Config\ConfigException;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Security\Secret\SecretException;
use InvalidArgumentException;

final readonly class SystemIntegrationHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemIntegrationService $integrations,
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

            $query = $request->query();
            $search = self::boundedString($query['q'] ?? '', 80, 'Integration search is invalid.');
            $section = self::section($query['section'] ?? null);
            $updated = isset($query['updated']) && is_string($query['updated'])
                ? $query['updated']
                : null;

            $content = SystemIntegrationHtml::page(
                $this->integrations->snapshot($actor),
                $this->basePath,
                $csrf,
                trim($search),
                $section,
                in_array($updated, ['setting','reset','secret','secret-delete'], true) ? $updated : null,
            );

            return Response::html(ProfileHtml::page(
                'Sistem ve Entegrasyonlar',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (ConfigException|SecretException) {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = self::boundedString($body['action'] ?? null, 32, 'Integration action is invalid.');
        $key = self::boundedString($body['key'] ?? null, 96, 'Integration key is invalid.');
        $section = self::section($body['return_section'] ?? null);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $auditRequestId = HttpAuditRequestId::fromRequest($request);
        $updated = 'setting';

        match ($action) {
            'save_setting' => $this->integrations->saveSetting(
                $actor,
                $key,
                $body['value'] ?? null,
                $auditRequestId,
                $now,
            ),
            'reset_setting' => $this->integrations->resetSetting(
                $actor,
                $key,
                $auditRequestId,
                $now,
            ),
            'set_secret' => $this->integrations->setSecret(
                $actor,
                $key,
                $body['secret_value'] ?? null,
                $auditRequestId,
                $now,
            ),
            'delete_secret' => $this->deleteSecret($actor, $key, $body, $auditRequestId, $now),
            default => throw new InvalidArgumentException('Unknown integration action.'),
        };

        $updated = match ($action) {
            'reset_setting' => 'reset',
            'set_secret' => 'secret',
            'delete_secret' => 'secret-delete',
            default => 'setting',
        };

        $target = $this->basePath->prepend('/admin/integrations')
            . '?updated=' . rawurlencode($updated);
        if ($section !== null) {
            $target .= '&section=' . rawurlencode($section->value);
        }

        return Response::redirect($target, 303)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string,mixed> $body
     */
    private function deleteSecret(
        EntityId $actor,
        string $key,
        array $body,
        \Forwext\Core\Audit\AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $confirm = self::boundedString(
            $body['confirm_key'] ?? null,
            96,
            'Secret deletion confirmation is invalid.',
        );
        if (!hash_equals($key, $confirm)) {
            throw new InvalidArgumentException('Secret deletion confirmation does not match.');
        }

        $this->integrations->deleteSecret($actor, $key, $requestId, $at);
    }

    private static function section(mixed $raw): ?IntegrationSection
    {
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }
        if (!is_string($raw) || strlen($raw) > 32) {
            throw new InvalidArgumentException('Integration section is invalid.');
        }

        return IntegrationSection::tryFrom($raw)
            ?? throw new InvalidArgumentException('Integration section is invalid.');
    }

    private static function boundedString(mixed $raw, int $max, string $message): string
    {
        if (!is_string($raw) || strlen($raw) > $max || preg_match('//u', $raw) !== 1) {
            throw new InvalidArgumentException($message);
        }

        return $raw;
    }
}
