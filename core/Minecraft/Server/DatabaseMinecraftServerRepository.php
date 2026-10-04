<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabaseMinecraftServerRepository implements MinecraftServerRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function publicDirectory(
        ?string $query = null,
        ?string $edition = null,
        int $limit = 30,
        int $offset = 0,
    ): array {
        $where = ["s.listing_state='published'"];
        $parameters = [];
        if ($edition !== null) {
            $where[] = 's.edition=:edition';
            $parameters['edition'] = $edition;
        }
        if ($query !== null) {
            $where[] = '(s.name LIKE :query OR s.summary LIKE :query OR s.host LIKE :query OR s.game_mode LIKE :query)';
            $parameters['query'] = '%' . $query . '%';
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY (s.verification_state=\'verified\') DESC,'
            . '(COALESCE(st.reachability,\'unknown\')=\'online\') DESC,'
            . 's.updated_at_utc DESC,s.server_id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function publicById(EntityId $serverId): ?MinecraftServer
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . " WHERE s.server_id=:server_id AND s.listing_state='published' LIMIT 1",
            ['server_id'=>$serverId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    private function selectSql(): string
    {
        return 'SELECT s.server_id,s.owner_user_id,s.slug,s.name,s.summary,s.description,s.host,s.port,'
            . 's.edition,s.version_label,s.game_mode,s.website_url,s.discord_url,s.listing_state,s.verification_state,'
            . 's.created_at_utc,s.updated_at_utc,COALESCE(st.reachability,\'unknown\') AS reachability,'
            . 'st.online_players,st.max_players,st.latency_ms,st.motd,st.checked_at_utc '
            . 'FROM forwext_minecraft_servers s '
            . 'LEFT JOIN forwext_minecraft_server_status st ON st.server_id=s.server_id';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): MinecraftServer
    {
        return new MinecraftServer(
            EntityId::fromString((string) $row['server_id']),
            UserId::fromStored((string) $row['owner_user_id']),
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['summary'],
            (string) $row['description'],
            (string) $row['host'],
            (int) $row['port'],
            (string) $row['edition'],
            (string) $row['version_label'],
            (string) $row['game_mode'],
            $row['website_url'] === null ? null : (string) $row['website_url'],
            $row['discord_url'] === null ? null : (string) $row['discord_url'],
            (string) $row['listing_state'],
            (string) $row['verification_state'],
            (string) $row['reachability'],
            $row['online_players'] === null ? null : (int) $row['online_players'],
            $row['max_players'] === null ? null : (int) $row['max_players'],
            $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
            $row['motd'] === null ? null : (string) $row['motd'],
            $row['checked_at_utc'] === null ? null : self::parse((string) $row['checked_at_utc']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new RuntimeException('Stored Minecraft server timestamp is invalid.');
    }
}
