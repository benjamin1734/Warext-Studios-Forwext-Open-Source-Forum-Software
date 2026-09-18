<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabaseBugDiagnosticContextRepository implements BugDiagnosticContextRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function save(BugDiagnosticContext $context): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_diagnostics '
            . '(report_id,actor_user_id,url_path,route_name,forum_id,thread_id,post_id,theme_key,module_key,'
            . 'browser_family,browser_major,os_family,device_class,user_agent_fingerprint,request_id,captured_at_utc) '
            . 'VALUES (:report_id,:actor_user_id,:url_path,:route_name,:forum_id,:thread_id,:post_id,:theme_key,:module_key,'
            . ':browser_family,:browser_major,:os_family,:device_class,:ua_fingerprint,:request_id,:captured_at)',
            [
                'report_id'=>$context->reportId->value(),
                'actor_user_id'=>$context->actorUserId?->value(),
                'url_path'=>$context->urlPath,
                'route_name'=>$context->routeName,
                'forum_id'=>$context->forumId?->value(),
                'thread_id'=>$context->threadId?->value(),
                'post_id'=>$context->postId?->value(),
                'theme_key'=>$context->themeKey,
                'module_key'=>$context->moduleKey,
                'browser_family'=>$context->client->browserFamily,
                'browser_major'=>$context->client->browserMajor,
                'os_family'=>$context->client->osFamily,
                'device_class'=>$context->client->deviceClass,
                'ua_fingerprint'=>$context->client->userAgentFingerprint,
                'request_id'=>$context->requestId,
                'captured_at'=>$context->capturedAt->format('Y-m-d H:i:s.u'),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Bug diagnostic context was not persisted.');
        }
    }

    public function find(EntityId $reportId): ?BugDiagnosticContext
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT report_id,actor_user_id,url_path,route_name,forum_id,thread_id,post_id,theme_key,module_key,'
            . 'browser_family,browser_major,os_family,device_class,user_agent_fingerprint,request_id,captured_at_utc '
            . 'FROM forwext_bug_report_diagnostics WHERE report_id=:report_id LIMIT 1',
            ['report_id'=>$reportId->value()],
        ));
        if ($row === null) {
            return null;
        }

        return new BugDiagnosticContext(
            EntityId::fromString((string) $row['report_id']),
            $row['actor_user_id'] === null ? null : UserId::fromStored((string) $row['actor_user_id']),
            (string) $row['url_path'],
            $row['route_name'] === null ? null : (string) $row['route_name'],
            self::id($row['forum_id']),
            self::id($row['thread_id']),
            self::id($row['post_id']),
            (string) $row['theme_key'],
            $row['module_key'] === null ? null : (string) $row['module_key'],
            new BugBrowserDeviceSummary(
                (string) $row['browser_family'],
                $row['browser_major'] === null ? null : (int) $row['browser_major'],
                (string) $row['os_family'],
                (string) $row['device_class'],
                $row['user_agent_fingerprint'] === null ? null : (string) $row['user_agent_fingerprint'],
            ),
            $row['request_id'] === null ? null : (string) $row['request_id'],
            self::time((string) $row['captured_at_utc']),
        );
    }

    private static function id(mixed $value): ?EntityId
    {
        return is_string($value) && $value !== '' ? EntityId::fromString($value) : null;
    }

    private static function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored bug diagnostic timestamp is invalid.');
        }
        return $time;
    }
}
