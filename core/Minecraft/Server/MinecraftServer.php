<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MinecraftServer
{
    public function __construct(
        public EntityId $serverId,
        public ?EntityId $ownerUserId,
        public string $slug,
        public string $name,
        public string $summary,
        public string $description,
        public string $host,
        public int $port,
        public string $edition,
        public string $versionLabel,
        public string $gameMode,
        public ?string $websiteUrl,
        public ?string $discordUrl,
        public string $listingState,
        public string $verificationState,
        public string $reachability,
        public ?int $onlinePlayers,
        public ?int $maxPlayers,
        public ?int $latencyMs,
        public ?string $motd,
        public ?DateTimeImmutable $statusCheckedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,119}$/D', $slug) !== 1) {
            throw new InvalidArgumentException('Minecraft server slug is invalid.');
        }
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($summary) > 240 || mb_strlen($description) > 20000) {
            throw new InvalidArgumentException('Minecraft server text fields are invalid.');
        }
        if ($host === '' || strlen($host) > 255 || preg_match('/[\s\x00-\x1f\x7f]/', $host) === 1) {
            throw new InvalidArgumentException('Minecraft server host is invalid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Minecraft server port is invalid.');
        }
        if (!in_array($edition, ['java','bedrock','crossplay'], true)) {
            throw new InvalidArgumentException('Minecraft server edition is invalid.');
        }
        if (!in_array($listingState, ['draft','published','suspended'], true)) {
            throw new InvalidArgumentException('Minecraft server listing state is invalid.');
        }
        if (!in_array($verificationState, ['unverified','pending','verified'], true)) {
            throw new InvalidArgumentException('Minecraft server verification state is invalid.');
        }
        if (!in_array($reachability, ['unknown','online','offline'], true)) {
            throw new InvalidArgumentException('Minecraft server reachability is invalid.');
        }
        foreach ([$onlinePlayers, $maxPlayers, $latencyMs] as $metric) {
            if ($metric !== null && $metric < 0) {
                throw new InvalidArgumentException('Minecraft server metric is invalid.');
            }
        }
    }

    public function address(): string
    {
        return $this->port === 25565 ? $this->host : $this->host . ':' . $this->port;
    }

    public function verified(): bool
    {
        return $this->verificationState === 'verified';
    }
}
