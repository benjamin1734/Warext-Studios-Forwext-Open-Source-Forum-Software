# Notification Sound System (07.06)

The notification sound layer is optional presentation on top of the 07.05 notification engine. It never replaces the visual/in-app notification and does not change delivery authorization.

## User preferences

Each account has a global mute flag, a 0–100 volume value and one built-in default sound preset. Per-category overrides can disable sound or select another built-in preset; deleting an override returns the category to the global setting. The system reuses the shared `notification.alert.view` and `notification.preference.manage` permissions.

Sound presets are first-party Web Audio tones (`soft`, `chime`, `pulse`, `minimal`). They do not accept user uploads or external URLs, so enabling notification sound does not introduce a remote-media, SSRF or malicious-upload surface.

## Browser autoplay and accessibility

`public/assets/notification-sound.js` never creates/resumes an `AudioContext` until a real pointer, keyboard or touch interaction occurs. Notifications arriving before unlock are not queued for delayed playback; this avoids a surprise burst of audio after the user later interacts with the page. Browsers without Web Audio continue with visual notifications only.

Global mute, zero volume and category-level disable are checked before playback. Callers must surface the visual notification independently and may treat `ForwextNotificationSound.play(category)` returning `false` as a normal silent path, not an error. No workflow may depend on audio as the only status or error signal.

## 07.07 integration boundary

The future polling/SSE/WebSocket realtime layer should first render/deliver the in-app notification, then call the sound playback API with the notification category. The sound system has no polling or socket dependency, keeping the minimum cPanel profile daemon-free.
