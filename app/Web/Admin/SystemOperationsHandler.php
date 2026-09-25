<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Operations\SystemBackupEntry;
use Forwext\Core\Admin\Operations\SystemOperationsService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use RuntimeException;

final readonly class SystemOperationsHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemOperationsService $operations,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($actor, $request);
            }

            $logLimit = $this->logLimit($request);
            $section = $this->section($request);
            $verified = $this->verification($actor, $request);
            $snapshot = $this->operations->snapshot($actor, $logLimit);
            $notice = $request->query()['updated'] ?? null;
            $notice = is_string($notice) && preg_match('/^[a-z0-9_-]{1,32}$/D', $notice) === 1 ? $notice : null;

            return Response::html(ProfileHtml::page(
                'System Operations',
                SystemOperationsHtml::page($snapshot, $verified, $this->basePath, $csrf, $notice, $logLimit, $section),
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return Response::text('System operation could not be completed.', 409)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('System operation action is missing.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $auditRequestId = HttpAuditRequestId::fromRequest($request);
        $updated = $action;

        switch ($action) {
            case 'set_maintenance':
                $this->setMaintenance($actor, $body, $auditRequestId, $now);
                break;
            case 'run_task':
                $this->operations->runScheduledTask(
                    $actor,
                    self::required($body, 'task_name', 191),
                    $auditRequestId,
                    $now,
                );
                break;
            case 'retry_failed_job':
                $this->operations->retryFailedJob(
                    $actor,
                    self::jobId($body['job_id'] ?? null),
                    $auditRequestId,
                    $now,
                );
                break;
            case 'delete_failed_job':
                $this->deleteFailedJob($actor, $body, $auditRequestId, $now);
                break;
            case 'prune_scheduler_claims':
                $this->operations->pruneSchedulerClaims(
                    $actor,
                    self::integer($body, 'older_than_days', 1, 3650),
                    $auditRequestId,
                    $now,
                );
                break;
            case 'create_backup':
                $this->operations->createBackup($actor, $auditRequestId, $now);
                break;
            case 'delete_backup':
                $this->deleteBackup($actor, $body, $auditRequestId, $now);
                break;
            case 'clear_cache':
                $this->clearCache($actor, $body, $auditRequestId, $now);
                break;
            default:
                throw new InvalidArgumentException('Unknown system operation action.');
        }

        return Response::redirect(
            $this->basePath->prepend('/admin/system/operations?updated=' . rawurlencode($updated)),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $body */
    private function setMaintenance(
        EntityId $actor,
        array $body,
        \Forwext\Core\Audit\AuditRequestId $requestId,
        DateTimeImmutable $now,
    ): void {
        if (($body['confirm'] ?? null) !== 'MAINTENANCE') {
            throw new InvalidArgumentException('Maintenance confirmation is invalid.');
        }
        $raw = $body['enabled'] ?? null;
        if (!in_array($raw, ['0', '1'], true)) {
            throw new InvalidArgumentException('Maintenance value is invalid.');
        }
        $this->operations->setMaintenance($actor, $raw === '1', $requestId, $now);
    }

    /** @param array<string,mixed> $body */
    private function deleteFailedJob(
        EntityId $actor,
        array $body,
        \Forwext\Core\Audit\AuditRequestId $requestId,
        DateTimeImmutable $now,
    ): void {
        $jobId = self::jobId($body['job_id'] ?? null);
        if (($body['confirm'] ?? null) !== $jobId) {
            throw new InvalidArgumentException('Failed-job delete confirmation is invalid.');
        }
        $this->operations->deleteFailedJob($actor, $jobId, $requestId, $now);
    }

    /** @param array<string,mixed> $body */
    private function deleteBackup(
        EntityId $actor,
        array $body,
        \Forwext\Core\Audit\AuditRequestId $requestId,
        DateTimeImmutable $now,
    ): void {
        $name = self::required($body, 'backup_name', 96);
        if (($body['confirm'] ?? null) !== $name) {
            throw new InvalidArgumentException('Backup delete confirmation is invalid.');
        }
        $this->operations->deleteBackup($actor, $name, $requestId, $now);
    }

    /** @param array<string,mixed> $body */
    private function clearCache(
        EntityId $actor,
        array $body,
        \Forwext\Core\Audit\AuditRequestId $requestId,
        DateTimeImmutable $now,
    ): void {
        if (($body['confirm'] ?? null) !== 'CLEAR CACHE') {
            throw new InvalidArgumentException('Cache clear confirmation is invalid.');
        }
        $this->operations->clearCache($actor, $requestId, $now);
    }

    private function verification(EntityId $actor, Request $request): ?SystemBackupEntry
    {
        $name = $request->query()['verify'] ?? null;
        if ($name === null || $name === '') {
            return null;
        }
        if (!is_string($name) || strlen($name) > 96) {
            throw new InvalidArgumentException('Backup verification target is invalid.');
        }

        return $this->operations->verifyBackup($actor, $name);
    }

    private function logLimit(Request $request): int
    {
        $raw = $request->query()['logs'] ?? '100';
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new InvalidArgumentException('Log limit is invalid.');
        }
        $value = (int) $raw;
        if ($value < 1 || $value > 250) {
            throw new InvalidArgumentException('Log limit is outside the allowed range.');
        }

        return $value;
    }

    private function section(Request $request): string
    {
        $value = $request->query()['section'] ?? 'all';
        if (!is_string($value) || !in_array($value, ['all','health','maintenance','jobs','backups','logs','repairs'], true)) {
            throw new InvalidArgumentException('System operations section filter is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new InvalidArgumentException('System operation field is invalid: ' . $key);
        }

        return trim($value);
    }

    private static function jobId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('System operation job id is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body, string $key, int $min, int $max): int
    {
        $value = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('System operation integer is invalid.');
        }

        return $value;
    }
}
