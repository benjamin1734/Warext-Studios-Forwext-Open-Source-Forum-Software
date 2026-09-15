(() => {
    'use strict';

    const coarsePointer = window.matchMedia?.('(pointer: coarse)').matches === true;

    document.querySelectorAll('[data-profile-music]').forEach((container) => {
        const audio = container.querySelector('audio');
        if (!(audio instanceof HTMLAudioElement)) {
            return;
        }

        const rawVolume = Number(container.dataset.volume ?? '70');
        const volume = Number.isFinite(rawVolume) ? Math.min(100, Math.max(0, rawVolume)) : 70;
        const requestedMuted = container.dataset.muted === '1';
        const requestedAutoplay = container.dataset.autoplay === '1';

        audio.volume = volume / 100;
        audio.muted = requestedMuted;

        // Mobile/coarse-pointer devices always require an explicit user gesture.
        if (!requestedAutoplay || coarsePointer) {
            return;
        }

        // Browser autoplay policies generally require muted media. Never auto-unmute.
        audio.muted = true;
        const attempt = audio.play();
        if (attempt instanceof Promise) {
            attempt.catch(() => {
                audio.muted = requestedMuted;
            });
        }
    });
})();
