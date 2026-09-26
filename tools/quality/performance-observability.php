<?php

declare(strict_types=1);

use Forwext\Core\Cache\DatabaseCacheStore;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Post\DatabasePostRepository;
use Forwext\Core\Forum\Thread\DatabaseThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use Forwext\Core\Queue\DatabaseQueueDriver;
use Forwext\Core\Queue\QueueName;
use Forwext\Core\Search\NativeDatabaseSearchDriver;
use Forwext\Core\Search\SearchQuery;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

final class PerformanceCountingDatabase implements TransactionalQueryExecutor
{
    public int $queries = 0;

    public function __construct(private readonly TransactionalQueryExecutor $inner)
    {
    }

    public function reset(): void
    {
        $this->queries = 0;
    }

    public function execute(CompiledQuery $query): int
    {
        ++$this->queries;
        return $this->inner->execute($query);
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        ++$this->queries;
        return $this->inner->fetchOne($query);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        ++$this->queries;
        return $this->inner->fetchAll($query);
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        ++$this->queries;
        return $this->inner->fetchValue($query);
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->inner->transaction(fn (): mixed => $callback($this));
    }
}

$env = static function (string $key, ?string $default = null): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new \RuntimeException(sprintf('Required environment variable %s is missing.', $key));
    }
    return $value;
};

$threadCount = (int) $env('FORWEXT_PERF_THREADS', '800');
$postsPerThread = (int) $env('FORWEXT_PERF_POSTS_PER_THREAD', '8');
$iterations = (int) $env('FORWEXT_PERF_ITERATIONS', '25');
if ($threadCount < 200 || $threadCount > 5000
    || $postsPerThread < 2 || $postsPerThread > 50
    || $iterations < 10 || $iterations > 200
) {
    throw new \RuntimeException('Performance qualification dimensions are outside their safe CI bounds.');
}

$database = (new PdoConnectionFactory())->create(new DatabaseConfig(
    $env('FORWEXT_TEST_DB_HOST', '127.0.0.1'),
    (int) $env('FORWEXT_TEST_DB_PORT', '3306'),
    $env('FORWEXT_TEST_DB_NAME', 'forwext_perf'),
    $env('FORWEXT_TEST_DB_USER', 'root'),
    $env('FORWEXT_TEST_DB_PASSWORD', 'root'),
));

$forumId = substr(hash('sha256', 'forwext-performance-forum'), 0, 32);
$targetThreadId = '';
$started = hrtime(true);

$database->transaction(function (TransactionalQueryExecutor $db) use (
    $threadCount,
    $postsPerThread,
    $forumId,
    &$targetThreadId,
): void {
    $db->execute(new CompiledQuery(
        'INSERT INTO forwext_nodes '
        . '(node_id,parent_id,node_type,title,slug,description,visibility,sort_order,page_content,link_target,'
        . 'link_new_window,created_at_utc,updated_at_utc) '
        . "VALUES (:id,NULL,'forum','Performance Forum','performance-forum','CI performance qualification','public',"
        . '0,NULL,NULL,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
        ['id'=>$forumId],
    ));
    $db->execute(new CompiledQuery(
        'INSERT INTO forwext_forum_settings '
        . '(node_id,allow_new_threads,allow_replies,require_thread_approval,require_post_approval,'
        . 'default_thread_sort,threads_per_page,updated_at_utc) '
        . "VALUES (:id,1,1,0,0,'last_post',50,UTC_TIMESTAMP(6))",
        ['id'=>$forumId],
    ));

    for ($i = 1; $i <= $threadCount; ++$i) {
        $threadId = substr(hash('sha256', 'forwext-performance-thread-' . $i), 0, 32);
        if ($i === (int) ceil($threadCount / 2)) {
            $targetThreadId = $threadId;
        }

        $db->execute(new CompiledQuery(
            'INSERT INTO forwext_threads '
            . '(thread_id,forum_node_id,author_user_id,type_key,title,moderation_state,locked,sticky,featured,'
            . 'version,created_at_utc,updated_at_utc) '
            . "VALUES (:thread_id,:forum_id,NULL,'discussion',:title,'visible',0,0,0,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
            [
                'thread_id'=>$threadId,
                'forum_id'=>$forumId,
                'title'=>'Performance thread ' . $i,
            ],
        ));

        for ($position = 1; $position <= $postsPerThread; ++$position) {
            $postId = substr(hash('sha256', 'forwext-performance-post-' . $i . '-' . $position), 0, 32);
            $db->execute(new CompiledQuery(
                'INSERT INTO forwext_posts '
                . '(post_id,thread_id,author_user_id,position,body_source,moderation_state,deleted,deleted_at_utc,'
                . 'version,created_at_utc,updated_at_utc) '
                . "VALUES (:post_id,:thread_id,NULL,:position,:body,'visible',0,NULL,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                [
                    'post_id'=>$postId,
                    'thread_id'=>$threadId,
                    'position'=>$position,
                    'body'=>'Performance qualification post ' . $i . '-' . $position . ' with representative forum text.',
                ],
            ));
        }

        $documentKey = hash('sha256', 'thread' . "\0" . $threadId);
        $needle = $i % 40 === 0 ? ' forwextneedle' : '';
        $db->execute(new CompiledQuery(
            'INSERT INTO forwext_search_documents '
            . '(document_key,document_type,document_id,title,body,locale,updated_at_utc) '
            . "VALUES (:document_key,'thread',:document_id,:title,:body,'en',UTC_TIMESTAMP(6))",
            [
                'document_key'=>$documentKey,
                'document_id'=>$threadId,
                'title'=>'Performance thread ' . $i,
                'body'=>'Representative searchable forum body ' . $i . $needle,
            ],
        ));
        $db->execute(new CompiledQuery(
            'INSERT INTO forwext_search_document_scopes (document_key,scope_token) VALUES (:document_key,:scope)',
            ['document_key'=>$documentKey,'scope'=>'public'],
        ));
    }
});

