(() => {
  "use strict";

  const button = document.querySelector("[data-forwext-nav-toggle]");
  const navigation = document.querySelector("[data-forwext-primary-navigation]");
  const header = document.querySelector(".top");
  if (!(button instanceof HTMLButtonElement) || !(navigation instanceof HTMLElement)) {
    return;
  }

  document.documentElement.dataset.forwextMobileNav = "enhanced";

  const setOpen = (open) => {
    navigation.dataset.mobileOpen = open ? "1" : "0";
    button.setAttribute("aria-expanded", open ? "true" : "false");
  };

  const normalizePath = (value) => {
    const collapsed = value.replace(/\/{2,}/g, "/");
    return collapsed.length > 1 ? collapsed.replace(/\/+$/, "") : collapsed;
  };

  const markCurrentNavigation = () => {
    if (!(header instanceof HTMLElement)) return;

    const currentPath = normalizePath(window.location.pathname);
    const anchors = [...header.querySelectorAll("[data-nav-key][href]")];
    let current = null;
    let currentLength = -1;

    for (const anchor of anchors) {
      if (!(anchor instanceof HTMLAnchorElement)) continue;

      const url = new URL(anchor.href, window.location.href);
      if (url.origin !== window.location.origin) continue;

      const path = normalizePath(url.pathname);
      const matches = path === "/"
        ? currentPath === "/"
        : currentPath === path || currentPath.startsWith(path + "/");

      if (matches && path.length > currentLength) {
        current = anchor;
        currentLength = path.length;
      }
    }

    if (current === null && currentPath.includes("/threads/")) {
      current = header.querySelector('[data-nav-key="forums"]');
    }

    if (current instanceof HTMLAnchorElement) {
      current.setAttribute("aria-current", "page");
      const accountMenu = current.closest(".nav-account-menu");
      if (accountMenu instanceof HTMLElement) {
        accountMenu.dataset.active = "1";
      }
    }
  };

  const syncHeader = () => {
    if (!(header instanceof HTMLElement)) return;
    header.dataset.scrolled = window.scrollY > 8 ? "1" : "0";
  };

  const closeAccountMenus = (except = null) => {
    if (!(header instanceof HTMLElement)) return;
    for (const details of header.querySelectorAll("details[open]")) {
      if (details !== except && details instanceof HTMLDetailsElement) {
        details.open = false;
      }
    }
  };

  setOpen(false);
  markCurrentNavigation();
  syncHeader();

  button.addEventListener("click", () => {
    const open = navigation.dataset.mobileOpen !== "1";
    setOpen(open);
    if (!open) closeAccountMenus();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;

    if (navigation.dataset.mobileOpen === "1") {
      setOpen(false);
      closeAccountMenus();
      button.focus();
      return;
    }

    closeAccountMenus();
  });

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Node)) return;

    const accountMenu = target instanceof Element ? target.closest(".nav-account-menu") : null;
    closeAccountMenus(accountMenu instanceof HTMLDetailsElement ? accountMenu : null);

    if (navigation.dataset.mobileOpen !== "1") return;
    if (navigation.contains(target) || button.contains(target)) return;
    setOpen(false);
  });

  navigation.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const link = target.closest("a");
    if (link instanceof HTMLAnchorElement) {
      setOpen(false);
    }
  });

  const desktop = window.matchMedia("(min-width: 921px)");
  const closeForDesktop = (event) => {
    if (event.matches) setOpen(false);
  };
  if (typeof desktop.addEventListener === "function") {
    desktop.addEventListener("change", closeForDesktop);
  } else if (typeof desktop.addListener === "function") {
    desktop.addListener(closeForDesktop);
  }

  window.addEventListener("scroll", syncHeader, { passive: true });
})();
