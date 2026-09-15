<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class SafeLinkEmbedResolver implements EmbedResolver
{
    public function __construct(private SafeEditorLinkPolicy $links)
    {
    }

    public function resolve(string $url): ?EmbedTarget
    {
        $normalized = $this->links->normalize($url);
        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);
        $label = is_array($parts) && isset($parts['host'])
            ? strtolower((string) $parts['host'])
            : 'Forwext';

        return new EmbedTarget($normalized, $label);
    }
}
