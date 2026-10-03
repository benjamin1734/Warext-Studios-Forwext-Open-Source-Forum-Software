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

  const desktop = window.matchMedia("(min-width: 921px)");
  const focusableSelector = [
    "a[href]",
    "button:not([disabled])",
    "summary",
    "input:not([disabled])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    "[tabindex]:not([tabindex='-1'])",
  ].join(",");

  const topLayerMenuSupported =
    typeof HTMLElement.prototype.showPopover === "function"
    && typeof HTMLElement.prototype.hidePopover === "function";

  const positionTopLayerMenu = (details, popover) => {
    const summary = details.querySelector(":scope > summary");
    if (!(summary instanceof HTMLElement) || !(popover instanceof HTMLElement)) return;

    const trigger = summary.getBoundingClientRect();
    const width = Math.min(230, Math.max(180, window.innerWidth - 24));
    const preferredLeft = details.classList.contains("nav-account-menu")
      ? trigger.right - width
      : trigger.left;
    const left = Math.min(
      Math.max(12, preferredLeft),
      Math.max(12, window.innerWidth - width - 12),
    );
    const top = Math.min(trigger.bottom + 7, Math.max(12, window.innerHeight - 56));

    popover.style.position = "fixed";
    popover.style.inset = "auto";
    popover.style.margin = "0";
    popover.style.left = left + "px";
    popover.style.right = "auto";
    popover.style.top = top + "px";
    popover.style.width = width + "px";
    popover.style.maxHeight = Math.max(120, window.innerHeight - top - 12) + "px";
    popover.style.overflowY = "auto";
  };

  const syncTopLayerMenu = (details, popover) => {
    if (!topLayerMenuSupported) return;
    if (details.open) {
      positionTopLayerMenu(details, popover);
      if (!popover.matches(":popover-open")) {
        try {
          popover.showPopover();
        } catch (_error) {
          return;
        }
      }
      positionTopLayerMenu(details, popover);
      return;
    }

    if (popover.matches(":popover-open")) {
      try {
        popover.hidePopover();
      } catch (_error) {
        // A concurrently closed popover is already in the desired state.
      }
    }
  };

  const topLayerMenus = [];
  if (topLayerMenuSupported) {
    for (const details of header.querySelectorAll(".nav-primary-menu, .nav-account-menu")) {
      if (!(details instanceof HTMLDetailsElement)) continue;
      const popover = details.querySelector(":scope > .nav-primary-popover, :scope > .nav-account-popover");
      if (!(popover instanceof HTMLElement)) continue;

      topLayerMenus.push([details, popover]);
      details.addEventListener("toggle", () => {
        if (desktop.matches) syncTopLayerMenu(details, popover);
      });
    }
  }

  const clearTopLayerPosition = (popover) => {
    for (const property of ["position", "inset", "margin", "left", "right", "top", "width", "max-height", "overflow-y"]) {
      popover.style.removeProperty(property);
    }
  };

  const configureTopLayerMenus = () => {
    for (const [details, popover] of topLayerMenus) {
      if (desktop.matches) {
        popover.setAttribute("popover", "manual");
        popover.dataset.forwextTopLayer = "1";
        syncTopLayerMenu(details, popover);
        continue;
      }

      if (popover.matches(":popover-open")) {
        try {
          popover.hidePopover();
        } catch (_error) {
          // A concurrently closed popover is already in the desired state.
        }
      }
      popover.removeAttribute("popover");
      delete popover.dataset.forwextTopLayer;
      clearTopLayerPosition(popover);
    }
  };

  const repositionTopLayerMenus = () => {
    if (!desktop.matches) return;
    for (const [details, popover] of topLayerMenus) {
      if (details.open && popover.matches(":popover-open")) {
        positionTopLayerMenu(details, popover);
      }
    }
  };

  const normalizePath = (value) => {
    const collapsed = value.replace(/\/{2,}/g, "/");
    return collapsed.length > 1 ? collapsed.replace(/\/+$/, "") : collapsed;
  };

  const brandHome = header.querySelector(".brand[href]");
  const basePath = brandHome instanceof HTMLAnchorElement
    ? normalizePath(new URL(brandHome.href, window.location.href).pathname)
    : "/";
  const relativeToBasePath = (path) => {
    if (basePath === "/") return path;
    if (path === basePath) return "/";
    if (path.startsWith(basePath + "/")) return path.slice(basePath.length);
    return path;
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

  const visibleFocusable = () => [...navigation.querySelectorAll(focusableSelector)].filter((element) => {
    if (!(element instanceof HTMLElement)) return false;
    if (element.closest("[hidden]")) return false;
    const style = window.getComputedStyle(element);
    return style.display !== "none"
      && style.visibility !== "hidden"
      && (element.offsetWidth > 0 || element.offsetHeight > 0 || element.getClientRects().length > 0);
  });

  const syncNavigationAccessibility = (open) => {
    if (desktop.matches) {
      navigation.removeAttribute("inert");
      navigation.removeAttribute("aria-hidden");
      return;
    }

    navigation.toggleAttribute("inert", !open);
    navigation.setAttribute("aria-hidden", open ? "false" : "true");
  };

  const setOpen = (open, moveFocus = false) => {
    navigation.dataset.mobileOpen = open ? "1" : "0";
    button.setAttribute("aria-expanded", open ? "true" : "false");
    document.body.classList.toggle("forwext-nav-open", open);
    syncNavigationAccessibility(open);

    if (open && moveFocus && !desktop.matches) {
      window.requestAnimationFrame(() => {
        const first = visibleFocusable()[0];
        if (first instanceof HTMLElement) first.focus();
      });
    }
  };

  const closeMenus = (restoreFocus = false, except = null) => {
    const active = document.activeElement;
    for (const details of header.querySelectorAll("details[open]")) {
      if (details === except || !(details instanceof HTMLDetailsElement)) continue;
      const summary = details.querySelector(":scope > summary");
      const shouldRestore = restoreFocus
        && active instanceof Node
        && details.contains(active)
        && summary instanceof HTMLElement;
      details.open = false;
      if (shouldRestore) summary.focus();
    }
  };

  const markCurrentNavigation = () => {
    const currentPath = normalizePath(window.location.pathname);
    let activeSection = sectionForPath(relativeToBasePath(currentPath));
    if (
      activeSection === "marketplace"
      && header.querySelector('[data-nav-section-link="marketplace"]') === null
      && header.querySelector('[data-nav-section-link="more"]') !== null
    ) {
      activeSection = "more";
    }
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
  configureTopLayerMenus();

  button.addEventListener("click", () => {
    const open = navigation.dataset.mobileOpen !== "1";
    setOpen(open, open);
    if (!open) closeMenus();
  });

  document.addEventListener("keydown", (event) => {
    if (
      event.key === "Tab"
      && !desktop.matches
      && navigation.dataset.mobileOpen === "1"
    ) {
      const focusable = visibleFocusable();
      if (focusable.length === 0) {
        event.preventDefault();
        button.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
      return;
    }

    if (event.key === "Escape") {
      if (navigation.dataset.mobileOpen === "1") {
        setOpen(false);
        closeMenus();
        button.focus();
        return;
      }
      closeMenus(true);
    }
  });

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Node)) return;

    const details = target instanceof Element ? target.closest("details") : null;
    closeMenus(false, details instanceof HTMLDetailsElement ? details : null);

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

  const closeForDesktop = (event) => {
    if (event.matches) {
      setOpen(false);
    } else {
      syncNavigationAccessibility(navigation.dataset.mobileOpen === "1");
    }
    configureTopLayerMenus();
  };
  if (typeof desktop.addEventListener === "function") {
    desktop.addEventListener("change", closeForDesktop);
  } else if (typeof desktop.addListener === "function") {
    desktop.addListener(closeForDesktop);
  }

  window.addEventListener("scroll", () => {
    syncHeader();
    repositionTopLayerMenus();
  }, { passive: true });
  window.addEventListener("resize", repositionTopLayerMenus, { passive: true });
})();
