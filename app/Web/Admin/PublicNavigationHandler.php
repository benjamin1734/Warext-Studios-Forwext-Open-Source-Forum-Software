<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Navigation\PublicNavigationService;
use Forwext\Core\Config\ConfigException;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationPlacement;
use InvalidArgumentException;
use RuntimeException;

final readonly class PublicNavigationHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicNavigationService $navigation,
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
                throw new RuntimeException('Navigation management CSRF render token is unavailable.');
            }
            $updated = $request->query()['updated'] ?? null;
            $updated = is_string($updated) && in_array($updated, ['saved','created','reset','deleted'], true)
                ? $updated
                : null;

            $content = PublicNavigationHtml::page(
                $this->navigation->snapshot($actor),
                $this->basePath,
                $csrf,
                $updated,
            );

            return Response::html(ProfileHtml::page(
                'Navigasyon Yönetimi',
                $content,
                $this->basePath,
                breadcrumbs: new BreadcrumbTrail([]),
                authenticated: true,
                viewerId: $actor->value(),
                headAssets: AdminAssetsHtml::headAssets($this->basePath),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (ConfigException) {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = self::string($body['action'] ?? null, 16);
        $requestId = HttpAuditRequestId::fromRequest($request);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $updated = 'saved';

        if ($action === 'create') {
            $this->navigation->createCustom(
                $actor,
                self::string($body['key_suffix'] ?? null, 61),
                self::string($body['label'] ?? null, 80),
                self::string($body['path'] ?? null, 191),
                self::integer($body['order'] ?? null),
                self::audience($body['audience'] ?? null),
                self::placement($body['placement'] ?? null),
                $requestId,
                $now,
            );
            $updated = 'created';
        } elseif ($action === 'save') {
            $this->navigation->save(
                $actor,
                self::string($body['key'] ?? null, 191),
                self::string($body['label'] ?? null, 80),
                self::string($body['path'] ?? null, 191),
                self::integer($body['order'] ?? null),
                self::audience($body['audience'] ?? null),
                self::placement($body['placement'] ?? null),
                ($body['enabled'] ?? null) === '1',
                $requestId,
                $now,
            );
        } elseif ($action === 'reset') {
            $this->navigation->resetItem(
                $actor,
                self::string($body['key'] ?? null, 191),
                $requestId,
                $now,
            );
            $updated = 'reset';
        } elseif ($action === 'delete') {
            $this->navigation->deleteCustom(
                $actor,
                self::string($body['key'] ?? null, 191),
                $requestId,
                $now,
            );
            $updated = 'deleted';
        } else {
            throw new InvalidArgumentException('Unknown navigation management action.');
        }

        return Response::redirect(
            $this->basePath->prepend('/admin/navigation') . '?updated=' . $updated,
            303,
        )->withHeader('Cache-Control', 'no-store');
    }

    private static function string(mixed $value, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Navigation input is invalid.');
        }

        return trim($value);
    }

    private static function integer(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^-?[0-9]{1,5}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Navigation order is invalid.');
        }
        $number = (int) $value;
        if ($number < -10000 || $number > 10000) {
            throw new InvalidArgumentException('Navigation order is outside supported bounds.');
        }

        return $number;
    }

    private static function audience(mixed $value): NavigationAudience
    {
        return is_string($value)
            ? NavigationAudience::tryFrom($value)
                ?? throw new InvalidArgumentException('Navigation audience is invalid.')
            : throw new InvalidArgumentException('Navigation audience is invalid.');
    }

    private static function placement(mixed $value): NavigationPlacement
    {
        $placement = is_string($value) ? NavigationPlacement::tryFrom($value) : null;
        if (!in_array($placement, [NavigationPlacement::Primary, NavigationPlacement::More], true)) {
            throw new InvalidArgumentException('Navigation placement is invalid.');
        }

        return $placement;
    }
}
