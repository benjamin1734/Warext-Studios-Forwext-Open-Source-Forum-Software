<?php

declare(strict_types=1);

namespace Forwext\Core\Minecraft\Server;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
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
            throw new InvalidArgumentException('Minecraft server comparison limit is invalid.');
        }

        $placeholders = [];
        $parameters = [];
        foreach (array_values($serverIds) as $index => $serverId) {
            if (!$serverId instanceof EntityId) {
                throw new InvalidArgumentException('Minecraft server comparison id is invalid.');
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
            throw new InvalidArgumentException('Minecraft season pagination is invalid.');
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

    public function voteSummary(
        EntityId $serverId,
        ?EntityId $voterUserId,
        DateTimeImmutable $now,
    ): MinecraftServerVoteSummary {
        $parameters = [
            'server_id'=>$serverId->value(),
            'since'=>self::format($now->modify('-30 days')),
        ];
        $select = 'SELECT COUNT(*) AS total_votes,'
            . 'COALESCE(SUM(created_at_utc>=:since),0) AS votes_30d';
        if ($voterUserId === null) {
            $select .= ',NULL AS last_vote_at,0 AS voted_today';
        } else {
            UserId::assert($voterUserId);
            $parameters['voter_last'] = $voterUserId->value();
            $parameters['voter_today'] = $voterUserId->value();
            $parameters['vote_day'] = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
            $select .= ',MAX(CASE WHEN voter_user_id=:voter_last THEN created_at_utc ELSE NULL END) AS last_vote_at'
                . ',COALESCE(SUM(CASE WHEN voter_user_id=:voter_today AND vote_day=:vote_day THEN 1 ELSE 0 END),0) '
                . 'AS voted_today';
        }
        $row = $this->database->fetchOne(new CompiledQuery(
            $select . ' FROM forwext_minecraft_server_votes WHERE server_id=:server_id',
            $parameters,
        ));
        if ($row === null) {
            throw new RuntimeException('Minecraft server vote summary query failed.');
        }

        return new MinecraftServerVoteSummary(
            (int) $row['total_votes'],
            (int) $row['votes_30d'],
            (int) $row['voted_today'] > 0,
            $row['last_vote_at'] === null ? null : self::parse((string) $row['last_vote_at']),
        );
    }

    public function castVote(
        EntityId $serverId,
        EntityId $voterUserId,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($voterUserId);
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_minecraft_server_votes '
            . '(vote_id,server_id,voter_user_id,vote_day,created_at_utc) '
            . 'SELECT :vote_id,s.server_id,:voter_user_id,:vote_day,:created_at '
            . "FROM forwext_minecraft_servers s WHERE s.server_id=:server_id AND s.listing_state='published'",
            [
                'vote_id'=>bin2hex(random_bytes(16)),
                'server_id'=>$serverId->value(),
                'voter_user_id'=>$voterUserId->value(),
                'vote_day'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
                'created_at'=>self::format($now),
            ],
        ));

        return $affected === 1;
    }

    public function publicUpdates(EntityId $serverId, int $limit = 20, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Minecraft server update pagination is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT update_id,server_id,author_user_id,title,body,state,created_at_utc,updated_at_utc '
            . 'FROM forwext_minecraft_server_updates '
            . "WHERE server_id=:server_id AND state='published' "
            . 'ORDER BY created_at_utc DESC,update_id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['server_id'=>$serverId->value()],
        ));
        return array_map($this->hydrateUpdate(...), $rows);
    }

    public function managementUpdates(EntityId $serverId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Minecraft server update management limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT update_id,server_id,author_user_id,title,body,state,created_at_utc,updated_at_utc '
            . 'FROM forwext_minecraft_server_updates WHERE server_id=:server_id '
            . 'ORDER BY created_at_utc DESC,update_id DESC LIMIT ' . $limit,
            ['server_id'=>$serverId->value()],
        ));
        return array_map($this->hydrateUpdate(...), $rows);
    }

    public function createUpdate(MinecraftServerUpdate $update, ?EntityId $requiredOwnerUserId = null): void
    {
        if ($requiredOwnerUserId !== null) {
            UserId::assert($requiredOwnerUserId);
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($update, $requiredOwnerUserId): void {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$update->serverId->value()],
            ));
            if ($row === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            if ($requiredOwnerUserId !== null) {
                $storedOwner = $row['owner_user_id'] === null ? null : (string) $row['owner_user_id'];
                if ($storedOwner === null || !hash_equals($storedOwner, $requiredOwnerUserId->value())) {
                    throw new RuntimeException('Minecraft server ownership changed concurrently.');
                }
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_minecraft_server_updates '
                . '(update_id,server_id,author_user_id,title,body,state,created_at_utc,updated_at_utc) '
                . 'VALUES (:update_id,:server_id,:author_user_id,:title,:body,:state,:created_at,:updated_at)',
                [
                    'update_id'=>$update->updateId->value(),
                    'server_id'=>$update->serverId->value(),
                    'author_user_id'=>$update->authorUserId?->value(),
                    'title'=>$update->title,
                    'body'=>$update->body,
                    'state'=>$update->state,
                    'created_at'=>self::format($update->createdAt),
                    'updated_at'=>self::format($update->updatedAt),
                ],
                true,
            ));
        });
    }

    public function setUpdateState(
        EntityId $serverId,
        EntityId $updateId,
        string $state,
        DateTimeImmutable $now,
        ?EntityId $requiredOwnerUserId = null,
    ): bool {
        if (!in_array($state, ['published','hidden'], true)) {
            throw new InvalidArgumentException('Minecraft server update state is invalid.');
        }
        if ($requiredOwnerUserId !== null) {
            UserId::assert($requiredOwnerUserId);
        }
        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $serverId,
            $updateId,
            $state,
            $now,
            $requiredOwnerUserId,
        ): bool {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$serverId->value()],
            ));
            if ($row === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            if ($requiredOwnerUserId !== null) {
                $storedOwner = $row['owner_user_id'] === null ? null : (string) $row['owner_user_id'];
                if ($storedOwner === null || !hash_equals($storedOwner, $requiredOwnerUserId->value())) {
                    throw new RuntimeException('Minecraft server ownership changed concurrently.');
                }
            }
            return $database->execute(new CompiledQuery(
                'UPDATE forwext_minecraft_server_updates SET state=:state,updated_at_utc=:updated_at '
                . 'WHERE update_id=:update_id AND server_id=:server_id',
                [
                    'state'=>$state,
                    'updated_at'=>self::format($now),
                    'update_id'=>$updateId->value(),
                    'server_id'=>$serverId->value(),
                ],
                true,
            )) === 1;
        });
    }

    public function statistics(EntityId $serverId, DateTimeImmutable $now): MinecraftServerStatistics
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $since = $utc->modify('-30 days');
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT '
            . '(SELECT COUNT(*) FROM forwext_minecraft_server_votes v WHERE v.server_id=:server_total) AS total_votes,'
            . '(SELECT COUNT(*) FROM forwext_minecraft_server_votes v '
            . 'WHERE v.server_id=:server_30 AND v.created_at_utc>=:since_30) AS votes_30d,'
            . '(SELECT COUNT(*) FROM forwext_minecraft_server_updates u '
            . "WHERE u.server_id=:server_updates AND u.state='published') AS published_updates,"
            . '(SELECT MAX(v.created_at_utc) FROM forwext_minecraft_server_votes v '
            . 'WHERE v.server_id=:server_last) AS last_vote_at',
            [
                'server_total'=>$serverId->value(),
                'server_30'=>$serverId->value(),
                'since_30'=>self::format($since),
                'server_updates'=>$serverId->value(),
                'server_last'=>$serverId->value(),
            ],
        ));
        if ($row === null) {
            throw new RuntimeException('Minecraft server statistics query failed.');
        }

        $startDay = $utc->modify('-29 days')->format('Y-m-d');
        $trendRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT vote_day,COUNT(*) AS vote_count FROM forwext_minecraft_server_votes '
            . 'WHERE server_id=:server_id AND vote_day>=:start_day GROUP BY vote_day ORDER BY vote_day ASC',
            ['server_id'=>$serverId->value(),'start_day'=>$startDay],
        ));
        $rawTrend = [];
        foreach ($trendRows as $trendRow) {
            $rawTrend[(string) $trendRow['vote_day']] = (int) $trendRow['vote_count'];
        }
        $dailyVotes = [];
        for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
            $day = $utc->modify('-' . $daysAgo . ' days')->format('Y-m-d');
            $dailyVotes[$day] = $rawTrend[$day] ?? 0;
        }

        return new MinecraftServerStatistics(
            (int) $row['total_votes'],
            (int) $row['votes_30d'],
            (int) $row['published_updates'],
            $row['last_vote_at'] === null ? null : self::parse((string) $row['last_vote_at']),
            $dailyVotes,
        );
    }

    public function managementById(EntityId $serverId): ?MinecraftServer
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE s.server_id=:server_id LIMIT 1',
            ['server_id'=>$serverId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function managementDirectory(?EntityId $actorUserId = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Minecraft server management limit is invalid.');
        }
        $where = '';
        $parameters = [];
        if ($actorUserId !== null) {
            UserId::assert($actorUserId);
            $where = ' WHERE (s.owner_user_id=:owner_user_id OR EXISTS ('
                . 'SELECT 1 FROM forwext_minecraft_server_team_members tm '
                . "WHERE tm.server_id=s.server_id AND tm.user_id=:team_user_id AND tm.role_key='manager'))";
            $parameters['owner_user_id'] = $actorUserId->value();
            $parameters['team_user_id'] = $actorUserId->value();
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . $where . ' ORDER BY s.updated_at_utc DESC,s.server_id DESC LIMIT ' . $limit,
            $parameters,
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function team(EntityId $serverId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT server_id,user_id,role_key,public_title,added_by_user_id,created_at_utc,updated_at_utc '
            . 'FROM forwext_minecraft_server_team_members WHERE server_id=:server_id '
            . "ORDER BY FIELD(role_key,'manager','member'),created_at_utc ASC,user_id ASC",
            ['server_id'=>$serverId->value()],
        ));
        return array_map($this->hydrateTeamMember(...), $rows);
    }

    public function teamRole(EntityId $serverId, EntityId $userId): ?string
    {
        UserId::assert($userId);
        $role = $this->database->fetchValue(new CompiledQuery(
            'SELECT role_key FROM forwext_minecraft_server_team_members '
            . 'WHERE server_id=:server_id AND user_id=:user_id LIMIT 1',
            ['server_id'=>$serverId->value(),'user_id'=>$userId->value()],
        ));
        return is_string($role) ? $role : null;
    }

    public function upsertTeamMember(
        MinecraftServerTeamMember $member,
        ?EntityId $expectedOwnerUserId,
    ): void {
        if ($expectedOwnerUserId !== null) {
            UserId::assert($expectedOwnerUserId);
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $member,
            $expectedOwnerUserId,
        ): void {
            $server = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$member->serverId->value()],
            ));
            if ($server === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $storedOwner = $server['owner_user_id'] === null ? null : (string) $server['owner_user_id'];
            if ($expectedOwnerUserId !== null
                && ($storedOwner === null || !hash_equals($storedOwner, $expectedOwnerUserId->value()))) {
                throw new RuntimeException('Minecraft server ownership changed concurrently.');
            }
            if ($storedOwner !== null && hash_equals($storedOwner, $member->userId->value())) {
                throw new InvalidArgumentException('Minecraft server owner cannot also be a team member.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_minecraft_server_team_members '
                . '(server_id,user_id,role_key,public_title,added_by_user_id,created_at_utc,updated_at_utc) '
                . 'VALUES (:server_id,:user_id,:role_key,:public_title,:added_by,:created_at,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE role_key=VALUES(role_key),public_title=VALUES(public_title),'
                . 'added_by_user_id=VALUES(added_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'server_id'=>$member->serverId->value(),
                    'user_id'=>$member->userId->value(),
                    'role_key'=>$member->roleKey,
                    'public_title'=>$member->publicTitle,
                    'added_by'=>$member->addedByUserId?->value(),
                    'created_at'=>self::format($member->createdAt),
                    'updated_at'=>self::format($member->updatedAt),
                ],
                true,
            ));
            $this->appendOwnershipEvent(
                $database,
                $member->serverId,
                'team_member_saved',
                $member->addedByUserId,
                null,
                null,
                'Team member ' . $member->userId->value() . ' saved with role ' . $member->roleKey . '.',
                $member->updatedAt,
            );
        });
    }

    public function removeTeamMember(
        EntityId $serverId,
        EntityId $userId,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        UserId::assert($actorUserId);
        if ($expectedOwnerUserId !== null) {
            UserId::assert($expectedOwnerUserId);
        }
        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $serverId,
            $userId,
            $expectedOwnerUserId,
            $actorUserId,
            $now,
        ): bool {
            $server = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$serverId->value()],
            ));
            if ($server === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $storedOwner = $server['owner_user_id'] === null ? null : (string) $server['owner_user_id'];
            if ($expectedOwnerUserId !== null
                && ($storedOwner === null || !hash_equals($storedOwner, $expectedOwnerUserId->value()))) {
                throw new RuntimeException('Minecraft server ownership changed concurrently.');
            }
            $removed = $database->execute(new CompiledQuery(
                'DELETE FROM forwext_minecraft_server_team_members '
                . 'WHERE server_id=:server_id AND user_id=:user_id',
                ['server_id'=>$serverId->value(),'user_id'=>$userId->value()],
                true,
            )) === 1;
            if ($removed) {
                $this->appendOwnershipEvent(
                    $database,
                    $serverId,
                    'team_member_removed',
                    $actorUserId,
                    null,
                    null,
                    'Team member ' . $userId->value() . ' removed.',
                    $now,
                );
            }
            return $removed;
        });
    }

    public function voteIntegration(EntityId $serverId): ?MinecraftServerVoteIntegration
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT server_id,enabled,token_prefix,last_rotated_at_utc,updated_by_user_id,'
            . 'created_at_utc,updated_at_utc FROM forwext_minecraft_server_vote_integrations '
            . 'WHERE server_id=:server_id LIMIT 1',
            ['server_id'=>$serverId->value()],
        ));
        return $row === null ? null : $this->hydrateVoteIntegration($row);
    }

    public function saveVoteIntegration(
        EntityId $serverId,
        bool $enabled,
        ?string $tokenHash,
        ?string $tokenPrefix,
        bool $replaceToken,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($actorUserId);
        if ($expectedOwnerUserId !== null) {
            UserId::assert($expectedOwnerUserId);
        }
        if ($replaceToken) {
            if ($tokenHash === null || preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1
                || $tokenPrefix === null || preg_match('/^[A-Za-z0-9_-]{6,12}$/D', $tokenPrefix) !== 1) {
                throw new InvalidArgumentException('Minecraft vote integration token material is invalid.');
            }
        } elseif ($tokenHash !== null || $tokenPrefix !== null) {
            throw new InvalidArgumentException('Minecraft vote integration token replacement flag is invalid.');
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $serverId,
            $enabled,
            $tokenHash,
            $tokenPrefix,
            $replaceToken,
            $expectedOwnerUserId,
            $actorUserId,
            $now,
        ): void {
            $server = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$serverId->value()],
            ));
            if ($server === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $storedOwner = $server['owner_user_id'] === null ? null : (string) $server['owner_user_id'];
            if ($expectedOwnerUserId !== null
                && ($storedOwner === null || !hash_equals($storedOwner, $expectedOwnerUserId->value()))) {
                throw new RuntimeException('Minecraft server ownership changed concurrently.');
            }

            if ($replaceToken) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO forwext_minecraft_server_vote_integrations '
                    . '(server_id,enabled,token_hash,token_prefix,last_rotated_at_utc,updated_by_user_id,'
                    . 'created_at_utc,updated_at_utc) '
                    . 'VALUES (:server_id,:enabled,:token_hash,:token_prefix,:rotated_at,:updated_by,:created_at,:updated_at) '
                    . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),token_hash=VALUES(token_hash),'
                    . 'token_prefix=VALUES(token_prefix),last_rotated_at_utc=VALUES(last_rotated_at_utc),'
                    . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
                    [
                        'server_id'=>$serverId->value(),
                        'enabled'=>$enabled ? 1 : 0,
                        'token_hash'=>$tokenHash,
                        'token_prefix'=>$tokenPrefix,
                        'rotated_at'=>self::format($now),
                        'updated_by'=>$actorUserId->value(),
                        'created_at'=>self::format($now),
                        'updated_at'=>self::format($now),
                    ],
                    true,
                ));
                $this->appendOwnershipEvent(
                    $database,
                    $serverId,
                    'vote_token_rotated',
                    $actorUserId,
                    null,
                    null,
                    'Vote integration token rotated.',
                    $now,
                );
                return;
            }

            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_minecraft_server_vote_integrations '
                . '(server_id,enabled,token_hash,token_prefix,last_rotated_at_utc,updated_by_user_id,'
                . 'created_at_utc,updated_at_utc) '
                . 'VALUES (:server_id,:enabled,NULL,NULL,NULL,:updated_by,:created_at,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by_user_id=VALUES(updated_by_user_id),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                [
                    'server_id'=>$serverId->value(),
                    'enabled'=>$enabled ? 1 : 0,
                    'updated_by'=>$actorUserId->value(),
                    'created_at'=>self::format($now),
                    'updated_at'=>self::format($now),
                ],
                true,
            ));
            $this->appendOwnershipEvent(
                $database,
                $serverId,
                $enabled ? 'vote_integration_on' : 'vote_integration_off',
                $actorUserId,
                null,
                null,
                $enabled ? 'Vote integration enabled.' : 'Vote integration disabled.',
                $now,
            );
        });
    }

    public function acceptsVoteIntegrationToken(EntityId $serverId, string $tokenHash): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            return false;
        }
        $stored = $this->database->fetchValue(new CompiledQuery(
            'SELECT token_hash FROM forwext_minecraft_server_vote_integrations '
            . 'WHERE server_id=:server_id AND enabled=1 AND token_hash IS NOT NULL LIMIT 1',
            ['server_id'=>$serverId->value()],
        ));
        return is_string($stored) && strlen($stored) === 64 && hash_equals($stored, $tokenHash);
    }

    public function voteFeed(EntityId $serverId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Minecraft vote integration feed limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT v.vote_id,v.server_id,v.voter_user_id,u.username,v.created_at_utc '
            . 'FROM forwext_minecraft_server_votes v '
            . 'INNER JOIN forwext_users u ON u.user_id=v.voter_user_id '
            . 'WHERE v.server_id=:server_id '
            . 'ORDER BY v.created_at_utc DESC,v.vote_id DESC LIMIT ' . $limit,
            ['server_id'=>$serverId->value()],
        ));
        return array_map($this->hydrateVoteFeedEntry(...), $rows);
    }

    public function claims(?EntityId $claimantUserId = null, ?EntityId $serverId = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Minecraft server claim limit is invalid.');
        }
        $where = [];
        $parameters = [];
        if ($claimantUserId !== null) {
            UserId::assert($claimantUserId);
            $where[] = 'claimant_user_id=:claimant_user_id';
            $parameters['claimant_user_id'] = $claimantUserId->value();
        }
        if ($serverId !== null) {
            $where[] = 'server_id=:server_id';
            $parameters['server_id'] = $serverId->value();
        }
        $sql = 'SELECT claim_id,server_id,claimant_user_id,proof_note,state,reviewed_by_user_id,review_note,'
            . 'created_at_utc,updated_at_utc,reviewed_at_utc FROM forwext_minecraft_server_claims';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY (state='pending') DESC,created_at_utc DESC,claim_id DESC LIMIT " . $limit;
        return array_map($this->hydrateClaim(...), $this->database->fetchAll(new CompiledQuery($sql, $parameters)));
    }

    public function createClaim(MinecraftServerClaim $claim): void
    {
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($claim): void {
            $server = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$claim->serverId->value()],
            ));
            if ($server === null) {
                throw new InvalidArgumentException('Minecraft server is unavailable.');
            }
            if ($server['owner_user_id'] !== null) {
                throw new InvalidArgumentException('Minecraft server already has an owner.');
            }
            $existing = (int) $database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_minecraft_server_claims "
                . "WHERE server_id=:server_id AND claimant_user_id=:claimant AND state='pending'",
                ['server_id'=>$claim->serverId->value(),'claimant'=>$claim->claimantUserId->value()],
            ));
            if ($existing > 0) {
                throw new InvalidArgumentException('A pending ownership claim already exists.');
            }
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_minecraft_server_claims '
                . '(claim_id,server_id,claimant_user_id,proof_note,state,reviewed_by_user_id,review_note,'
                . 'created_at_utc,updated_at_utc,reviewed_at_utc) '
                . "VALUES (:claim_id,:server_id,:claimant,:proof_note,'pending',NULL,NULL,:created_at,:updated_at,NULL)",
                [
                    'claim_id'=>$claim->claimId->value(),
                    'server_id'=>$claim->serverId->value(),
                    'claimant'=>$claim->claimantUserId->value(),
                    'proof_note'=>$claim->proofNote,
                    'created_at'=>self::format($claim->createdAt),
                    'updated_at'=>self::format($claim->updatedAt),
                ],
                true,
            ));
            $this->appendOwnershipEvent(
                $database,
                $claim->serverId,
                'claim_submitted',
                $claim->claimantUserId,
                null,
                null,
                'Ownership claim submitted for staff review.',
                $claim->createdAt,
            );
        });
    }

    public function reviewClaim(
        EntityId $claimId,
        EntityId $reviewerUserId,
        bool $approve,
        ?string $reviewNote,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($reviewerUserId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $claimId,
            $reviewerUserId,
            $approve,
            $reviewNote,
            $now,
        ): void {
            $claim = $database->fetchOne(new CompiledQuery(
                'SELECT claim_id,server_id,claimant_user_id,state FROM forwext_minecraft_server_claims '
                . 'WHERE claim_id=:claim_id FOR UPDATE',
                ['claim_id'=>$claimId->value()],
            ));
            if ($claim === null || (string) $claim['state'] !== 'pending') {
                throw new InvalidArgumentException('Pending Minecraft server claim was not found.');
            }

            $serverId = EntityId::fromString((string) $claim['server_id']);
            $claimant = UserId::fromStored((string) $claim['claimant_user_id']);
            if ($approve) {
                $server = $database->fetchOne(new CompiledQuery(
                    'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                    ['server_id'=>$serverId->value()],
                ));
                if ($server === null || $server['owner_user_id'] !== null) {
                    throw new InvalidArgumentException('Minecraft server cannot be claimed.');
                }
                $affected = $database->execute(new CompiledQuery(
                    'UPDATE forwext_minecraft_servers SET owner_user_id=:owner,updated_at_utc=:updated_at '
                    . 'WHERE server_id=:server_id AND owner_user_id IS NULL',
                    ['owner'=>$claimant->value(),'updated_at'=>self::format($now),'server_id'=>$serverId->value()],
                    true,
                ));
                if ($affected !== 1) {
                    throw new RuntimeException('Minecraft server ownership changed concurrently.');
                }
                $database->execute(new CompiledQuery(
                    "UPDATE forwext_minecraft_server_claims SET state='rejected',reviewed_by_user_id=:reviewer,"
                    . "review_note='Another ownership claim was approved.',reviewed_at_utc=:reviewed_at,"
                    . 'updated_at_utc=:updated_at WHERE server_id=:server_id AND state=\'pending\' AND claim_id<>:claim_id',
                    [
                        'reviewer'=>$reviewerUserId->value(),
                        'reviewed_at'=>self::format($now),
                        'updated_at'=>self::format($now),
                        'server_id'=>$serverId->value(),
                        'claim_id'=>$claimId->value(),
                    ],
                    true,
                ));
            }

            $state = $approve ? 'approved' : 'rejected';
            $database->execute(new CompiledQuery(
                'UPDATE forwext_minecraft_server_claims SET state=:state,reviewed_by_user_id=:reviewer,'
                . 'review_note=:review_note,reviewed_at_utc=:reviewed_at,updated_at_utc=:updated_at '
                . 'WHERE claim_id=:claim_id',
                [
                    'state'=>$state,
                    'reviewer'=>$reviewerUserId->value(),
                    'review_note'=>$reviewNote,
                    'reviewed_at'=>self::format($now),
                    'updated_at'=>self::format($now),
                    'claim_id'=>$claimId->value(),
                ],
                true,
            ));
            $this->appendOwnershipEvent(
                $database,
                $serverId,
                $approve ? 'claim_approved' : 'claim_rejected',
                $reviewerUserId,
                null,
                $approve ? $claimant : null,
                $reviewNote,
                $now,
            );
        });
    }

    public function updateDetails(
        EntityId $serverId,
        ?EntityId $expectedOwnerUserId,
        EntityId $actorUserId,
        string $name,
        string $summary,
        string $description,
        string $host,
        int $port,
        string $edition,
        string $versionLabel,
        string $gameMode,
        ?string $websiteUrl,
        ?string $discordUrl,
        string $listingState,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($actorUserId);
        if ($expectedOwnerUserId !== null) {
            UserId::assert($expectedOwnerUserId);
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $serverId,$expectedOwnerUserId,$actorUserId,$name,$summary,$description,$host,$port,$edition,$versionLabel,$gameMode,
            $websiteUrl,$discordUrl,$listingState,$now,
        ): void {
            $ownership = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$serverId->value()],
            ));
            if ($ownership === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $storedOwner = $ownership['owner_user_id'] === null ? null : (string) $ownership['owner_user_id'];
            $expectedOwner = $expectedOwnerUserId?->value();
            if (($storedOwner === null) !== ($expectedOwner === null) || ($storedOwner !== null && !hash_equals($storedOwner, (string) $expectedOwner))) {
                throw new RuntimeException('Minecraft server ownership changed concurrently.');
            }
            $affected = $database->execute(new CompiledQuery(
                'UPDATE forwext_minecraft_servers SET name=:name,summary=:summary,description=:description,'
                . 'host=:host,port=:port,edition=:edition,version_label=:version_label,game_mode=:game_mode,'
                . 'website_url=:website_url,discord_url=:discord_url,listing_state=:listing_state,updated_at_utc=:updated_at '
                . 'WHERE server_id=:server_id',
                [
                    'name'=>$name,'summary'=>$summary,'description'=>$description,'host'=>$host,'port'=>$port,
                    'edition'=>$edition,'version_label'=>$versionLabel,'game_mode'=>$gameMode,
                    'website_url'=>$websiteUrl,'discord_url'=>$discordUrl,'listing_state'=>$listingState,
                    'updated_at'=>self::format($now),'server_id'=>$serverId->value(),
                ],
                true,
            ));
            if ($affected !== 1) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $this->appendOwnershipEvent(
                $database,$serverId,'details_updated',$actorUserId,null,null,null,$now,
            );
        });
    }

    public function transferOwnership(
        EntityId $serverId,
        ?EntityId $expectedOwnerUserId,
        ?EntityId $newOwnerUserId,
        EntityId $actorUserId,
        string $eventType,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($actorUserId);
        if ($expectedOwnerUserId !== null) {
            UserId::assert($expectedOwnerUserId);
        }
        if ($newOwnerUserId !== null) {
            UserId::assert($newOwnerUserId);
        }
        if (!in_array($eventType, ['ownership_transferred','ownership_released'], true)) {
            throw new InvalidArgumentException('Minecraft server ownership event is invalid.');
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $serverId,$expectedOwnerUserId,$newOwnerUserId,$actorUserId,$eventType,$now,
        ): void {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT owner_user_id FROM forwext_minecraft_servers WHERE server_id=:server_id FOR UPDATE',
                ['server_id'=>$serverId->value()],
            ));
            if ($row === null) {
                throw new InvalidArgumentException('Minecraft server was not found.');
            }
            $stored = $row['owner_user_id'] === null ? null : (string) $row['owner_user_id'];
            $expected = $expectedOwnerUserId?->value();
            if (($stored === null) !== ($expected === null) || ($stored !== null && !hash_equals($stored, (string) $expected))) {
                throw new RuntimeException('Minecraft server ownership changed concurrently.');
            }
            $database->execute(new CompiledQuery(
                'UPDATE forwext_minecraft_servers SET owner_user_id=:owner,updated_at_utc=:updated_at '
                . 'WHERE server_id=:server_id',
                ['owner'=>$newOwnerUserId?->value(),'updated_at'=>self::format($now),'server_id'=>$serverId->value()],
                true,
            ));
            $this->appendOwnershipEvent(
                $database,$serverId,$eventType,$actorUserId,$expectedOwnerUserId,$newOwnerUserId,null,$now,
            );
        });
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

    private function appendOwnershipEvent(
        TransactionalQueryExecutor $database,
        EntityId $serverId,
        string $eventType,
        ?EntityId $actorUserId,
        ?EntityId $fromOwnerUserId,
        ?EntityId $toOwnerUserId,
        ?string $detail,
        DateTimeImmutable $now,
    ): void {
        $database->execute(new CompiledQuery(
            'INSERT INTO forwext_minecraft_server_ownership_events '
            . '(event_id,server_id,event_type,actor_user_id,from_owner_user_id,to_owner_user_id,detail,created_at_utc) '
            . 'VALUES (:event_id,:server_id,:event_type,:actor,:from_owner,:to_owner,:detail,:created_at)',
            [
                'event_id'=>bin2hex(random_bytes(16)),
                'server_id'=>$serverId->value(),
                'event_type'=>$eventType,
                'actor'=>$actorUserId?->value(),
                'from_owner'=>$fromOwnerUserId?->value(),
                'to_owner'=>$toOwnerUserId?->value(),
                'detail'=>$detail,
                'created_at'=>self::format($now),
            ],
            true,
        ));
    }

    /** @param array<string,mixed> $row */
    private function hydrateTeamMember(array $row): MinecraftServerTeamMember
    {
        return new MinecraftServerTeamMember(
            EntityId::fromString((string) $row['server_id']),
            UserId::fromStored((string) $row['user_id']),
            (string) $row['role_key'],
            $row['public_title'] === null ? null : (string) $row['public_title'],
            $row['added_by_user_id'] === null ? null : UserId::fromStored((string) $row['added_by_user_id']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateVoteIntegration(array $row): MinecraftServerVoteIntegration
    {
        return new MinecraftServerVoteIntegration(
            EntityId::fromString((string) $row['server_id']),
            (int) $row['enabled'] === 1,
            $row['token_prefix'] === null ? null : (string) $row['token_prefix'],
            $row['last_rotated_at_utc'] === null ? null : self::parse((string) $row['last_rotated_at_utc']),
            $row['updated_by_user_id'] === null ? null : UserId::fromStored((string) $row['updated_by_user_id']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateVoteFeedEntry(array $row): MinecraftServerVoteFeedEntry
    {
        return new MinecraftServerVoteFeedEntry(
            EntityId::fromString((string) $row['vote_id']),
            EntityId::fromString((string) $row['server_id']),
            UserId::fromStored((string) $row['voter_user_id']),
            (string) $row['username'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateUpdate(array $row): MinecraftServerUpdate
    {
        return new MinecraftServerUpdate(
            EntityId::fromString((string) $row['update_id']),
            EntityId::fromString((string) $row['server_id']),
            $row['author_user_id'] === null ? null : UserId::fromStored((string) $row['author_user_id']),
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['state'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
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
    private function hydrateClaim(array $row): MinecraftServerClaim
    {
        return new MinecraftServerClaim(
            EntityId::fromString((string) $row['claim_id']),
            EntityId::fromString((string) $row['server_id']),
            UserId::fromStored((string) $row['claimant_user_id']),
            (string) $row['proof_note'],
            (string) $row['state'],
            $row['reviewed_by_user_id'] === null ? null : UserId::fromStored((string) $row['reviewed_by_user_id']),
            $row['review_note'] === null ? null : (string) $row['review_note'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            $row['reviewed_at_utc'] === null ? null : self::parse((string) $row['reviewed_at_utc']),
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

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
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
