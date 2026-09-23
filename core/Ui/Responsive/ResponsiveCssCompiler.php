<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

final class ResponsiveCssCompiler
{
    public function compile(ResponsiveRegistry $registry): string
    {
        $grouped = [];
        foreach ($registry->rules() as $rule) {
            $grouped[$rule->breakpoint][] = $rule;
        }

        $css = '';
        foreach ($registry->breakpoints() as $breakpoint) {
            $rules = $grouped[$breakpoint->key] ?? [];
            if ($rules === []) {
                continue;
            }

            $body = '';
            foreach ($rules as $rule) {
                $selector = $this->selector($rule->target);
                $body .= $selector . '{font-size:' . $rule->fontScalePercent . '%;'
                    . $this->visibility($rule->visibility)
                    . $this->layout($rule->layout)
                    . $this->spacing($rule->target, $rule->spacingScalePercent)
                    . '}';

                if ($rule->target === ResponsiveTarget::Profile) {
                    $body .= '.profilebody{padding-inline:'
                        . self::pixels(24, $rule->spacingScalePercent)
                        . ';padding-block-end:'
                        . self::pixels(26, $rule->spacingScalePercent)
                        . '}';
                }

                if ($rule->target === ResponsiveTarget::Table) {
                    $body .= 'th,td{padding:'
                        . self::pixels(8, $rule->spacingScalePercent)
                        . '}';
                }
            }

            $css .= '@media ' . $breakpoint->mediaQuery() . '{' . $body . '}';
        }

        $mobile = $registry->breakpoint('mobile');
        $mobileQuery = $mobile->mediaQuery();
        $css .= '.nav-toggle{display:none;align-items:center;gap:8px;margin-inline-start:auto;border:'
            . 'var(--forwext-component-button-border-width) solid var(--forwext-component-button-border-color);'
            . 'border-radius:var(--forwext-component-button-radius);background:var(--forwext-component-button-background);'
            . 'color:var(--forwext-component-button-text);padding:8px 11px;font:inherit;cursor:pointer}'
            . '@media ' . $mobileQuery . '{.nav-toggle{display:inline-flex}.topin{flex-wrap:wrap}'
            . '.nav{width:100%}'
            . 'html[data-forwext-mobile-nav="enhanced"] .nav[data-mobile-open="0"]{display:none!important}'
            . 'html[data-forwext-mobile-nav="enhanced"] .nav[data-mobile-open="1"]{display:flex!important}}'
            . '.skip-link{position:fixed;inset-block-start:8px;inset-inline-start:8px;z-index:1000;'
            . 'padding:8px 12px;border-radius:var(--forwext-component-button-radius);'
            . 'background:var(--forwext-component-button-accent);color:var(--forwext-component-button-contrast-text);'
            . 'transform:translateY(-160%)}.skip-link:focus{transform:none}'
            . ':focus-visible{outline:2px solid var(--forwext-semantic-accent-primary);outline-offset:3px}'
            . '[dir="rtl"] .nav{direction:rtl}'
            . '@media (prefers-reduced-motion: reduce){html:focus-within{scroll-behavior:auto}'
            . '*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;'
            . 'transition-duration:.01ms!important;scroll-behavior:auto!important}}';

        return $css;
    }

    private function selector(ResponsiveTarget $target): string
    {
        return match ($target) {
            ResponsiveTarget::Site => 'body',
            ResponsiveTarget::Header => '.topin',
            ResponsiveTarget::Navigation => '.nav',
            ResponsiveTarget::Main => '.wrap',
            ResponsiveTarget::Forum => '.card',
            ResponsiveTarget::Profile => '.profile',
            ResponsiveTarget::Sidebar => '[data-forwext-responsive-target="sidebar"]',
            ResponsiveTarget::Editor => '.rich-editor',
            ResponsiveTarget::Table => 'table',
        };
    }

    private function visibility(ResponsiveVisibility $visibility): string
    {
        return match ($visibility) {
            ResponsiveVisibility::Inherit => '',
            ResponsiveVisibility::Visible => 'visibility:visible;',
            ResponsiveVisibility::Hidden => 'display:none!important;',
        };
    }

    private function layout(ResponsiveLayout $layout): string
    {
        return match ($layout) {
            ResponsiveLayout::Auto => '',
            ResponsiveLayout::Stack => 'display:flex;flex-direction:column;',
            ResponsiveLayout::Row => 'display:flex;flex-direction:row;',
            ResponsiveLayout::Grid => 'display:grid;',
        };
    }

    private function spacing(ResponsiveTarget $target, int $percent): string
    {
        return match ($target) {
            ResponsiveTarget::Site => '',
            ResponsiveTarget::Header => 'gap:' . self::pixels(18, $percent) . ';',
            ResponsiveTarget::Navigation => 'gap:' . self::pixels(16, $percent) . ';',
            ResponsiveTarget::Main => 'margin-block-start:' . self::pixels(32, $percent)
                . ';margin-block-end:' . self::pixels(64, $percent) . ';',
            ResponsiveTarget::Forum => 'padding:' . self::pixels(20, $percent) . ';',
            ResponsiveTarget::Profile => '',
            ResponsiveTarget::Sidebar => 'gap:' . self::pixels(12, $percent) . ';',
            ResponsiveTarget::Editor => 'padding:' . self::pixels(12, $percent) . ';',
            ResponsiveTarget::Table => '',
        };
    }

    private static function pixels(int $base, int $percent): string
    {
        $value = number_format($base * ($percent / 100), 2, '.', '');
        return rtrim(rtrim($value, '0'), '.') . 'px';
    }
}
