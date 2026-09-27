(() => {
  'use strict';

  const root = document.querySelector('[data-profile-activity-wall]');
  if (!(root instanceof HTMLElement)) return;

  const ownerId = root.dataset.profileOwnerId;
  if (!ownerId || !/^[a-f0-9]{32}$/.test(ownerId)) return;

  const scriptPath = document.currentScript?.src
    ? new URL(document.currentScript.src, window.location.href).pathname
    : '';
  const basePath = scriptPath.endsWith('/assets/profile-activity-wall.js')
    ? scriptPath.slice(0, -'/assets/profile-activity-wall.js'.length)
    : '';

  const reactionLabels = Object.freeze({
    like: 'Beğen',
    love: 'Sevgi',
    haha: 'Haha',
    wow: 'Vay',
    sad: 'Üzgün',
    angry: 'Kızgın',
  });

  let csrfPromise = null;

  const csrfToken = async () => {
    if (csrfPromise) return csrfPromise;
    csrfPromise = fetch(`${basePath}/account/profile-activity/csrf`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    }).then(async (response) => {
      if (!response.ok) throw new Error('profile_activity_csrf_unavailable');
      const payload = await response.json();
      if (!payload || typeof payload.csrf_token !== 'string' || payload.csrf_token === '') {
        throw new Error('profile_activity_csrf_invalid');
      }
      return payload.csrf_token;
    }).catch((error) => {
      csrfPromise = null;
      throw error;
    });
    return csrfPromise;
  };

  const postForm = async (url, values) => {
    const token = await csrfToken();
    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
      if (typeof value === 'string') body.set(key, value);
    }

    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': token,
      },
      body,
      cache: 'no-store',
    });
    if (!response.ok) throw new Error(`profile_activity_failed_${response.status}`);
    return response.json();
  };

  const setStatus = (scope, message, error = false) => {
    const target = scope.querySelector('[data-profile-wall-status]');
    if (!(target instanceof HTMLElement)) return;
    target.textContent = message;
    target.dataset.error = error ? '1' : '0';
  };

  const composer = root.querySelector('[data-profile-wall-post-form]');
  if (composer instanceof HTMLFormElement) {
    composer.addEventListener('submit', async (event) => {
      event.preventDefault();
      const textarea = composer.elements.namedItem('body');
      const submit = composer.querySelector('button[type="submit"]');
      if (!(textarea instanceof HTMLTextAreaElement)) return;
      const body = textarea.value.trim();
      if (body === '') {
        setStatus(composer, 'Gönderi boş olamaz.', true);
        return;
      }

      if (submit instanceof HTMLButtonElement) submit.disabled = true;
      try {
        await postForm(`${basePath}/users/${encodeURIComponent(ownerId)}/profile-posts`, { body });
        setStatus(composer, 'Gönderi paylaşıldı.');
        window.location.reload();
      } catch (_) {
        setStatus(composer, 'Gönderi paylaşılamadı.', true);
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
      }
    });
  }

  const renderSummary = (post, payload) => {
    const total = post.querySelector('[data-profile-wall-reaction-total]');
    const counts = post.querySelector('[data-profile-wall-reaction-counts]');
    const value = Number.isSafeInteger(Number(payload?.total)) && Number(payload.total) >= 0
      ? Number(payload.total)
      : 0;

    if (total instanceof HTMLElement) total.textContent = value > 0 ? `(${value})` : '';
    if (!(counts instanceof HTMLElement)) return;

    const source = payload?.counts && typeof payload.counts === 'object' ? payload.counts : {};
    const nodes = [];
    for (const [key, label] of Object.entries(reactionLabels)) {
      const count = Number(source[key] ?? 0);
      if (!Number.isSafeInteger(count) || count < 1) continue;
      const badge = document.createElement('span');
      badge.className = 'profile-wall-reaction-count';
      badge.textContent = `${label} ${count}`;
      nodes.push(badge);
    }
    counts.replaceChildren(...nodes);
    counts.hidden = nodes.length === 0;
  };

  const reactionSummary = async (postId) => {
    const response = await fetch(
      `${basePath}/profile-posts/${encodeURIComponent(postId)}/reactions`,
      {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      },
    );
    if (!response.ok) throw new Error(`profile_reaction_summary_failed_${response.status}`);
    return response.json();
  };

  for (const post of root.querySelectorAll('[data-profile-wall-post]')) {
    if (!(post instanceof HTMLElement)) continue;
    const postId = post.dataset.profilePostId;
    if (!postId || !/^[a-f0-9]{32}$/.test(postId)) continue;

    const commentForm = post.querySelector('[data-profile-wall-comment-form]');
    if (commentForm instanceof HTMLFormElement) {
      commentForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const textarea = commentForm.elements.namedItem('body');
        const submit = commentForm.querySelector('button[type="submit"]');
        if (!(textarea instanceof HTMLTextAreaElement)) return;
        const body = textarea.value.trim();
        if (body === '') {
          setStatus(commentForm, 'Yorum boş olamaz.', true);
          return;
        }

        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
          await postForm(
            `${basePath}/profile-posts/${encodeURIComponent(postId)}/comments`,
            { body },
          );
          setStatus(commentForm, 'Yorum gönderildi.');
          window.location.reload();
        } catch (_) {
          setStatus(commentForm, 'Yorum gönderilemedi.', true);
          if (submit instanceof HTMLButtonElement) submit.disabled = false;
        }
      });
    }

    const reactionMenu = post.querySelector('[data-profile-wall-reaction-menu]');
    let summaryLoaded = false;
    if (reactionMenu instanceof HTMLDetailsElement) {
      reactionMenu.addEventListener('toggle', async () => {
        if (!reactionMenu.open || summaryLoaded) return;
        try {
          renderSummary(post, await reactionSummary(postId));
          summaryLoaded = true;
        } catch (_) {
          setStatus(post, 'Tepkiler yüklenemedi.', true);
        }
      });
    }

    post.querySelectorAll('[data-profile-wall-reaction]').forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) return;
      button.addEventListener('click', async () => {
        const key = button.dataset.profileWallReaction;
        if (!key || !Object.hasOwn(reactionLabels, key)) return;
        button.disabled = true;
        try {
          const payload = await postForm(
            `${basePath}/profile-posts/${encodeURIComponent(postId)}/reactions`,
            { action: 'react', reaction_key: key },
          );
          renderSummary(post, payload);
          summaryLoaded = true;
          setStatus(post, 'Tepkin kaydedildi.');
        } catch (_) {
          setStatus(post, 'Tepki kaydedilemedi.', true);
        } finally {
          button.disabled = false;
        }
      });
    });

    const removeReaction = post.querySelector('[data-profile-wall-reaction-remove]');
    if (removeReaction instanceof HTMLButtonElement) {
      removeReaction.addEventListener('click', async () => {
        removeReaction.disabled = true;
        try {
          const payload = await postForm(
            `${basePath}/profile-posts/${encodeURIComponent(postId)}/reactions`,
            { action: 'remove_reaction' },
          );
          renderSummary(post, payload);
          summaryLoaded = true;
          setStatus(post, 'Tepkin kaldırıldı.');
        } catch (_) {
          setStatus(post, 'Tepki kaldırılamadı.', true);
        } finally {
          removeReaction.disabled = false;
        }
      });
    }
  }
})();
