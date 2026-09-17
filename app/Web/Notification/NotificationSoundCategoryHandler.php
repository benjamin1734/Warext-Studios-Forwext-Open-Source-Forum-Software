<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class NotificationSoundCategoryHandler implements RequestHandlerInterface
{
    public function __construct(private NotificationSoundService $service, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $categoryKey = is_array($parameters) ? ($parameters['categoryKey'] ?? null) : null;
        if (!is_string($categoryKey)) return $this->json(['error' => 'invalid_notification_category'], 400);

        try {
            if ($request->method() === HttpMethod::Delete) {
                $this->service->resetCategory($actor, $categoryKey);
                return $this->json(['category_key' => $categoryKey, 'inherited' => true]);
            }
            if ($request->method() !== HttpMethod::Put) throw new InvalidArgumentException('Unsupported category sound method.');

            $body = $request->parsedBody();
            $enabled = $this->boolean($body['enabled'] ?? null);
            $soundKey = $body['sound_key'] ?? null;
            if ($soundKey === '') $soundKey = null;
            if ($soundKey !== null && !is_string($soundKey)) throw new InvalidArgumentException('sound_key must be a string or null.');
            $setting = $this->service->setCategory($actor, $categoryKey, $enabled, $soundKey);
            return $this->json([
                'category_key' => $setting->categoryKey,
                'enabled' => $setting->enabled,
                'sound_key' => $setting->soundKey,
                'inherited' => false,
            ]);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_notification_category'], 400);
        } catch (NotificationException) {
            return $this->json(['error' => 'notification_sound_unavailable'], 403);
        }
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1' || $value === 'true') return true;
        if ($value === 0 || $value === '0' || $value === 'false') return false;
        throw new InvalidArgumentException('enabled must be boolean.');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
