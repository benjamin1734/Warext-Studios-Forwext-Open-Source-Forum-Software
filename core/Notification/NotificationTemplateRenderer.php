<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use InvalidArgumentException;

final class NotificationTemplateRenderer
{
    /** @param array<string, scalar|null> $variables */
    public function render(string $template, array $variables): string
    {
        $rendered = preg_replace_callback(
            '/\{\{([A-Za-z][A-Za-z0-9_.-]{0,63})\}\}/',
            static function (array $match) use ($variables): string {
                $key = $match[1];
                if (!array_key_exists($key, $variables)) {
                    throw new InvalidArgumentException(sprintf('Missing notification template variable "%s".', $key));
                }
                $value = $variables[$key];
                return $value === null ? '' : (string) $value;
            },
            $template,
        );
        if ($rendered === null) throw new InvalidArgumentException('Notification template rendering failed.');
        return $rendered;
    }
}
