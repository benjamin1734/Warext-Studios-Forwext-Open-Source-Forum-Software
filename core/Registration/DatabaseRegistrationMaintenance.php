<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class DatabaseRegistrationMaintenance
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    /** @return array{verification_tokens:int, rate_limit_buckets:int, invites:int} */
    public function purge(DateTimeImmutable $now, int $retentionDays = 30, int $batchSize = 1000): array
    {
        if ($retentionDays < 1 || $retentionDays > 365 || $batchSize < 1 || $batchSize > 5000) {
            throw new RegistrationException('Registration maintenance bounds are invalid.');
        }

        $cutoff = $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->sub(new DateInterval('P' . $retentionDays . 'D'))
            ->format('Y-m-d H:i:s.u');

        $verification = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_email_verification_tokens` '
            . 'WHERE (`consumed_at_utc` IS NOT NULL AND `consumed_at_utc` < :cutoff) '
            . 'OR (`expires_at_utc` < :cutoff) LIMIT ' . $batchSize,
            ['cutoff' => $cutoff],
        ));
        $rateLimits = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_registration_rate_limits` WHERE `bucket_start_utc` < :cutoff LIMIT ' . $batchSize,
            ['cutoff' => $cutoff],
        ));
        $invites = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_registration_invites` '
            . 'WHERE (`expires_at_utc` IS NOT NULL AND `expires_at_utc` < :cutoff) '
            . 'OR (`disabled` = 1 AND `created_at_utc` < :cutoff) LIMIT ' . $batchSize,
            ['cutoff' => $cutoff],
        ));

        return [
            'verification_tokens' => $verification,
            'rate_limit_buckets' => $rateLimits,
            'invites' => $invites,
        ];
    }
}
