(() => {
  "use strict";

  // Rendering and disclosure do not depend on JavaScript. Only the user's
  // preference for each visible category is persisted when storage is usable.
  for (const category of document.querySelectorAll("details.forum-category[data-forum-category-key]")) {
    if (!(category instanceof HTMLDetailsElement)) continue;
    const key = category.dataset.forumCategoryKey;
    if (!key || !/^[A-Za-z0-9_.:-]{1,191}$/.test(key)) continue;

    const storageKey = "forwext.forum.category." + key;
    try {
      const saved = window.localStorage.getItem(storageKey);
      if (saved === "closed") category.open = false;
      if (saved === "open") category.open = true;
    } catch (_error) {
      // Blocked storage must never hide categories or break navigation.
    }

    category.addEventListener("toggle", () => {
      try {
        window.localStorage.setItem(storageKey, category.open ? "open" : "closed");
      } catch (_error) {
        // The native disclosure remains fully functional.
      }
    });
  }
})();
