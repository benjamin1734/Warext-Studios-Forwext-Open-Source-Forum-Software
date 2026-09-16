<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BbCodeRenderer
{
    private const MAX_SOURCE_BYTES = 100000;
    private const MAX_NESTING_DEPTH = 32;
    private const MAX_PLAIN64_BYTES = 65536;
    private const MAX_PLAIN64_ATTRIBUTE_BYTES = 87382;

    public function __construct(
        private SafeEditorLinkPolicy $links,
        private MentionResolver $mentions,
        private EmbedResolver $embeds,
    ) {
    }

    public function render(string $source): string
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new InvalidArgumentException('BBCode source exceeds the supported render limit.');
        }
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('BBCode source must be valid UTF-8.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1) {
            throw new InvalidArgumentException('BBCode source contains unsupported control characters.');
        }

        $offset = 0;
        [$html] = $this->renderSequence($source, $offset, null, 0);
        return '<div class="fx-rich-text">' . $html . '</div>';
    }

    /** @return array{0:string,1:bool} */
    private function renderSequence(string $source, int &$offset, ?string $untilTag, int $depth): array
    {
        $html = '';
        $length = strlen($source);
        while ($offset < $length) {
            $next = strpos($source, '[', $offset);
            if ($next === false) {
                $html .= $this->escapeText(substr($source, $offset));
                $offset = $length;
                return [$html, false];
            }
            if ($next > $offset) {
                $html .= $this->escapeText(substr($source, $offset, $next - $offset));
                $offset = $next;
            }

            if (preg_match('/\G\[(\/?)\s*([a-z0-9]+)(?:=([^\]\r\n]+))?\]/i', $source, $match, 0, $offset) !== 1) {
                $html .= '&#91;';
                ++$offset;
                continue;
            }

            $token = $match[0];
            $closing = $match[1] === '/';
            $tag = strtolower($match[2]);
            $attribute = array_key_exists(3, $match) ? trim($match[3]) : null;
            $offset += strlen($token);

            if ($closing) {
                if ($untilTag !== null && $tag === $untilTag) {
                    return [$html, true];
                }
                $html .= $this->escapeText($token);
                continue;
            }

            if ($tag === 'plain64') {
                $html .= $this->renderPlain64($attribute);
                continue;
            }

            if ($tag === 'code') {
                $close = stripos($source, '[/code]', $offset);
                if ($close === false) {
                    $html .= $this->escapeText($token);
                    continue;
                }
                $raw = substr($source, $offset, $close - $offset);
                $html .= '<pre class="fx-bbcode-code"><code>' . $this->escape($raw) . '</code></pre>';
                $offset = $close + 7;
                continue;
            }

            if ($tag === 'mention') {
                $html .= $this->renderMention($attribute);
                continue;
            }

            if ($tag === 'emoji') {
                $key = $attribute === null ? '' : strtolower(trim($attribute, " \t\n\r\0\x0B\"'"));
                $html .= EmojiCatalog::tag($key) ?? '<span class="fx-emoji fx-emoji--missing">�</span>';
                continue;
            }

            if ($tag === 'embed') {
                $html .= $this->renderEmbed($source, $offset, $attribute, $token);
                continue;
            }

            if ($tag === 'url' && $attribute === null) {
                $html .= $this->renderSimpleUrl($source, $offset, $token);
                continue;
            }

            if (!in_array($tag, ['b', 'i', 'u', 's', 'quote', 'url'], true)) {
                $html .= $this->escapeText($token);
                continue;
            }

            if ($depth >= self::MAX_NESTING_DEPTH) {
                $html .= $this->escapeText($token);
                continue;
            }

            [$inner, $closed] = $this->renderSequence($source, $offset, $tag, $depth + 1);
            if (!$closed) {
                $html .= $this->escapeText($token) . $inner;
                return [$html, false];
            }

            $html .= $this->wrapContainer($tag, $attribute, $inner);
        }

        return [$html, false];
    }

    private function renderPlain64(?string $attribute): string
    {
        if ($attribute === null
            || strlen($attribute) > self::MAX_PLAIN64_ATTRIBUTE_BYTES
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $attribute) !== 1
        ) {
            return '<span class="fx-bbcode-plain fx-bbcode-plain--invalid"></span>';
        }
        $padded = strtr($attribute, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($padded, true);
        if (!is_string($decoded)
            || strlen($decoded) > self::MAX_PLAIN64_BYTES
            || preg_match('//u', $decoded) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded) === 1
        ) {
            return '<span class="fx-bbcode-plain fx-bbcode-plain--invalid"></span>';
        }
        return '<span class="fx-bbcode-plain">' . $this->escapeText($decoded) . '</span>';
    }

    private function wrapContainer(string $tag, ?string $attribute, string $inner): string
    {
        return match ($tag) {
            'b' => '<strong>' . $inner . '</strong>',
            'i' => '<em>' . $inner . '</em>',
            'u' => '<u>' . $inner . '</u>',
            's' => '<s>' . $inner . '</s>',
            'quote' => $this->renderQuote($attribute, $inner),
            'url' => $this->renderAttributedUrl($attribute, $inner),
            default => $inner,
        };
    }

    private function renderQuote(?string $attribute, string $inner): string
    {
        $label = $attribute === null ? '' : trim($attribute, " \t\n\r\0\x0B\"'");
        $footer = $label === '' ? '' : '<footer class="fx-bbcode-quote__author">' . $this->escape($label) . '</footer>';
        return '<blockquote class="fx-bbcode-quote">' . $footer . '<div>' . $inner . '</div></blockquote>';
    }

    private function renderAttributedUrl(?string $attribute, string $inner): string
    {
        $raw = $attribute === null ? '' : trim($attribute, " \t\n\r\0\x0B\"'");
        $url = $this->links->normalize($raw);
        if ($url === null) {
            return '<span class="fx-bbcode-link--invalid">' . $inner . '</span>';
        }
        return '<a class="fx-bbcode-link" href="' . $this->escape($url)
            . '" rel="nofollow ugc noopener">' . $inner . '</a>';
    }

    private function renderSimpleUrl(string $source, int &$offset, string $token): string
    {
        $close = stripos($source, '[/url]', $offset);
        if ($close === false) {
            return $this->escapeText($token);
        }
        $raw = trim(substr($source, $offset, $close - $offset));
        $offset = $close + 6;
        $url = $this->links->normalize($raw);
        if ($url === null) {
            return '<span class="fx-bbcode-link--invalid">' . $this->escapeText($raw) . '</span>';
        }
        return '<a class="fx-bbcode-link" href="' . $this->escape($url)
            . '" rel="nofollow ugc noopener">' . $this->escape($raw) . '</a>';
    }

    private function renderMention(?string $attribute): string
    {
        if ($attribute === null) {
            return '<span class="fx-mention fx-mention--missing">@mention</span>';
        }
        $id = trim($attribute, " \t\n\r\0\x0B\"'");
        try {
            $target = $this->mentions->resolve(UserId::fromStored($id));
        } catch (InvalidArgumentException) {
            $target = null;
        }
        if ($target === null) {
            return '<span class="fx-mention fx-mention--missing">@mention</span>';
        }
        $url = $this->links->normalize($target->url);
        if ($url === null) {
            return '<span class="fx-mention">' . $this->escape($target->label) . '</span>';
        }
        return '<a class="fx-mention" href="' . $this->escape($url) . '">' . $this->escape($target->label) . '</a>';
    }

    private function renderEmbed(string $source, int &$offset, ?string $attribute, string $token): string
    {
        if ($attribute !== null) {
            $raw = trim($attribute, " \t\n\r\0\x0B\"'");
        } else {
            $close = stripos($source, '[/embed]', $offset);
            if ($close === false) {
                return $this->escapeText($token);
            }
            $raw = trim(substr($source, $offset, $close - $offset));
            $offset = $close + 8;
        }

        $target = $this->embeds->resolve($raw);
        if ($target === null) {
            return '<span class="fx-embed fx-embed--invalid">' . $this->escape($raw) . '</span>';
        }
        return '<figure class="fx-embed"><a href="' . $this->escape($target->url)
            . '" rel="nofollow ugc noopener" target="_blank"><span class="fx-embed__label">'
            . $this->escape($target->label) . '</span><span class="fx-embed__url">'
            . $this->escape($target->url) . '</span></a></figure>';
    }

    private function escapeText(string $value): string
    {
        $normalized = str_replace("\r\n", "\n", str_replace("\r", "\n", $value));
        $escaped = EmojiCatalog::renderAliases($this->escape($normalized));
        return str_replace("\n", "<br>\n", $escaped);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
