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
$groupId = str_repeat('13', 16);
$roleId = str_repeat('31', 16);

$userExists = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_users WHERE user_id=:id AND username_key='phase12-member'",
    ['id'=>$userId],
));
if ($userExists !== 1) {
    throw new RuntimeException('Phase 13 requires the Phase 12 browser member fixture.');
}

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_user_groups '
    . '(group_id,group_key,name,is_system,sort_order,created_at_utc,updated_at_utc) '
    . "VALUES (:id,'phase13_browser_group','Phase 13 Browser Group',0,313,'2026-10-05 13:00:00.000000','2026-10-05 13:00:00.000000') "
    . 'ON DUPLICATE KEY UPDATE name=VALUES(name),is_system=0,sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)',
    ['id'=>$groupId],
));
$database->execute(new CompiledQuery(
    'INSERT INTO forwext_roles '
    . '(role_id,role_key,name,kind,is_protected,priority,created_at_utc,updated_at_utc) '
    . "VALUES (:id,'phase13_browser_role','Phase 13 Browser Role','custom',0,313,'2026-10-05 13:00:00.000000','2026-10-05 13:00:00.000000') "
    . 'ON DUPLICATE KEY UPDATE name=VALUES(name),kind=VALUES(kind),is_protected=0,priority=VALUES(priority),updated_at_utc=VALUES(updated_at_utc)',
    ['id'=>$roleId],
));
$database->execute(new CompiledQuery(
    'INSERT INTO forwext_user_primary_groups (user_id,group_id,assigned_at_utc) '
    . "VALUES (:user_id,:group_id,'2026-10-05 13:00:00.000000') "
    . 'ON DUPLICATE KEY UPDATE group_id=VALUES(group_id),assigned_at_utc=VALUES(assigned_at_utc)',
    ['user_id'=>$userId,'group_id'=>$groupId],
));
$database->execute(new CompiledQuery(
    'INSERT INTO forwext_user_role_assignments (user_id,role_id,assigned_at_utc) '
    . "VALUES (:user_id,:role_id,'2026-10-05 13:00:00.000000') "
    . 'ON DUPLICATE KEY UPDATE assigned_at_utc=VALUES(assigned_at_utc)',
    ['user_id'=>$userId,'role_id'=>$roleId],
));

$groupMembers = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_user_primary_groups WHERE user_id=:user_id AND group_id=:group_id',
    ['user_id'=>$userId,'group_id'=>$groupId],
));
$roleMembers = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_user_role_assignments WHERE user_id=:user_id AND role_id=:role_id',
    ['user_id'=>$userId,'role_id'=>$roleId],
));
if ($groupMembers !== 1 || $roleMembers !== 1) {
    throw new RuntimeException('Phase 13 access fixture assignments are incomplete.');
}

echo "Phase 13 access fixtures seeded.\n";
