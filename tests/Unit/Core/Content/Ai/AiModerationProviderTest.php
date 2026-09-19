<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Ai;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\Provider\AnthropicAiModerationProvider;
use Forwext\Core\Content\Ai\Provider\CustomAiModerationProvider;
use Forwext\Core\Content\Ai\Provider\GeminiAiModerationProvider;
use Forwext\Core\Content\Ai\Provider\OpenAiModerationProvider;
use Forwext\Core\Content\Ai\Provider\OpenRouterAiModerationProvider;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpointPolicy;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpResponse;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use Forwext\Core\Content\Ai\Transport\PinnedHttpsAiModerationTransport;
use Forwext\Core\Forum\Editor\HostAddressResolver;
use PHPUnit\Framework\TestCase;

final class AiModerationProviderTest extends TestCase
{
    public function testOpenAiNormalizesCategoryScores(): void
    {
        $transport = new RecordingAiTransport(new AiModerationHttpResponse(200, [], json_encode([
            'results'=>[[
                'category_scores'=>[
                    'harassment'=>0.2,
                    'self-harm/intent'=>0.8,
                ],
            ]],
        ], JSON_THROW_ON_ERROR)));
        $provider = new OpenAiModerationProvider($transport, $this->endpoint(), 'secret', 'moderation-model');

        $assessment = $provider->assess(new AiModerationRequest('forum.post', 'content'), 1000);

        self::assertSame(0.8, $assessment->riskScore);
        self::assertSame(0.8, $assessment->categories['self-harm_intent']);
        self::assertStringContainsString('"input":"content"', $transport->json ?? '');
        self::assertSame('Bearer secret', $transport->headers['Authorization'] ?? null);
    }

    public function testGeminiAnthropicOpenRouterAndCustomNormalizeClassifierJson(): void
    {
        $classifier = '{"risk_score":0.61,"categories":{"spam":0.61,"harassment":0.2}}';

        $geminiTransport = new RecordingAiTransport(new AiModerationHttpResponse(200, [], json_encode([
            'candidates'=>[['content'=>['parts'=>[['text'=>$classifier]]]]],
        ], JSON_THROW_ON_ERROR)));
        $gemini = new GeminiAiModerationProvider($geminiTransport, $this->endpoint(), 'g-key', 'gemini-model');
        self::assertSame(0.61, $gemini->assess(new AiModerationRequest('forum.post', 'body'), 1000)->riskScore);
        self::assertSame('g-key', $geminiTransport->headers['x-goog-api-key'] ?? null);

        $anthropicTransport = new RecordingAiTransport(new AiModerationHttpResponse(200, [], json_encode([
            'content'=>[['type'=>'text','text'=>$classifier]],
        ], JSON_THROW_ON_ERROR)));
        $anthropic = new AnthropicAiModerationProvider(
            $anthropicTransport,
            $this->endpoint(),
            'a-key',
            'anthropic-model',
        );
        self::assertSame(0.61, $anthropic->assess(new AiModerationRequest('forum.post', 'body'), 1000)->riskScore);
        self::assertSame('2023-06-01', $anthropicTransport->headers['anthropic-version'] ?? null);

        $routerTransport = new RecordingAiTransport(new AiModerationHttpResponse(200, [], json_encode([
            'choices'=>[['message'=>['content'=>$classifier]]],
        ], JSON_THROW_ON_ERROR)));
        $router = new OpenRouterAiModerationProvider(
            $routerTransport,
            $this->endpoint(),
            'r-key',
            'router-model',
        );
        self::assertSame(0.61, $router->assess(new AiModerationRequest('forum.post', 'body'), 1000)->riskScore);
        self::assertSame('Bearer r-key', $routerTransport->headers['Authorization'] ?? null);

        $customTransport = new RecordingAiTransport(new AiModerationHttpResponse(200, [], $classifier));
        $custom = new CustomAiModerationProvider(
            $customTransport,
            $this->endpoint(),
            'custom_provider',
            'custom-model',
            'c-key',
        );
        $assessment = $custom->assess(new AiModerationRequest('forum.thread', 'title'), 1000);
        self::assertSame(0.61, $assessment->riskScore);
        self::assertSame('custom_provider', $assessment->providerKey);
        self::assertStringContainsString('"content_type":"forum.thread"', $customTransport->json ?? '');
    }

    public function testEndpointPolicyRejectsPrivateResolutionAndApprovesPinnedPublicAddress(): void
    {
        try {
            (new AiModerationEndpointPolicy(new StaticHostResolver(['127.0.0.1'])))
                ->approve('https://provider.example/v1/moderate');
            self::fail('Private provider resolution must be rejected.');
        } catch (AiModerationProviderException $exception) {
            self::assertStringContainsString('non-public', $exception->getMessage());
        }

        $approved = (new AiModerationEndpointPolicy(new StaticHostResolver(['93.184.216.34'])))
            ->approve('https://provider.example/v1/moderate?mode=strict');

        self::assertSame('provider.example', $approved->host);
        self::assertSame('/v1/moderate?mode=strict', $approved->requestTarget);
        self::assertSame(['93.184.216.34'], $approved->addresses);
    }

    public function testPinnedTransportRejectsInjectedPrivateEndpointBeforeNetworkAccess(): void
    {
        $transport = new PinnedHttpsAiModerationTransport();
        $this->expectException(AiModerationProviderException::class);
        $transport->postJson(
            new AiModerationEndpoint(
                'https://provider.example/v1/moderate',
                'provider.example',
                443,
                '/v1/moderate',
                ['127.0.0.1'],
            ),
            [],
            '{}',
            1000,
        );
    }

    private function endpoint(): AiModerationEndpoint
    {
        return new AiModerationEndpoint(
            'https://provider.example/v1/moderate',
            'provider.example',
            443,
            '/v1/moderate',
            ['93.184.216.34'],
        );
    }
}

final class RecordingAiTransport implements AiModerationHttpTransport
{
    /** @var array<string,string> */
    public array $headers = [];
    public ?string $json = null;

    public function __construct(private readonly AiModerationHttpResponse $response)
    {
    }

    public function postJson(
        AiModerationEndpoint $endpoint,
        array $headers,
        string $json,
        int $timeoutMilliseconds,
        int $maxResponseBytes = 524288,
    ): AiModerationHttpResponse {
        $this->headers = $headers;
        $this->json = $json;
        return $this->response;
    }
}

final readonly class StaticHostResolver implements HostAddressResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses)
    {
    }

    public function resolve(string $host): array
    {
        return $this->addresses;
    }
}
