<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MinecraftServerDirectoryHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 24;

    public function __construct(
        private MinecraftServerService $servers,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $query = self::optionalString($request->query()['q'] ?? null, 120);
            $edition = self::edition($request->query()['edition'] ?? null);
            $page = self::page($request->query()['page'] ?? null);
            $rows = $this->servers->directory(
                $query,
                $edition,
                self::PER_PAGE + 1,
                ($page - 1) * self::PER_PAGE,
            );
            $hasMore = count($rows) > self::PER_PAGE;
            if ($hasMore) {
                array_pop($rows);
            }
            $actor = $this->viewers->resolve($request);

            return Response::html(MinecraftServerHtml::directory(
                $rows,
                $this->basePath,
                $actor !== null,
                $query,
                $edition,
                $page,
                $hasMore,
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private static function optionalString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Minecraft server query is invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server query is invalid.');
        }
        return $value;
    }

    private static function edition(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, ['java','bedrock','crossplay'], true)) {
            throw new InvalidArgumentException('Minecraft server edition is invalid.');
        }
        return $value;
    }

    private static function page(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (is_int($value) && $value >= 1 && $value <= 10000) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,3}$/D', $value) === 1) {
            return (int) $value;
        }
        throw new InvalidArgumentException('Minecraft server page is invalid.');
    }
}
