<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfileMusicHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepository $users,
        private ProfileMusicService $music,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['username'] ?? null) : null;
        if (!is_string($value)) {
            return Response::text('Not Found', 404);
        }

        try {
            $username = Username::fromString($value);
        } catch (InvalidArgumentException) {
            return Response::text('Not Found', 404);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || $user->status() !== UserStatus::Active) {
            return Response::text('Not Found', 404);
        }

        $payload = $this->music->readUpload(
            $user->id(),
            $this->viewers->resolve($request),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($payload === null) {
            return Response::text('Not Found', 404);
        }

        $length = strlen($payload->contents);
        $rangeHeader = $request->headers()->first('range');
        if ($rangeHeader !== null) {
            $range = $this->parseRange($rangeHeader, $length);
            if ($range === null) {
                return new Response('', 416, new HeaderBag([
                    'Content-Range' => 'bytes */' . $length,
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]));
            }

            [$start, $end] = $range;
            $body = substr($payload->contents, $start, ($end - $start) + 1);
            return new Response($body, 206, new HeaderBag([
                'Content-Type' => $payload->contentType,
                'Content-Length' => (string) strlen($body),
                'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $length),
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]));
        }

        return new Response($payload->contents, 200, new HeaderBag([
            'Content-Type' => $payload->contentType,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /** @return array{0:int,1:int}|null */
    private function parseRange(string $header, int $length): ?array
    {
        if ($length < 1 || preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $matches) !== 1) {
            return null;
        }

        $startText = $matches[1];
        $endText = $matches[2];
        if ($startText === '' && $endText === '') {
            return null;
        }

        if ($startText === '') {
            $suffix = (int) $endText;
            if ($suffix < 1) {
                return null;
            }
            $suffix = min($suffix, $length);
            return [$length - $suffix, $length - 1];
        }

        $start = (int) $startText;
        if ($start < 0 || $start >= $length) {
            return null;
        }
        $end = $endText === '' ? $length - 1 : (int) $endText;
        if ($end < $start) {
            return null;
        }

        return [$start, min($end, $length - 1)];
    }
}
