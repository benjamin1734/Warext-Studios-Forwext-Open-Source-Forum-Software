(() => {
  "use strict";

  const root = document.querySelector("[data-layout-builder]");
  if (!(root instanceof HTMLElement)) return;

  const form = root.querySelector("[data-builder-form]");
  const source = root.querySelector("[data-builder-document]");
  const preview = root.querySelector("[data-builder-preview]");
  const routeInput = root.querySelector("[data-preview-route]");
  const audienceInput = root.querySelector("[data-preview-audience]");
  const status = root.querySelector("[data-builder-status]");
  const undoButton = root.querySelector("[data-builder-undo]");
  const redoButton = root.querySelector("[data-builder-redo]");
  if (!(form instanceof HTMLFormElement) || !(source instanceof HTMLTextAreaElement) || !(preview instanceof HTMLElement)) return;

  let model;
  try {
    model = JSON.parse(source.value);
  } catch {
    model = {version: 1, placements: []};
  }
  if (!model || model.version !== 1 || !Array.isArray(model.placements)) model = {version: 1, placements: []};

  const undo = [];
  const redo = [];
  let device = "desktop";
  let draggedId = null;

  const clone = (value) => JSON.parse(JSON.stringify(value));
  const uuid = () => {
    if (globalThis.crypto && typeof globalThis.crypto.getRandomValues === "function") {
      const bytes = new Uint8Array(16);
      globalThis.crypto.getRandomValues(bytes);
      return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    }
    throw new Error("Secure browser randomness is required.");
  };

  const updateHistoryButtons = () => {
    if (undoButton instanceof HTMLButtonElement) undoButton.disabled = undo.length === 0;
    if (redoButton instanceof HTMLButtonElement) redoButton.disabled = redo.length === 0;
  };

  const snapshot = () => {
    undo.push(clone(model));
    if (undo.length > 50) undo.shift();
    redo.length = 0;
    updateHistoryButtons();
  };

  const routeMatches = (pattern, route) => {
    if (pattern === "*") return true;
    const escaped = pattern.replace(/[.+?^$()|[\]\\]/g, "\\$&").replaceAll("*", ".*");
    return new RegExp("^" + escaped + "$").test(route);
  };

  const visibleInPreview = (placement) => {
    if (!placement.enabled) return false;
    const condition = placement.condition || {};
    const devices = Array.isArray(condition.devices) ? condition.devices : ["desktop", "tablet", "mobile"];
    if (!devices.includes(device)) return false;
    const audience = condition.audience || "all";
    const currentAudience = audienceInput instanceof HTMLSelectElement ? audienceInput.value : "member";
    if (audience !== "all" && audience !== currentAudience) return false;
    const route = routeInput instanceof HTMLInputElement ? routeInput.value.trim() || "home" : "home";
    return routeMatches(condition.route || "*", route);
  };

  const normalizeOrders = () => {
    for (const zone of preview.querySelectorAll("[data-layout-dropzone]")) {
      if (!(zone instanceof HTMLElement)) continue;
      const ids = Array.from(zone.querySelectorAll("[data-placement-id]"))
        .map((element) => element.getAttribute("data-placement-id"))
        .filter(Boolean);
      ids.forEach((id, index) => {
        const placement = model.placements.find((entry) => entry.id === id);
        if (placement) {
          placement.slot = zone.dataset.layoutDropzone;
          placement.order = (index + 1) * 100;
        }
      });
    }
    model.placements.sort((a, b) => a.slot.localeCompare(b.slot) || a.order - b.order || a.id.localeCompare(b.id));
  };

  const placementElement = (placement) => {
    const element = document.createElement("article");
    element.className = "builder-placement";
    element.draggable = true;
    element.dataset.placementId = placement.id;
    element.hidden = !visibleInPreview(placement);

    const head = document.createElement("div");
    head.className = "builder-placement-head";
    const title = document.createElement("strong");
    title.textContent = placement.widget;
    const actions = document.createElement("div");
    actions.className = "builder-placement-actions";

    const duplicate = document.createElement("button");
    duplicate.type = "button";
    duplicate.textContent = "Çoğalt";
    duplicate.addEventListener("click", () => {
      snapshot();
      const copy = clone(placement);
      copy.id = uuid();
      copy.order = placement.order + 1;
      model.placements.push(copy);
      render();
    });

    const remove = document.createElement("button");
    remove.type = "button";
    remove.textContent = "Kaldır";
    remove.addEventListener("click", () => {
      snapshot();
      model.placements = model.placements.filter((entry) => entry.id !== placement.id);
      render();
    });

    actions.append(duplicate, remove);
    head.append(title, actions);

    const condition = document.createElement("div");
    condition.className = "builder-condition";

    const routeLabel = document.createElement("label");
    routeLabel.textContent = "Route koşulu";
    const route = document.createElement("input");
    route.value = placement.condition?.route || "*";
    route.maxLength = 128;
    route.addEventListener("change", () => {
      snapshot();
      placement.condition.route = route.value.trim() || "*";
      render();
    });
    routeLabel.append(route);

    const audienceLabel = document.createElement("label");
    audienceLabel.textContent = "Kitle";
    const audience = document.createElement("select");
    for (const value of ["all", "guest", "member"]) {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      option.selected = (placement.condition?.audience || "all") === value;
      audience.append(option);
    }
    audience.addEventListener("change", () => {
      snapshot();
      placement.condition.audience = audience.value;
      render();
    });
    audienceLabel.append(audience);
    condition.append(routeLabel, audienceLabel);

    const devices = document.createElement("div");
    devices.className = "builder-device-checks";
    for (const value of ["desktop", "tablet", "mobile"]) {
      const label = document.createElement("label");
      const input = document.createElement("input");
      input.type = "checkbox";
      input.checked = (placement.condition?.devices || []).includes(value);
      input.addEventListener("change", () => {
        const current = new Set(placement.condition.devices || []);
        if (input.checked) current.add(value); else current.delete(value);
        if (current.size === 0) {
          input.checked = true;
          current.add(value);
          return;
        }
        snapshot();
        placement.condition.devices = Array.from(current);
        render();
      });
      label.append(input, document.createTextNode(value));
      devices.append(label);
    }

    element.addEventListener("dragstart", () => {
      draggedId = placement.id;
      element.setAttribute("aria-grabbed", "true");
    });
    element.addEventListener("dragend", () => {
      draggedId = null;
      element.removeAttribute("aria-grabbed");
    });

    element.append(head, condition, devices);
    return element;
  };

  const render = () => {
    for (const zone of preview.querySelectorAll("[data-layout-dropzone]")) {
      if (zone instanceof HTMLElement) zone.replaceChildren();
    }

    for (const placement of model.placements) {
      const selector = '[data-layout-dropzone="' + CSS.escape(placement.slot) + '"]';
      const zone = preview.querySelector(selector);
      if (zone instanceof HTMLElement) zone.append(placementElement(placement));
    }

    source.value = JSON.stringify(model);
    if (status instanceof HTMLElement) status.textContent = model.placements.length + " yerleşim · " + device;
    updateHistoryButtons();
  };

  for (const zone of preview.querySelectorAll("[data-layout-dropzone]")) {
    if (!(zone instanceof HTMLElement)) continue;
    zone.addEventListener("dragover", (event) => event.preventDefault());
    zone.addEventListener("drop", (event) => {
      event.preventDefault();
      if (!draggedId) return;
      const placement = model.placements.find((entry) => entry.id === draggedId);
      if (!placement) return;
      snapshot();
      placement.slot = zone.dataset.layoutDropzone;
      placement.order = (zone.children.length + 1) * 100;
      render();
    });
  }

  root.querySelectorAll("[data-widget-key]").forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("click", () => {
      snapshot();
      model.placements.push({
        id: uuid(),
        widget: button.dataset.widgetKey,
        slot: button.dataset.widgetSlot,
        order: (model.placements.filter((entry) => entry.slot === button.dataset.widgetSlot).length + 1) * 100,
        enabled: true,
        condition: {route: "*", audience: "all", devices: ["desktop", "tablet", "mobile"]},
      });
      render();
    });
  });

  root.querySelectorAll("[data-builder-device]").forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener("click", () => {
      device = button.dataset.builderDevice || "desktop";
      preview.dataset.device = device;
      render();
    });
  });

  if (routeInput instanceof HTMLInputElement) routeInput.addEventListener("input", render);
  if (audienceInput instanceof HTMLSelectElement) audienceInput.addEventListener("change", render);

  if (undoButton instanceof HTMLButtonElement) {
    undoButton.addEventListener("click", () => {
      if (undo.length === 0) return;
      redo.push(clone(model));
      model = undo.pop();
      render();
    });
  }

  if (redoButton instanceof HTMLButtonElement) {
    redoButton.addEventListener("click", () => {
      if (redo.length === 0) return;
      undo.push(clone(model));
      model = redo.pop();
      render();
    });
  }

  form.addEventListener("submit", () => {
    normalizeOrders();
    source.value = JSON.stringify(model);
  });

  render();
})();
