<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use InvalidArgumentException;

final readonly class ThemePayload
{
    public const VERSION = 1;

    /** @var array<string,string> */
    public array $templates;

    /** @var array<string,array<string,string>> */
    public array $phrases;

    /**
     * @param array<string,string> $templates
     * @param array<string,array<string,string>> $phrases
     */
    public function __construct(
        array $templates,
        array $phrases,
        public string $customCss = '',
        public string $customJs = '',
    ) {
        if (count($templates) > 100) {
            throw new InvalidArgumentException('Theme cannot contain more than 100 templates.');
        }

        $normalizedTemplates = [];
        foreach ($templates as $key => $source) {
            self::assertKey($key, 'template');
            if (!is_string($source) || strlen($source) > 262_144) {
                throw new InvalidArgumentException('Theme template source is invalid.');
            }
            $normalizedTemplates[$key] = $source;
        }
        ksort($normalizedTemplates, SORT_STRING);
        $this->templates = $normalizedTemplates;

        if (count($phrases) > 32) {
            throw new InvalidArgumentException('Theme cannot contain more than 32 languages.');
        }

        $normalizedPhrases = [];
        $totalPhrases = 0;
        foreach ($phrases as $locale => $entries) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) !== 1) {
                throw new InvalidArgumentException('Theme phrase locale is invalid.');
            }
            if (!is_array($entries) || array_is_list($entries)) {
                throw new InvalidArgumentException('Theme phrase dictionary must be an object.');
            }

            $dictionary = [];
            foreach ($entries as $key => $value) {
                self::assertKey($key, 'phrase');
                if (!is_string($value) || strlen($value) > 4096 || preg_match('//u', $value) !== 1) {
                    throw new InvalidArgumentException('Theme phrase value is invalid.');
                }
                $dictionary[$key] = $value;
                ++$totalPhrases;
            }
            ksort($dictionary, SORT_STRING);
            $normalizedPhrases[$locale] = $dictionary;
        }
        if ($totalPhrases > 5000) {
            throw new InvalidArgumentException('Theme phrase count exceeds the supported limit.');
        }
        ksort($normalizedPhrases, SORT_STRING);
        $this->phrases = $normalizedPhrases;

        if (strlen($this->customCss) > 262_144 || str_contains(strtolower($this->customCss), '@import')) {
            throw new InvalidArgumentException('Theme custom CSS is invalid.');
        }
        if (preg_match('/url\s*\(\s*[\'"]?\s*(?:https?:|javascript:|data:)/i', $this->customCss) === 1) {
            throw new InvalidArgumentException('Theme custom CSS may not reference external or executable URLs.');
        }

        if (strlen($this->customJs) > 131_072 || str_contains($this->customJs, '</script')) {
            throw new InvalidArgumentException('Theme custom JavaScript is invalid.');
        }
    }

    /** @return array{version:int,templates:array<string,string>,phrases:array<string,array<string,string>>,custom_css:string,custom_js:string} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'templates' => $this->templates,
            'phrases' => $this->phrases,
            'custom_css' => $this->customCss,
            'custom_js' => $this->customJs,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported theme payload version.');
        }

        $templates = $data['templates'] ?? null;
        $phrases = $data['phrases'] ?? null;
        $css = $data['custom_css'] ?? '';
        $js = $data['custom_js'] ?? '';
        if (
            !is_array($templates)
            || array_is_list($templates)
            || !is_array($phrases)
            || array_is_list($phrases)
            || !is_string($css)
            || !is_string($js)
        ) {
            throw new InvalidArgumentException('Theme payload shape is invalid.');
        }

        /** @var array<string,string> $templates */
        /** @var array<string,array<string,string>> $phrases */
        return new self($templates, $phrases, $css, $js);
    }

    public static function merge(self $parent, self $child): self
    {
        $templates = array_replace($parent->templates, $child->templates);
        $phrases = $parent->phrases;
        foreach ($child->phrases as $locale => $entries) {
            $phrases[$locale] = array_replace($phrases[$locale] ?? [], $entries);
        }

        return new self(
            $templates,
            $phrases,
            trim($parent->customCss . "\n" . $child->customCss),
            trim($parent->customJs . "\n" . $child->customJs),
        );
    }

    public function phrase(string $locale, string $key, string $fallbackLocale = 'tr'): ?string
    {
        return $this->phrases[$locale][$key]
            ?? $this->phrases[$fallbackLocale][$key]
            ?? null;
    }

    private static function assertKey(mixed $key, string $label): void
    {
        if (
            !is_string($key)
            || strlen($key) < 3
            || strlen($key) > 96
            || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $key) !== 1
        ) {
            throw new InvalidArgumentException('Theme ' . $label . ' key is invalid.');
        }
    }
}
