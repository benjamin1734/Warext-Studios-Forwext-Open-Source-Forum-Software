<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqOperationException;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class FaqArticleIdHandler implements RequestHandlerInterface
{
    public function __construct(
        private FaqService $faq,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $id = is_array($params) ? ($params['articleId'] ?? null) : null;
        if (!is_string($id)) {
            return Response::text('Not Found',404);
        }
        try {
            $view = $this->faq->article(EntityId::fromString($id), $this->viewers->resolve($request));
            return Response::text('',302)->withHeader(
                'Location',
                $this->basePath->prepend('/faq/' . rawurlencode($view->article->language)
                    . '/' . rawurlencode($view->article->slug)),
            )->withHeader('Cache-Control','no-store');
        } catch (FaqOperationException|InvalidArgumentException) {
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }
}
