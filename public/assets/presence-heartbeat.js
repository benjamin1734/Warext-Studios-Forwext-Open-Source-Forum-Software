(() => {
  'use strict';

  const endpoint = document.querySelector('meta[name="forwext-presence-endpoint"]')?.content;
  if (!endpoint) return;

  const ping = () => {
    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'X-Forwext-Presence': '1'},
      cache: 'no-store',
      keepalive: true,
    }).catch(() => {});
  };

  ping();
  window.setInterval(ping, 120000);
})();
