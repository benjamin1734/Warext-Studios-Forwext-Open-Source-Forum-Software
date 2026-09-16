<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final class NativeHostAddressResolver implements HostAddressResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) {
                        $addresses[] = $record['ip'];
                    }
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($addresses === [] && function_exists('gethostbynamel')) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $addresses = array_merge($addresses, $fallback);
            }
        }

        return array_values(array_unique($addresses));
    }
}
