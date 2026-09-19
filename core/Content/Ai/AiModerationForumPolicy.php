<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AiModerationForumPolicy
{
    public function __construct(
        public EntityId $forumNodeId,
        public bool $enabled = true,
        public string $providerKey = 'openai',
        public string $promptVersion = 'core.v1',
        public bool $redactSensitiveData = true,
        public float $flagThreshold = 0.25,
        public float $queueThreshold = 0.50,
        public float $rejectThreshold = 0.85,
        public int $inputMicrosPerMillionTokens = 0,
        public int $outputMicrosPerMillionTokens = 0,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $this->promptVersion) !== 1
        ) {
            throw new InvalidArgumentException('AI moderation forum policy provider or prompt is invalid.');
        }
        foreach ([$this->flagThreshold, $this->queueThreshold, $this->rejectThreshold] as $threshold) {
            if (!is_finite($threshold) || $threshold < 0.0 || $threshold > 1.0) {
                throw new InvalidArgumentException('AI moderation forum policy thresholds are invalid.');
            }
        }
        if (!($this->flagThreshold <= $this->queueThreshold && $this->queueThreshold <= $this->rejectThreshold)) {
            throw new InvalidArgumentException('AI moderation forum policy thresholds must be ordered.');
        }
        if ($this->inputMicrosPerMillionTokens < 0 || $this->outputMicrosPerMillionTokens < 0) {
            throw new InvalidArgumentException('AI moderation forum policy pricing is invalid.');
        }
    }

    public function moderationPolicy(): AiModerationPolicy
    {
        return new AiModerationPolicy($this->flagThreshold, $this->queueThreshold, $this->rejectThreshold);
    }

    public function costPolicy(): AiModerationCostPolicy
    {
        return new AiModerationCostPolicy(
            $this->inputMicrosPerMillionTokens,
            $this->outputMicrosPerMillionTokens,
        );
    }
}
