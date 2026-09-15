<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Totp;

use Forwext\Core\Auth\Mfa\MfaException;

final class Totp
{
    public static function code(string $secret, int $counter, int $digits = 6): string
    {
        if ($secret === '' || $counter < 0 || $digits < 6 || $digits > 8) {
            throw new MfaException('TOTP input is invalid.');
        }

        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $message = pack('N2', $high, $low);
        $digest = hash_hmac('sha1', $message, $secret, true);
        $offset = ord($digest[19]) & 0x0f;
        $binary = (
            ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff)
        );
        $modulo = 10 ** $digits;

        return str_pad((string) ($binary % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    public static function matchingCounter(
        string $secret,
        string $code,
        int $unixTime,
        int $period = 30,
        int $window = 1,
        int $digits = 6,
    ): ?int {
        if (preg_match('/^[0-9]{' . $digits . '}$/D', $code) !== 1 || $unixTime < 0 || $period < 15 || $window < 0 || $window > 5) {
            return null;
        }

        $current = intdiv($unixTime, $period);
        for ($offset = -$window; $offset <= $window; ++$offset) {
            $counter = $current + $offset;
            if ($counter >= 0 && hash_equals(self::code($secret, $counter, $digits), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
