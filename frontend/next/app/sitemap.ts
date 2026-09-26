import type { MetadataRoute } from "next";

import { forwextPublicUrl } from "@/lib/runtime-config";

export default function sitemap(): MetadataRoute.Sitemap {
  const base = forwextPublicUrl();
  const paths = ["/", "/forums", "/marketplace", "/modules", "/support"];

  return paths.map((path) => ({
    url: new URL(path.replace(/^\//u, ""), base).href,
    changeFrequency: path === "/" ? "hourly" : "daily",
    priority: path === "/" ? 1 : 0.8,
  }));
}
