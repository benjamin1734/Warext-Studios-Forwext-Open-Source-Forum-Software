<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Totp;

use Forwext\Core\Auth\Mfa\MfaException;

final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            throw new MfaException('TOTP secret cannot be empty.');
        }

        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $output;
    }

    public static function decode(string $encoded): string
    {
        $encoded = strtoupper(str_replace([' ', '-'], '', trim($encoded)));
        if ($encoded === '' || preg_match('/^[A-Z2-7]+$/D', $encoded) !== 1) {
            throw new MfaException('TOTP secret encoding is invalid.');
        }

        $buffer = 0;
        $bits = 0;
        $output = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; ++$i) {
            $value = strpos(self::ALPHABET, $encoded[$i]);
            if ($value === false) {
                throw new MfaException('TOTP secret encoding is invalid.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }

        return $output;
    }
}
