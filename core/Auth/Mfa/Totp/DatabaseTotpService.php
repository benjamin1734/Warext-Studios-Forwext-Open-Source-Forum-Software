<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Totp;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Security\Secret\SecretCipher;

final readonly class DatabaseTotpService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SecretCipher $cipher,
        private string $issuer = 'Forwext',
        private int $periodSeconds = 30,
        private int $window = 1,
        private Clock $clock = new SystemClock(),
    ) {
        if ($issuer === '' || strlen($issuer) > 64 || $periodSeconds < 15 || $periodSeconds > 120 || $window < 0 || $window > 5) {
            throw new MfaException('TOTP configuration is invalid.');
        }
    }

    public function beginEnrollment(EntityId $userId, string $accountLabel): TotpEnrollment
    {
        $accountLabel = trim($accountLabel);
        if ($accountLabel === '' || strlen($accountLabel) > 191 || str_contains($accountLabel, "\0")) {
            throw new MfaException('TOTP account label is invalid.');
        }
        $secret = random_bytes(20);
        $secretBase32 = Base32::encode($secret);
        $encrypted = $this->cipher->encrypt($secret);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_totp_factors` (`user_id`, `encrypted_secret`, `last_counter`, `created_at_utc`, `enabled_at_utc`) '
            . 'VALUES (:user_id, :encrypted_secret, -1, :created_at_utc, NULL) '
            . 'ON DUPLICATE KEY UPDATE `encrypted_secret` = VALUES(`encrypted_secret`), `last_counter` = -1, '
            . '`created_at_utc` = VALUES(`created_at_utc`), `enabled_at_utc` = NULL',
            ['user_id' => $userId->value(), 'encrypted_secret' => $encrypted, 'created_at_utc' => self::date($this->clock->now())],
        ));
        $uri = sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=%d',
            rawurlencode($this->issuer . ':' . $accountLabel),
            $secretBase32,
            rawurlencode($this->issuer),
            $this->periodSeconds,
        );
        return new TotpEnrollment($secretBase32, $uri);
    }

    public function confirmEnrollment(EntityId $userId, string $code): bool
    {
        return $this->verifyInternal($userId, $code, false, true);
    }

    public function verify(EntityId $userId, string $code): bool
    {
        return $this->verifyInternal($userId, $code, true, false);
    }

    public function isEnabled(EntityId $userId): bool
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_totp_factors` WHERE `user_id` = :user_id AND `enabled_at_utc` IS NOT NULL',
            ['user_id' => $userId->value()],
        )) === 1;
    }

    private function verifyInternal(EntityId $userId, string $code, bool $requireEnabled, bool $enableOnSuccess): bool
    {
        return $this->database->transaction(function () use ($userId, $code, $requireEnabled, $enableOnSuccess): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `encrypted_secret`, `last_counter`, `enabled_at_utc` FROM `forwext_totp_factors` '
                . 'WHERE `user_id` = :user_id LIMIT 1 FOR UPDATE',
                ['user_id' => $userId->value()],
                requiresTransaction: true,
            ));
            if ($row === null || !is_string($row['encrypted_secret'] ?? null) || ($requireEnabled && ($row['enabled_at_utc'] ?? null) === null)) {
                return false;
            }
            $lastCounter = (int) ($row['last_counter'] ?? -1);
            $counter = Totp::matchingCounter(
                $this->cipher->decrypt($row['encrypted_secret']),
                trim($code),
                $this->clock->now()->getTimestamp(),
                $this->periodSeconds,
                $this->window,
            );
            if ($counter === null || $counter <= $lastCounter) {
                return false;
            }
            return $this->database->execute(new CompiledQuery(
                'UPDATE `forwext_totp_factors` SET `last_counter` = :last_counter, '
                . '`enabled_at_utc` = CASE WHEN :enable = 1 THEN COALESCE(`enabled_at_utc`, :enabled_at_utc) ELSE `enabled_at_utc` END '
                . 'WHERE `user_id` = :user_id',
                [
                    'last_counter' => $counter,
                    'enable' => $enableOnSuccess ? 1 : 0,
                    'enabled_at_utc' => self::date($this->clock->now()),
                    'user_id' => $userId->value(),
                ],
            )) === 1;
        });
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
