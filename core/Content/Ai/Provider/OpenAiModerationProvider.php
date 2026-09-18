<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use SensitiveParameter;

final readonly class OpenAiModerationProvider implements AiModerationProvider
{
    private string $credential;
    private string $providerModel;

    public function __construct(
        private AiModerationHttpTransport $transport,
        private AiModerationEndpoint $endpoint,
        #[SensitiveParameter] string $credential,
        string $model,
    ) {
        $this->credential = AiModerationProviderSupport::assertCredential($credential);
        $this->providerModel = AiModerationProviderSupport::assertModel($model);
    }

    public function key(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->providerModel;
    }

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment
    {
        $response = $this->transport->postJson(
            $this->endpoint,
            ['Authorization'=>'Bearer ' . $this->credential],
            AiModerationProviderSupport::json([
                'model'=>$this->providerModel,
                'input'=>$request->text,
            ]),
            $timeoutMilliseconds,
        );
        $object = AiModerationProviderSupport::object($response);
        $scores = $object['results'][0]['category_scores'] ?? null;
        if (!is_array($scores) || array_is_list($scores) || $scores === []) {
            throw new AiModerationProviderException('OpenAI moderation response category scores are invalid.');
        }

        $categories = [];
        $risk = 0.0;
        foreach ($scores as $key=>$score) {
            if (!is_string($key)) {
                throw new AiModerationProviderException('OpenAI moderation category key is invalid.');
            }
            $normalized = AiModerationProviderSupport::categoryKey($key);
            $value = AiModerationProviderSupport::score($score);
            $categories[$normalized] = $value;
            $risk = max($risk, $value);
        }
        ksort($categories, SORT_STRING);
        return new AiModerationAssessment($this->key(), $this->providerModel, $risk, $categories);
    }
}
