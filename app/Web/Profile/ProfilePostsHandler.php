<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ProfileActivityBody;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfilePost;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfilePostsHandler implements RequestHandlerInterface
{
    public function __construct(private ProfileActivityService $service, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $rawOwner = is_array($params) ? ($params['userId'] ?? null) : null;
        if (!is_string($rawOwner)) return $this->json(['error' => 'invalid_user'], 400);
        try {
            $owner = UserId::fromStored($rawOwner);
            if ($request->method() === HttpMethod::Get) {
                $items = $this->service->posts($actor, $owner, $this->queryInt($request, 'limit', 50), $this->queryInt($request, 'offset', 0));
                return $this->json(['items' => array_map($this->serialize(...), $items)]);
            }
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody()['body'] ?? null;
                if (!is_string($body)) throw new InvalidArgumentException('Profile post body is invalid.');
                return $this->json(['item' => $this->serialize($this->service->createPost($actor, $owner, ProfileActivityBody::fromString($body), $this->now()))], 201);
            }
            throw new InvalidArgumentException('Unsupported profile posts method.');
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_profile_post_request'], 400);
        } catch (ProfileActivityException) {
            return $this->json(['error' => 'profile_activity_unavailable'], 409);
        }
    }

    /** @return array<string,mixed> */
    private function serialize(ProfilePost $post): array
    {
        return [
            'profile_post_id' => $post->id->value(), 'profile_owner_user_id' => $post->profileOwnerUserId->value(),
            'author_user_id' => $post->authorUserId?->value(), 'body' => $post->body->source(),
            'created_at_utc' => $post->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
    private function queryInt(Request $request, string $key, int $default): int { $v = $request->query()[$key] ?? null; return is_string($v) && preg_match('/^[0-9]{1,7}$/D', $v) === 1 ? (int) $v : $default; }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
