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

            $body = '<section class="content-operation-page discovery-page"><header class="surface-head content-operation-head"><div>'
                . '<span class="forum-eyebrow">İÇERİK YÖNETİCİSİ</span><h1>İşlem durumu</h1>'
                . '<p>' . ProfileHtml::escape($operation->action->value) . ' · '
                . ProfileHtml::escape($operation->status->value) . '</p></div>'
                . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/content-manager'))
                . '">İçerik yöneticisine dön</a></header>'
                . '<section class="surface-panel content-operation-summary"><div class="content-operation-progress"><div><span>İlerleme</span><strong>'
                . $operation->processedCount . '/' . $operation->totalCount . '</strong></div>'
                . '<div class="content-operation-bar"><span style="width:' . max(0, min(100, $operation->percent())) . '%"></span></div>'
                . '<small>%' . $operation->percent() . '</small></div>'
                . '<div class="content-operation-counts">'
                . '<span><strong>' . $operation->succeededCount . '</strong>Başarılı</span>'
                . '<span><strong>' . $operation->skippedCount . '</strong>Atlanan</span>'
                . '<span><strong>' . $operation->failedCount . '</strong>Hatalı</span></div>';

            if (!$operation->status->terminal()) {
                $body .= '<form class="content-operation-next" method="post" action="'
                    . ProfileHtml::escape($this->basePath->prepend(
                        '/content-manager/operations/' . $operationId->value(),
                    )) . '"><input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
                    . '<button class="fx-btn fx-btn--primary" type="submit">Sonraki 50 hedefi işle</button></form>';
            }

            $body .= '</section><section class="surface-panel content-operation-targets"><header><h2>Hedefler</h2><span>'
                . count($items) . '</span></header><div class="content-operation-target-list">';
            if ($items === []) {
                $body .= '<div class="surface-empty"><strong>Hedef yok.</strong><span>Bu işlem için kayıt bulunamadı.</span></div>';
            } else {
                foreach ($items as $item) {
                    $body .= '<article class="content-operation-target"><span>'
                        . ProfileHtml::escape($item->type->value) . '</span><strong>'
                        . ProfileHtml::escape($item->contentId->value()) . '</strong><small>'
                        . ProfileHtml::escape($item->status->value)
                        . ($item->failureCode === null ? '' : ' · ' . ProfileHtml::escape($item->failureCode))
                        . '</small></article>';
                }
            }
            $body .= '</div></section></section>';

            return Response::html(ProfileHtml::page('İçerik yöneticisi işlemi', $body, $this->basePath, authenticated:true))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (ContentManagerAccessDeniedException) {
            return Response::text('Permission denied.', 403)->withHeader('Cache-Control', 'no-store');
        } catch (ContentManagerOperationException|InvalidArgumentException) {
            return Response::text('Content manager operation is unavailable.', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
