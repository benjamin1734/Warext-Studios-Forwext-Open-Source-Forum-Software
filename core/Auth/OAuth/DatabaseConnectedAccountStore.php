<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseConnectedAccountStore implements ConnectedAccountStore
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function find(string $providerId, string $subject): ?ConnectedAccount
    {
        return $this->hydrate($this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`,`provider`,`provider_subject`,`provider_email_normalized`,`display_name`,`linked_at_utc`,`last_authenticated_at_utc` FROM `forwext_connected_accounts` WHERE `provider`=:provider AND `provider_subject`=:subject LIMIT 1',
            ['provider' => $providerId, 'subject' => $subject],
        )));
    }

    public function findForUser(EntityId $userId, string $providerId): ?ConnectedAccount
    {
        UserId::assert($userId);
        return $this->hydrate($this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`,`provider`,`provider_subject`,`provider_email_normalized`,`display_name`,`linked_at_utc`,`last_authenticated_at_utc` FROM `forwext_connected_accounts` WHERE `user_id`=:user_id AND `provider`=:provider LIMIT 1',
            ['user_id' => $userId->value(), 'provider' => $providerId],
        )));
    }

    public function countForUser(EntityId $userId): int
    {
        UserId::assert($userId);
        return (int) $this->database->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM `forwext_connected_accounts` WHERE `user_id`=:user_id', ['user_id' => $userId->value()]));
    }

    public function link(ConnectedAccount $account): void
    {
        UserId::assert($account->userId);
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_connected_accounts` (`provider`,`provider_subject`,`user_id`,`provider_email_normalized`,`display_name`,`linked_at_utc`,`last_authenticated_at_utc`) VALUES (:provider,:subject,:user_id,:email,:display_name,:linked_at,:last_authenticated_at)',
            [
                'provider' => $account->providerId,
                'subject' => $account->subject,
                'user_id' => $account->userId->value(),
                'email' => $account->emailNormalized,
                'display_name' => $account->displayName,
                'linked_at' => self::format($account->linkedAt),
                'last_authenticated_at' => self::format($account->lastAuthenticatedAt),
            ],
        ));
        if ($affected !== 1) { throw new OAuthException('Connected account could not be persisted.'); }
    }

    public function touch(string $providerId, string $subject, DateTimeImmutable $authenticatedAt): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_connected_accounts` SET `last_authenticated_at_utc`=:authenticated_at WHERE `provider`=:provider AND `provider_subject`=:subject',
            ['authenticated_at' => self::format($authenticatedAt), 'provider' => $providerId, 'subject' => $subject],
        ));
        if ($affected !== 1) { throw new OAuthException('Connected account authentication timestamp could not be updated.'); }
    }

    public function unlink(EntityId $userId, string $providerId): bool
    {
        UserId::assert($userId);
        return $this->database->execute(new CompiledQuery('DELETE FROM `forwext_connected_accounts` WHERE `user_id`=:user_id AND `provider`=:provider', ['user_id' => $userId->value(), 'provider' => $providerId])) === 1;
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?ConnectedAccount
    {
        if ($row === null) { return null; }
        foreach (['user_id','provider','provider_subject','linked_at_utc','last_authenticated_at_utc'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key])) { throw new OAuthException('Connected account row is malformed.'); }
        }
        return new ConnectedAccount(
            UserId::fromStored($row['user_id']),
            $row['provider'],
            $row['provider_subject'],
            isset($row['provider_email_normalized']) && is_string($row['provider_email_normalized']) ? $row['provider_email_normalized'] : null,
            isset($row['display_name']) && is_string($row['display_name']) ? $row['display_name'] : null,
            self::parse($row['linked_at_utc']),
            self::parse($row['last_authenticated_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) { throw new OAuthException('Connected account timestamp is malformed.'); }
        return $date;
    }
}
