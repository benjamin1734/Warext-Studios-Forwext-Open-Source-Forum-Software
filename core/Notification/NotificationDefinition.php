<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use InvalidArgumentException;

final readonly class NotificationDefinition
{
    /** @param list<NotificationChannel> $defaultChannels */
    public function __construct(
        public string $typeKey,
        public string $categoryKey,
        public string $titleTemplate,
        public string $bodyTemplate,
        public array $defaultChannels = [NotificationChannel::InApp],
    ) {
        self::assertKey($typeKey, 'type');
        self::assertKey($categoryKey, 'category');
        if ($titleTemplate === '' || strlen($titleTemplate) > 255) {
            throw new InvalidArgumentException('Notification title template must be 1-255 characters.');
        }
        if ($bodyTemplate === '' || strlen($bodyTemplate) > 2000) {
            throw new InvalidArgumentException('Notification body template must be 1-2000 characters.');
        }
        if ($defaultChannels === []) {
            throw new InvalidArgumentException('Notification definition requires at least one default channel.');
        }
        $seen = [];
        foreach ($defaultChannels as $channel) {
            if (!$channel instanceof NotificationChannel) {
                throw new InvalidArgumentException('Notification default channels are invalid.');
            }
            if (isset($seen[$channel->value])) {
                throw new InvalidArgumentException('Notification default channels must be unique.');
            }
            $seen[$channel->value] = true;
        }
    }

    private static function assertKey(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Notification %s key is invalid.', $label));
        }
    }
}
