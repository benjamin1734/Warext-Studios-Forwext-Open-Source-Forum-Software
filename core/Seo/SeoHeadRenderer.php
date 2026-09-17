<?php

declare(strict_types=1);

namespace Forwext\Core\Seo;

use JsonException;
use RuntimeException;

final class SeoHeadRenderer
{
    public static function render(?SeoMetadata $metadata): string
    {
        if ($metadata === null || !$metadata->indexable) {
            return '<meta data-forwext-seo="1" name="robots" content="noindex,nofollow">';
        }

        $head = '<meta data-forwext-seo="1" name="description" content="'
            . self::escape($metadata->description) . '">'
            . '<meta name="robots" content="index,follow">'
            . '<meta property="og:title" content="' . self::escape($metadata->title) . '">'
            . '<meta property="og:description" content="' . self::escape($metadata->description) . '">'
            . '<meta property="og:type" content="' . self::escape($metadata->openGraphType) . '">'
            . '<meta name="twitter:card" content="summary">';

        if ($metadata->canonicalUrl !== null) {
            $safeCanonical = self::escape($metadata->canonicalUrl);
            $head .= '<link rel="canonical" href="' . $safeCanonical . '">'
                . '<meta property="og:url" content="' . $safeCanonical . '">';
        }

        foreach ($metadata->structuredData as $document) {
            try {
                $json = json_encode(
                    $document,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to encode SEO structured data.', previous: $exception);
            }
            $head .= '<script type="application/ld+json">' . $json . '</script>';
        }

        return $head;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
