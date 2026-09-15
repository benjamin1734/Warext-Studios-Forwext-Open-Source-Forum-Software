<?php

declare(strict_types=1);

namespace Forwext\App\Web\Access;

use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleDisplayContext;
use Forwext\Core\Domain\Access\Role;
use InvalidArgumentException;

final class RoleAppearanceHtml
{
    public function render(
        Role $role,
        RoleAppearance $appearance,
        RoleDisplayContext $context,
        bool $mobile = false,
    ): string {
        if (!$role->id()->equals($appearance->roleId())) {
            throw new InvalidArgumentException('Role appearance does not belong to the supplied role.');
        }
        if (!$appearance->isVisibleIn($context, $mobile)) {
            return '';
        }

        $classes = [
            'role-appearance',
            'role-appearance--pattern-' . $appearance->pattern()->value,
            'role-appearance--animation-' . $appearance->animation()->value,
        ];
        if ($appearance->hasGradient()) {
            $classes[] = 'role-appearance--gradient';
        }

        $styles = [];
        if ($appearance->textColor() !== null) {
            $styles[] = '--forwext-role-color:' . $appearance->textColor()->value();
        }
        if ($appearance->gradientFrom() !== null && $appearance->gradientTo() !== null) {
            $styles[] = '--forwext-role-gradient-from:' . $appearance->gradientFrom()->value();
            $styles[] = '--forwext-role-gradient-to:' . $appearance->gradientTo()->value();
            $styles[] = '--forwext-role-gradient-angle:' . $appearance->gradientAngle() . 'deg';
        }
        if ($appearance->bannerColor() !== null) {
            $styles[] = '--forwext-role-banner-color:' . $appearance->bannerColor()->value();
        }

        $html = '<span class="' . $this->escape(implode(' ', $classes)) . '"'
            . ' data-role="' . $this->escape($role->key()->value()) . '"'
            . ' data-priority="' . $role->priority() . '"'
            . ' data-context="' . $context->value . '"';
        if ($styles !== []) {
            $html .= ' style="' . $this->escape(implode(';', $styles)) . '"';
        }
        $html .= '>';

        if ($appearance->icon() !== null) {
            $icon = $appearance->icon()->value;
            $html .= '<span class="role-appearance__icon role-appearance__icon--'
                . $icon . '" aria-hidden="true"></span>';
        }

        $html .= '<span class="role-appearance__name">' . $this->escape($role->name()) . '</span>';

        if ($appearance->bannerText() !== null) {
            $html .= '<span class="role-appearance__banner">'
                . $this->escape($appearance->bannerText()) . '</span>';
        }

        return $html . '</span>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
