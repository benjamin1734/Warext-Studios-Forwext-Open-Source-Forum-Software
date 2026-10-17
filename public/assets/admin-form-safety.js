(() => {
  "use strict";

  if (document.body.dataset.forwextSurface !== "admin") return;

  const dirtyForms = new Set();
  let submittingForm = null;

  const editable = (control) =>
    (control instanceof HTMLInputElement
      || control instanceof HTMLTextAreaElement
      || control instanceof HTMLSelectElement)
    && control.name !== ""
    && !control.disabled
    && !(control instanceof HTMLInputElement
      && (["hidden", "button", "submit", "reset", "image", "file"].includes(control.type)
        || control.readOnly))
    && !(control instanceof HTMLTextAreaElement && control.readOnly);

  const fingerprint = (form) => JSON.stringify(
    Array.from(form.elements).filter(editable).map((control) => {
      if (control instanceof HTMLInputElement && ["checkbox", "radio"].includes(control.type)) {
        return [control.name, control.type, control.value, control.checked];
      }
      if (control instanceof HTMLSelectElement && control.multiple) {
        return [control.name, "multiple", Array.from(control.selectedOptions, (option) => option.value)];
      }
      return [control.name, control.type, control.value];
    }),
  );

  for (const form of document.querySelectorAll('form.ac-form[method="post"]')) {
    if (!(form instanceof HTMLFormElement)) continue;
    if (!Array.from(form.elements).some(editable)) continue;

    const initial = fingerprint(form);
    const notice = document.createElement("div");
    notice.className = "ac-form-unsaved";
    notice.hidden = true;
    notice.setAttribute("role", "status");
    notice.setAttribute("aria-live", "polite");

    const message = document.createElement("span");
    message.textContent = "Kaydedilmemiş değişiklikler";
    const reset = document.createElement("button");
    reset.type = "button";
    reset.className = "ac-btn ac-form-unsaved-reset";
    reset.textContent = "Değişiklikleri geri al";
    reset.addEventListener("click", () => {
      form.reset();
      sync();
    });

    notice.append(message, reset);
    form.append(notice);

    const sync = () => {
      const changed = fingerprint(form) !== initial;
      notice.hidden = !changed;
      form.classList.toggle("ac-form-is-dirty", changed);
      if (changed) dirtyForms.add(form);
      else dirtyForms.delete(form);
    };

    form.addEventListener("input", sync);
    form.addEventListener("change", sync);
    form.addEventListener("reset", () => queueMicrotask(sync));
    form.addEventListener("submit", () => {
      submittingForm = form;
      // If navigation is cancelled by another dirty form, restore guarding.
      window.setTimeout(() => { if (submittingForm === form) submittingForm = null; }, 1000);
    });
  }

  window.addEventListener("beforeunload", (event) => {
    if (dirtyForms.size === 0) return;
    if (submittingForm && dirtyForms.size === 1 && dirtyForms.has(submittingForm)) return;
    event.preventDefault();
    event.returnValue = "";
  });
})();
