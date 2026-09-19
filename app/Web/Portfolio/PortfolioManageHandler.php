<?php

declare(strict_types=1);

namespace Forwext\App\Web\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Portfolio\PortfolioCategory;
use Forwext\Core\Portfolio\PortfolioProject;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Portfolio\PortfolioState;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class PortfolioManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private PortfolioService $portfolio,
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
                $projectId = $this->mutate($actor, $request);
                $location = '/portfolio/manage?updated=1';
                if ($projectId !== null) {
                    $location .= '&project=' . rawurlencode($projectId->value());
                }
                return Response::text('', 303)
                    ->withHeader('Location', $this->basePath->prepend($location))
                    ->withHeader('Cache-Control', 'no-store');
            }

            $project = null;
            $queryId = $request->query()['project'] ?? null;
            if (is_string($queryId) && $queryId !== '') {
                if (preg_match('/^[a-f0-9]{32}$/D', $queryId) !== 1) {
                    throw new InvalidArgumentException('Portfolio project id is invalid.');
                }
                $project = $this->portfolio->project(EntityId::fromString($queryId), $actor);
                if (!$this->portfolio->canManageProject($actor, $project)) {
                    throw new PermissionDeniedException(
                        $this->portfolio->decision($actor, 'portfolio.manage_own'),
                    );
                }
            } elseif (!$this->portfolio->canCreate($actor)) {
                return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(PortfolioHtml::manage(
                $this->portfolio->categories(),
                $project,
                $this->basePath,
                $token,
                $this->portfolio->canManageAll($actor),
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): ?EntityId
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Portfolio action is missing.');
        }

        if ($action === 'category_save') {
            $this->portfolio->saveCategory($actor, new PortfolioCategory(
                strtolower(self::required($body, 'key', 64)),
                self::required($body, 'label', 120),
                self::optional($body, 'category_description', 500) ?? '',
                self::integer($body, 'sort_order', 0, 65535, 100),
                self::checked($body, 'active'),
            ));
            return null;
        }

        if ($action !== 'project_save') {
            throw new InvalidArgumentException('Portfolio action is invalid.');
        }

        $rawId = self::optional($body, 'project_id', 32);
        $existing = null;
        if ($rawId !== null) {
            if (preg_match('/^[a-f0-9]{32}$/D', $rawId) !== 1) {
                throw new InvalidArgumentException('Portfolio project id is invalid.');
            }
            $existing = $this->portfolio->project(EntityId::fromString($rawId), $actor);
            if (!$this->portfolio->canManageProject($actor, $existing)) {
                throw new PermissionDeniedException($this->portfolio->decision($actor, 'portfolio.manage_own'));
            }
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $project = new PortfolioProject(
            $existing?->projectId ?? PortfolioProject::generateId(),
            $existing?->ownerUserId ?? $actor,
            strtolower(self::required($body, 'category', 64)),
            strtolower(self::required($body, 'slug', 160)),
            self::required($body, 'title', 180),
            self::optional($body, 'summary', 500) ?? '',
            self::required($body, 'description', 100000),
            self::tags(self::optional($body, 'tags', 2200) ?? ''),
            $existing?->media ?? [],
            self::checked($body, 'publish') ? PortfolioState::Published : PortfolioState::Draft,
            self::checked($body, 'featured'),
            $existing?->createdAt ?? $now,
            $now,
        );
        return $this->portfolio->saveProject($actor, $project)->projectId;
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Portfolio field is missing: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Portfolio field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body, string $key, int $max): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max) {
            throw new InvalidArgumentException('Portfolio optional field is invalid: ' . $key);
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return $default;
        }
        $value = filter_var($body[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Portfolio integer field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === '1' || ($body[$key] ?? null) === 1 || ($body[$key] ?? null) === true;
    }

    /** @return list<string> */
    private static function tags(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $tags = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $tag): bool => $tag !== '',
        ));
        if (count($tags) > 32) {
            throw new InvalidArgumentException('Portfolio tag limit exceeded.');
        }
        return $tags;
    }

}
