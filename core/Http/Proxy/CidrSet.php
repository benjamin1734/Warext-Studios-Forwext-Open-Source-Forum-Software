<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Proxy;

use Forwext\Core\Http\HttpException;

final readonly class CidrSet
{
    /** @var list<array{network: string, prefix: int, bytes: int}> */
    private array $ranges;

    /** @param list<string> $cidrs */
    public function __construct(array $cidrs = [])
    {
        $ranges = [];
        foreach ($cidrs as $cidr) {
            $ranges[] = $this->parse($cidr);
        }
        $this->ranges = $ranges;
    }

    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->ranges as $range) {
            if (strlen($packed) !== $range['bytes']) {
                continue;
            }

            $fullBytes = intdiv($range['prefix'], 8);
            $remainingBits = $range['prefix'] % 8;

            if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($range['network'], 0, $fullBytes)) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($packed[$fullBytes]) & $mask) === (ord($range['network'][$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{network: string, prefix: int, bytes: int} */
    private function parse(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new HttpException('Trusted proxy CIDR may not be empty.');
        }

        [$networkText, $prefixText] = array_pad(explode('/', $cidr, 2), 2, null);
        $network = @inet_pton($networkText);
        if ($network === false) {
            throw new HttpException(sprintf('Invalid trusted proxy address "%s".', $networkText));
        }

        $bits = strlen($network) * 8;
        $prefix = $prefixText === null ? $bits : filter_var($prefixText, FILTER_VALIDATE_INT);
        if (!is_int($prefix) || $prefix < 0 || $prefix > $bits) {
            throw new HttpException(sprintf('Invalid trusted proxy prefix in "%s".', $cidr));
        }

        return ['network' => $network, 'prefix' => $prefix, 'bytes' => strlen($network)];
    }
}
