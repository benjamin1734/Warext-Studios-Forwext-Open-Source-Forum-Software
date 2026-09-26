import type { ComponentType } from "react";

import type {
  AddonUiExtensionManifestEntry,
  AddonUiExtensionManifestV1,
} from "./addon-manifest.js";

const ADDON_ID = /^[A-Za-z][A-Za-z0-9]{1,63}\/[A-Za-z][A-Za-z0-9]{1,63}$/u;
const NAMESPACE = /^addon\.[a-z][a-z0-9_.-]+$/u;
const UI_KEY = /^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/u;
const PERMISSION_KEY = /^[a-z][a-z0-9_.-]{1,95}$/u;

export interface AddonReactWidgetProps {
  addonId: string;
  widgetKey: string;
  slot: string;
  context: Readonly<Record<string, unknown>>;
}

export type AddonReactWidgetComponent = ComponentType<AddonReactWidgetProps>;

export interface AddonReactWidgetRegistration {
  addonId: string;
  widgetKey: string;
  component: AddonReactWidgetComponent;
  requiredPermissions?: readonly string[];
}

export interface RegisteredAddonReactWidget {
  addonId: string;
  namespace: string;
  widgetKey: string;
  slot: string;
  order: number;
  component: AddonReactWidgetComponent;
  requiredPermissions: readonly string[];
}

interface WidgetDeclaration {
  addon: AddonUiExtensionManifestEntry;
  key: string;
  slot: string;
  order: number;
}

export class AddonReactSlotRegistry {
  readonly #widgets = new Map<string, WidgetDeclaration>();
  readonly #renderers = new Map<string, RegisteredAddonReactWidget>();

  public constructor(manifest: AddonUiExtensionManifestV1) {
    if (manifest.schema !== 1 || !Array.isArray(manifest.addons) || !Array.isArray(manifest.assets)) {
      throw new Error("Unsupported Forwext add-on UI manifest.");
    }

    const addonIds = new Set<string>();
    const namespaces = new Set<string>();
    for (const addon of manifest.addons) {
      this.#indexAddon(addon, addonIds, namespaces);
    }
  }

  public register(registration: AddonReactWidgetRegistration): void {
    const declaration = this.#widgets.get(registration.widgetKey);
    if (declaration === undefined || declaration.addon.id !== registration.addonId) {
      throw new Error("React add-on widget is not declared by the enabled UI manifest.");
    }
    if (this.#renderers.has(registration.widgetKey)) {
      throw new Error(`React add-on widget renderer is already registered: ${registration.widgetKey}`);
    }

    const requiredPermissions = normalizePermissions(registration.requiredPermissions ?? []);
    this.#renderers.set(registration.widgetKey, {
      addonId: declaration.addon.id,
      namespace: declaration.addon.namespace,
      widgetKey: declaration.key,
      slot: declaration.slot,
      order: declaration.order,
      component: registration.component,
      requiredPermissions,
    });
  }

  public forSlot(
    slot: string,
    permissions: ReadonlySet<string> = new Set<string>(),
  ): readonly RegisteredAddonReactWidget[] {
    if (!UI_KEY.test(slot)) {
      throw new Error("Invalid Forwext React slot key.");
    }

    const items = [...this.#renderers.values()].filter(
      (entry) =>
        entry.slot === slot &&
        entry.requiredPermissions.every((permission) => permissions.has(permission)),
    );
    items.sort((left, right) => left.order - right.order || left.widgetKey.localeCompare(right.widgetKey));

    return items;
  }

  public hasRenderer(widgetKey: string): boolean {
    return this.#renderers.has(widgetKey);
  }

  #indexAddon(
    addon: AddonUiExtensionManifestEntry,
    addonIds: Set<string>,
    namespaces: Set<string>,
  ): void {
    if (!ADDON_ID.test(addon.id) || !NAMESPACE.test(addon.namespace)) {
      throw new Error("Invalid add-on identity in UI manifest.");
    }
    if (addonIds.has(addon.id) || namespaces.has(addon.namespace)) {
      throw new Error("Duplicate add-on identity in UI manifest.");
    }
    addonIds.add(addon.id);
    namespaces.add(addon.namespace);

    for (const widget of addon.widgets) {
      if (
        !UI_KEY.test(widget.key) ||
        !widget.key.startsWith(addon.namespace + ".") ||
        !UI_KEY.test(widget.slot) ||
        !Number.isInteger(widget.order) ||
        widget.order < -10000 ||
        widget.order > 10000
      ) {
        throw new Error("Invalid add-on widget declaration in UI manifest.");
      }
      if (this.#widgets.has(widget.key)) {
        throw new Error(`Duplicate add-on widget declaration: ${widget.key}`);
      }
      this.#widgets.set(widget.key, {
        addon,
        key: widget.key,
        slot: widget.slot,
        order: widget.order,
      });
    }
  }
}

function normalizePermissions(values: readonly string[]): readonly string[] {
  const unique = new Set<string>();
  for (const permission of values) {
    const key = permission.trim().toLowerCase();
    if (!PERMISSION_KEY.test(key)) {
      throw new Error("Invalid Forwext permission key.");
    }
    unique.add(key);
  }
  return [...unique].sort();
}
