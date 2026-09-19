<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class CustomAiModerationProvider implements AiModerationProvider
{
    private string $providerKey;
    private string $providerModel;
    private ?string $credential;

    public function __construct(
        private AiModerationHttpTransport $transport,
        private AiModerationEndpoint $endpoint,
        string $providerKey,
        string $model,
        #[SensitiveParameter] ?string $credential = null,
    ) {
        $providerKey = strtolower(trim($providerKey));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('Custom AI moderation provider key is invalid.');
        }
        $this->providerKey = $providerKey;
        $this->providerModel = AiModerationProviderSupport::assertModel($model);
        $this->credential = $credential === null ? null : AiModerationProviderSupport::assertCredential($credential);
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
        $headers = [];
        if ($this->credential !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->credential;
        }
        $response = $this->transport->postJson(
            $this->endpoint,
            $headers,
            AiModerationProviderSupport::json([
                'model'=>$this->providerModel,
                'content_type'=>$request->contentType,
                'prompt_version'=>$request->prompt->version,
                'system_prompt'=>$request->prompt->systemPrompt,
                'text'=>$request->text,
            ]),
            $timeoutMilliseconds,
        );
        $object = AiModerationProviderSupport::object($response);
        $risk = AiModerationProviderSupport::score($object['risk_score'] ?? null);
        $raw = $object['categories'] ?? [];
        if (!is_array($raw) || array_is_list($raw)) {
            throw new AiModerationProviderException('Custom AI moderation categories are invalid.');
        }
        $categories = [];
        foreach ($raw as $key=>$score) {
            if (!is_string($key)) {
                throw new AiModerationProviderException('Custom AI moderation category key is invalid.');
            }
            $categories[AiModerationProviderSupport::categoryKey($key)] =
                AiModerationProviderSupport::score($score);
        }
        ksort($categories, SORT_STRING);
        return new AiModerationAssessment(
            $this->providerKey,
            $this->providerModel,
            $risk,
            $categories,
            null,
            AiModerationProviderSupport::openAiLikeUsage($object),
            $request->prompt->version,
        );
    }
}
