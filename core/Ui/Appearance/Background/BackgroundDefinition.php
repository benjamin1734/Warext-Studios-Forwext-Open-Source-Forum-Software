<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

use InvalidArgumentException;

final readonly class BackgroundDefinition
{
    /** @var list<string> */
    public array $colorTokens;

    /**
     * @param list<string> $colorTokens
     */
    public function __construct(
        public string $key,
        public BackgroundScope $scope,
        public ?string $scopeId,
        public BackgroundKind $kind,
        array $colorTokens,
        public ?BackgroundAssetPath $asset,
        public ?BackgroundPattern $pattern,
        public int $opacityPercent = 100,
        public int $scalePercent = 100,
        public int $rotationDegrees = 0,
        public BackgroundBlendMode $blendMode = BackgroundBlendMode::Normal,
        public bool $animatedGradient = false,
        public int $animationDurationMs = 12000,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Background key is invalid.');
        }

        if ($this->scope->requiresEntityId()) {
            if ($this->scopeId === null || preg_match('/^[a-f0-9]{32}$/D', $this->scopeId) !== 1) {
                throw new InvalidArgumentException('Scoped background requires a 128-bit entity id.');
            }
        } elseif ($this->scopeId !== null) {
            throw new InvalidArgumentException('Global background scope cannot carry an entity id.');
        }

        if ($this->opacityPercent < 0 || $this->opacityPercent > 100) {
            throw new InvalidArgumentException('Background opacity must be between 0 and 100.');
        }

        if ($this->scalePercent < 25 || $this->scalePercent > 400) {
            throw new InvalidArgumentException('Background scale must be between 25 and 400 percent.');
        }

        if ($this->rotationDegrees < -360 || $this->rotationDegrees > 360) {
            throw new InvalidArgumentException('Background rotation must be between -360 and 360 degrees.');
        }

        if ($this->animationDurationMs < 1000 || $this->animationDurationMs > 120000) {
            throw new InvalidArgumentException('Background animation duration is outside supported bounds.');
        }

        $colors = [];
        foreach ($colorTokens as $token) {
            if (
                !is_string($token)
                || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $token) !== 1
            ) {
                throw new InvalidArgumentException('Background color token is invalid.');
            }
            $colors[] = $token;
        }
        $this->colorTokens = $colors;

        $this->assertKindPayload();
    }

    private function assertKindPayload(): void
    {
        if ($this->kind === BackgroundKind::Solid) {
            if (count($this->colorTokens) !== 1 || $this->asset !== null || $this->pattern !== null) {
                throw new InvalidArgumentException('Solid backgrounds require exactly one color token.');
            }
            if ($this->animatedGradient) {
                throw new InvalidArgumentException('Solid backgrounds cannot enable gradient animation.');
            }
            return;
        }

        if ($this->kind === BackgroundKind::Gradient) {
            if (
                count($this->colorTokens) < 2
                || count($this->colorTokens) > 4
                || $this->asset !== null
                || $this->pattern !== null
            ) {
                throw new InvalidArgumentException('Gradient backgrounds require two to four color tokens.');
            }
            return;
        }

        if ($this->kind === BackgroundKind::Image) {
            if ($this->colorTokens !== [] || $this->asset === null || $this->pattern !== null) {
                throw new InvalidArgumentException('Image backgrounds require one safe raster asset.');
            }
            if ($this->animatedGradient) {
                throw new InvalidArgumentException('Image backgrounds cannot enable gradient animation.');
            }
            return;
        }

        if (
            count($this->colorTokens) < 1
            || count($this->colorTokens) > 2
            || $this->asset !== null
            || $this->pattern === null
            || $this->animatedGradient
        ) {
            throw new InvalidArgumentException(
                'Pattern backgrounds require one or two color tokens and one built-in pattern.',
            );
        }
    }
}
