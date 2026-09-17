<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundCategorySetting;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class NotificationSoundSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private NotificationSoundService $service,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);

        try {
            if ($request->method() === HttpMethod::Put) {
                $body = $request->parsedBody();
                $muted = $this->boolean($body['muted'] ?? null, 'muted');
                $volume = $this->integer($body['volume'] ?? null, 'volume');
                $soundKey = $body['default_sound_key'] ?? null;
                if (!is_string($soundKey)) throw new InvalidArgumentException('default_sound_key is required.');
                $this->service->updateSettings($actor, $muted, $volume, $soundKey);
            } elseif ($request->method() !== HttpMethod::Get) {
                throw new InvalidArgumentException('Unsupported notification sound settings method.');
            }

            $settings = $this->service->settings($actor);
            $categories = array_map(
                static fn (NotificationSoundCategorySetting $setting): array => [
                    'category_key' => $setting->categoryKey,
                    'enabled' => $setting->enabled,
                    'sound_key' => $setting->soundKey,
                ],
                $this->service->categorySettings($actor),
            );
            $presets = [];
            foreach ($this->service->presets() as $key => $label) $presets[] = ['key' => $key, 'label' => $label];

            return $this->json([
                'muted' => $settings->muted,
                'volume' => $settings->volume,
                'default_sound_key' => $settings->defaultSoundKey,
                'categories' => $categories,
                'presets' => $presets,
                'playback_asset' => $this->basePath->prepend('/assets/notification-sound.js'),
                'autoplay_policy' => 'user_interaction_required',
            ]);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_notification_sound_settings'], 400);
        } catch (NotificationException) {
            return $this->json(['error' => 'notification_sound_unavailable'], 403);
        }
    }

    private function boolean(mixed $value, string $name): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1' || $value === 'true') return true;
        if ($value === 0 || $value === '0' || $value === 'false') return false;
        throw new InvalidArgumentException($name . ' must be boolean.');
    }

    private function integer(mixed $value, string $name): int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) return (int) $value;
        throw new InvalidArgumentException($name . ' must be an integer.');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
