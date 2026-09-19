<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\AiModerationUsage;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use SensitiveParameter;

abstract class AbstractPromptAiModerationProvider implements AiModerationProvider
{
    protected readonly string $providerModel;
    protected readonly string $credential;

    public function __construct(
        protected readonly AiModerationHttpTransport $transport,
        protected readonly AiModerationEndpoint $endpoint,
        #[SensitiveParameter] string $credential,
        string $model,
    ) {
        $this->credential = AiModerationProviderSupport::assertCredential($credential);
        $this->providerModel = AiModerationProviderSupport::assertModel($model);
    }

    public function model(): string
    {
        return $this->providerModel;
    }

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment
    {
        $response = $this->transport->postJson(
            $this->endpoint,
            $this->headers(),
            AiModerationProviderSupport::json($this->payload($request)),
            $timeoutMilliseconds,
        );
        $object = AiModerationProviderSupport::object($response);
        $assessment = PromptJsonModerationParser::assessment(
            $this->responseText($object),
            $this->key(),
            $this->providerModel,
        );
        return $assessment->withOperationalMetadata(
            $this->usage($object),
            $request->prompt->version,
            false,
        );
    }

    /** @return array<string,string> */
    abstract protected function headers(): array;

    /** @return array<string,mixed> */
    abstract protected function payload(AiModerationRequest $request): array;

    /** @param array<string,mixed> $response */
    abstract protected function responseText(array $response): string;

    /** @param array<string,mixed> $response */
    protected function usage(array $response): AiModerationUsage
    {
        return AiModerationProviderSupport::openAiLikeUsage($response);
    }
}
