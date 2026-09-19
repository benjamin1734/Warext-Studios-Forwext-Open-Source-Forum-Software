<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Ai\AiModerationAction;
use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationDecisionRecord;
use Forwext\Core\Content\Ai\AiModerationDecisionStore;
use Forwext\Core\Content\Ai\AiModerationHumanOverride;
use Forwext\Core\Content\Ai\AiModerationOverrideRepository;
use Forwext\Core\Content\Ai\AiModerationPipelineProcessor;
use Forwext\Core\Content\Ai\AiModerationPolicy;
use Forwext\Core\Content\Ai\AiModerationPolicyProcessor;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationProviderRegistry;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\AiModerationService;
use Forwext\Core\Content\Ai\AiModerationTimeoutException;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ContentPipelineRejectedException;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class AiModerationDomainTest extends TestCase
{
    public function testRiskThresholdsProduceAllFourActions(): void
    {
        $policy = new AiModerationPolicy(0.25, 0.50, 0.85);

        self::assertSame(AiModerationAction::Allow, $policy->decide($this->assessment(0.10))->action);
        self::assertSame(AiModerationAction::Flag, $policy->decide($this->assessment(0.30))->action);
        self::assertSame(AiModerationAction::Queue, $policy->decide($this->assessment(0.60))->action);
        self::assertSame(AiModerationAction::Reject, $policy->decide($this->assessment(0.90))->action);
    }

    public function testTimeoutFallbackIsQueuedAndHumanOverrideWins(): void
    {
        $provider = new TimeoutModerationProvider();
        $service = new AiModerationService(
            new AiModerationProviderRegistry([$provider]),
            $provider->key(),
            1000,
            0.5,
        );
        $assessment = $service->evaluate(new AiModerationRequest('forum.post', 'Content'));

        self::assertSame('timeout', $assessment->fallbackReason);
        self::assertSame(AiModerationAction::Queue, (new AiModerationPolicy())->decide($assessment)->action);

        $override = new AiModerationHumanOverride(
            hash('sha256', 'fingerprint'),
            AiModerationAction::Allow,
            EntityId::fromString(str_repeat('1', 32)),
            'Human reviewed exact content.',
            $this->time(),
        );
        self::assertSame(
            AiModerationAction::Allow,
            (new AiModerationPolicy())->decide($this->assessment(0.99), $override)->action,
        );
    }

    public function testPipelineQueueSetsReviewAndPersistsDecisionWithRealTarget(): void
    {
        $provider = new FixedModerationProvider($this->assessment(0.60));
        $service = new AiModerationService(new AiModerationProviderRegistry([$provider]), $provider->key());
        $ai = new AiModerationPipelineProcessor($service);
        $decisions = new RecordingDecisionStore();
        $policy = new AiModerationPolicyProcessor(
            new AiModerationPolicy(),
            new MemoryOverrideRepository(),
            $decisions,
        );
        $context = new ContentPipelineContext(
            EntityId::fromString(str_repeat('1', 32)),
            'forum.post',
            'Queue this',
            100000,
        );

        $context = $ai->process($context, $this->time());
        $context = $policy->process($context, $this->time());

        self::assertTrue($context->requiresReview);
        self::assertSame('queue', $context->attributes['ai.action'] ?? null);

        $target = EntityId::fromString(str_repeat('a', 32));
        $policy->afterPersist(
            $context,
            new ContentPipelinePersisted(new \stdClass(), 'forum.post', $target),
            $this->time(),
        );

        self::assertCount(1, $decisions->records);
        self::assertSame($target->value(), $decisions->records[0]->targetId?->value());
        self::assertSame(AiModerationAction::Queue, $decisions->records[0]->action);
    }

    public function testRejectIsRecordedWithoutTargetBeforePersistence(): void
    {
        $provider = new FixedModerationProvider($this->assessment(0.95));
        $service = new AiModerationService(new AiModerationProviderRegistry([$provider]), $provider->key());
        $ai = new AiModerationPipelineProcessor($service);
        $decisions = new RecordingDecisionStore();
        $policy = new AiModerationPolicyProcessor(
            new AiModerationPolicy(),
            new MemoryOverrideRepository(),
            $decisions,
        );
        $context = $ai->process(new ContentPipelineContext(
            EntityId::fromString(str_repeat('1', 32)),
            'forum.thread',
            'Reject this',
            200,
        ), $this->time());

        try {
            $policy->process($context, $this->time());
            self::fail('High-risk content must be rejected.');
        } catch (ContentPipelineRejectedException) {
            self::assertCount(1, $decisions->records);
            self::assertSame(AiModerationAction::Reject, $decisions->records[0]->action);
            self::assertNull($decisions->records[0]->targetId);
        }
    }

    private function assessment(float $risk): AiModerationAssessment
    {
        return new AiModerationAssessment('fake', 'fake-model', $risk, ['spam'=>$risk]);
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18 20:30:00', new DateTimeZone('UTC'));
    }
}

final readonly class FixedModerationProvider implements AiModerationProvider
{
    public function __construct(private AiModerationAssessment $assessment)
    {
    }

    public function key(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment
    {
        return $this->assessment;
    }
}

final readonly class TimeoutModerationProvider implements AiModerationProvider
{
    public function key(): string
    {
        return 'timeout';
    }

    public function model(): string
    {
        return 'timeout-model';
    }

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment
    {
        throw new AiModerationTimeoutException('timeout');
    }
}

final class RecordingDecisionStore implements AiModerationDecisionStore
{
    /** @var list<AiModerationDecisionRecord> */
    public array $records = [];

    public function record(AiModerationDecisionRecord $record): void
    {
        $this->records[] = $record;
    }
}

final class MemoryOverrideRepository implements AiModerationOverrideRepository
{
    /** @var array<string,AiModerationHumanOverride> */
    private array $items = [];

    public function active(string $contentFingerprint, DateTimeImmutable $at): ?AiModerationHumanOverride
    {
        $override = $this->items[$contentFingerprint] ?? null;
        return $override !== null && $override->isActive($at) ? $override : null;
    }

    public function save(AiModerationHumanOverride $override): void
    {
        $this->items[$override->contentFingerprint] = $override;
    }

    public function delete(string $contentFingerprint): bool
    {
        if (!isset($this->items[$contentFingerprint])) {
            return false;
        }
        unset($this->items[$contentFingerprint]);
        return true;
    }
}
