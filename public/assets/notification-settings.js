(() => {
  'use strict';

  const volume = document.querySelector('[data-notification-volume]');
  const output = document.querySelector('[data-notification-volume-output]');
  const select = document.querySelector('[data-notification-sound-select]');
  const preview = document.querySelector('[data-notification-preview]');

  if (volume instanceof HTMLInputElement && output instanceof HTMLOutputElement) {
    const sync = () => { output.value = volume.value; };
    volume.addEventListener('input', sync);
    sync();
  }

  if (preview instanceof HTMLButtonElement && select instanceof HTMLSelectElement) {
    preview.addEventListener('click', async () => {
      const key = select.value;
      try {
        await window.ForwextNotificationSound?.load?.();
        await window.ForwextNotificationSound?.preview?.(key);
      } catch (_) {
        try { await window.ForwextNotificationSound?.play?.('preview'); } catch (_) {}
      }
    });
  }
})();
