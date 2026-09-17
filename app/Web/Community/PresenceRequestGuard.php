<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\Request;

final readonly class PresenceRequestGuard
{
    public function __construct(private CanonicalUrl $canonicalUrl)
    {
    }

    public function allows(Request $request): bool
    {
        if ($request->headers()->first('x-forwext-presence') !== '1') {
            return false;
        }
        $origin = $request->headers()->first('origin');
        if ($origin !== null && !hash_equals($this->canonicalUrl->origin(), rtrim($origin, '/'))) {
            return false;
        }
        $fetchSite = $request->headers()->first('sec-fetch-site');
        return $fetchSite === null || in_array($fetchSite, ['same-origin', 'none'], true);
    }
}
