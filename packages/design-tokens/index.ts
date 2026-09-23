import backgroundManifest from "../../resources/appearance/forwext-backgrounds-default.json";
import componentManifest from "../../resources/appearance/forwext-components-default.json";
import manifest from "../../resources/design-tokens/forwext-default.json";

export const forwextDesignTokenManifest = manifest;
export const forwextComponentAppearanceManifest = componentManifest;
export const forwextBackgroundManifest = backgroundManifest;

export type ForwextDesignTokenManifest = typeof forwextDesignTokenManifest;
export type ForwextBackgroundManifest = typeof forwextBackgroundManifest;
export type ForwextDesignTokenEntry = ForwextDesignTokenManifest["tokens"][number];
export type ForwextDesignTokenKey = ForwextDesignTokenEntry["key"];

const tokenKeyPattern = /^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/;

export function designTokenCssVariable(key: string): string {
  if (!tokenKeyPattern.test(key)) {
    throw new Error("Invalid Forwext design token key.");
  }

  return `--forwext-${key.replaceAll(".", "-")}`;
}
