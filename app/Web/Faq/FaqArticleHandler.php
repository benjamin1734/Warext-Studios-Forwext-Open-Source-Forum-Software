<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Faq\FaqOperationException;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class FaqArticleHandler implements RequestHandlerInterface
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
        $language = is_array($params) ? ($params['language'] ?? null) : null;
        $slug = is_array($params) ? ($params['slug'] ?? null) : null;
        if (!is_string($language) || !is_string($slug)) {
            return Response::text('Not Found', 404);
        }

        try {
            $actor = $this->viewers->resolve($request);
            $view = $this->faq->articleBySlug($language, $slug, $actor);
            if ($request->method() === HttpMethod::Post) {
                if ($actor === null) {
                    return Response::text('Authentication required.', 401)->withHeader('Cache-Control','no-store');
                }
                $value = $request->parsedBody()['helpful'] ?? null;
                if ($value !== '1' && $value !== '0' && $value !== 1 && $value !== 0) {
                    throw new InvalidArgumentException('FAQ helpful value is invalid.');
                }
                $this->faq->voteHelpful($actor, $view->article->articleId, $value === '1' || $value === 1);
                return Response::text('',303)->withHeader(
                    'Location',
                    $this->basePath->prepend('/faq/' . rawurlencode($language) . '/' . rawurlencode($slug) . '?voted=1'),
                )->withHeader('Cache-Control','no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            $token = is_string($token) && $token !== '' ? $token : null;
            return Response::html(FaqHtml::article(
                $view,
                $this->basePath,
                $token,
                $actor !== null,
                ($request->query()['voted'] ?? null) === '1',
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=120' : 'private, no-store');
        } catch (FaqOperationException) {
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }
}
