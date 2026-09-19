<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Database\TransactionalQueryExecutor;
use InvalidArgumentException;
use LogicException;

final readonly class ContentPipeline
{
    /** @var array<string, ContentPipelineProcessor> */
    private array $processors;

    /**
     * @param list<ContentPipelineProcessor> $processors
     */
    public function __construct(
        private TransactionalQueryExecutor $database,
        array $processors,
        private ContentPipelineNotifier $notifier = new NullContentPipelineNotifier(),
        private ContentPipelineIndexer $indexer = new NullContentPipelineIndexer(),
    ) {
        $registered = [];
        foreach ($processors as $processor) {
            if (!$processor instanceof ContentPipelineProcessor) {
                throw new InvalidArgumentException('Content pipeline processors are invalid.');
            }
            $stage = $processor->stage();
            if (!in_array($stage, ContentPipelineStage::prePersist(), true)) {
                throw new InvalidArgumentException('Persist/notify/index are controlled by the pipeline engine.');
            }
            if (isset($registered[$stage->value])) {
                throw new InvalidArgumentException('Content pipeline stage has more than one processor: ' . $stage->value);
            }
            $registered[$stage->value] = $processor;
        }

        foreach (ContentPipelineStage::prePersist() as $stage) {
            if (!isset($registered[$stage->value])) {
                throw new InvalidArgumentException('Content pipeline stage is missing: ' . $stage->value);
            }
        }
        $this->processors = $registered;
    }

    /**
     * @param Closure(ContentPipelineContext): ContentPipelinePersisted $persist
     */
    public function preprocess(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        $current = $context;
        foreach (ContentPipelineStage::prePersist() as $stage) {
            $processor = $this->processors[$stage->value]
                ?? throw new LogicException('Content pipeline processor registry is incomplete.');
            $current = $processor->process($current, $at);
        }
        return $current;
    }

    public function execute(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
        Closure $persist,
    ): object {
        $current = $this->preprocess($context, $at);

        /** @var ContentPipelinePersisted $persisted */
        $persisted = $this->database->transaction(function () use ($current, $at, $persist): ContentPipelinePersisted {
            $persisted = $persist($current);
            if (!$persisted instanceof ContentPipelinePersisted) {
                throw new LogicException('Content pipeline persistence callback returned an invalid result.');
            }

            foreach (ContentPipelineStage::prePersist() as $stage) {
                $processor = $this->processors[$stage->value]
                    ?? throw new LogicException('Content pipeline processor registry is incomplete.');
                if ($processor instanceof ContentPipelineAfterPersistProcessor) {
                    $processor->afterPersist($current, $persisted, $at);
                }
            }

            $this->notifier->notify($current, $persisted, $at);
            $this->indexer->index($current, $persisted, $at);
            return $persisted;
        });

        return $persisted->value;
    }
}
