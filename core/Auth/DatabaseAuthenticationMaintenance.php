<?php

declare(strict_types=1);

namespace Forwext\Core\Auth;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class DatabaseAuthenticationMaintenance
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    /** @return array{login_history:int,rate_limits:int,remember_tokens:int,challenge_tokens:int} */
    public function purge(
        DateTimeImmutable $now,
        int $historyRetentionDays = 180,
        int $tokenRetentionDays = 30,
        int $batchSize = 1000,
    ): array {
        if ($historyRetentionDays < 30 || $historyRetentionDays > 3650
            || $tokenRetentionDays < 1 || $tokenRetentionDays > 365
            || $batchSize < 1 || $batchSize > 5000
        ) {
            throw new AuthException('Authentication maintenance bounds are invalid.');
        }
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $historyCutoff = $utc->sub(new DateInterval('P' . $historyRetentionDays . 'D'))->format('Y-m-d H:i:s.u');
        $tokenCutoff = $utc->sub(new DateInterval('P' . $tokenRetentionDays . 'D'))->format('Y-m-d H:i:s.u');

        $loginHistory = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_login_history` WHERE `occurred_at_utc` < :cutoff '
            . 'ORDER BY `occurred_at_utc` ASC LIMIT ' . $batchSize,
            ['cutoff' => $historyCutoff],
        ));
        $rateLimits = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_auth_rate_limits` WHERE `bucket_start_utc` < :cutoff LIMIT ' . $batchSize,
            ['cutoff' => $tokenCutoff],
        ));
        $rememberTokens = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_remember_tokens` WHERE '
            . '(`expires_at_utc` < :cutoff) OR (`revoked_at_utc` IS NOT NULL AND `revoked_at_utc` < :cutoff) '
            . 'OR (`consumed_at_utc` IS NOT NULL AND `consumed_at_utc` < :cutoff) LIMIT ' . $batchSize,
            ['cutoff' => $tokenCutoff],
        ));
        $challengeTokens = $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_auth_challenge_tokens` WHERE '
            . '(`expires_at_utc` < :cutoff) OR (`consumed_at_utc` IS NOT NULL AND `consumed_at_utc` < :cutoff) '
            . 'LIMIT ' . $batchSize,
            ['cutoff' => $tokenCutoff],
        ));

        return [
            'login_history' => $loginHistory,
            'rate_limits' => $rateLimits,
            'remember_tokens' => $rememberTokens,
            'challenge_tokens' => $challengeTokens,
        ];
    }
}
