(() => {
  'use strict';

  for (const form of document.querySelectorAll('form[data-report-form]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = form.querySelector('button[type="submit"]');
      if (submit) submit.disabled = true;

      try {
        const body = new URLSearchParams();
        for (const [key, value] of new FormData(form).entries()) {
          if (typeof value === 'string') body.append(key, value);
        }
        const response = await fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Forwext-Report': '1',
          },
          body,
        });
        if (!response.ok) {
          throw new Error(`Report request failed with status ${response.status}.`);
        }
        window.location.assign(response.url);
      } catch (_error) {
        window.alert('Rapor gönderilemedi. Lütfen sayfayı yenileyip tekrar deneyin.');
        if (submit) submit.disabled = false;
      }
    });
  }
})();
