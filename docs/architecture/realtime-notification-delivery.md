# Realtime Notification Delivery (07.07)

Forwext 07.07 connects the 07.05 notification engine to the durable realtime infrastructure introduced in 03.06. It does **not** create a second websocket/queue stack.

## Transport chain

The browser uses the configured preference with graceful fallback:

1. WebSocket (advanced deployment, optional same-origin gateway path),
2. SSE,
3. database-backed polling (minimum cPanel baseline).

Polling is always available and requires no daemon, Node.js, Redis or Supervisor. SSE is a bounded HTTP response and reconnects through the browser. A WebSocket gateway is an advanced-runtime concern and may consume/broadcast the same persisted sequence stream.

## Cursor and delivery model

`forwext_realtime_messages.sequence_id` is the resume cursor. Notification grouping may update one existing notification row many times, therefore notification timestamps/ids are not used as event cursors. Each meaningful in-app notification mutation publishes a new `notification.changed` wake record with only `notification_id`.

The notification dispatcher publishes after durable notification mutation and external delivery enqueue. A dedupe hit does not publish another wake. Realtime publisher failures are contained so notification persistence remains the source of truth. WebSocket transport persists before gateway broadcast, allowing a failed gateway to recover via SSE/polling history.

## Authorization and data minimization

- The client never supplies a user id or arbitrary channel name.
- Channel name is derived from the authenticated actor as `notification.user.<user-id>`.
- `notification.alert.view` is checked before bootstrap or reading.
- Notification snapshots are queried again with `recipient_user_id = actor` and `in_app_visible = 1`; knowledge of a notification id/channel/cursor grants no access.
- Realtime wake payload contains only `notification_id`; title/body/action are not broadcast to an external gateway.
- Snapshot query intentionally excludes `payload_json`.
- WebSocket path is same-origin only. The advanced gateway must authenticate the session and authorize channel subscription; it must not trust a client-provided channel.

## Accessibility and sound

Every received notification emits a visual/ARIA live notice and a `forwext:notification` DOM event. The 07.06 sound player is invoked only as a supplementary signal and still obeys user mute/volume/category settings plus browser user-interaction gating.

## Native PHP frontend

The native profile frontend loads both `notification-sound.js` and `notification-realtime.js`. The realtime client bootstraps its cursor without replaying historical notifications, then chooses the configured transport. Invalid/stale wake records advance the cursor so they cannot create replay loops.
