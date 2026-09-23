<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Analytics\Report\AnalyticsReportExporter;
use Forwext\Core\Analytics\Report\AnalyticsReportService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use InvalidArgumentException;

final readonly class AnalyticsReportExportHandler implements RequestHandlerInterface
{
    public function __construct(
        private AnalyticsReportService $reports,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $query = $request->query();
            $format = $query['format'] ?? 'csv';
            if (!is_string($format) || !in_array($format, ['csv','json'], true)) {
                throw new InvalidArgumentException('Analytics export format is invalid.');
            }

            $rawId = $query['report_id'] ?? null;
            if (is_string($rawId) && $rawId !== '') {
                if (preg_match('/^[a-f0-9]{32}$/D', $rawId) !== 1) {
                    throw new InvalidArgumentException('Analytics saved report id is invalid.');
                }
                $definition = $this->reports
                    ->savedReport($actor, EntityId::fromString($rawId))
                    ->definition;
            } else {
                $definition = AnalyticsReportInputReader::read($query);
            }

            $result = $this->reports->export($actor, $definition);
            if ($format === 'json') {
                return Response::json(AnalyticsReportExporter::jsonPayload($result))
                    ->withHeader('Content-Disposition', 'attachment; filename="forwext-analytics-report.json"')
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Cache-Control', 'private, no-store')
                    ->withHeader('X-Robots-Tag', 'noindex,nofollow');
            }

            return Response::text(AnalyticsReportExporter::csv($result))
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="forwext-analytics-report.csv"')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }
}
