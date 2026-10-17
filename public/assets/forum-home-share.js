// Forum home sharing. Progressive enhancement: no external requests, trackers,
// permissions or hard-coded domain. The native page remains usable without JS.
(() => {
  "use strict";
  const card = document.querySelector("[data-forum-share]");
  if (!card) return;
  const button = card.querySelector("[data-forum-share-copy]");
  const feedback = card.querySelector("[data-forum-share-feedback]");
  if (!(button instanceof HTMLButtonElement) || !feedback) return;

  let busy = false;
  button.addEventListener("click", async () => {
    if (busy) return;
    busy = true;
    button.disabled = true;
    feedback.textContent = "";
    const url = window.location.href;
    let copied = false;

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        await navigator.clipboard.writeText(url);
        copied = true;
      }
    } catch {
      // Browser or user denied the clipboard permission. Try the DOM fallback.
    }

    if (!copied) {
      const temporary = document.createElement("textarea");
      temporary.value = url;
      temporary.readOnly = true;
      temporary.setAttribute("aria-hidden", "true");
      temporary.style.position = "fixed";
      temporary.style.opacity = "0";
      temporary.style.pointerEvents = "none";
      document.body.append(temporary);
      try {
        temporary.focus();
        temporary.select();
        copied = document.execCommand("copy");
      } catch {
        copied = false;
      } finally {
        temporary.remove();
      }
    }
    feedback.textContent = copied
      ? "Sayfa bağlantısı kopyalandı."
      : "Kopyalama engellendi. Adres çubuğundaki bağlantıyı kopyalayabilirsiniz.";
    button.disabled = false;
    busy = false;
  });
})();
