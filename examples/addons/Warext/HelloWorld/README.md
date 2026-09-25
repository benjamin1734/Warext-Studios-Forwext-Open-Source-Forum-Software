# Warext/HelloWorld

Minimal Forwext add-on example for the typed extension APIs introduced in roadmap step 18.

The example intentionally declares only the capability it actually demonstrates: `user_ui`.

It does not bypass lifecycle activation, backend permissions, CSP, or the shared registries. The UI registration returns an `AddonUiRegistration` containing a normal namespaced widget targeting the existing `footer.before` slot.
