<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationCostPolicy;
use Forwext\Core\Content\Ai\AiModerationForumPolicy;
use Forwext\Core\Content\Ai\AiModerationForumPolicyRepository;
use Forwext\Core\Content\Ai\AiModerationMetric;
use Forwext\Core\Content\Ai\AiModerationMetricsStore;
use Forwext\Core\Content\Ai\AiModerationPipelineProcessor;
use Forwext\Core\Content\Ai\AiModerationPrompt;
use Forwext\Core\Content\Ai\AiModerationPromptRegistry;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationProviderRegistry;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\AiModerationService;
use Forwext\Core\Content\Ai\AiModerationTextRedactor;
use Forwext\Core\Content\Ai\AiModerationUsage;
use Forwext\Core\Content\Ai\SecretStoreAiModerationCredentialStore;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class AiModerationPrivacyCostPolicyTest extends TestCase
{
    public function testRedactorRemovesCommonSensitiveIdentifiers(): void
    {
        $result = (new AiModerationTextRedactor())->redact(
            'Mail user@example.com ip 192.168.1.10 tel +90 555 111 22 33 token sk-test_abcdefghijklmnop.',
        );

        self::assertTrue($result->redacted);
        self::assertStringNotContainsString('user@example.com', $result->text);
        self::assertStringNotContainsString('192.168.1.10', $result->text);
        self::assertStringNotContainsString('555 111 22 33', $result->text);
        self::assertStringNotContainsString('sk-test_abcdefghijklmnop', $result->text);
        self::assertStringContainsString('[EMAIL]', $result->text);
        self::assertStringContainsString('[IP]', $result->text);
        self::assertStringContainsString('[PHONE]', $result->text);
        self::assertStringContainsString('[TOKEN]', $result->text);
    }

    public function testCredentialAdapterUsesSecretStoreNamespace(): void
    {
        $secrets = new MemoryAiSecretStore();
        $credentials = new SecretStoreAiModerationCredentialStore($secrets);
        $credentials->set('openai', 'secret-value');

        self::assertSame('secret-value', $credentials->get('openai'));
        self::assertArrayHasKey('ai.provider.openai.credential', $secrets->all());
        self::assertTrue($credentials->delete('openai'));
        self::assertNull($credentials->get('openai'));
    }

    public function testForumPolicySelectsProviderPromptRedactionAndCost(): void
    {
        $provider = new CaptureAiProvider('alt', 'alt-model', new AiModerationUsage(100, 50));
        $prompts = new AiModerationPromptRegistry([
            new AiModerationPrompt('forum.v2', 'Classify forum content and return strict JSON moderation risk scores only.'),
        ]);
        $service = new AiModerationService(
            new AiModerationProviderRegistry([$provider]),
            'alt',
            1000,
            0.5,
            new AiModerationTextRedactor(),
            $prompts,
            new AiModerationCostPolicy(),
        );
        $policy = new AiModerationForumPolicy(
            EntityId::fromString(str_repeat('a', 32)),
            true,
            'alt',
            'forum.v2',
            true,
            0.20,
            0.40,
            0.80,
            10_000_000,
            20_000_000,
        );

        $assessment = $service->evaluateForPolicy(
            new AiModerationRequest('forum.post', 'Reach me at user@example.com'),
            $policy,
        );

        self::assertSame('alt', $assessment->providerKey);
        self::assertSame('forum.v2', $assessment->promptVersion);
        self::assertTrue($assessment->redacted);
        self::assertSame(2000, $assessment->usage->costMicros);
        self::assertStringContainsString('[EMAIL]', $provider->lastRequest?->text ?? '');
        self::assertSame('forum.v2', $provider->lastRequest?->prompt->version);
    }

    public function testPipelineRecordsForumScopedUsageMetric(): void
    {
        $forumId = EntityId::fromString(str_repeat('b', 32));
        $provider = new CaptureAiProvider('alt', 'alt-model', new AiModerationUsage(20, 10));
        $policy = new AiModerationForumPolicy(
            $forumId,
            true,
            'alt',
            'core.v1',
            true,
            0.25,
            0.50,
            0.85,
            1_000_000,
            2_000_000,
        );
        $service = new AiModerationService(
            new AiModerationProviderRegistry([$provider]),
            'alt',
        );
        $metrics = new MemoryAiMetricsStore();
        $processor = new AiModerationPipelineProcessor(
            $service,
            new MemoryAiForumPolicyRepository($policy),
            $metrics,
        );
        $context = new ContentPipelineContext(
            EntityId::fromString(str_repeat('1', 32)),
            'forum.post',
            'Normal content',
            100000,
            false,
            ['forum.node_id'=>$forumId->value()],
        );

        $processed = $processor->process($context, $this->time());

        self::assertSame('alt', $processed->attributes['ai.provider'] ?? null);
        self::assertSame(40, $processed->attributes['ai.cost_micros'] ?? null);
        self::assertCount(1, $metrics->items);
        self::assertSame($forumId->value(), $metrics->items[0]->forumNodeId?->value());
        self::assertSame(40, $metrics->items[0]->usage->costMicros);
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 07:30:00', new DateTimeZone('UTC'));
    }
}

final class CaptureAiProvider implements AiModerationProvider
{
    public ?AiModerationRequest $lastRequest = null;

    public function __construct(
        private readonly string $providerKey,
        private readonly string $providerModel,
        private readonly AiModerationUsage $usage,
    ) {
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function model(): string
    {
        return $this->providerModel;
    }

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment
    {
        $this->lastRequest = $request;
        return new AiModerationAssessment(
            $this->providerKey,
            $this->providerModel,
            0.10,
            ['spam'=>0.10],
            null,
            $this->usage,
            $request->prompt->version,
        );
    }
}

final readonly class MemoryAiForumPolicyRepository implements AiModerationForumPolicyRepository
{
    public function __construct(private AiModerationForumPolicy $policy)
    {
    }

    public function find(EntityId $forumNodeId): ?AiModerationForumPolicy
    {
        return $forumNodeId->equals($this->policy->forumNodeId) ? $this->policy : null;
    }
}

final class MemoryAiMetricsStore implements AiModerationMetricsStore
{
    /** @var list<AiModerationMetric> */
    public array $items = [];

    public function record(AiModerationMetric $metric): void
    {
        $this->items[] = $metric;
    }
}

final class MemoryAiSecretStore implements SecretStore
{
    /** @var array<string,string> */
    private array $items = [];

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->items);
    }

    public function get(string $name): ?string
    {
        return $this->items[$name] ?? null;
    }

    public function set(string $name, #[SensitiveParameter] string $value): void
    {
        $this->items[$name] = $value;
    }

    public function delete(string $name): bool
    {
        if (!array_key_exists($name, $this->items)) {
            return false;
        }
        unset($this->items[$name]);
        return true;
    }

    public function all(): array
    {
        return $this->items;
    }
}
