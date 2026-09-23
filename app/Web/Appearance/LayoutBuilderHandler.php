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
use Forwext\Core\Ui\Layout\Builder\LayoutBuilderService;
use Forwext\Core\Ui\Layout\Builder\LayoutDocument;
use Forwext\Core\Ui\Layout\Builder\LayoutRevisionSource;
use InvalidArgumentException;
use JsonException;
use ValueError;

final readonly class LayoutBuilderHandler implements RequestHandlerInterface
{
    private const LAYOUT_KEY = 'site.default';

    public function __construct(
        private LayoutBuilderService $builder,
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

            $snapshot = $this->builder->snapshot($actor, self::LAYOUT_KEY);
            $catalog = $this->builder->catalog($actor);
            $content = LayoutBuilderHtml::page(
                $snapshot,
                $catalog['slots'],
                $catalog['widgets'],
                $this->basePath,
                $csrf,
                ($request->query()['saved'] ?? null) === '1',
                ($request->query()['published'] ?? null) === '1',
                ($request->query()['imported'] ?? null) === '1',
            );

            return Response::html(ProfileHtml::page(
                'Layout Builder',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|JsonException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Layout builder action is missing.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $requestId = HttpAuditRequestId::fromRequest($request);

        if ($action === 'save') {
            $json = $body['document_json'] ?? null;
            if (!is_string($json) || strlen($json) > 1_048_576) {
                throw new InvalidArgumentException('Layout document payload is invalid.');
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new InvalidArgumentException('Layout document payload is invalid.');
            }

            $this->builder->saveDraft(
                $actor,
                self::LAYOUT_KEY,
                LayoutDocument::fromArray($data),
                $now,
                $requestId,
                LayoutRevisionSource::Editor,
            );

            return $this->redirect('?saved=1');
        }

        if ($action === 'import') {
            $json = $body['import_json'] ?? null;
            if (!is_string($json)) {
                throw new InvalidArgumentException('Layout import payload is invalid.');
            }
            $this->builder->import($actor, self::LAYOUT_KEY, $json, $now, $requestId);

            return $this->redirect('?imported=1');
        }

        if ($action === 'publish') {
            $revisionId = $body['draft_revision_id'] ?? null;
            if (!is_string($revisionId) || preg_match('/^[a-f0-9]{32}$/D', $revisionId) !== 1) {
                throw new InvalidArgumentException('Layout draft revision id is invalid.');
            }
            $this->builder->publish(
                $actor,
                self::LAYOUT_KEY,
                EntityId::fromString($revisionId),
                $now,
                $requestId,
            );

            return $this->redirect('?published=1');
        }

        throw new InvalidArgumentException('Unknown layout builder action.');
    }

    private function redirect(string $query): Response
    {
        return Response::redirect(
            $this->basePath->prepend('/admin/appearance/layout' . $query),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }
}
