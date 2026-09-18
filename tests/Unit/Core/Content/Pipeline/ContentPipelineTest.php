<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Pipeline;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\ContentPipelineAfterPersistProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelineIndexer;
use Forwext\Core\Content\Pipeline\ContentPipelineNotifier;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipelineRejectedException;
use Forwext\Core\Content\Pipeline\ContentPipelineStage;
use Forwext\Core\Content\Pipeline\DefaultContentValidationProcessor;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContentPipelineTest extends TestCase
{
    public function testPipelineEnforcesCanonicalOrderAndTransactionalPersistNotifyIndex(): void
    {
        $trace = new PipelineTrace();
        $database = new PipelineDatabase($trace);
        $pipeline = new ContentPipeline(
            $database,
            [
                new RecordingPipelineProcessor(ContentPipelineStage::AiModeration, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Validation, $trace),
                new RecordingAfterPipelineProcessor(ContentPipelineStage::Spam, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::ModerationPolicy, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spellcheck, $trace),
            ],
            new RecordingPipelineNotifier($trace),
            new RecordingPipelineIndexer($trace),
        );

        $value = $pipeline->execute(
            new ContentPipelineContext(
                EntityId::fromString(str_repeat('1', 32)),
                'forum.post',
                'Pipeline body',
                100000,
            ),
            $this->time(),
            function (ContentPipelineContext $context) use ($trace): ContentPipelinePersisted {
                $trace->events[] = 'persist';
                return new ContentPipelinePersisted(
                    new PipelineValue($context->text),
                    'forum.post',
                    EntityId::fromString(str_repeat('a', 32)),
                );
            },
        );

        self::assertInstanceOf(PipelineValue::class, $value);
        self::assertSame([
            'validation',
            'spam',
            'spellcheck',
            'ai_moderation',
            'moderation_policy',
            'tx.begin',
            'persist',
            'after:spam',
            'notify',
            'index',
            'tx.commit',
        ], $trace->events);
    }

    public function testIndexerFailureRollsBackPersistNotifyIndexTransaction(): void
    {
        $trace = new PipelineTrace();
        $database = new PipelineDatabase($trace);
        $pipeline = new ContentPipeline(
            $database,
            [
                new RecordingPipelineProcessor(ContentPipelineStage::Validation, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spam, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spellcheck, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::AiModeration, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::ModerationPolicy, $trace),
            ],
            new RecordingPipelineNotifier($trace),
            new RecordingPipelineIndexer($trace, true),
        );

        try {
            $pipeline->execute(
                new ContentPipelineContext(
                    EntityId::fromString(str_repeat('1', 32)),
                    'forum.thread',
                    'Pipeline title',
                    200,
                ),
                $this->time(),
                function (ContentPipelineContext $context) use ($trace): ContentPipelinePersisted {
                    $trace->events[] = 'persist';
                    return new ContentPipelinePersisted(
                        new PipelineValue($context->text),
                        'forum.thread',
                        EntityId::fromString(str_repeat('b', 32)),
                    );
                },
            );
            self::fail('Indexer failure must escape the pipeline transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('index failed', $exception->getMessage());
        }

        self::assertSame('tx.rollback', $trace->events[count($trace->events) - 1]);
        self::assertFalse($database->inTransaction());
    }

    public function testPipelineRefusesIncompleteOrDuplicatePrePersistRegistry(): void
    {
        $trace = new PipelineTrace();

        try {
            new ContentPipeline(
                new PipelineDatabase($trace),
                [new RecordingPipelineProcessor(ContentPipelineStage::Validation, $trace)],
            );
            self::fail('Missing stages must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('stage is missing', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new ContentPipeline(
            new PipelineDatabase($trace),
            [
                new RecordingPipelineProcessor(ContentPipelineStage::Validation, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Validation, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spam, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spellcheck, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::AiModeration, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::ModerationPolicy, $trace),
            ],
        );
    }

    public function testValidationRejectsBeforePersistenceTransactionStarts(): void
    {
        $trace = new PipelineTrace();
        $pipeline = new ContentPipeline(
            new PipelineDatabase($trace),
            [
                new DefaultContentValidationProcessor(),
                new RecordingPipelineProcessor(ContentPipelineStage::Spam, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::Spellcheck, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::AiModeration, $trace),
                new RecordingPipelineProcessor(ContentPipelineStage::ModerationPolicy, $trace),
            ],
            new RecordingPipelineNotifier($trace),
            new RecordingPipelineIndexer($trace),
        );

        $this->expectException(ContentPipelineRejectedException::class);
        try {
            $pipeline->execute(
                new ContentPipelineContext(
                    EntityId::fromString(str_repeat('1', 32)),
                    'forum.post',
                    "bad\x00body",
                    100000,
                ),
                $this->time(),
                static fn (ContentPipelineContext $context): ContentPipelinePersisted =>
                    new ContentPipelinePersisted(
                        new PipelineValue($context->text),
                        'forum.post',
                        EntityId::fromString(str_repeat('c', 32)),
                    ),
            );
        } finally {
            self::assertNotContains('tx.begin', $trace->events);
            self::assertNotContains('persist', $trace->events);
        }
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18 19:30:00', new DateTimeZone('UTC'));
    }
}

final class PipelineTrace
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class PipelineValue
{
    public function __construct(public string $text)
    {
    }
}

class RecordingPipelineProcessor implements ContentPipelineProcessor
{
    public function __construct(
        private readonly ContentPipelineStage $pipelineStage,
        protected readonly PipelineTrace $trace,
    ) {
    }

    public function stage(): ContentPipelineStage
    {
        return $this->pipelineStage;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $this->trace->events[] = $this->pipelineStage->value;
        return $context;
    }
}

final class RecordingAfterPipelineProcessor extends RecordingPipelineProcessor implements ContentPipelineAfterPersistProcessor
{
    public function afterPersist(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        $this->trace->events[] = 'after:' . $this->stage()->value;
    }
}

final readonly class RecordingPipelineNotifier implements ContentPipelineNotifier
{
    public function __construct(private PipelineTrace $trace)
    {
    }

    public function notify(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        $this->trace->events[] = 'notify';
    }
}

final readonly class RecordingPipelineIndexer implements ContentPipelineIndexer
{
    public function __construct(
        private PipelineTrace $trace,
        private bool $fail = false,
    ) {
    }

    public function index(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        $this->trace->events[] = 'index';
        if ($this->fail) {
            throw new RuntimeException('index failed');
        }
    }
}

final class PipelineDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;

    public function __construct(private readonly PipelineTrace $trace)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        $previous = $this->inside;
        $this->inside = true;
        $this->trace->events[] = 'tx.begin';
        try {
            $result = $callback($this);
            $this->trace->events[] = 'tx.commit';
            return $result;
        } catch (\Throwable $exception) {
            $this->trace->events[] = 'tx.rollback';
            throw $exception;
        } finally {
            $this->inside = $previous;
        }
    }
}
