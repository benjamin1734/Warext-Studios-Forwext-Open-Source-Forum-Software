import type { ReactNode } from "react";

import { usePermissions } from "./permissions.js";
import {
  type AddonReactSlotRegistry,
  type AddonReactWidgetProps,
} from "./react-slot-registry.js";

export interface AddonSlotProps {
  registry: AddonReactSlotRegistry;
  slot: string;
  context?: Readonly<Record<string, unknown>>;
  permissions?: ReadonlySet<string>;
  empty?: ReactNode;
}

export function AddonSlot({
  registry,
  slot,
  context = {},
  permissions,
  empty = null,
}: AddonSlotProps) {
  const inheritedPermissions = usePermissions();
  const renderers = registry.forSlot(slot, permissions ?? inheritedPermissions);

  if (renderers.length === 0) {
    return <>{empty}</>;
  }

  return (
    <>
      {renderers.map((registration) => {
        const Component = registration.component;
        const props: AddonReactWidgetProps = {
          addonId: registration.addonId,
          widgetKey: registration.widgetKey,
          slot: registration.slot,
          context,
        };

        return <Component key={registration.widgetKey} {...props} />;
      })}
    </>
  );
}
