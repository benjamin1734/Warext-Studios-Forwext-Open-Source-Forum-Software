<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use Forwext\Core\Giveaway\GiveawayFingerprint;
use Forwext\Core\Http\Request;
use InvalidArgumentException;

final class GiveawayRequestFingerprint
{
    /** @return array{0:string,1:?string} */
    public static function fromRequest(Request $request, GiveawayFingerprint $fingerprint): array
    {
        $remote = $request->server()['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Giveaway request client IP is unavailable.');
        }
        $network = $fingerprint->network($remote);
        $userAgent = $request->headers()->first('user-agent');
        $device = is_string($userAgent) && trim($userAgent) !== ''
            ? $fingerprint->device($remote, $userAgent)
            : null;
        return [$network, $device];
    }
}
