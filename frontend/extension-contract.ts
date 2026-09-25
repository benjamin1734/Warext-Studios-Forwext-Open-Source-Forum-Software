export type AddonUiAssetKind = "css" | "js";
export type AddonUiAudience = "public" | "member";
export type AddonUiRegion = "header" | "main" | "sidebar" | "footer" | "page";

export interface AddonUiAssetManifestEntry {
  key: string;
  kind: AddonUiAssetKind;
  path: string;
  integrity: string;
}

export interface AddonUiExtensionManifestEntry {
  id: string;
  namespace: string;
  slots: Array<{ key: string; region: AddonUiRegion; order: number }>;
  widgets: Array<{ key: string; slot: string; order: number }>;
  navigation: Array<{
    key: string;
    label: string;
    path: string;
    order: number;
    audience: AddonUiAudience;
  }>;
  editor_extensions: Array<{
    key: string;
    label: string;
    order: number;
    surfaces: Array<"thread" | "post">;
  }>;
  templates: string[];
  design_tokens: Array<{ key: string; category: string }>;
  assets: AddonUiAssetManifestEntry[];
}

export interface AddonUiExtensionManifestV1 {
  schema: 1;
  addons: AddonUiExtensionManifestEntry[];
  assets: AddonUiAssetManifestEntry[];
}
