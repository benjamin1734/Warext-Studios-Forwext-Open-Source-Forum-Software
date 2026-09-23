<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

final class ThemeDiffService
{
    /** @return list<ThemeDiffEntry> */
    public function diff(ThemePayload $before, ThemePayload $after): array
    {
        $left = $this->flatten($before);
        $right = $this->flatten($after);
        $keys = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        sort($keys, SORT_STRING);

        $diff = [];
        foreach ($keys as $key) {
            $old = $left[$key] ?? null;
            $new = $right[$key] ?? null;
            if ($old === $new) {
                continue;
            }
            $diff[] = new ThemeDiffEntry(
                $key,
                $this->preview($old),
                $this->preview($new),
            );
        }

        return $diff;
    }

    /** @return array<string,string> */
    private function flatten(ThemePayload $payload): array
    {
        $flat = [];
        foreach ($payload->templates as $key => $value) {
            $flat['template.' . $key] = $value;
        }
        foreach ($payload->phrases as $locale => $entries) {
            foreach ($entries as $key => $value) {
                $flat['phrase.' . $locale . '.' . $key] = $value;
            }
        }
        $flat['custom.css'] = $payload->customCss;
        $flat['custom.js'] = $payload->customJs;

        return $flat;
    }

    private function preview(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= 500) {
            return $value;
        }

        return substr($value, 0, 500) . '…';
    }
}
