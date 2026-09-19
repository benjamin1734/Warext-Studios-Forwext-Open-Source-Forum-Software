<?php

declare(strict_types=1);

namespace Forwext\App\Web\ContentManager;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Manager\ContentManagerAccessDeniedException;
use Forwext\Core\Content\Manager\ContentManagerOperationException;
use Forwext\Core\Content\Manager\ContentManagerOperationProcessor;
use Forwext\Core\Content\Manager\ContentManagerService;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ContentManagerOperationHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentManagerService $manager,
        private ContentManagerOperationProcessor $processor,
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
            $raw = $request->attribute('operationId');
            if (!is_string($raw)) {
                throw new InvalidArgumentException('Operation id is unavailable.');
            }
            $operationId = EntityId::fromString($raw);
            $operation = $this->manager->operation($actor, $operationId);

            if ($request->method() === HttpMethod::Post && !$operation->status->terminal()) {
                $this->manager->requireExecute($actor);
                $this->processor->process($operationId, new DateTimeImmutable('now', new DateTimeZone('UTC')), 50);
                return Response::text('', 303)
                    ->withHeader('Location', $this->basePath->prepend('/content-manager/operations/' . $operationId->value()))
                    ->withHeader('Cache-Control', 'no-store');
            }

            $items = $this->manager->operationItems($actor, $operationId, 200);
            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $body = '<section class="card"><h1>İçerik yöneticisi işlemi</h1>'
                . '<p><strong>İşlem:</strong> ' . ProfileHtml::escape($operation->action->value) . '</p>'
                . '<p><strong>Durum:</strong> ' . ProfileHtml::escape($operation->status->value) . '</p>'
                . '<p><strong>İlerleme:</strong> ' . $operation->processedCount . '/' . $operation->totalCount
                . ' (' . $operation->percent() . '%)</p>'
                . '<p>Başarılı: ' . $operation->succeededCount . ' · Atlanan: ' . $operation->skippedCount
                . ' · Hatalı: ' . $operation->failedCount . '</p>';

            if (!$operation->status->terminal()) {
                $body .= '<form method="post" action="' . ProfileHtml::escape($this->basePath->prepend('/content-manager/operations/' . $operationId->value())) . '">'
                    . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
                    . '<button type="submit">Sonraki 50 hedefi işle</button></form>';
            }

            $body .= '<p><a href="' . ProfileHtml::escape($this->basePath->prepend('/content-manager')) . '">İçerik yöneticisine dön</a></p></section>'
                . '<section class="card" style="margin-top:18px"><h2>Hedefler</h2>';
            foreach ($items as $item) {
                $body .= '<p><strong>' . ProfileHtml::escape($item->type->value) . '</strong> '
                    . ProfileHtml::escape($item->contentId->value()) . ' · '
                    . ProfileHtml::escape($item->status->value)
                    . ($item->failureCode === null ? '' : ' · ' . ProfileHtml::escape($item->failureCode))
                    . '</p>';
            }
            $body .= '</section>';

            return Response::html(ProfileHtml::page('İçerik yöneticisi işlemi', $body, $this->basePath, authenticated:true))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (ContentManagerAccessDeniedException) {
            return Response::text('Permission denied.', 403)->withHeader('Cache-Control', 'no-store');
        } catch (ContentManagerOperationException|InvalidArgumentException) {
            return Response::text('Content manager operation is unavailable.', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
