import {
  FORWEXT_CORE_DESIGN_TOKEN_CSS_VARIABLES,
  FORWEXT_CORE_DESIGN_TOKENS,
  type ForwextCoreDesignTokenKey,
} from "./generated/design-tokens.js";

const TOKEN_KEY = /^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/u;

export {
  FORWEXT_CORE_DESIGN_TOKEN_CSS_VARIABLES,
  FORWEXT_CORE_DESIGN_TOKENS,
  type ForwextCoreDesignTokenKey,
};

export function designTokenCssVariable(key: string): `--forwext-${string}` {
  if (!TOKEN_KEY.test(key)) {
    throw new Error("Invalid Forwext design-token key.");
  }

  return `--forwext-${key.replaceAll(".", "-")}`;
}

export function designTokenReference(
  key: string,
  fallback?: string,
): string {
  const variable = designTokenCssVariable(key);
  return fallback === undefined ? `var(${variable})` : `var(${variable}, ${fallback})`;
}
