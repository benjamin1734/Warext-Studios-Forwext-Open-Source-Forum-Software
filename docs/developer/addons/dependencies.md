# Add-on dependencies

Declare add-on requirements under `requires.addons` and conflicts under `conflicts.addons`.

Forwext validates:

- missing required packages;
- version constraints;
- bidirectional conflicts;
- dependency cycles;
- dependent add-ons before disable/uninstall.

The developer platform also exposes deterministic dependency-first ordering through `AddonDependencyResolver::resolveInstallOrder()` for a closed package set.

The resolver never downloads remote code. Package acquisition remains a separate trusted operation.
