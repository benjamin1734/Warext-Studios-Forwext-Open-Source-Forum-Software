<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Analytics\Report\AnalyticsReportDataset;
use Forwext\Core\Analytics\Report\AnalyticsReportService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AnalyticsReportBuilderHandler implements RequestHandlerInterface
{
    public function __construct(
        private AnalyticsReportService $reports,
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

            $datasets = $this->reports->datasets($actor);
            if ($datasets === []) {
                return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
            }

            $selected = null;
            $reportId = self::optionalId($request->query()['id'] ?? null);
            if ($reportId !== null) {
                $selected = $this->reports->savedReport($actor, $reportId);
                $definition = $selected->definition;
            } else {
                $definition = AnalyticsReportInputReader::read($request->query(), $datasets[0]);
            }

            $result = $this->reports->run($actor, $definition);

            return Response::html(AnalyticsReportHtml::page(
                $definition,
                $result,
                $datasets,
                $this->reports->savedReports($actor),
                $selected,
                $actor,
                $csrf,
                $this->reports->canExport($actor),
                $this->reports->canUseUnaggregated($actor),
                $this->reports->canManageAll($actor),
                ($request->query()['saved'] ?? null) === '1',
                ($request->query()['deleted'] ?? null) === '1',
                $this->basePath,
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $requestId = HttpAuditRequestId::fromRequest($request);

        if ($action === 'save') {
            $name = $body['name'] ?? null;
            if (!is_string($name)) {
                throw new InvalidArgumentException('Analytics saved report name is invalid.');
            }
            $definition = AnalyticsReportInputReader::read($body);
            $report = $this->reports->save(
                $actor,
                $name,
                $definition,
                self::optionalId($body['report_id'] ?? null),
                $now,
                $requestId,
            );

            return Response::redirect(
                $this->basePath->prepend('/admin/analytics/reports?id='.$report->reportId->value().'&saved=1'),
                303,
            )->withHeader('Cache-Control', 'no-store');
        }

        if ($action === 'delete') {
            $reportId = self::requiredId($body['report_id'] ?? null);
            $this->reports->delete($actor, $reportId, $now, $requestId);

            return Response::redirect(
                $this->basePath->prepend('/admin/analytics/reports?deleted=1'),
                303,
            )->withHeader('Cache-Control', 'no-store');
        }

        throw new InvalidArgumentException('Analytics report action is invalid.');
    }

    private static function optionalId(mixed $value): ?EntityId
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::requiredId($value);
    }

    private static function requiredId(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Analytics saved report id is invalid.');
        }
        return EntityId::fromString($value);
    }
}
