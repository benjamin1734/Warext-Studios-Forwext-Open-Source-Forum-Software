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
use InvalidArgumentException;
use JsonException;

final readonly class NotificationRealtimeSseHandler implements RequestHandlerInterface
{
    public function __construct(
        private NotificationRealtimeService $service,
        private ProfileViewerResolver $viewers,
        private int $limit,
        private int $retryMs,
    ) {
        if ($limit < 1 || $limit > 100 || $retryMs < 1000 || $retryMs > 60000) {
            throw new InvalidArgumentException('Notification SSE configuration is invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::json(['error' => 'authentication_required'], 401)->withHeader('Cache-Control', 'no-store');

        try {
            $after = $this->cursor($request);
            $batch = $after === null ? $this->service->bootstrap($actor) : $this->service->read($actor, $after, $this->limit);
            $body = 'retry: ' . $this->retryMs . "\n\n";
            foreach ($batch->items as $item) {
                $body .= 'id: ' . $item->sequence . "\n";
                $body .= "event: notification\n";
                $body .= 'data: ' . $this->json($item->toArray()) . "\n\n";
            }
            // Advance over stale/invalid wake records too, preventing an endless replay loop.
            $body .= 'id: ' . $batch->cursor . "\n";
            $body .= "event: cursor\n";
            $body .= 'data: ' . $this->json(['cursor' => $batch->cursor]) . "\n\n";

            return Response::text($body)
                ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Accel-Buffering', 'no');
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'invalid_realtime_cursor'], 400)->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::json(['error' => 'forbidden'], 403)->withHeader('Cache-Control', 'private, no-store');
        } catch (NotificationRealtimeException) {
            return Response::json(['error' => 'realtime_unavailable'], 503)->withHeader('Cache-Control', 'private, no-store');
        }
    }

    private function cursor(Request $request): ?int
    {
        $raw = $request->query()['after'] ?? $request->headers()->first('last-event-id');
        if ($raw === null || $raw === '') return null;
        if (is_int($raw) && $raw >= 0) return $raw;
        if (is_string($raw) && preg_match('/^[0-9]{1,19}$/D', $raw) === 1) {
            $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (is_int($parsed)) return $parsed;
        }
        throw new InvalidArgumentException('Realtime cursor is invalid.');
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new NotificationRealtimeException('Unable to encode SSE notification payload.', previous: $exception);
        }
    }
}
