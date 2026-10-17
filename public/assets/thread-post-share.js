// First-party thread and post sharing. Every button carries a server-issued,
// page-aware URL; no tracking endpoints, mutation requests, or dependencies.
(() => {
  "use strict";

  const copyText = async (value) => {
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        await navigator.clipboard.writeText(value);
        return true;
      }
    } catch {
      // Permission or insecure-context failure: use the native selection fallback.
    }

    const previousFocus = document.activeElement;
    const field = document.createElement("textarea");
    field.value = value;
    field.readOnly = true;
    field.setAttribute("aria-hidden", "true");
    field.style.cssText = "position:fixed;left:-9999px;top:0;opacity:0";
    document.body.append(field);
    let copied = false;
    try {
      field.focus();
      field.select();
      copied = document.execCommand("copy");
    } catch {
      copied = false;
    } finally {
      field.remove();
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) {
        previousFocus.focus({ preventScroll: true });
      }
    }
    return copied;
  };

  document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const button = target.closest("[data-thread-copy-url]");
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    const feedback = button.parentElement?.querySelector(".thread-copy-status");
    if (!(feedback instanceof HTMLElement)) return;
    const rawUrl = button.getAttribute("data-thread-copy-url");
    if (!rawUrl) return;
    let url;
    try {
      url = new URL(rawUrl, window.location.href);
    } catch {
      feedback.textContent = "Geçersiz bağlantı.";
      return;
    }
    // A malformed link must never cause a cross-site address to be copied.
    if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) {
      feedback.textContent = "Geçersiz bağlantı.";
      return;
    }

    button.disabled = true;
    feedback.textContent = "";
    try {
      const copied = await copyText(url.href);
      feedback.textContent = copied
        ? "Bağlantı kopyalandı."
        : "Kopyalama engellendi. Mesaj numarasındaki bağlantıyı kullanın.";
    } finally {
      button.disabled = false;
    }
  });
})();
