<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use Forwext\Core\Ui\Theme\PublishedThemeAssetService;
use InvalidArgumentException;
use RuntimeException;

final readonly class ThemeAssetHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublishedThemeAssetService $assets,
        private string $kind,
    ) {
        if (!in_array($this->kind, ['css', 'js'], true)) {
            throw new InvalidArgumentException('Theme asset handler kind is invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS);
        $themeKey = is_array($parameters) ? ($parameters['themeKey'] ?? null) : null;
        $revisionId = is_array($parameters) ? ($parameters['revisionId'] ?? null) : null;

        if (
            !is_string($themeKey)
            || !is_string($revisionId)
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $themeKey) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $revisionId) !== 1
        ) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $content = $this->assets->asset(
                $themeKey,
                EntityId::fromString($revisionId),
                $this->kind,
            );

            return Response::text($content)
                ->withHeader(
                    'Content-Type',
                    $this->kind === 'css'
                        ? 'text/css; charset=utf-8'
                        : 'text/javascript; charset=utf-8',
                )
                ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
