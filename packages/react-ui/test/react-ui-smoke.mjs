import assert from "node:assert/strict";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";

import {
  AddonReactSlotRegistry,
  AddonSlot,
  Can,
  ForwextAlert,
  ForwextButton,
  PermissionProvider,
  designTokenCssVariable,
  designTokenReference,
} from "../dist/index.js";

assert.equal(designTokenCssVariable("semantic.accent.primary"), "--forwext-semantic-accent-primary");
assert.equal(
  designTokenReference("semantic.accent.primary"),
  "var(--forwext-semantic-accent-primary)",
);
assert.throws(() => designTokenCssVariable("invalid token"), /Invalid Forwext design-token key/u);

const button = renderToStaticMarkup(
  React.createElement(ForwextButton, { loading: true }, "Kaydet"),
);
assert.match(button, /type="button"/u);
assert.match(button, /aria-busy="true"/u);
assert.match(button, /disabled=""/u);
assert.match(button, /fxr-button--primary/u);

const alert = renderToStaticMarkup(
  React.createElement(ForwextAlert, { tone: "danger", title: "Hata" }, "İşlem başarısız."),
);
assert.match(alert, /role="alert"/u);
assert.match(alert, /aria-live="assertive"/u);

const allowed = renderToStaticMarkup(
  React.createElement(
    PermissionProvider,
    { permissions: ["support.ticket.view_own"] },
    React.createElement(Can, { required: "support.ticket.view_own", fallback: "Yok" }, "Var"),
  ),
);
assert.match(allowed, />Var</u);
assert.doesNotMatch(allowed, /Yok/u);

const denied = renderToStaticMarkup(
  React.createElement(
    PermissionProvider,
    { permissions: [] },
    React.createElement(Can, { required: "support.ticket.view_own", fallback: "Yok" }, "Var"),
  ),
);
assert.match(denied, /Yok/u);
assert.doesNotMatch(denied, />Var</u);

const manifest = {
  schema: 1,
  addons: [
    {
      id: "Acme/Demo",
      namespace: "addon.acme.demo",
      slots: [],
      widgets: [
        { key: "addon.acme.demo.widget.early", slot: "page.sidebar", order: 10 },
        { key: "addon.acme.demo.widget.secure", slot: "page.sidebar", order: 20 },
      ],
      navigation: [],
      editor_extensions: [],
      templates: [],
      design_tokens: [],
      assets: [],
    },
  ],
  assets: [],
};

const registry = new AddonReactSlotRegistry(manifest);
registry.register({
  addonId: "Acme/Demo",
  widgetKey: "addon.acme.demo.widget.early",
  component: ({ context }) => React.createElement("span", null, `early:${String(context.value)}`),
});
registry.register({
  addonId: "Acme/Demo",
  widgetKey: "addon.acme.demo.widget.secure",
  requiredPermissions: ["demo.widget.view"],
  component: () => React.createElement("span", null, "secure"),
});

const slotAllowed = renderToStaticMarkup(
  React.createElement(
    PermissionProvider,
    { permissions: ["demo.widget.view"] },
    React.createElement(AddonSlot, { registry, slot: "page.sidebar", context: { value: 7 } }),
  ),
);
assert.ok(slotAllowed.indexOf("early:7") < slotAllowed.indexOf("secure"));
assert.match(slotAllowed, /secure/u);

const slotDenied = renderToStaticMarkup(
  React.createElement(
    PermissionProvider,
    { permissions: [] },
    React.createElement(AddonSlot, { registry, slot: "page.sidebar", context: { value: 8 } }),
  ),
);
assert.match(slotDenied, /early:8/u);
assert.doesNotMatch(slotDenied, /secure/u);

assert.throws(
  () =>
    registry.register({
      addonId: "Acme/Demo",
      widgetKey: "addon.acme.demo.widget.unknown",
      component: () => React.createElement("span", null, "bad"),
    }),
  /not declared by the enabled UI manifest/u,
);

assert.throws(
  () =>
    new AddonReactSlotRegistry({
      ...manifest,
      addons: [{ ...manifest.addons[0], namespace: "addon.other.owner" }],
    }),
  /namespace does not match its add-on id/u,
);

console.log("Forwext React UI/accessibility/permission/slot smoke passed.");