if ($targetThreadId === '') {
    throw new \RuntimeException('Performance seed did not select a target thread.');
}

$seedMilliseconds = (hrtime(true) - $started) / 1_000_000;
$counting = new PerformanceCountingDatabase($database);
$nodes = new DatabaseForumNodeRepository($counting);
$threads = new DatabaseThreadRepository($counting, ThreadTypeRegistry::withCoreDefaults());
$posts = new DatabasePostRepository($counting);
$search = new NativeDatabaseSearchDriver($counting);
$forumEntityId = EntityId::fromString($forumId);
$threadEntityId = EntityId::fromString($targetThreadId);
$searchQuery = new SearchQuery('forwextneedle', ['public'], ['thread'], 'en', 20, 0);

$counting->reset();
if (count($nodes->all()) < 1 || $counting->queries !== 1) {
    throw new \RuntimeException('Forum listing violated its one-query N+1 budget.');
}
$counting->reset();
if (count($threads->findByForum($forumEntityId, 50, 0)) !== 50 || $counting->queries !== 1) {
    throw new \RuntimeException('Thread listing violated its one-query N+1 budget.');
}
$counting->reset();
$postPage = $posts->pageByThread($threadEntityId, 1, 50);
if (count($postPage->posts) !== $postsPerThread || $counting->queries !== 2) {
    throw new \RuntimeException('Post pagination violated its two-query count+page budget.');
}
$counting->reset();
$hits = $search->search($searchQuery);
if ($hits === [] || $counting->queries !== 1) {
    throw new \RuntimeException('Native search violated its one-query budget or returned no benchmark hit.');
}

$latencies = [
    'forum_ms'=>[],
    'thread_ms'=>[],
    'post_ms'=>[],
    'search_ms'=>[],
];
$loadStarted = hrtime(true);
for ($iteration = 0; $iteration < $iterations; ++$iteration) {
    $latencies['forum_ms'][] = performanceMeasure(static fn () => $nodes->all());
    $latencies['thread_ms'][] = performanceMeasure(
        static fn () => $threads->findByForum($forumEntityId, 50, 0),
    );
    $latencies['post_ms'][] = performanceMeasure(
        static fn () => $posts->pageByThread($threadEntityId, 1, 50),
    );
    $latencies['search_ms'][] = performanceMeasure(static fn () => $search->search($searchQuery));
}
$loadMilliseconds = (hrtime(true) - $loadStarted) / 1_000_000;

$summary = [];
foreach ($latencies as $name=>$values) {
    $summary[$name] = [
        'p50'=>performancePercentile($values, 50),
        'p95'=>performancePercentile($values, 95),
        'max'=>max($values),
    ];
}
foreach (['forum_ms'=>750.0,'thread_ms'=>750.0,'post_ms'=>750.0,'search_ms'=>1500.0] as $name=>$budget) {
    if ($summary[$name]['p95'] > $budget) {
        throw new \RuntimeException(sprintf(
            '%s p95 %.2f ms exceeds the shared-hosting qualification budget %.2f ms.',
            $name,
            $summary[$name]['p95'],
            $budget,
        ));
    }
}
if ($loadMilliseconds > 20000.0) {
    throw new \RuntimeException(sprintf('Combined load probe %.2f ms exceeds the 20 second CI budget.', $loadMilliseconds));
}

