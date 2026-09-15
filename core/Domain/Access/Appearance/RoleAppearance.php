<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class RoleAppearance
{
    private EntityId $roleId;
    private ?RoleColor $textColor;
    private ?RoleColor $gradientFrom;
    private ?RoleColor $gradientTo;
    private int $gradientAngle;
    private ?RoleAppearanceIcon $icon;
    private ?string $bannerText;
    private ?RoleColor $bannerColor;
    private RoleAppearancePattern $pattern;
    private RoleAppearanceAnimation $animation;
    private bool $showMobile;
    private bool $showProfile;
    private bool $showPosts;

    public function __construct(
        EntityId $roleId,
        ?RoleColor $textColor = null,
        ?RoleColor $gradientFrom = null,
        ?RoleColor $gradientTo = null,
        int $gradientAngle = 90,
        ?RoleAppearanceIcon $icon = null,
        ?string $bannerText = null,
        ?RoleColor $bannerColor = null,
        RoleAppearancePattern $pattern = RoleAppearancePattern::None,
        RoleAppearanceAnimation $animation = RoleAppearanceAnimation::None,
        bool $showMobile = true,
        bool $showProfile = true,
        bool $showPosts = true,
    ) {
        if (($gradientFrom === null) !== ($gradientTo === null)) {
            throw new InvalidArgumentException('Role gradient requires both start and end colors.');
        }
        if ($gradientAngle < 0 || $gradientAngle > 360) {
            throw new InvalidArgumentException('Role gradient angle must be between 0 and 360 degrees.');
        }

        $bannerText = $bannerText === null ? null : trim($bannerText);
        if ($bannerText === '') {
            $bannerText = null;
        }
        if (
            $bannerText !== null
            && (strlen($bannerText) > 64 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $bannerText) === 1)
        ) {
            throw new InvalidArgumentException('Role banner text must contain at most 64 UTF-8 bytes and no control characters.');
        }

        $this->roleId = $roleId;
        $this->textColor = $textColor;
        $this->gradientFrom = $gradientFrom;
        $this->gradientTo = $gradientTo;
        $this->gradientAngle = $gradientAngle;
        $this->icon = $icon;
        $this->bannerText = $bannerText;
        $this->bannerColor = $bannerColor;
        $this->pattern = $pattern;
        $this->animation = $animation;
        $this->showMobile = $showMobile;
        $this->showProfile = $showProfile;
        $this->showPosts = $showPosts;
    }

    public function roleId(): EntityId
    {
        return $this->roleId;
    }

    public function textColor(): ?RoleColor
    {
        return $this->textColor;
    }

    public function gradientFrom(): ?RoleColor
    {
        return $this->gradientFrom;
    }

    public function gradientTo(): ?RoleColor
    {
        return $this->gradientTo;
    }

    public function gradientAngle(): int
    {
        return $this->gradientAngle;
    }

    public function icon(): ?RoleAppearanceIcon
    {
        return $this->icon;
    }

    public function bannerText(): ?string
    {
        return $this->bannerText;
    }

    public function bannerColor(): ?RoleColor
    {
        return $this->bannerColor;
    }

    public function pattern(): RoleAppearancePattern
    {
        return $this->pattern;
    }

    public function animation(): RoleAppearanceAnimation
    {
        return $this->animation;
    }

    public function showMobile(): bool
    {
        return $this->showMobile;
    }

    public function showProfile(): bool
    {
        return $this->showProfile;
    }

    public function showPosts(): bool
    {
        return $this->showPosts;
    }

    public function hasGradient(): bool
    {
        return $this->gradientFrom !== null && $this->gradientTo !== null;
    }

    public function isVisibleIn(RoleDisplayContext $context, bool $mobile): bool
    {
        if ($mobile && !$this->showMobile) {
            return false;
        }

        return match ($context) {
            RoleDisplayContext::Profile => $this->showProfile,
            RoleDisplayContext::Post => $this->showPosts,
        };
    }
}
