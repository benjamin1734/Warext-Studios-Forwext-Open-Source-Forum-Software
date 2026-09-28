(() => {
  "use strict";

  const button = document.querySelector("[data-forwext-nav-toggle]");
  const navigation = document.querySelector("[data-forwext-primary-navigation]");
  const header = document.querySelector(".top");
  const subnav = document.querySelector("[data-forwext-subnav]");
  const subnavShell = document.querySelector("[data-forwext-subnav-shell]");
  if (!(button instanceof HTMLButtonElement) || !(navigation instanceof HTMLElement) || !(header instanceof HTMLElement)) {
    return;
  }

  document.documentElement.dataset.forwextMobileNav = "enhanced";

  const normalizePath = (value) => {
    const collapsed = value.replace(/\/{2,}/g, "/");
    return collapsed.length > 1 ? collapsed.replace(/\/+$/, "") : collapsed;
  };

  const sectionForPath = (path) => {
    if (path === "/") return "home";
    if (path === "/activity" || path.startsWith("/activity/")) return "whatsnew";
    if (
      path === "/marketplace" || path.startsWith("/marketplace/") ||
      path === "/portfolio" || path.startsWith("/portfolio/") ||
      path === "/giveaways" || path.startsWith("/giveaways/")
    ) return "marketplace";
    if (
      path === "/members" || path.startsWith("/members/") ||
      path === "/stats"
    ) return "members";
    if (
      path === "/account" || path.startsWith("/account/") ||
      path === "/bugs" || path.startsWith("/bugs/")
    ) return "account";
    if (path === "/faq" || path.startsWith("/faq/")) return "more";
    if (
      path === "/forums" || path.startsWith("/forums/") ||
      path.startsWith("/threads/") || path === "/search"
    ) return "forums";
    return "home";
  };

  const setOpen = (open) => {
    navigation.dataset.mobileOpen = open ? "1" : "0";
    button.setAttribute("aria-expanded", open ? "true" : "false");
  };

  const closeMenus = (except = null) => {
    for (const details of header.querySelectorAll("details[open]")) {
      if (details !== except && details instanceof HTMLDetailsElement) {
        details.open = false;
      }
    }
  };

  const markCurrentNavigation = () => {
    const currentPath = normalizePath(window.location.pathname);
    const activeSection = sectionForPath(currentPath);
    header.dataset.activeNavSection = activeSection;

    for (const anchor of header.querySelectorAll("[aria-current]")) {
      anchor.removeAttribute("aria-current");
    }
    for (const details of header.querySelectorAll("[data-active]")) {
      delete details.dataset.active;
    }

    const primary = header.querySelector('[data-nav-section-link="' + activeSection + '"]');
    if (primary instanceof HTMLAnchorElement) {
      primary.setAttribute("aria-current", "page");
    } else if (primary instanceof HTMLDetailsElement) {
      primary.dataset.active = "1";
    }

    if (activeSection === "account") {
      const accountMenu = header.querySelector(".nav-account-menu");
      if (accountMenu instanceof HTMLElement) accountMenu.dataset.active = "1";
    }

    const anchors = [...header.querySelectorAll("[data-nav-key][href]")];
    let exact = null;
    let exactLength = -1;
    for (const anchor of anchors) {
      if (!(anchor instanceof HTMLAnchorElement)) continue;
      const url = new URL(anchor.href, window.location.href);
      if (url.origin !== window.location.origin) continue;

      const path = normalizePath(url.pathname);
      const matches = path === "/"
        ? currentPath === "/"
        : currentPath === path || currentPath.startsWith(path + "/");

      if (matches && path.length > exactLength) {
        exact = anchor;
        exactLength = path.length;
      }
    }
    if (exact instanceof HTMLAnchorElement && !exact.hasAttribute("data-nav-section-link")) {
      exact.setAttribute("aria-current", "page");
    }

    let visibleSubnav = false;
    if (subnav instanceof HTMLElement) {
      for (const group of subnav.querySelectorAll("[data-nav-section]")) {
        if (!(group instanceof HTMLElement)) continue;
        const visible = group.dataset.navSection === activeSection && group.children.length > 0;
        group.hidden = !visible;
        visibleSubnav ||= visible;
      }
    }
    if (subnavShell instanceof HTMLElement) {
      subnavShell.hidden = !visibleSubnav;
    }
  };

  const syncHeader = () => {
    header.dataset.scrolled = window.scrollY > 8 ? "1" : "0";
  };

  setOpen(false);
  markCurrentNavigation();
  syncHeader();

  button.addEventListener("click", () => {
    const open = navigation.dataset.mobileOpen !== "1";
    setOpen(open);
    if (!open) closeMenus();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      if (navigation.dataset.mobileOpen === "1") {
        setOpen(false);
        closeMenus();
        button.focus();
        return;
      }
      closeMenus();
    }
  });

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Node)) return;

    const details = target instanceof Element ? target.closest("details") : null;
    closeMenus(details instanceof HTMLDetailsElement ? details : null);

    if (navigation.dataset.mobileOpen !== "1") return;
    if (navigation.contains(target) || button.contains(target)) return;
    setOpen(false);
  });

  navigation.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const link = target.closest("a");
    if (link instanceof HTMLAnchorElement) setOpen(false);
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