$cache = new DatabaseCacheStore($database);
$cacheWriteMs = performanceMeasure(static fn () => $cache->put('perf.hot', 'warm-value', 300, ['perf']));
$cacheReadMs = performanceMeasure(static fn () => $cache->get('perf.hot'));
if ($cache->get('perf.hot') === null) {
    throw new \RuntimeException('Database cache qualification did not return the written entry.');
}
$cacheEntries = (int) $database->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM forwext_cache'));

$queue = new DatabaseQueueDriver($database);
$queueName = QueueName::fromString('performance');
for ($i = 0; $i < 3; ++$i) {
    $queue->push($queueName, 'perf.probe', json_encode(['n'=>$i], JSON_THROW_ON_ERROR));
}
$queueReadyBefore = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_jobs WHERE queue_name=:queue AND reserved_until_utc IS NULL',
    ['queue'=>'performance'],
));
$reservation = $queue->reserve($queueName);
if ($reservation === null) {
    throw new \RuntimeException('Database queue qualification could not reserve a ready job.');
}
$queue->acknowledge($reservation);
$queueReadyAfter = (int) $database->fetchValue(new CompiledQuery(
    'SELECT COUNT(*) FROM forwext_jobs WHERE queue_name=:queue AND reserved_until_utc IS NULL',
    ['queue'=>'performance'],
));
$failedJobs = (int) $database->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM forwext_failed_jobs'));
if ($queueReadyBefore !== 3 || $queueReadyAfter !== 2) {
    throw new \RuntimeException('Queue backlog metrics did not track reserve/ack behavior.');
}

$peakMemory = memory_get_peak_usage(true);
if ($peakMemory > 100 * 1024 * 1024) {
    throw new \RuntimeException(sprintf('Peak memory %d bytes exceeds the 100 MiB qualification ceiling.', $peakMemory));
}

$opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if (!is_array($opcache) || ($opcache['opcache_enabled'] ?? false) !== true) {
    throw new \RuntimeException('OPcache must be enabled for the low-resource performance qualification.');
}

$result = [
    'profile'=>'shared-hosting-low-resource',
    'dataset'=>[
        'forums'=>1,
        'threads'=>$threadCount,
        'posts'=>$threadCount * $postsPerThread,
        'search_documents'=>$threadCount,
        'seed_ms'=>round($seedMilliseconds, 3),
    ],
    'n_plus_one_query_budget'=>[
        'forum_listing'=>1,
        'thread_listing'=>1,
        'post_page'=>2,
        'search'=>1,
    ],
    'load'=>[
        'iterations'=>$iterations,
        'combined_ms'=>round($loadMilliseconds, 3),
        'latency'=>$summary,
    ],
    'cache'=>[
        'entries'=>$cacheEntries,
        'write_ms'=>round($cacheWriteMs, 3),
        'read_ms'=>round($cacheReadMs, 3),
    ],
    'queue'=>[
        'ready_before_ack'=>$queueReadyBefore,
        'ready_after_ack'=>$queueReadyAfter,
        'failed_jobs'=>$failedJobs,
    ],
    'runtime'=>[
        'memory_limit'=>(string) ini_get('memory_limit'),
        'peak_memory_bytes'=>$peakMemory,
        'opcache_enabled'=>true,
        'php'=>PHP_VERSION,
    ],
];

$build = $root . '/build';
if (!is_dir($build) && !mkdir($build, 0775, true) && !is_dir($build)) {
    throw new \RuntimeException('Cannot create performance artifact directory.');
}
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($build . '/performance-observability.json', $json) === false) {
    throw new \RuntimeException('Cannot write performance observability artifact.');
}

echo $json;

function performanceMeasure(\Closure $operation): float
{
    $started = hrtime(true);
    $operation();
    return (hrtime(true) - $started) / 1_000_000;
}

/** @param list<float> $values */
function performancePercentile(array $values, int $percentile): float
{
    sort($values, SORT_NUMERIC);
    $index = (int) ceil((count($values) * $percentile) / 100) - 1;
    return round($values[max(0, min(count($values) - 1, $index))], 3);
}
