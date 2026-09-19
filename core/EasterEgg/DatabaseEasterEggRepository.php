<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseEasterEggRepository implements EasterEggRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function globalEnabled(): bool
    {
        return (bool) $this->database->fetchValue(new CompiledQuery(
            "SELECT enabled FROM forwext_easter_egg_settings WHERE setting_key='global' LIMIT 1",
        ));
    }

    public function setGlobalEnabled(bool $enabled, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_easter_egg_settings(setting_key,enabled,updated_at_utc) "
            . "VALUES ('global',:enabled,:updated) "
            . "ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at_utc=VALUES(updated_at_utc)",
            ['enabled'=>$enabled ? 1 : 0,'updated'=>self::format($at)],
        ));
    }

    public function all(int $limit = 200): array
    {
        self::limit($limit);
        return array_map(
            $this->hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_easter_eggs ORDER BY priority DESC,name,easter_egg_id LIMIT ' . $limit,
            )),
        );
    }

    public function find(EntityId $easterEggId): ?EasterEggDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_easter_eggs WHERE easter_egg_id=:id LIMIT 1',
            ['id'=>$easterEggId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function activeAt(DateTimeImmutable $at, int $limit = 50): array
    {
        self::limit($limit);
        $now = self::format($at);
        return array_map(
            $this->hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_easter_eggs WHERE enabled=1 '
                . 'AND (starts_at_utc IS NULL OR starts_at_utc<=:now1) '
                . 'AND (ends_at_utc IS NULL OR ends_at_utc>:now2) '
                . 'ORDER BY priority DESC,name,easter_egg_id LIMIT ' . $limit,
                ['now1'=>$now,'now2'=>$now],
            )),
        );
    }

    public function save(EasterEggDefinition $definition): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_easter_eggs '
            . '(easter_egg_id,egg_key,name,enabled,priority,trigger_type,trigger_value,route_name,path_pattern,'
            . 'starts_at_utc,ends_at_utc,message,animation,badge_label,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:enabled,:priority,:trigger_type,:trigger_value,:route_name,:path_pattern,'
            . ':starts,:ends,:message,:animation,:badge,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE egg_key=VALUES(egg_key),name=VALUES(name),enabled=VALUES(enabled),'
            . 'priority=VALUES(priority),trigger_type=VALUES(trigger_type),trigger_value=VALUES(trigger_value),'
            . 'route_name=VALUES(route_name),path_pattern=VALUES(path_pattern),starts_at_utc=VALUES(starts_at_utc),'
            . 'ends_at_utc=VALUES(ends_at_utc),message=VALUES(message),animation=VALUES(animation),'
            . 'badge_label=VALUES(badge_label),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$definition->easterEggId->value(),
                'key'=>$definition->key,
                'name'=>$definition->name,
                'enabled'=>$definition->enabled ? 1 : 0,
                'priority'=>$definition->priority,
                'trigger_type'=>$definition->triggerType->value,
                'trigger_value'=>$definition->triggerValue,
                'route_name'=>$definition->routeName,
                'path_pattern'=>$definition->pathPattern,
                'starts'=>$definition->startsAt === null ? null : self::format($definition->startsAt),
                'ends'=>$definition->endsAt === null ? null : self::format($definition->endsAt),
                'message'=>$definition->message,
                'animation'=>$definition->animation->value,
                'badge'=>$definition->badgeLabel,
                'created'=>self::format($definition->createdAt),
                'updated'=>self::format($definition->updatedAt),
            ],
        ));
    }

    public function groupIds(EntityId $easterEggId): array
    {
        return array_map(
            static fn (array $row): EntityId => EntityId::fromString((string) $row['group_id']),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT group_id FROM forwext_easter_egg_groups WHERE easter_egg_id=:id ORDER BY group_id',
                ['id'=>$easterEggId->value()],
            )),
        );
    }

    public function replaceGroups(EntityId $easterEggId, array $groupIds): void
    {
        $this->database->transaction(function () use ($easterEggId, $groupIds): void {
            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_easter_egg_groups WHERE easter_egg_id=:id',
                ['id'=>$easterEggId->value()],
            ));
            $seen = [];
            foreach ($groupIds as $groupId) {
                if (!$groupId instanceof EntityId || isset($seen[$groupId->value()])) continue;
                $seen[$groupId->value()] = true;
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_easter_egg_groups(easter_egg_id,group_id) VALUES (:egg,:group_id)',
                    ['egg'=>$easterEggId->value(),'group_id'=>$groupId->value()],
                ));
            }
        });
    }

    public function availableGroups(): array
    {
        return array_map(
            static fn (array $row): EasterEggGroupOption => new EasterEggGroupOption(
                EntityId::fromString((string) $row['group_id']),
                (string) $row['name'],
            ),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT group_id,name FROM forwext_user_groups ORDER BY sort_order,name,group_id',
            )),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): EasterEggDefinition
    {
        try {
            $trigger = EasterEggTriggerType::from((string) $row['trigger_type']);
            $animation = EasterEggAnimation::from((string) $row['animation']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored easter egg enum value is invalid.', previous:$exception);
        }

        return new EasterEggDefinition(
            EntityId::fromString((string) $row['easter_egg_id']),
            (string) $row['egg_key'],
            (string) $row['name'],
            (bool) $row['enabled'],
            (int) $row['priority'],
            $trigger,
            isset($row['trigger_value']) && is_string($row['trigger_value']) ? $row['trigger_value'] : null,
            isset($row['route_name']) && is_string($row['route_name']) ? $row['route_name'] : null,
            isset($row['path_pattern']) && is_string($row['path_pattern']) ? $row['path_pattern'] : null,
            isset($row['starts_at_utc']) && is_string($row['starts_at_utc']) ? self::parse($row['starts_at_utc']) : null,
            isset($row['ends_at_utc']) && is_string($row['ends_at_utc']) ? self::parse($row['ends_at_utc']) : null,
            (string) $row['message'],
            $animation,
            isset($row['badge_label']) && is_string($row['badge_label']) ? $row['badge_label'] : null,
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Easter egg listing limit is invalid.');
        }
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) return $date;
        }
        throw new RuntimeException('Stored easter egg timestamp is invalid.');
    }
}
