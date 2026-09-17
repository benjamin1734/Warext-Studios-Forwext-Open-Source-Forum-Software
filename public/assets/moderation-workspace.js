(() => {
  'use strict';

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-moderation-form]')) {
      return;
    }

    event.preventDefault();
    const submitter = form.querySelector('button[type="submit"]');
    if (submitter instanceof HTMLButtonElement) {
      submitter.disabled = true;
    }

    try {
      const body = new URLSearchParams();
      for (const [key, value] of new FormData(form).entries()) {
        if (typeof value === 'string') {
          body.append(key, value);
        }
      }
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Forwext-Moderation': '1',
        },
        body,
      });
      if (!response.ok) {
        throw new Error(`Moderation request failed with ${response.status}`);
      }
      window.location.assign(response.url || window.location.href);
    } catch (_error) {
      window.alert('Moderasyon işlemi tamamlanamadı. Sayfayı yenileyip tekrar deneyin.');
      if (submitter instanceof HTMLButtonElement) {
        submitter.disabled = false;
      }
    }
  });
})();
