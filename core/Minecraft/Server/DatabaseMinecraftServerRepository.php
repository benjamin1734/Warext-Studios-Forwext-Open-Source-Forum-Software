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
            $where[] = '(s.name LIKE :query_name OR s.summary LIKE :query_summary '
                . 'OR s.host LIKE :query_host OR s.game_mode LIKE :query_mode)';
            $like = '%' . $query . '%';
            $parameters['query_name'] = $like;
            $parameters['query_summary'] = $like;
            $parameters['query_host'] = $like;
            $parameters['query_mode'] = $like;
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

    public function publicByIds(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }
        if (count($serverIds) > 4) {
            throw new \InvalidArgumentException('Minecraft server comparison limit is invalid.');
        }

        $placeholders = [];
        $parameters = [];
        foreach (array_values($serverIds) as $index => $serverId) {
            if (!$serverId instanceof EntityId) {
                throw new \InvalidArgumentException('Minecraft server comparison id is invalid.');
            }
            $key = 'server_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $serverId->value();
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . " WHERE s.listing_state='published' AND s.server_id IN ("
            . implode(',', $placeholders) . ')',
            $parameters,
        ));
        $byId = [];
        foreach ($rows as $row) {
            $server = $this->hydrate($row);
            $byId[$server->serverId->value()] = $server;
        }

        $ordered = [];
        foreach ($serverIds as $serverId) {
            $server = $byId[$serverId->value()] ?? null;
            if ($server !== null) {
                $ordered[] = $server;
            }
        }
        return $ordered;
    }

    public function publicSeasons(?string $state = null, int $limit = 30, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new \InvalidArgumentException('Minecraft season pagination is invalid.');
        }
        $where = '';
        $parameters = [];
        if ($state !== null) {
            $where = ' WHERE s.state=:state';
            $parameters['state'] = $state;
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT s.season_id,s.slug,s.name,s.summary,s.state,s.starts_at_utc,s.ends_at_utc,'
            . 's.created_at_utc,s.updated_at_utc,COUNT(e.server_id) AS server_count '
            . 'FROM forwext_minecraft_server_seasons s '
            . 'LEFT JOIN forwext_minecraft_server_season_entries e ON e.season_id=s.season_id'
            . $where
            . ' GROUP BY s.season_id,s.slug,s.name,s.summary,s.state,s.starts_at_utc,s.ends_at_utc,'
            . 's.created_at_utc,s.updated_at_utc '
            . "ORDER BY FIELD(s.state,'active','upcoming','closed'),s.starts_at_utc DESC,s.season_id DESC "
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        ));

        return array_map($this->hydrateSeason(...), $rows);
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
    private function hydrateSeason(array $row): MinecraftServerSeason
    {
        return new MinecraftServerSeason(
            EntityId::fromString((string) $row['season_id']),
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['summary'],
            (string) $row['state'],
            self::parse((string) $row['starts_at_utc']),
            self::parse((string) $row['ends_at_utc']),
            (int) $row['server_count'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): MinecraftServer
    {
        return new MinecraftServer(
            EntityId::fromString((string) $row['server_id']),
            $row['owner_user_id'] === null ? null : UserId::fromStored((string) $row['owner_user_id']),
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
