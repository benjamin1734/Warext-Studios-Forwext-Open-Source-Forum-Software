(() => {
  "use strict";

  const host = document.querySelector("[data-acp-navigation-list][data-acp-navigation-url]");
  if (!(host instanceof HTMLElement)) return;

  let endpoint;
  try {
    endpoint = new URL(host.dataset.acpNavigationUrl ?? "", window.location.href);
  } catch (_error) {
    return;
  }
  if (endpoint.origin !== window.location.origin || endpoint.protocol !== window.location.protocol) return;

  const currentPath = window.location.pathname.replace(/\/+$/, "") || "/";
  const safeLink = (value) => {
    if (typeof value !== "string" || !value.startsWith("/")) return null;
    try {
      const url = new URL(value, window.location.origin);
      if (url.origin !== window.location.origin || url.search || url.hash) return null;
      return url;
    } catch (_error) {
      return null;
    }
  };

  const populate = async () => {
    const response = await fetch(endpoint, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!response.ok) throw new Error("ACP navigation unavailable");

    const payload = await response.json();
    if (!Array.isArray(payload?.sections)) throw new Error("Invalid ACP navigation response");

    const groups = [];
    const anchors = [];
    let firstGroup = null;

    for (const section of payload.sections) {
      if (!section || typeof section.label !== "string" || !Array.isArray(section.items)) continue;
      const details = document.createElement("details");
      details.className = "acp-app-nav-group";
      const summary = document.createElement("summary");
      summary.textContent = section.label;
      const nav = document.createElement("nav");
      nav.setAttribute("aria-label", section.label);

      for (const item of section.items) {
        const url = safeLink(item?.href);
        if (!url || typeof item.label !== "string" || item.label.trim() === "") continue;
        const link = document.createElement("a");
        link.href = url.href;
        link.textContent = item.label;
        nav.appendChild(link);
        anchors.push({ link, details, path: url.pathname.replace(/\/+$/, "") || "/" });
      }

      if (nav.childElementCount === 0) continue;
      details.append(summary, nav);
      firstGroup ??= details;
      groups.push(details);
    }

    if (groups.length === 0) {
      host.dataset.acpNavigationLoaded = "1";
      return;
    }

    // Prefer the most specific existing authorized route; nested screens inherit
    // its active section without painting multiple parent links as current.
    const active = anchors
      .filter(({ path }) => currentPath === path || currentPath.startsWith(path + "/"))
      .sort((left, right) => right.path.length - left.path.length)[0];
    if (active) {
      active.link.setAttribute("aria-current", "page");
      active.details.open = true;
    } else if (firstGroup) {
      firstGroup.open = true;
    }

    // Keep a working server-rendered directory link until the authorized payload
    // has been validated and rendered. No unauthorized links are guessed.
    host.replaceChildren(...groups);
    host.dataset.acpNavigationLoaded = "1";
  };

  void populate().catch(() => {
    // The existing dashboard directory remains usable with JavaScript disabled,
    // expired credentials, or a temporary network failure.
    host.dataset.acpNavigationFailed = "1";
  });
})();
