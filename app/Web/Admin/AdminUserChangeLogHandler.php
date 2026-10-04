<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Logs\AdminLogExplorerService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class AdminUserChangeLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminLogExplorerService $logs,
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
            $query = self::query($request, 'q', 120);
            $fromRaw = self::query($request, 'from', 10);
            $toRaw = self::query($request, 'to', 10);
            $from = self::date($fromRaw);
            $to = self::date($toRaw);
            $limit = self::limit($request);
            $entries = $this->logs->userChanges($actor, $query, $from, $to, $limit);

            return Response::html(ProfileHtml::page(
                'Kullanıcı Değişiklik Logu',
                AdminUserChangeLogHtml::page(
                    $entries,
                    $this->basePath,
                    $query,
                    $fromRaw,
                    $toRaw,
                    $limit,
                ),
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
        }
    }

    private static function query(Request $request, string $key, int $max): string
    {
        $value = $request->query()[$key] ?? '';
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('User change log filter is invalid.');
        }

        return trim($value);
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
            throw new InvalidArgumentException('User change log date is invalid.');
        }

        return $date;
    }

    private static function limit(Request $request): int
    {
        $value = $request->query()['limit'] ?? '100';
        if (!is_string($value) || !in_array($value, ['50', '100', '250'], true)) {
            throw new InvalidArgumentException('User change log limit is invalid.');
        }

        return (int) $value;
    }
}
