<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class LinkPreviewService
{
    private const MAX_REDIRECTS = 3;
    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        private LinkPreviewUrlPolicy $policy,
        private LinkPreviewTransport $transport,
    ) {
    }

    public function preview(string $url): LinkPreview
    {
        $current = $url;
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            $approved = $this->policy->approve($current);
            $response = $this->transport->fetch($approved, self::MAX_BODY_BYTES, 4);

            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($redirects === self::MAX_REDIRECTS) {
                    throw new LinkPreviewException('Link preview redirect limit exceeded.');
                }
                $location = $response->header('location');
                if ($location === null || trim($location) === '') {
                    throw new LinkPreviewException('Link preview redirect target is missing.');
                }
                $current = $this->redirectUrl($approved->url, trim($location));
                continue;
            }

            if ($response->status !== 200) {
                throw new LinkPreviewException('Link preview origin returned an unsupported status.');
            }
            $contentType = strtolower(trim(explode(';', $response->header('content-type') ?? '')[0]));
            if (!in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
                throw new LinkPreviewException('Link preview origin is not HTML.');
            }

            $title = $this->title($response->body);
            $description = $this->description($response->body);
            return new LinkPreview($approved->url, $approved->host, $title, $description);
        }

        throw new LinkPreviewException('Link preview redirect resolution failed.');
    }

    private function title(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/isu', $html, $match) !== 1) {
            return '';
        }
        return $this->text($match[1], 180);
    }

    private function description(string $html): string
    {
        $patterns = [
            '/<meta\b[^>]*\bname\s*=\s*(["\'])description\1[^>]*\bcontent\s*=\s*(["\'])(.*?)\2[^>]*>/isu',
            '/<meta\b[^>]*\bcontent\s*=\s*(["\'])(.*?)\1[^>]*\bname\s*=\s*(["\'])description\3[^>]*>/isu',
            '/<meta\b[^>]*\bproperty\s*=\s*(["\'])og:description\1[^>]*\bcontent\s*=\s*(["\'])(.*?)\2[^>]*>/isu',
        ];
        foreach ($patterns as $index => $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                $raw = $index === 1 ? ($match[2] ?? '') : ($match[3] ?? '');
                return $this->text((string) $raw, 400);
            }
        }
        return '';
    }

    private function text(string $html, int $maxCharacters): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (!is_string($text) || $text === '') {
            return '';
        }
        $count = preg_match_all('/./us', $text, $unused);
        if ($count !== false && $count > $maxCharacters) {
            preg_match('/\A.{0,' . $maxCharacters . '}/us', $text, $match);
            $text = rtrim($match[0] ?? '') . '…';
        }
        return $text;
    }

    private function redirectUrl(string $base, string $location): string
    {
        if (preg_match('/\Ahttps:\/\//i', $location) === 1) {
            return $location;
        }
        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $location) === 1 || str_starts_with($location, '//')) {
            throw new LinkPreviewException('Link preview redirect scheme is not allowed.');
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new LinkPreviewException('Link preview redirect base is invalid.');
        }
        $origin = 'https://' . (string) $parts['host'];
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $directory = str_ends_with($path, '/') ? $path : substr($path, 0, (int) strrpos($path, '/') + 1);
        return $origin . $directory . $location;
    }
}
