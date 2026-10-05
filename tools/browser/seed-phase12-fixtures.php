<?php

declare(strict_types=1);

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;

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

$userId = str_repeat('12', 16);
$database->execute(new CompiledQuery(
    'INSERT INTO forwext_users '
    . '(user_id,username,username_key,email,email_key,status,locale,timezone,version,created_at_utc,updated_at_utc) '
    . "VALUES (:id,'phase12-member','phase12-member','phase12-member@forwext.test','phase12-member@forwext.test',"
    . "'active','tr-TR','Europe/Istanbul',1,'2026-10-01 09:00:00.000000','2026-10-05 12:00:00.000000') "
    . "ON DUPLICATE KEY UPDATE username=VALUES(username),username_key=VALUES(username_key),email=VALUES(email),"
    . "email_key=VALUES(email_key),status='active',locale=VALUES(locale),timezone=VALUES(timezone),"
    . "version=GREATEST(version,1),updated_at_utc=VALUES(updated_at_utc)",
    ['id'=>$userId],
));

$historyExists = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_user_history WHERE user_id=:id AND event_type='user.phase12_fixture'",
    ['id'=>$userId],
));
if ($historyExists === 0) {
    $database->execute(new CompiledQuery(
        'INSERT INTO forwext_user_history '
        . '(user_id,event_type,changed_fields_json,occurred_at_utc,actor_user_id,from_status,to_status,reason_code) '
        . "VALUES (:id,'user.phase12_fixture','[\"status\",\"locale\"]','2026-10-05 12:00:00.000000',"
        . "NULL,NULL,'active','phase12.browser.fixture')",
        ['id'=>$userId],
    ));
}

$userCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_users WHERE user_id=:id AND username_key='phase12-member' AND status='active'",
    ['id'=>$userId],
));
$historyCount = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_user_history WHERE user_id=:id AND reason_code='phase12.browser.fixture'",
    ['id'=>$userId],
));
if ($userCount !== 1 || $historyCount < 1) {
    throw new RuntimeException('Phase 12 browser user fixture was not created completely.');
}

echo "Phase 12 browser user fixture seeded.\n";
