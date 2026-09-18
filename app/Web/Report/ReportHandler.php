<?php

declare(strict_types=1);

namespace Forwext\App\Web\Report;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Report\ReportService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ReportHandler
{
    public function __construct(
        private ReportService $reports,
        private ReportRequestGuard $guard,
        private BasePath $basePath,
    ) {
    }

    public function form(Request $request): Response
    {
        $query = $request->query();
        $type = $this->string($query['type'] ?? null, 'Report target type is required.');
        $targetId = EntityId::fromString($this->string($query['id'] ?? null, 'Report target id is required.'));
        $content = $this->reports->reportable($type, $targetId);
        $reasons = $this->reports->reasons();
        if ($reasons === []) {
            return Response::text('Report reasons are unavailable.', 503);
        }
        return $this->secure(Response::html(ReportHtml::form($content, $reasons, $this->basePath)));
    }

    public function submit(Request $request): Response
    {
        if (!$this->guard->allows($request)) {
            return $this->secure(Response::text('Forbidden', 403));
        }
        $body = $request->parsedBody();
        $type = $this->string($body['target_type'] ?? null, 'Report target type is required.');
        $targetId = EntityId::fromString($this->string($body['target_id'] ?? null, 'Report target id is required.'));
        $reason = $this->string($body['reason_key'] ?? null, 'Report reason is required.');
        $detail = $this->optionalString($body['detail'] ?? null);
        $receipt = $this->reports->submit($type, $targetId, $reason, $detail);
        $suffix = $receipt->created ? '?submitted=1' : '?already=1';
        return $this->secure(Response::redirect($this->basePath->prepend('/account/reports' . $suffix), 303));
    }

    public function history(Request $request): Response
    {
        $query = $request->query();
        $submitted = ($query['submitted'] ?? null) === '1';
        $already = ($query['already'] ?? null) === '1';
        return $this->secure(Response::html(ReportHtml::history(
            $this->reports->ownReports(50),
            $this->basePath,
            $submitted,
            $already,
        )));
    }

    private function string(mixed $value, string $message): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
        return trim($value);
    }

    private function optionalString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Report detail is invalid.');
        }
        return trim($value);
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
