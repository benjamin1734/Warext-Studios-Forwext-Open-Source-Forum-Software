<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class FaqIndexHandler implements RequestHandlerInterface
{
    public function __construct(
        private FaqService $faq,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $actor = $this->viewers->resolve($request);
            $language = $request->query()['lang'] ?? null;
            if ($language !== null && !is_string($language)) {
                throw new InvalidArgumentException('FAQ language query is invalid.');
            }
            $language = is_string($language) && trim($language) !== '' ? trim($language) : null;
            $categories = $this->faq->categories($actor, $language);
            $articles = [];
            foreach ($categories as $category) {
                $articles[$category->key] = $this->faq->articlesIn($category->key, $actor);
            }
            return Response::html(FaqHtml::index(
                $categories,
                $articles,
                $this->basePath,
                $language,
                $actor !== null,
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=120' : 'private, no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }
}
