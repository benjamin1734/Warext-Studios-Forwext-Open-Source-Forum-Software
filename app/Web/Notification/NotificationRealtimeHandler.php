<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\Realtime\NotificationRealtimeException;
use Forwext\Core\Notification\Realtime\NotificationRealtimeService;
use Forwext\Core\Realtime\RealtimeMode;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class NotificationRealtimeHandler implements RequestHandlerInterface
{
    public function __construct(
        private NotificationRealtimeService $service,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private RealtimeMode $mode,
        private int $pollIntervalMs,
        private int $hiddenPollIntervalMs,
        private int $pollLimit,
        private ?string $websocketPath,
    ) {
        if ($pollIntervalMs < 1000 || $pollIntervalMs > 60000 || $hiddenPollIntervalMs < $pollIntervalMs || $hiddenPollIntervalMs > 300000) {
            throw new InvalidArgumentException('Notification realtime polling intervals are invalid.');
        }
        if ($pollLimit < 1 || $pollLimit > 100) throw new InvalidArgumentException('Notification realtime poll limit is invalid.');
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);

        try {
            $after = $this->optionalNonNegativeInt($request->query()['after'] ?? null, 'after');
            $batch = $after === null
                ? $this->service->bootstrap($actor)
                : $this->service->read($actor, $after, $this->pollLimit);

            $payload = $batch->toArray();
            $payload['transport'] = [
                'mode' => $this->mode->value,
                'poll_url' => $this->basePath->prepend('/account/notifications/realtime'),
                'sse_url' => $this->basePath->prepend('/account/notifications/realtime/sse'),
                'websocket_path' => $this->websocketPath === null ? null : $this->basePath->prepend($this->websocketPath),
                'poll_interval_ms' => $this->pollIntervalMs,
                'hidden_poll_interval_ms' => $this->hiddenPollIntervalMs,
            ];
            return $this->json($payload);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_realtime_cursor'], 400);
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (NotificationRealtimeException) {
            return $this->json(['error' => 'realtime_unavailable'], 503);
        }
    }

    private function optionalNonNegativeInt(mixed $value, string $name): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^[0-9]{1,19}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (is_int($parsed)) return $parsed;
        }
        throw new InvalidArgumentException($name . ' must be a non-negative integer.');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
