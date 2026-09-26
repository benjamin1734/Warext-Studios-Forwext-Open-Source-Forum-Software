import type { NextConfig } from "next";

const backendFallback = backendFallbackDestination();

const nextConfig: NextConfig = {
  output: "standalone",
  poweredByHeader: false,
  reactStrictMode: true,
  transpilePackages: ["@forwext/sdk", "@forwext/react-ui"],
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "SAMEORIGIN" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=(), payment=()",
          },
        ],
      },
    ];
  },
  async rewrites() {
    return {
      beforeFiles: [],
      afterFiles: [],
      fallback: [
        {
          source: "/:path*",
          destination: backendFallback,
        },
      ],
    };
  },
};

export default nextConfig;

function backendFallbackDestination(): string {
  const raw = process.env.FORWEXT_BACKEND_URL?.trim() || "http://127.0.0.1:8080/";
  const url = new URL(raw);
  if (!["http:", "https:"].includes(url.protocol) || url.username !== "" || url.password !== "") {
    throw new Error("FORWEXT_BACKEND_URL must be a credential-free HTTP(S) URL.");
  }
  url.hash = "";
  url.search = "";
  if (!url.pathname.endsWith("/")) url.pathname += "/";
  return new URL(":path*", url).href;
}
