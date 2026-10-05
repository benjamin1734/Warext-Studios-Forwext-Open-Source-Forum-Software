<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class DatabaseOversightReviewerDirectory implements OversightReviewerDirectory
{
    private const PERMISSION = 'audit.review';
    private const BATCH_SIZE = 200;

    public function __construct(
        private QueryExecutor $database,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function list(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Oversight reviewer list limit is invalid.');
        }

        $reviewers = [];
        $offset = 0;
        $permission = PermissionKey::fromString(self::PERMISSION);

        while (count($reviewers) < $limit) {
            $rows = $this->database->fetchAll(new CompiledQuery(
                $this->candidateSql() . ' LIMIT ' . self::BATCH_SIZE . ' OFFSET ' . $offset,
                ['permission_key' => self::PERMISSION],
            ));
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $userId = UserId::fromStored((string) ($row['user_id'] ?? ''));
                if (!$this->authorizer->allows($userId, $permission)) {
                    continue;
                }
                $reviewers[] = new OversightReviewer($userId, (string) ($row['username'] ?? ''));
                if (count($reviewers) >= $limit) {
                    break;
                }
            }

            if (count($rows) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        }

        return $reviewers;
    }

    private function candidateSql(): string
    {
        return "SELECT DISTINCT u.user_id,u.username,u.username_key FROM forwext_users u "
            . "WHERE u.status='active' AND ("
            . "EXISTS (SELECT 1 FROM forwext_permission_global_rules r "
            . "WHERE r.permission_key=:permission_key AND r.subject_type='user' AND r.subject_id=u.user_id) "
            . "OR EXISTS (SELECT 1 FROM forwext_user_primary_groups pg "
            . "INNER JOIN forwext_permission_global_rules r ON r.subject_type='group' AND r.subject_id=pg.group_id "
            . "WHERE pg.user_id=u.user_id AND r.permission_key=:permission_key) "
            . "OR EXISTS (SELECT 1 FROM forwext_user_secondary_groups sg "
            . "INNER JOIN forwext_permission_global_rules r ON r.subject_type='group' AND r.subject_id=sg.group_id "
            . "WHERE sg.user_id=u.user_id AND r.permission_key=:permission_key) "
            . "OR EXISTS (SELECT 1 FROM forwext_user_role_assignments ur "
            . "INNER JOIN forwext_permission_global_rules r ON r.subject_type='role' AND r.subject_id=ur.role_id "
            . "WHERE ur.user_id=u.user_id AND r.permission_key=:permission_key) "
            . "OR EXISTS (SELECT 1 FROM forwext_user_subscriptions s "
            . "INNER JOIN forwext_subscription_plan_permissions sp ON sp.plan_id=s.plan_id "
            . "WHERE s.user_id=u.user_id AND s.state='active' "
            . "AND (s.ends_at_utc IS NULL OR s.ends_at_utc>UTC_TIMESTAMP(6)) "
            . "AND sp.permission_key=:permission_key) "
            . "OR EXISTS (SELECT 1 FROM forwext_user_subscriptions s "
            . "INNER JOIN forwext_subscription_plan_roles pr ON pr.plan_id=s.plan_id "
            . "INNER JOIN forwext_permission_global_rules r ON r.subject_type='role' AND r.subject_id=pr.role_id "
            . "WHERE s.user_id=u.user_id AND s.state='active' "
            . "AND (s.ends_at_utc IS NULL OR s.ends_at_utc>UTC_TIMESTAMP(6)) "
            . "AND r.permission_key=:permission_key)"
            . ") ORDER BY u.username_key,u.user_id";
    }
}
