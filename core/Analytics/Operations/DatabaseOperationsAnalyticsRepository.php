<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Operations;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseOperationsAnalyticsRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function snapshot(int $days, ?DateTimeImmutable $now = null): OperationsAnalyticsSnapshot
    {
        if (!in_array($days, [7, 30, 90], true)) {
            throw new InvalidArgumentException('Operations analytics range must be 7, 30 or 90 days.');
        }

        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $end = $now->setTime(0, 0)->add(new DateInterval('P1D'));
        $start = $end->sub(new DateInterval('P' . $days . 'D'));
        $window = ['start' => self::format($start), 'end' => self::format($end)];

        $reportVolume = $this->count(
            'SELECT COUNT(*) FROM forwext_reports WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $reportGroupsOpened = $this->count(
            'SELECT COUNT(*) FROM forwext_report_groups WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $reportResolved = $this->count(
            "SELECT COUNT(*) FROM forwext_report_groups WHERE status='resolved' "
            . 'AND updated_at_utc>=:start AND updated_at_utc<:end',
            $window,
        );
        $reportRejected = $this->count(
            "SELECT COUNT(*) FROM forwext_report_groups WHERE status='rejected' "
            . 'AND updated_at_utc>=:start AND updated_at_utc<:end',
            $window,
        );
        $reportAvgResolution = $this->average(
            "SELECT AVG(TIMESTAMPDIFF(SECOND,created_at_utc,updated_at_utc)) FROM forwext_report_groups "
            . "WHERE status IN ('resolved','rejected') AND updated_at_utc>=:start AND updated_at_utc<:end "
            . 'AND updated_at_utc>=created_at_utc',
            $window,
        );

        $discipline = $this->database->fetchOne(new CompiledQuery(
            "SELECT COALESCE(SUM(action_type='warning'),0) AS warnings,"
            . "COALESCE(SUM(action_type='restriction'),0) AS restrictions,"
            . "COALESCE(SUM(action_type='suspension'),0) AS suspensions,"
            . "COALESCE(SUM(action_type='ban'),0) AS bans "
            . 'FROM forwext_discipline_actions WHERE starts_at_utc>=:start AND starts_at_utc<:end',
            $window,
        )) ?? [];

        $supportCreated = $this->count(
            'SELECT COUNT(*) FROM forwext_support_tickets WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $supportResolved = $this->count(
            'SELECT COUNT(*) FROM forwext_support_tickets WHERE resolved_at_utc>=:start AND resolved_at_utc<:end',
            $window,
        );
        $supportClosed = $this->count(
            'SELECT COUNT(*) FROM forwext_support_tickets WHERE closed_at_utc>=:start AND closed_at_utc<:end',
            $window,
        );
        $supportFirstBreach = $this->count(
            'SELECT COUNT(*) FROM forwext_support_tickets '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end AND first_response_due_at_utc IS NOT NULL '
            . 'AND ((first_responded_at_utc IS NULL AND first_response_due_at_utc<:now) '
            . 'OR first_responded_at_utc>first_response_due_at_utc)',
            $window + ['now' => self::format($now)],
        );
        $supportResolutionBreach = $this->count(
            'SELECT COUNT(*) FROM forwext_support_tickets '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end AND resolution_due_at_utc IS NOT NULL '
            . 'AND ((resolved_at_utc IS NULL AND resolution_due_at_utc<:now) OR resolved_at_utc>resolution_due_at_utc)',
            $window + ['now' => self::format($now)],
        );
        $supportAvgFirst = $this->average(
            'SELECT AVG(TIMESTAMPDIFF(SECOND,created_at_utc,first_responded_at_utc)) '
            . 'FROM forwext_support_tickets WHERE created_at_utc>=:start AND created_at_utc<:end '
            . 'AND first_responded_at_utc IS NOT NULL AND first_responded_at_utc>=created_at_utc',
            $window,
        );
        $supportAvgResolution = $this->average(
            'SELECT AVG(TIMESTAMPDIFF(SECOND,created_at_utc,resolved_at_utc)) '
            . 'FROM forwext_support_tickets WHERE resolved_at_utc>=:start AND resolved_at_utc<:end '
            . 'AND resolved_at_utc>=created_at_utc',
            $window,
        );

        $bugCreated = $this->count(
            'SELECT COUNT(*) FROM forwext_bug_reports WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $bugResolved = $this->count(
            "SELECT COUNT(*) FROM forwext_bug_reports WHERE status='resolved' "
            . 'AND finalized_at_utc>=:start AND finalized_at_utc<:end',
            $window,
        );
        $bugRejected = $this->count(
            "SELECT COUNT(*) FROM forwext_bug_reports WHERE status='rejected' "
            . 'AND finalized_at_utc>=:start AND finalized_at_utc<:end',
            $window,
        );
        $bugDuplicate = $this->count(
            "SELECT COUNT(*) FROM forwext_bug_reports WHERE status='duplicate' "
            . 'AND finalized_at_utc>=:start AND finalized_at_utc<:end',
            $window,
        );
        $bugAvgFinalization = $this->average(
            'SELECT AVG(TIMESTAMPDIFF(SECOND,created_at_utc,finalized_at_utc)) '
            . 'FROM forwext_bug_reports WHERE finalized_at_utc>=:start AND finalized_at_utc<:end '
            . 'AND finalized_at_utc>=created_at_utc',
            $window,
        );

        return new OperationsAnalyticsSnapshot(
            $days,
            $reportVolume,
            $reportGroupsOpened,
            $reportResolved,
            $reportRejected,
            $reportAvgResolution,
            (int) ($discipline['warnings'] ?? 0),
            (int) ($discipline['restrictions'] ?? 0),
            (int) ($discipline['suspensions'] ?? 0),
            (int) ($discipline['bans'] ?? 0),
            $supportCreated,
            $supportResolved,
            $supportClosed,
            $supportFirstBreach,
            $supportResolutionBreach,
            $supportAvgFirst,
            $supportAvgResolution,
            $bugCreated,
            $bugResolved,
            $bugRejected,
            $bugDuplicate,
            $bugAvgFinalization,
            $this->bugCategories($window),
            $this->staffWorkload($window),
            $now,
        );
    }

    /** @param array{start:string,end:string} $window @return list<array{key:string,label:string,total:int,active:int,resolved:int,rejected:int,duplicate:int}> */
    private function bugCategories(array $window): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT c.category_key,c.label,COUNT(r.report_id) AS total,'
            . "COALESCE(SUM(r.status IN ('new','in_review')),0) AS active,"
            . "COALESCE(SUM(r.status='resolved'),0) AS resolved,"
            . "COALESCE(SUM(r.status='rejected'),0) AS rejected,"
            . "COALESCE(SUM(r.status='duplicate'),0) AS duplicate "
            . 'FROM forwext_bug_report_categories c LEFT JOIN forwext_bug_reports r '
            . 'ON r.category_key=c.category_key AND r.created_at_utc>=:start AND r.created_at_utc<:end '
            . 'GROUP BY c.category_key,c.label,c.sort_order ORDER BY total DESC,c.sort_order,c.category_key LIMIT 100',
            $window,
        ));

        return array_map(static fn (array $row): array => [
            'key' => (string) $row['category_key'],
            'label' => (string) $row['label'],
            'total' => (int) $row['total'],
            'active' => (int) $row['active'],
            'resolved' => (int) $row['resolved'],
            'rejected' => (int) $row['rejected'],
            'duplicate' => (int) $row['duplicate'],
        ], $rows);
    }

    /** @param array{start:string,end:string} $window @return list<array{id:string,username:string,active_reports:int,active_support:int,active_bugs:int,discipline_actions:int,audit_actions:int,total:int}> */
    private function staffWorkload(array $window): array
    {
        /** @var array<string,array{id:string,username:string,active_reports:int,active_support:int,active_bugs:int,discipline_actions:int,audit_actions:int,total:int}> $staff */
        $staff = [];

        $this->mergeStaffRows($staff, $this->database->fetchAll(new CompiledQuery(
            "SELECT u.user_id,u.username,COUNT(*) AS metric FROM forwext_report_groups g "
            . 'INNER JOIN forwext_users u ON u.user_id=g.assigned_moderator_user_id '
            . "WHERE g.status IN ('open','in_review') GROUP BY u.user_id,u.username",
        )), 'active_reports');
        $this->mergeStaffRows($staff, $this->database->fetchAll(new CompiledQuery(
            "SELECT u.user_id,u.username,COUNT(*) AS metric FROM forwext_support_tickets t "
            . 'INNER JOIN forwext_users u ON u.user_id=t.assigned_user_id '
            . "WHERE t.status IN ('open','in_progress','waiting_requester') GROUP BY u.user_id,u.username",
        )), 'active_support');
        $this->mergeStaffRows($staff, $this->database->fetchAll(new CompiledQuery(
            "SELECT u.user_id,u.username,COUNT(*) AS metric FROM forwext_bug_reports b "
            . 'INNER JOIN forwext_users u ON u.user_id=b.assigned_user_id '
            . "WHERE b.status IN ('new','in_review') GROUP BY u.user_id,u.username",
        )), 'active_bugs');
        $this->mergeStaffRows($staff, $this->database->fetchAll(new CompiledQuery(
            'SELECT u.user_id,u.username,COUNT(*) AS metric FROM forwext_discipline_actions d '
            . 'INNER JOIN forwext_users u ON u.user_id=d.actor_user_id '
            . 'WHERE d.starts_at_utc>=:start AND d.starts_at_utc<:end GROUP BY u.user_id,u.username',
            $window,
        )), 'discipline_actions');
        $this->mergeStaffRows($staff, $this->database->fetchAll(new CompiledQuery(
            'SELECT u.user_id,u.username,COUNT(*) AS metric FROM forwext_core_audit_events a '
            . 'INNER JOIN forwext_users u ON u.user_id=a.actor_user_id '
            . "WHERE a.scope IN ('moderation','support','bug') "
            . 'AND a.occurred_at_utc>=:start AND a.occurred_at_utc<:end GROUP BY u.user_id,u.username',
            $window,
        )), 'audit_actions');

        foreach ($staff as &$row) {
            $row['total'] = $row['active_reports'] + $row['active_support'] + $row['active_bugs']
                + $row['discipline_actions'] + $row['audit_actions'];
        }
        unset($row);

        uasort($staff, static function (array $left, array $right): int {
            return $right['total'] <=> $left['total']
                ?: strcasecmp($left['username'], $right['username'])
                ?: strcmp($left['id'], $right['id']);
        });

        return array_slice(array_values($staff), 0, 50);
    }

    /**
     * @param array<string,array{id:string,username:string,active_reports:int,active_support:int,active_bugs:int,discipline_actions:int,audit_actions:int,total:int}> $staff
     * @param list<array<string,mixed>> $rows
     */
    private function mergeStaffRows(array &$staff, array $rows, string $metric): void
    {
        foreach ($rows as $row) {
            $id = (string) ($row['user_id'] ?? '');
            $username = (string) ($row['username'] ?? '');
            if ($id === '' || $username === '') {
                continue;
            }
            $staff[$id] ??= [
                'id' => $id,
                'username' => $username,
                'active_reports' => 0,
                'active_support' => 0,
                'active_bugs' => 0,
                'discipline_actions' => 0,
                'audit_actions' => 0,
                'total' => 0,
            ];
            if (array_key_exists($metric, $staff[$id])) {
                $staff[$id][$metric] = max(0, (int) ($row['metric'] ?? 0));
            }
        }
    }

    /** @param array<string,mixed> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery($sql, $parameters)));
    }

    /** @param array<string,mixed> $parameters */
    private function average(string $sql, array $parameters = []): ?float
    {
        $value = $this->database->fetchValue(new CompiledQuery($sql, $parameters));
        return $value === null ? null : max(0.0, (float) $value);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
