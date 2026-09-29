(() => {
  'use strict';

  const roots = Array.from(document.querySelectorAll('[data-thread-interactions]'));
  if (roots.length === 0) return;

  const scriptPath = document.currentScript?.src
    ? new URL(document.currentScript.src, window.location.href).pathname
    : '';
  const basePath = scriptPath.endsWith('/assets/thread-interactions.js')
    ? scriptPath.slice(0, -'/assets/thread-interactions.js'.length)
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
    csrfPromise = fetch(`${basePath}/account/interactions/csrf`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    }).then(async (response) => {
      if (!response.ok) throw new Error('interaction_csrf_unavailable');
      const payload = await response.json();
      if (!payload || typeof payload.csrf_token !== 'string' || payload.csrf_token === '') {
        throw new Error('interaction_csrf_invalid');
      }
      return payload.csrf_token;
    }).catch((error) => {
      csrfPromise = null;
      throw error;
    });
    return csrfPromise;
  };

  const endpoint = (postId, action) =>
    `${basePath}/posts/${encodeURIComponent(postId)}/${action}`;

  const postAction = async (postId, action, values) => {
    const token = await csrfToken();
    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
      if (typeof value === 'string') body.set(key, value);
    }

    const response = await fetch(endpoint(postId, action), {
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
    if (!response.ok) throw new Error(`interaction_failed_${response.status}`);
    return response.json();
  };

  const getSummary = async (postId) => {
    const response = await fetch(endpoint(postId, 'reactions'), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) throw new Error(`reaction_summary_failed_${response.status}`);
    return response.json();
  };

  const setStatus = (root, message, error = false) => {
    const status = root.querySelector('[data-interaction-status]');
    if (!(status instanceof HTMLElement)) return;
    status.textContent = message;
    status.dataset.error = error ? '1' : '0';
  };

  const renderSummary = (root, payload) => {
    const total = root.querySelector('[data-reaction-total]');
    const counts = root.querySelector('[data-reaction-counts]');
    const value = Number.isSafeInteger(Number(payload?.total)) && Number(payload.total) >= 0
      ? Number(payload.total)
      : 0;

    if (total instanceof HTMLElement) total.textContent = value > 0 ? `(${value})` : '';
    if (!(counts instanceof HTMLElement)) return;

    const nodes = [];
    const source = payload?.counts && typeof payload.counts === 'object' ? payload.counts : {};
    for (const [key, label] of Object.entries(reactionLabels)) {
      const count = Number(source[key] ?? 0);
      if (!Number.isSafeInteger(count) || count < 1) continue;
      const badge = document.createElement('span');
      badge.className = 'thread-reaction-count';
      badge.textContent = `${label} ${count}`;
      nodes.push(badge);
    }

    counts.replaceChildren(...nodes);
    counts.hidden = nodes.length === 0;
  };

  for (const root of roots) {
    if (!(root instanceof HTMLElement)) continue;
    const postId = root.dataset.postId;
    if (!postId || !/^[a-f0-9]{32}$/.test(postId)) continue;

    const quoteButton = root.querySelector('[data-quote-post]');
    if (quoteButton instanceof HTMLButtonElement) {
      quoteButton.addEventListener('click', async () => {
        const editorRoot = document.getElementById('thread-quick-reply-editor');
        const editorApi = window.ForwextRichEditor;
        if (!(editorRoot instanceof HTMLElement) || !editorApi || typeof editorApi.quotePost !== 'function') {
          setStatus(root, 'Hızlı yanıt editörü kullanılamıyor.', true);
          return;
        }
        quoteButton.disabled = true;
        try {
          const inserted = await editorApi.quotePost(editorRoot, postId);
          if (!inserted) {
            setStatus(root, 'Mesaj alıntılanamadı.', true);
            return;
          }
          editorRoot.scrollIntoView({ behavior: 'smooth', block: 'center' });
          const source = editorRoot.querySelector('[data-fx-editor-source]');
          if (source instanceof HTMLTextAreaElement) source.focus({ preventScroll: true });
          setStatus(root, 'Alıntı hızlı yanıta eklendi.');
        } catch (_) {
          setStatus(root, 'Mesaj alıntılanamadı.', true);
        } finally {
          quoteButton.disabled = false;
        }
      });
    }

    const reactionMenu = root.querySelector('[data-reaction-menu]');
    let summaryLoaded = false;
    if (reactionMenu instanceof HTMLDetailsElement) {
      reactionMenu.addEventListener('toggle', async () => {
        if (!reactionMenu.open || summaryLoaded) return;
        try {
          renderSummary(root, await getSummary(postId));
          summaryLoaded = true;
        } catch (_) {
          setStatus(root, 'Tepkiler yüklenemedi.', true);
        }
      });
    }

    root.querySelectorAll('[data-reaction-key]').forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) return;
      button.addEventListener('click', async () => {
        const reactionKey = button.dataset.reactionKey;
        if (!reactionKey || !Object.hasOwn(reactionLabels, reactionKey)) return;
        button.disabled = true;
        try {
          const payload = await postAction(postId, 'reactions', {
            action: 'react',
            reaction_key: reactionKey,
          });
          renderSummary(root, payload);
          summaryLoaded = true;
          setStatus(root, 'Tepkin kaydedildi.');
        } catch (_) {
          setStatus(root, 'Tepki kaydedilemedi.', true);
        } finally {
          button.disabled = false;
        }
      });
    });

    const removeReaction = root.querySelector('[data-remove-reaction]');
    if (removeReaction instanceof HTMLButtonElement) {
      removeReaction.addEventListener('click', async () => {
        removeReaction.disabled = true;
        try {
          const payload = await postAction(postId, 'reactions', { action: 'remove_reaction' });
          renderSummary(root, payload);
          summaryLoaded = true;
          setStatus(root, 'Tepkin kaldırıldı.');
        } catch (_) {
          setStatus(root, 'Tepki kaldırılamadı.', true);
        } finally {
          removeReaction.disabled = false;
        }
      });
    }

    const bookmarkForm = root.querySelector('[data-bookmark-form]');
    if (bookmarkForm instanceof HTMLFormElement) {
      bookmarkForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = bookmarkForm.querySelector('button[type="submit"]');
        const note = bookmarkForm.elements.namedItem('note');
        if (!(note instanceof HTMLInputElement)) return;
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        try {
          await postAction(postId, 'bookmark', {
            action: 'save_bookmark',
            note: note.value,
          });
          setStatus(root, 'Yer imi kaydedildi.');
        } catch (_) {
          setStatus(root, 'Yer imi kaydedilemedi.', true);
        } finally {
          if (submit instanceof HTMLButtonElement) submit.disabled = false;
        }
      });
    }

    const removeBookmark = root.querySelector('[data-remove-bookmark]');
    if (removeBookmark instanceof HTMLButtonElement) {
      removeBookmark.addEventListener('click', async () => {
        removeBookmark.disabled = true;
        try {
          await postAction(postId, 'bookmark', { action: 'remove_bookmark' });
          const note = bookmarkForm instanceof HTMLFormElement
            ? bookmarkForm.elements.namedItem('note')
            : null;
          if (note instanceof HTMLInputElement) note.value = '';
          setStatus(root, 'Yer imi kaldırıldı.');
        } catch (_) {
          setStatus(root, 'Yer imi kaldırılamadı.', true);
        } finally {
          removeBookmark.disabled = false;
        }
      });
    }
  }
})();
