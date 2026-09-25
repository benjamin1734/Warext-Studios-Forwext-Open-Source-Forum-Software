# Forwext add-on developer guide

Forwext add-ons use canonical `Vendor/AddOn` identity and the same backend/UI registries as first-party systems.

## Typical workflow

1. Create: `php tools/forwext.php addon:create Vendor/AddOn`
2. Generate code with `make:class`, `make:service` or `make:entity`.
3. Declare real package capabilities in `addon.json`.
4. Run `addon:check Vendor/AddOn`.
5. Build with `addon:build Vendor/AddOn`.
6. Publish the generated ZIP together with its `.sha256` sidecar.
7. Optionally sign the ZIP with `addon:sign`.
8. Verify signatures with an explicit trusted-key file and `addon:verify`.

Developer mode is tool-only. It never bypasses ACP permissions, backend permission checks or persisted add-on lifecycle state.

See also:

- `capabilities.md`
- `dependencies.md`
- `package-signing.md`
- `sample-addon.md`
