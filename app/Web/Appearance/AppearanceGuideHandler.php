<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideLevel;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideService;
use Forwext\Core\Ui\Appearance\Guide\AppearancePreviewDevice;
use InvalidArgumentException;

final readonly class AppearanceGuideHandler implements RequestHandlerInterface
{
    public function __construct(
        private AppearanceGuideService $guide,
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
            $query = $request->query();
            $mode = $this->enumQuery($query, 'mode', 'basic', AppearanceGuideLevel::class);
            $device = $this->enumQuery($query, 'device', 'desktop', AppearancePreviewDevice::class);
            $preset = $this->stringQuery($query, 'preset', 'balanced', 32);
            $search = $this->stringQuery($query, 'q', '', 80);

            $snapshot = $this->guide->snapshot($actor, $mode, $device, $search, $preset);
            $content = AppearanceGuideHtml::page($snapshot, $this->basePath);

            return Response::html(ProfileHtml::page(
                'Appearance Studio',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|\ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    /**
     * @template T of \BackedEnum
     * @param array<string,mixed> $query
     * @param class-string<T> $enum
     * @return T
     */
    private function enumQuery(array $query, string $key, string $default, string $enum): \BackedEnum
    {
        $raw = $this->stringQuery($query, $key, $default, 32);
        $value = $enum::tryFrom($raw);
        if (!$value instanceof $enum) {
            throw new InvalidArgumentException('Appearance guide enum query is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function stringQuery(array $query, string $key, string $default, int $maxLength): string
    {
        $value = $query[$key] ?? $default;
        if (!is_string($value) || strlen($value) > $maxLength || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Appearance guide query is invalid.');
        }

        return $value;
    }
}
