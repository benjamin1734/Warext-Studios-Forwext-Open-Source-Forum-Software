<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class RecoveryCodeService
{
    public function __construct(private TransactionalQueryExecutor $database, private int $codeCount = 10, private Clock $clock = new SystemClock())
    {
        if ($codeCount < 5 || $codeCount > 20) {
            throw new MfaException('Recovery code count must be between 5 and 20.');
        }
    }

    /** @return list<string> */
    public function regenerate(EntityId $userId): array
    {
        $codes = [];
        for ($i = 0; $i < $this->codeCount; ++$i) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
        }
        $this->database->transaction(function () use ($userId, $codes): void {
            $this->database->execute(new CompiledQuery('DELETE FROM `forwext_recovery_codes` WHERE `user_id` = :user_id', ['user_id' => $userId->value()]));
            foreach ($codes as $code) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_recovery_codes` (`user_id`, `code_hash`, `created_at_utc`, `used_at_utc`) VALUES (:user_id, :code_hash, :created_at_utc, NULL)',
                    ['user_id' => $userId->value(), 'code_hash' => hash('sha256', self::normalize($code)), 'created_at_utc' => self::date($this->clock->now())],
                ));
            }
        });
        return $codes;
    }

    public function consume(EntityId $userId, string $code): bool
    {
        $normalized = self::normalize($code);
        if (preg_match('/^[A-F0-9]{40}$/D', $normalized) !== 1) {
            return false;
        }
        $hash = hash('sha256', $normalized);
        return $this->database->transaction(function () use ($userId, $hash): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `used_at_utc` FROM `forwext_recovery_codes` WHERE `user_id` = :user_id AND `code_hash` = :code_hash LIMIT 1 FOR UPDATE',
                ['user_id' => $userId->value(), 'code_hash' => $hash],
                requiresTransaction: true,
            ));
            if ($row === null || ($row['used_at_utc'] ?? null) !== null) {
                return false;
            }
            return $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_recovery_codes` SET `used_at_utc` = :used_at_utc WHERE `user_id` = :user_id AND `code_hash` = :code_hash AND `used_at_utc` IS NULL',
                ['used_at_utc' => self::date($this->clock->now()), 'user_id' => $userId->value(), 'code_hash' => $hash],
            )) === 1;
        });
    }

    public function remaining(EntityId $userId): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_recovery_codes` WHERE `user_id` = :user_id AND `used_at_utc` IS NULL',
            ['user_id' => $userId->value()],
        )));
    }

    private static function normalize(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
