<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use InvalidArgumentException;

final class ThemeTemplateCompiler
{
    public function compile(string $templateKey, string $source): string
    {
        $this->assertTemplateKey($templateKey);
        if (strlen($source) > 262_144 || preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('Theme template source is invalid.');
        }

        $parts = preg_split(
            '/(\{\{\s*[A-Za-z][A-Za-z0-9_.-]{0,63}\s*\}\})/',
            $source,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if ($parts === false) {
            throw new InvalidArgumentException('Theme template could not be tokenized.');
        }

        $expressions = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{\{\s*([A-Za-z][A-Za-z0-9_.-]{0,63})\s*\}\}$/D', $part, $match) === 1) {
                $key = var_export($match[1], true);
                $expressions[] = "htmlspecialchars((string) (\$context[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')";
                continue;
            }
            $expressions[] = var_export($part, true);
        }

        $body = $expressions === [] ? "''" : implode(' . ', $expressions);

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "return static function (array \$context): string {\n"
            . "    return " . $body . ";\n"
            . "};\n";
    }

    /** @param array<string,scalar|null> $context */
    public function render(string $source, array $context): string
    {
        $rendered = preg_replace_callback(
            '/\{\{\s*([A-Za-z][A-Za-z0-9_.-]{0,63})\s*\}\}/',
            static function (array $match) use ($context): string {
                $value = $context[$match[1]] ?? '';
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $source,
        );

        if ($rendered === null) {
            throw new InvalidArgumentException('Theme template render failed.');
        }

        return $rendered;
    }

    private function assertTemplateKey(string $templateKey): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $templateKey) !== 1) {
            throw new InvalidArgumentException('Theme template key is invalid.');
        }
    }
}
