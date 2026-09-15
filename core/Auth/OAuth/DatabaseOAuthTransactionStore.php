<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseOAuthTransactionStore implements OAuthTransactionStore
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function create(OAuthTransaction $transaction): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_oauth_transactions` (`state_hash`,`provider`,`pkce_verifier`,`redirect_uri`,`intended_user_id`,`created_at_utc`,`expires_at_utc`,`consumed_at_utc`) VALUES (:state_hash,:provider,:pkce_verifier,:redirect_uri,:intended_user_id,:created_at_utc,:expires_at_utc,NULL)',
            [
                'state_hash' => $transaction->stateHash,
                'provider' => $transaction->providerId,
                'pkce_verifier' => $transaction->codeVerifier,
                'redirect_uri' => $transaction->redirectUri,
                'intended_user_id' => $transaction->intendedUserId?->value(),
                'created_at_utc' => self::format($transaction->createdAt),
                'expires_at_utc' => self::format($transaction->expiresAt),
            ],
        ));
        if ($affected !== 1) {
            throw new OAuthException('OAuth transaction could not be persisted.');
        }
    }

    public function consume(string $stateHash, string $providerId, string $redirectUri, DateTimeImmutable $now): ?OAuthTransaction
    {
        return $this->database->transaction(function (TransactionalQueryExecutor $database) use ($stateHash, $providerId, $redirectUri, $now): ?OAuthTransaction {
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT `provider`,`pkce_verifier`,`redirect_uri`,`intended_user_id`,`created_at_utc`,`expires_at_utc`,`consumed_at_utc` FROM `forwext_oauth_transactions` WHERE `state_hash`=:state_hash FOR UPDATE',
                ['state_hash' => $stateHash],
                true,
            ));
            if ($row === null || ($row['provider'] ?? null) !== $providerId || ($row['redirect_uri'] ?? null) !== $redirectUri || ($row['consumed_at_utc'] ?? null) !== null) {
                return null;
            }
            $expires = self::parse((string) ($row['expires_at_utc'] ?? ''));
            if ($expires < self::utc($now)) {
                return null;
            }
            $affected = $database->execute(new CompiledQuery(
                'UPDATE `forwext_oauth_transactions` SET `consumed_at_utc`=:consumed_at_utc WHERE `state_hash`=:state_hash AND `consumed_at_utc` IS NULL',
                ['consumed_at_utc' => self::format($now), 'state_hash' => $stateHash],
                true,
            ));
            if ($affected !== 1) {
                return null;
            }
            $intended = isset($row['intended_user_id']) && is_string($row['intended_user_id']) && $row['intended_user_id'] !== '' ? UserId::fromStored($row['intended_user_id']) : null;
            return new OAuthTransaction(
                $stateHash,
                $providerId,
                (string) ($row['pkce_verifier'] ?? ''),
                $redirectUri,
                $intended,
                self::parse((string) ($row['created_at_utc'] ?? '')),
                $expires,
            );
        });
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable { return $date->setTimezone(new DateTimeZone('UTC')); }
    private static function format(DateTimeImmutable $date): string { return self::utc($date)->format('Y-m-d H:i:s.u'); }
    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) { throw new OAuthException('OAuth transaction timestamp is malformed.'); }
        return $date;
    }
}
