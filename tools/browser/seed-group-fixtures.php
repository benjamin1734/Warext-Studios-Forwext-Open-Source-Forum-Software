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

$ownerId = 'cccccccccccccccccccccccccccccccc';
$groupId = 'dddddddddddddddddddddddddddddddd';

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_users '
    . '(user_id,username,username_key,email,email_key,status,locale,timezone,version,created_at_utc,updated_at_utc) '
    . "VALUES (:user_id,'GroupFixtureOwner','groupfixtureowner','group-owner@forwext.test',"
    . "'group-owner@forwext.test','active','tr','Europe/Istanbul',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
    . 'ON DUPLICATE KEY UPDATE status=VALUES(status),updated_at_utc=UTC_TIMESTAMP(6)',
    ['user_id'=>$ownerId],
));

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_groups '
    . '(group_id,owner_user_id,slug,name,tagline,description,join_policy,state,created_at_utc,updated_at_utc) '
    . "VALUES (:group_id,:owner,'ci-approval-group','CI Onaylı Grup','Gerçek üyelik akışını doğrulayan grup.',"
    . "'Phase 8 grup katılım isteği, onay ve ayrılma akışını gerçek runtime üzerinde doğrular.',"
    . "'approval','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
    . 'ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),join_policy=VALUES(join_policy),'
    . "state='active',updated_at_utc=UTC_TIMESTAMP(6)",
    ['group_id'=>$groupId,'owner'=>$ownerId],
));

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_group_members '
    . '(group_id,user_id,role_key,state,acted_by_user_id,created_at_utc,updated_at_utc) '
    . "VALUES (:group_id,:user_id,'owner','active',:actor,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
    . "ON DUPLICATE KEY UPDATE role_key='owner',state='active',acted_by_user_id=VALUES(acted_by_user_id),"
    . 'updated_at_utc=UTC_TIMESTAMP(6)',
    ['group_id'=>$groupId,'user_id'=>$ownerId,'actor'=>$ownerId],
));

$count = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_groups WHERE group_id=:group_id AND state='active'",
    ['group_id'=>$groupId],
));
if ($count !== 1) {
    throw new RuntimeException('Community group browser fixture was not created.');
}

echo "Community group browser fixture seeded.\n";
