<?php

declare(strict_types=1);

use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Moderation\Oversight\DatabaseModerationOversightStore;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$env = static function (string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException(sprintf('Required environment variable %s is missing.', $key));
    }
    return $value;
};

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3308'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_browser_ci'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$administratorId = $database->fetchValue(new CompiledQuery(
    "SELECT user_id FROM forwext_users WHERE username_key='ci-admin' AND status='active' LIMIT 1",
));
if (!is_string($administratorId) || preg_match('/^[a-f0-9]{32}$/D', $administratorId) !== 1) {
    throw new RuntimeException('Phase 9 browser fixture administrator is unavailable.');
}

$sourceAuditId = EntityId::fromString(str_repeat('ce', 16));
$caseId = str_repeat('cd', 16);
$sourceExists = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_moderation_oversight_entries WHERE source_audit_id=:id',
    ['id'=>$sourceAuditId->value()],
));

if ($sourceExists === 0) {
    $store = new DatabaseModerationOversightStore($database);
    $event = new ModerationAuditEvent(
        $sourceAuditId,
        EntityId::fromString(str_repeat('11', 16)),
        ModerationAuditAction::ThreadLock,
        'thread',
        str_repeat('22', 16),
        null,
        ModerationReasonCode::fromString('phase9.browser.fixture'),
        ModerationRequestId::fromString('phase9-browser-fixture'),
        ['locked'=>false],
        ['locked'=>true],
        new DateTimeImmutable('2026-10-05T12:00:00+00:00'),
    );
    $database->transaction(static function () use ($store, $event): void {
        $store->append($event);
    });
}

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_moderation_oversight_review_cases '
    . '(case_id,source_audit_id,opened_by_user_id,summary,status,opened_at_utc,resolved_at_utc,resolved_by_user_id,resolution) '
    . "VALUES (:case_id,:source_audit_id,:opened_by,'Phase 9 tarayıcı denetim vakası','open',UTC_TIMESTAMP(6),NULL,NULL,NULL) "
    . "ON DUPLICATE KEY UPDATE source_audit_id=VALUES(source_audit_id),opened_by_user_id=VALUES(opened_by_user_id),"
    . "summary=VALUES(summary),status='open',resolved_at_utc=NULL,resolved_by_user_id=NULL,resolution=NULL",
    [
        'case_id'=>$caseId,
        'source_audit_id'=>$sourceAuditId->value(),
        'opened_by'=>$administratorId,
    ],
));

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_moderation_oversight_anomaly_flags '
    . '(flag_id,source_audit_id,flag_type,severity,details,created_at_utc,resolved_at_utc,resolved_by_user_id,resolution) '
    . "VALUES (:flag_id,:source_audit_id,'browser.fixture','medium','Phase 9 gerçek Chromium anomali görünümü.',"
    . 'UTC_TIMESTAMP(6),NULL,NULL,NULL) '
    . 'ON DUPLICATE KEY UPDATE severity=VALUES(severity),details=VALUES(details),'
    . 'resolved_at_utc=NULL,resolved_by_user_id=NULL,resolution=NULL',
    [
        'flag_id'=>str_repeat('cf', 16),
        'source_audit_id'=>$sourceAuditId->value(),
    ],
));

$caseCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_moderation_oversight_review_cases WHERE case_id=:id AND status='open'",
    ['id'=>$caseId],
));
$entryCount = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_moderation_oversight_entries WHERE source_audit_id=:id',
    ['id'=>$sourceAuditId->value()],
));
if ($caseCount !== 1 || $entryCount !== 1) {
    throw new RuntimeException('Phase 9 browser fixtures were not created completely.');
}

echo "Phase 9 browser fixtures seeded.\n";
