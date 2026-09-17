(() => {
  'use strict';

  for (const form of document.querySelectorAll('[data-presence-settings]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const status = form.querySelector('[data-presence-status]');
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Forwext-Presence': '1',
          },
          body: new URLSearchParams(new FormData(form)),
          cache: 'no-store',
        });
        if (!response.ok) throw new Error('presence-setting-failed');
        if (status) status.textContent = 'Görünürlük güncellendi.';
      } catch (_) {
        if (status) status.textContent = 'Görünürlük güncellenemedi.';
      }
    });
  }
})();
