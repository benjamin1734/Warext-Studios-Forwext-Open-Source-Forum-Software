(() => {
  'use strict';

  const links = document.querySelectorAll('[data-bug-report-link]');
  if (!links.length) return;

  const path = window.location.pathname || '/';
  for (const link of links) {
    try {
      const url = new URL(link.getAttribute('href') || '/bugs/report', window.location.origin);
      if (!url.searchParams.has('source')) url.searchParams.set('source', path);
      link.setAttribute('href', url.pathname + url.search);
    } catch (_) {
      // Keep the server-provided safe fallback URL.
    }
  }
})();
