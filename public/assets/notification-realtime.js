(() => {
  'use strict';

  const scriptPath = document.currentScript?.src ? new URL(document.currentScript.src, window.location.href).pathname : '';
  const basePath = scriptPath.endsWith('/assets/notification-realtime.js')
    ? scriptPath.slice(0, -'/assets/notification-realtime.js'.length)
    : '';
  const bootstrapUrl = `${basePath}/account/notifications/realtime`;

  const state = {
    cursor: 0,
    mode: 'polling',
    pollUrl: bootstrapUrl,
    sseUrl: `${basePath}/account/notifications/realtime/sse`,
    websocketPath: null,
    pollIntervalMs: 3000,
    hiddenPollIntervalMs: 15000,
    stopped: false,
    pollTimer: null,
    eventSource: null,
    socket: null,
    deliveredSequence: 0,
  };

  const safeInt = (value, fallback = 0) => Number.isSafeInteger(Number(value)) && Number(value) >= 0 ? Number(value) : fallback;

  const ensureLiveRegion = () => {
    let region = document.getElementById('forwext-notification-live');
    if (region) return region;
    region = document.createElement('div');
    region.id = 'forwext-notification-live';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    region.style.position = 'fixed';
    region.style.right = '1rem';
    region.style.bottom = '1rem';
    region.style.maxWidth = '24rem';
    region.style.zIndex = '2147483000';
    region.style.pointerEvents = 'none';
    document.body.appendChild(region);
    return region;
  };

  const visualNotice = (notification) => {
    const region = ensureLiveRegion();
    const item = document.createElement('div');
    item.style.marginTop = '.5rem';
    item.style.padding = '.75rem 1rem';
    item.style.border = '1px solid currentColor';
    item.style.borderRadius = '.6rem';
    item.style.background = 'Canvas';
    item.style.color = 'CanvasText';
    item.style.boxShadow = '0 8px 24px rgba(0,0,0,.16)';
    item.textContent = notification.body ? `${notification.title}: ${notification.body}` : notification.title;
    region.appendChild(item);
    window.setTimeout(() => item.remove(), 6000);
  };

  const deliver = (entry) => {
    const sequence = safeInt(entry?.sequence, -1);
    const notification = entry?.notification;
    if (sequence < 1 || sequence <= state.deliveredSequence || !notification || typeof notification !== 'object') return;
    if (typeof notification.category_key !== 'string' || typeof notification.title !== 'string') return;
    state.deliveredSequence = sequence;
    state.cursor = Math.max(state.cursor, sequence);

    visualNotice(notification);
    window.dispatchEvent(new CustomEvent('forwext:notification', { detail: Object.freeze({ sequence, notification }) }));
    window.ForwextNotificationSound?.play?.(notification.category_key);
  };

  const configure = (payload) => {
    const transport = payload?.transport;
    if (!transport || typeof transport !== 'object') return false;
    state.cursor = Math.max(state.cursor, safeInt(payload.cursor, state.cursor));
    state.mode = ['polling', 'sse', 'websocket'].includes(transport.mode) ? transport.mode : 'polling';
    state.pollUrl = typeof transport.poll_url === 'string' ? transport.poll_url : state.pollUrl;
    state.sseUrl = typeof transport.sse_url === 'string' ? transport.sse_url : state.sseUrl;
    state.websocketPath = typeof transport.websocket_path === 'string' ? transport.websocket_path : null;
    state.pollIntervalMs = Math.max(1000, safeInt(transport.poll_interval_ms, 3000));
    state.hiddenPollIntervalMs = Math.max(state.pollIntervalMs, safeInt(transport.hidden_poll_interval_ms, 15000));
    return true;
  };

  const fetchBatch = async (bootstrap = false) => {
    const url = new URL(state.pollUrl, window.location.origin);
    if (!bootstrap) url.searchParams.set('after', String(state.cursor));
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (response.status === 401 || response.status === 403) {
      state.stopped = true;
      return null;
    }
    if (!response.ok) throw new Error('notification_realtime_fetch_failed');
    const payload = await response.json();
    configure(payload);
    for (const item of Array.isArray(payload.items) ? payload.items : []) deliver(item);
    state.cursor = Math.max(state.cursor, safeInt(payload.cursor, state.cursor));
    return payload;
  };

  const pollDelay = () => document.visibilityState === 'hidden' ? state.hiddenPollIntervalMs : state.pollIntervalMs;
  const schedulePoll = (delay = pollDelay()) => {
    if (state.stopped) return;
    window.clearTimeout(state.pollTimer);
    state.pollTimer = window.setTimeout(async () => {
      try { await fetchBatch(false); } catch (_) { /* retry on bounded cadence */ }
      schedulePoll();
    }, delay);
  };

  const startPolling = () => {
    state.eventSource?.close();
    state.eventSource = null;
    try { state.socket?.close(); } catch (_) {}
    state.socket = null;
    schedulePoll(0);
  };

  const startSse = () => {
    if (state.stopped || typeof window.EventSource !== 'function') return startPolling();
    const url = new URL(state.sseUrl, window.location.origin);
    url.searchParams.set('after', String(state.cursor));
    const source = new EventSource(url, { withCredentials: true });
    state.eventSource = source;
    source.addEventListener('notification', (event) => {
      try {
        const item = JSON.parse(event.data);
        deliver(item);
        state.cursor = Math.max(state.cursor, safeInt(event.lastEventId, state.cursor));
      } catch (_) {}
    });
    source.addEventListener('cursor', (event) => {
      state.cursor = Math.max(state.cursor, safeInt(event.lastEventId, state.cursor));
    });
    source.onerror = () => {
      source.close();
      if (state.eventSource === source) state.eventSource = null;
      startPolling();
    };
  };

  const startWebSocket = () => {
    if (state.stopped || typeof window.WebSocket !== 'function' || !state.websocketPath) return startSse();
    try {
      const url = new URL(state.websocketPath, window.location.origin);
      if (url.origin !== window.location.origin) return startSse();
      url.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const socket = new WebSocket(url.toString());
      state.socket = socket;
      let opened = false;
      const fallbackTimer = window.setTimeout(() => {
        if (!opened && state.socket === socket) {
          try { socket.close(); } catch (_) {}
          startSse();
        }
      }, 5000);
      socket.onopen = () => { opened = true; window.clearTimeout(fallbackTimer); };
      socket.onmessage = async () => {
        // Gateway payload is a wake signal only; canonical authorized data is fetched over HTTP.
        try { await fetchBatch(false); } catch (_) {}
      };
      socket.onerror = () => { try { socket.close(); } catch (_) {} };
      socket.onclose = () => {
        window.clearTimeout(fallbackTimer);
        if (state.socket === socket) state.socket = null;
        if (!state.stopped) startSse();
      };
    } catch (_) {
      startSse();
    }
  };

  const startPreferredTransport = () => {
    if (state.mode === 'websocket') return startWebSocket();
    if (state.mode === 'sse') return startSse();
    startPolling();
  };

  const bootstrap = async () => {
    try {
      const payload = await fetchBatch(true);
      if (!payload || state.stopped) return false;
      await window.ForwextNotificationSound?.load?.();
      startPreferredTransport();
      return true;
    } catch (_) {
      startPolling();
      return false;
    }
  };

  document.addEventListener('visibilitychange', () => {
    if (!state.stopped && document.visibilityState === 'visible' && !state.eventSource && !state.socket) schedulePoll(0);
  });
  window.addEventListener('beforeunload', () => {
    state.stopped = true;
    window.clearTimeout(state.pollTimer);
    state.eventSource?.close();
    try { state.socket?.close(); } catch (_) {}
  });

  window.ForwextNotificationRealtime = Object.freeze({ bootstrap, fetchNow: () => fetchBatch(false) });
  bootstrap();
})();
