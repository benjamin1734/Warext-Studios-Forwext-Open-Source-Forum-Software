import type { MetadataRoute } from "next";

import { forwextPublicUrl } from "@/lib/runtime-config";

export default function robots(): MetadataRoute.Robots {
  const base = forwextPublicUrl();
  return {
    rules: [
      {
        userAgent: "*",
        allow: "/",
        disallow: ["/account/", "/auth/"],
      },
    ],
    sitemap: new URL("sitemap.xml", base).href,
  };
}
