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

$administratorId = $database->fetchValue(new CompiledQuery(
    "SELECT user_id FROM forwext_users WHERE username_key='ci-admin' AND status='active' LIMIT 1",
));
if (!is_string($administratorId) || preg_match('/^[a-f0-9]{32}$/D', $administratorId) !== 1) {
    throw new RuntimeException('Browser Minecraft fixture administrator is unavailable.');
}

$ownedId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$unownedId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_minecraft_servers '
    . '(server_id,owner_user_id,slug,name,summary,description,host,port,edition,version_label,game_mode,'
    . 'website_url,discord_url,listing_state,verification_state,created_at_utc,updated_at_utc) '
    . "VALUES (:server_id,:owner,'forwext-ci-owned','Forwext CI Sunucusu','Gerçek tarayıcı kabul fixture sunucusu.',"
    . "'Phase 7 canlı route ve yönetim akışlarını doğrulayan kurulum sonrası fixture.',"
    . "'play.forwext-ci.test',25565,'java','1.21.4','survival',NULL,NULL,'published','verified',"
    . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
    . 'ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),listing_state=VALUES(listing_state),'
    . 'verification_state=VALUES(verification_state),updated_at_utc=UTC_TIMESTAMP(6)',
    ['server_id'=>$ownedId,'owner'=>$administratorId],
));

$database->execute(new CompiledQuery(
    'INSERT INTO forwext_minecraft_servers '
    . '(server_id,owner_user_id,slug,name,summary,description,host,port,edition,version_label,game_mode,'
    . 'website_url,discord_url,listing_state,verification_state,created_at_utc,updated_at_utc) '
    . "VALUES (:server_id,NULL,'forwext-ci-unowned','Sahipsiz CI Sunucusu','Sahiplik doğrulama fixture sunucusu.',"
    . "'Sahiplik talebi route ve form durumunu gerçek kurulum üzerinde doğrular.',"
    . "'unowned.forwext-ci.test',25565,'java','1.21.4','survival',NULL,NULL,'published','unverified',"
    . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
    . 'ON DUPLICATE KEY UPDATE owner_user_id=NULL,listing_state=VALUES(listing_state),'
    . 'verification_state=VALUES(verification_state),updated_at_utc=UTC_TIMESTAMP(6)',
    ['server_id'=>$unownedId],
));

foreach ([$ownedId,$unownedId] as $serverId) {
    $database->execute(new CompiledQuery(
        'INSERT INTO forwext_minecraft_server_status '
        . '(server_id,reachability,online_players,max_players,latency_ms,motd,checked_at_utc) '
        . "VALUES (:server_id,'online',7,100,24,'Forwext CI',UTC_TIMESTAMP(6)) "
        . 'ON DUPLICATE KEY UPDATE reachability=VALUES(reachability),online_players=VALUES(online_players),'
        . 'max_players=VALUES(max_players),latency_ms=VALUES(latency_ms),motd=VALUES(motd),'
        . 'checked_at_utc=VALUES(checked_at_utc)',
        ['server_id'=>$serverId],
    ));
}

$count = (int) $database->fetchValue(new CompiledQuery(
    "SELECT COUNT(*) FROM forwext_minecraft_servers WHERE slug IN ('forwext-ci-owned','forwext-ci-unowned')",
));
if ($count !== 2) {
    throw new RuntimeException('Browser Minecraft fixtures were not created.');
}

echo "Minecraft browser fixtures seeded.\n";
