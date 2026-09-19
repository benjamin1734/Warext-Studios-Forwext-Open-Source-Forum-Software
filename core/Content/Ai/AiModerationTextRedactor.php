<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

final class AiModerationTextRedactor
{
    public function redact(string $text): AiModerationRedactionResult
    {
        $redacted = $text;
        $patterns = [
            '/(?<![\pL\pN._%+-])[\pL\pN._%+-]+@[\pL\pN.-]+\.[\pL]{2,63}(?![\pL\pN.-])/iu' => '[EMAIL]',
            '/(?<!\d)(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\d)/' => '[IP]',
            '/(?<!\d)(?:\+?90[\s.-]?)?(?:0[\s.-]?)?5\d{2}(?:[\s.-]?\d{3})(?:[\s.-]?\d{2}){2}(?!\d)/' => '[PHONE]',
            '/\b(?:sk|pk|rk|api)[-_][A-Za-z0-9_-]{16,}\b/' => '[TOKEN]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $next = preg_replace($pattern, $replacement, $redacted);
            if (is_string($next)) {
                $redacted = $next;
            }
        }
        return new AiModerationRedactionResult($redacted, $redacted !== $text);
    }
}
