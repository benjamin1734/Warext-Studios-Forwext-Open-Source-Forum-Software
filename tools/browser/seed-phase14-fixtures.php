<?php

declare(strict_types=1);

use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Node\ForumDefaultThreadSort;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;

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

$nodeId = EntityId::fromString(str_repeat('14', 16));
$repository = new DatabaseForumNodeRepository($database);
$repository->save(ForumNode::forum(
    $nodeId,
    null,
    'Phase 14 Browser Forum',
    ForumNodeSlug::fromString('phase14-browser-forum'),
    new ForumSettings(true, true, false, false, ForumDefaultThreadSort::LastPost, 25),
    'Deterministic Phase 14 browser forum fixture.',
    414,
    ForumNodeVisibility::Listed,
));

$node = $repository->find($nodeId);
if (
    $node === null
    || $node->title() !== 'Phase 14 Browser Forum'
    || $node->slug()->value() !== 'phase14-browser-forum'
    || $node->forumSettings()?->threadsPerPage() !== 25
) {
    throw new RuntimeException('Phase 14 forum fixture failed repository round-trip verification.');
}

echo "Phase 14 forum fixture seeded through the real repository.\n";
