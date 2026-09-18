(() => {
    'use strict';

    const link = document.querySelector('[data-bug-report-entry]');
    if (!(link instanceof HTMLAnchorElement)) {
        return;
    }

    try {
        const target = new URL(link.href, window.location.origin);
        const sourcePath = window.location.pathname;
        if (sourcePath && sourcePath !== target.pathname) {
            target.searchParams.set('source_path', sourcePath);
        }
        link.href = target.pathname + target.search;
    } catch {
        // The server-rendered /bugs/new fallback remains valid.
    }
})();
