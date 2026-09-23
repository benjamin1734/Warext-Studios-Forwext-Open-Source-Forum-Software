<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use InvalidArgumentException;

final readonly class LayoutPlacementCondition
{
    /** @var list<LayoutDevice> */
    public array $devices;

    /**
     * @param list<LayoutDevice> $devices
     */
    public function __construct(
        public string $routePattern = '*',
        public LayoutAudience $audience = LayoutAudience::All,
        array $devices = [
            LayoutDevice::Desktop,
            LayoutDevice::Tablet,
            LayoutDevice::Mobile,
        ],
    ) {
        if (
            strlen($this->routePattern) < 1
            || strlen($this->routePattern) > 128
            || preg_match('/^[A-Za-z0-9*][A-Za-z0-9._*:-]*$/D', $this->routePattern) !== 1
        ) {
            throw new InvalidArgumentException('Layout route condition is invalid.');
        }

        $normalized = [];
        foreach ($devices as $device) {
            if (!$device instanceof LayoutDevice) {
                throw new InvalidArgumentException('Layout device condition is invalid.');
            }
            $normalized[$device->value] = $device;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Layout condition must target at least one device.');
        }

        ksort($normalized, SORT_STRING);
        $this->devices = array_values($normalized);
    }

    public function matches(string $routeName, bool $authenticated, LayoutDevice $device): bool
    {
        if (!in_array($device, $this->devices, true)) {
            return false;
        }

        if (
            ($this->audience === LayoutAudience::Guest && $authenticated)
            || ($this->audience === LayoutAudience::Member && !$authenticated)
        ) {
            return false;
        }

        if ($this->routePattern === '*') {
            return true;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($this->routePattern, '/')) . '$/D';
        return preg_match($regex, $routeName) === 1;
    }

    /** @return array{route:string,audience:string,devices:list<string>} */
    public function toArray(): array
    {
        return [
            'route' => $this->routePattern,
            'audience' => $this->audience->value,
            'devices' => array_map(
                static fn (LayoutDevice $device): string => $device->value,
                $this->devices,
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        $route = $data['route'] ?? '*';
        $audience = $data['audience'] ?? 'all';
        $devices = $data['devices'] ?? ['desktop', 'tablet', 'mobile'];

        if (!is_string($route) || !is_string($audience) || !is_array($devices) || !array_is_list($devices)) {
            throw new InvalidArgumentException('Layout condition shape is invalid.');
        }

        $typedDevices = [];
        foreach ($devices as $device) {
            if (!is_string($device)) {
                throw new InvalidArgumentException('Layout device condition is invalid.');
            }
            $typedDevices[] = LayoutDevice::from($device);
        }

        return new self($route, LayoutAudience::from($audience), $typedDevices);
    }
}
