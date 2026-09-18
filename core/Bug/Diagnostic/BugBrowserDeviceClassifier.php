<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use Forwext\Core\Auth\AuthenticationFingerprint;

final readonly class BugBrowserDeviceClassifier
{
    public function __construct(private AuthenticationFingerprint $fingerprint)
    {
    }

    public function classify(?string $userAgent): BugBrowserDeviceSummary
    {
        $userAgent = is_string($userAgent) ? trim($userAgent) : '';
        if ($userAgent === '') {
            return new BugBrowserDeviceSummary('unknown', null, 'unknown', 'unknown', null);
        }
        if (strlen($userAgent) > 2048) {
            $userAgent = substr($userAgent, 0, 2048);
        }

        [$browser,$major] = $this->browser($userAgent);
        return new BugBrowserDeviceSummary(
            $browser,
            $major,
            $this->os($userAgent),
            $this->device($userAgent),
            $this->fingerprint->userAgent($userAgent),
        );
    }

    /** @return array{string,?int} */
    private function browser(string $ua): array
    {
        foreach ([
            ['edge','/Edg(?:A|iOS)?\/([0-9]+)/i'],
            ['opera','/OPR\/([0-9]+)/i'],
            ['firefox','/Firefox\/([0-9]+)/i'],
            ['chrome','/(?:Chrome|CriOS)\/([0-9]+)/i'],
            ['safari','/Version\/([0-9]+)(?:\.[0-9]+)*.*Safari\//i'],
        ] as [$name,$pattern]) {
            if (preg_match($pattern, $ua, $matches) === 1) {
                return [$name, isset($matches[1]) ? (int) $matches[1] : null];
            }
        }

        if (preg_match('/(?:bot|spider|crawler|slurp)/i', $ua) === 1) {
            return ['bot', null];
        }
        return ['other', null];
    }

    private function os(string $ua): string
    {
        return match (true) {
            preg_match('/Android/i', $ua) === 1 => 'android',
            preg_match('/iPhone|iPad|iPod/i', $ua) === 1 => 'ios',
            preg_match('/Windows NT/i', $ua) === 1 => 'windows',
            preg_match('/CrOS/i', $ua) === 1 => 'chromeos',
            preg_match('/Mac OS X|Macintosh/i', $ua) === 1 => 'macos',
            preg_match('/Linux/i', $ua) === 1 => 'linux',
            default => 'other',
        };
    }

    private function device(string $ua): string
    {
        return match (true) {
            preg_match('/bot|spider|crawler|slurp/i', $ua) === 1 => 'bot',
            preg_match('/iPad|Tablet|Nexus 7|Nexus 9|SM-T/i', $ua) === 1 => 'tablet',
            preg_match('/Mobile|iPhone|iPod|Android/i', $ua) === 1 => 'mobile',
            default => 'desktop',
        };
    }
}
