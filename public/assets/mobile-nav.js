(() => {
  "use strict";

  const button = document.querySelector("[data-forwext-nav-toggle]");
  const navigation = document.querySelector("[data-forwext-primary-navigation]");
  if (!(button instanceof HTMLButtonElement) || !(navigation instanceof HTMLElement)) {
    return;
  }

  document.documentElement.dataset.forwextMobileNav = "enhanced";

  const setOpen = (open) => {
    navigation.dataset.mobileOpen = open ? "1" : "0";
    button.setAttribute("aria-expanded", open ? "true" : "false");
  };

  setOpen(false);

  button.addEventListener("click", () => {
    setOpen(navigation.dataset.mobileOpen !== "1");
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && navigation.dataset.mobileOpen === "1") {
      setOpen(false);
      button.focus();
    }
  });

  navigation.addEventListener("click", (event) => {
    if (event.target instanceof HTMLAnchorElement) {
      setOpen(false);
    }
  });
})();
