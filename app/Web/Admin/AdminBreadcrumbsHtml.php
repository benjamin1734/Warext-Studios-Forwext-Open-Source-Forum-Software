<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final class AdminBreadcrumbsHtml
{
    /**
     * @param list<array{label:string,path:?string}> $crumbs
     */
    public static function render(array $crumbs, BasePath $basePath): string
    {
        if ($crumbs === []) {
            return '';
        }

        $items = [];
        foreach ($crumbs as $index => $crumb) {
            $label = $crumb['label'] ?? null;
            $path = $crumb['path'] ?? null;
            if (!is_string($label) || $label === '' || strlen($label) > 100) {
                throw new InvalidArgumentException('ACP breadcrumb label is invalid.');
            }
            if ($path !== null && (
                !is_string($path)
                || ($path !== '/admin' && !str_starts_with($path, '/admin/'))
                || str_contains($path, '?')
                || str_contains($path, '#')
                || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            )) {
                throw new InvalidArgumentException('ACP breadcrumb path is invalid.');
            }

            $last = $index === array_key_last($crumbs);
            $content = $path !== null && !$last
                ? '<a href="' . self::escape($basePath->prepend($path)) . '">' . self::escape($label) . '</a>'
                : '<span' . ($last ? ' aria-current="page"' : '') . '>' . self::escape($label) . '</span>';
            $items[] = '<li>' . $content . '</li>';
        }

        return '<nav class="acp-breadcrumbs" aria-label="Breadcrumb"><ol>'
            . implode('', $items)
            . '</ol></nav>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
