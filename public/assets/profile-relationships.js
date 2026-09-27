(() => {
  'use strict';

  const roots = Array.from(document.querySelectorAll('[data-user-relationship]'));
  if (roots.length === 0) return;

  const scriptPath = document.currentScript?.src
    ? new URL(document.currentScript.src, window.location.href).pathname
    : '';
  const basePath = scriptPath.endsWith('/assets/profile-relationships.js')
    ? scriptPath.slice(0, -'/assets/profile-relationships.js'.length)
    : '';

  let csrfPromise = null;

  const csrfToken = async () => {
    if (csrfPromise) return csrfPromise;
    csrfPromise = fetch(`${basePath}/account/interactions/csrf`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    }).then(async (response) => {
      if (!response.ok) throw new Error('relationship_csrf_unavailable');
      const payload = await response.json();
      if (!payload || typeof payload.csrf_token !== 'string' || payload.csrf_token === '') {
        throw new Error('relationship_csrf_invalid');
      }
      return payload.csrf_token;
    }).catch((error) => {
      csrfPromise = null;
      throw error;
    });
    return csrfPromise;
  };

  const mutate = async (userId, kind, action) => {
    const token = await csrfToken();
    const body = new URLSearchParams({ action });
    const response = await fetch(
      `${basePath}/users/${encodeURIComponent(userId)}/${kind}`,
      {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-Token': token,
        },
        body,
        cache: 'no-store',
      },
    );
    if (!response.ok) throw new Error(`relationship_failed_${response.status}`);
    return response.json();
  };

  const booleanData = (root, key) => root.dataset[key] === '1';

  const render = (root) => {
    const following = booleanData(root, 'following');
    const ignoring = booleanData(root, 'ignoring');
    const follow = root.querySelector('[data-follow-toggle]');
    const ignore = root.querySelector('[data-ignore-toggle]');

    if (follow instanceof HTMLButtonElement) {
      follow.textContent = following ? 'Takibi bırak' : 'Takip et';
      follow.dataset.active = following ? '1' : '0';
      follow.disabled = ignoring;
      follow.title = ignoring ? 'Takip etmek için önce yok saymayı kaldır.' : '';
      follow.setAttribute('aria-pressed', following ? 'true' : 'false');
    }
    if (ignore instanceof HTMLButtonElement) {
      ignore.textContent = ignoring ? 'Yok saymayı kaldır' : 'Yok say';
      ignore.dataset.active = ignoring ? '1' : '0';
      ignore.setAttribute('aria-pressed', ignoring ? 'true' : 'false');
    }
  };

  const status = (root, message, error = false) => {
    const target = root.querySelector('[data-relationship-status]');
    if (!(target instanceof HTMLElement)) return;
    target.textContent = message;
    target.dataset.error = error ? '1' : '0';
  };

  for (const root of roots) {
    if (!(root instanceof HTMLElement)) continue;
    const userId = root.dataset.userId;
    if (!userId || !/^[a-f0-9]{32}$/.test(userId)) continue;

    render(root);

    const follow = root.querySelector('[data-follow-toggle]');
    if (follow instanceof HTMLButtonElement) {
      follow.addEventListener('click', async () => {
        if (booleanData(root, 'ignoring')) {
          status(root, 'Takip etmek için önce yok saymayı kaldır.', true);
          return;
        }
        follow.disabled = true;
        const action = booleanData(root, 'following') ? 'unfollow' : 'follow';
        try {
          const payload = await mutate(userId, 'follow', action);
          root.dataset.following = payload?.following === true ? '1' : '0';
          status(root, root.dataset.following === '1' ? 'Kullanıcı takip ediliyor.' : 'Takip bırakıldı.');
        } catch (_) {
          status(root, 'Takip durumu güncellenemedi.', true);
        } finally {
          render(root);
        }
      });
    }

    const ignore = root.querySelector('[data-ignore-toggle]');
    if (ignore instanceof HTMLButtonElement) {
      ignore.addEventListener('click', async () => {
        ignore.disabled = true;
        const action = booleanData(root, 'ignoring') ? 'unignore' : 'ignore';
        try {
          const payload = await mutate(userId, 'ignore', action);
          root.dataset.ignoring = payload?.ignoring === true ? '1' : '0';
          if (root.dataset.ignoring === '1') root.dataset.following = '0';
          status(root, root.dataset.ignoring === '1' ? 'Kullanıcı yok sayılıyor.' : 'Yok sayma kaldırıldı.');
        } catch (_) {
          status(root, 'Yok sayma durumu güncellenemedi.', true);
        } finally {
          ignore.disabled = false;
          render(root);
        }
      });
    }
  }
})();
