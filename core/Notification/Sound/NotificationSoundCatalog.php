<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use InvalidArgumentException;

final readonly class NotificationSoundCatalog
{
    /** @param array<string, string> $presets */
    public function __construct(private array $presets)
    {
        if ($presets === []) throw new InvalidArgumentException('Notification sound catalog cannot be empty.');
        foreach ($presets as $key => $label) {
            if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $key) !== 1 || trim($label) === '' || strlen($label) > 64) {
                throw new InvalidArgumentException('Notification sound preset is invalid.');
            }
        }
    }

    public static function coreDefaults(): self
    {
        return new self([
            'soft' => 'Soft',
            'chime' => 'Chime',
            'pulse' => 'Pulse',
            'minimal' => 'Minimal',
        ]);
    }

    public function supports(string $key): bool
    {
        return isset($this->presets[$key]);
    }

    public function require(string $key): string
    {
        if (!$this->supports($key)) throw new InvalidArgumentException('Unsupported notification sound preset.');
        return $key;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->presets;
    }

    public function defaultKey(): string
    {
        if (isset($this->presets['soft'])) return 'soft';
        $key = array_key_first($this->presets);
        if (!is_string($key)) throw new InvalidArgumentException('Notification sound catalog has no default preset.');
        return $key;
    }
}
