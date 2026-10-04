<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MinecraftServerCompareHandler implements RequestHandlerInterface
{
    public function __construct(
        private MinecraftServerService $servers,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $selectedIds = self::selectedIds($request->query()['server'] ?? null);
            $comparison = count($selectedIds) >= 2 ? $this->servers->compare($selectedIds) : [];
            $candidates = $this->servers->directory(null, null, 50, 0);
            $actor = $this->viewers->resolve($request);

            return Response::html(MinecraftServerHtml::comparison(
                $candidates,
                $comparison,
                array_map(static fn (EntityId $id): string => $id->value(), $selectedIds),
                count($selectedIds) === 1 ? 'Karşılaştırma için en az iki sunucu seç.' : null,
                $this->basePath,
                $actor !== null,
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    /** @return list<EntityId> */
    private static function selectedIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 4) {
            throw new InvalidArgumentException('Minecraft server comparison limit is invalid.');
        }

        $ids = [];
        $seen = [];
        foreach ($values as $raw) {
            if (!is_string($raw) || preg_match('/^[a-f0-9]{32}$/D', $raw) !== 1 || isset($seen[$raw])) {
                throw new InvalidArgumentException('Minecraft server comparison id is invalid.');
            }
            $seen[$raw] = true;
            $ids[] = EntityId::fromString($raw);
        }
        return $ids;
    }
}
