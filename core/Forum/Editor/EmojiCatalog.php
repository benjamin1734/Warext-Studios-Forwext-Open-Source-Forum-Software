<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final class EmojiCatalog
{
    /** @return array<string,array{emoji:string,label:string}> */
    public static function all(): array
    {
        return [
            'smile' => ['emoji' => '😄', 'label' => 'Gülümseme'],
            'grin' => ['emoji' => '😁', 'label' => 'Sırıtma'],
            'laugh' => ['emoji' => '😂', 'label' => 'Kahkaha'],
            'wink' => ['emoji' => '😉', 'label' => 'Göz kırpma'],
            'heart' => ['emoji' => '❤️', 'label' => 'Kalp'],
            'like' => ['emoji' => '👍', 'label' => 'Beğeni'],
            'dislike' => ['emoji' => '👎', 'label' => 'Beğenmeme'],
            'sad' => ['emoji' => '😢', 'label' => 'Üzgün'],
            'angry' => ['emoji' => '😠', 'label' => 'Kızgın'],
            'surprised' => ['emoji' => '😮', 'label' => 'Şaşkın'],
            'thinking' => ['emoji' => '🤔', 'label' => 'Düşünen'],
            'fire' => ['emoji' => '🔥', 'label' => 'Ateş'],
            'party' => ['emoji' => '🎉', 'label' => 'Kutlama'],
            'check' => ['emoji' => '✅', 'label' => 'Onay'],
            'warning' => ['emoji' => '⚠️', 'label' => 'Uyarı'],
            'eyes' => ['emoji' => '👀', 'label' => 'Gözler'],
        ];
    }

    public static function tag(string $key): ?string
    {
        $entry = self::all()[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        return '<span class="fx-emoji" role="img" aria-label="' . self::escape($entry['label']) . '">'
            . $entry['emoji'] . '</span>';
    }

    public static function renderAliases(string $escapedText): string
    {
        $named = [
            ':smile:' => 'smile', ':grin:' => 'grin', ':laugh:' => 'laugh', ':wink:' => 'wink',
            ':heart:' => 'heart', ':like:' => 'like', ':sad:' => 'sad', ':angry:' => 'angry',
            ':thinking:' => 'thinking', ':fire:' => 'fire', ':party:' => 'party',
        ];
        foreach ($named as $alias => $key) {
            $escapedText = str_replace($alias, self::tag($key) ?? $alias, $escapedText);
        }
        $simple = [':-)' => 'smile', ':)' => 'smile', ':-D' => 'grin', ':D' => 'grin', ';-)' => 'wink', ';)' => 'wink', ':-(' => 'sad', ':(' => 'sad'];
        foreach ($simple as $alias => $key) {
            $replacement = self::tag($key) ?? $alias;
            $escapedText = preg_replace(
                '/(?<![\p{L}\p{N}:])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}:])/u',
                $replacement,
                $escapedText,
            ) ?? $escapedText;
        }
        return $escapedText;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
