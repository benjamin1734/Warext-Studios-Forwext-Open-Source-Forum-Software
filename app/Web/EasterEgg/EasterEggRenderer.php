<?php

declare(strict_types=1);

namespace Forwext\App\Web\EasterEgg;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\EasterEgg\EasterEggAnimation;
use Forwext\Core\EasterEgg\EasterEggDefinition;

final class EasterEggRenderer
{
    /** @param list<EasterEggDefinition> $definitions */
    public function render(array $definitions): string
    {
        if ($definitions === []) {
            return '';
        }

        $items = '';
        foreach (array_slice($definitions, 0, 3) as $definition) {
            $class = match ($definition->animation) {
                EasterEggAnimation::None => '',
                EasterEggAnimation::Pulse => ' fx-egg-pulse',
                EasterEggAnimation::Glow => ' fx-egg-glow',
                EasterEggAnimation::Confetti => ' fx-egg-confetti',
            };
            $badge = $definition->badgeLabel === null
                ? ''
                : '<span class="fx-egg-badge">' . ProfileHtml::escape($definition->badgeLabel) . '</span>';

            $items .= '<aside class="fx-egg' . $class . '" data-easter-egg="'
                . ProfileHtml::escape($definition->key) . '" role="status" aria-live="polite">'
                . $badge
                . '<strong>' . ProfileHtml::escape($definition->name) . '</strong>'
                . '<p>' . nl2br(ProfileHtml::escape($definition->message), false) . '</p>'
                . '</aside>';
        }

        return '<style>'
            . '.fx-egg-stack{position:fixed;right:18px;bottom:82px;z-index:45;width:min(360px,calc(100vw - 36px));'
            . 'display:grid;gap:10px;pointer-events:none}.fx-egg{position:relative;overflow:hidden;padding:14px 16px;'
            . 'border:1px solid #ff7a1a66;border-radius:14px;background:#161b22f2;color:#e6edf3;'
            . 'box-shadow:0 12px 38px #0009;pointer-events:auto}.fx-egg strong{display:block;padding-right:72px}.fx-egg p{margin:6px 0 0;color:#c9d1d9}'
            . '.fx-egg-badge{position:absolute;right:10px;top:10px;padding:3px 7px;border-radius:999px;background:#ff7a1a;color:#111;'
            . 'font-size:11px;font-weight:850}.fx-egg-pulse{animation:fxEggPulse 1.8s ease-in-out 2}.fx-egg-glow{animation:fxEggGlow 2.2s ease-in-out 3}'
            . '.fx-egg-confetti:before{content:"✦  ·  ◆  ·  ✧  ·  ●";display:block;color:#ffb36f;letter-spacing:5px;'
            . 'font-size:12px;margin-bottom:6px;animation:fxEggConfetti 1.6s ease-out 2}'
            . '@keyframes fxEggPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.025)}}'
            . '@keyframes fxEggGlow{0%,100%{box-shadow:0 12px 38px #0009}50%{box-shadow:0 12px 42px #ff7a1a55}}'
            . '@keyframes fxEggConfetti{0%{transform:translateY(-10px);opacity:0}35%{opacity:1}100%{transform:translateY(5px);opacity:.7}}'
            . '@media(prefers-reduced-motion:reduce){.fx-egg-pulse,.fx-egg-glow,.fx-egg-confetti:before{animation:none}}'
            . '@media(max-width:620px){.fx-egg-stack{right:12px;bottom:74px;width:calc(100vw - 24px)}}'
            . '</style><div class="fx-egg-stack" data-easter-egg-stack>' . $items . '</div>';
    }
}
