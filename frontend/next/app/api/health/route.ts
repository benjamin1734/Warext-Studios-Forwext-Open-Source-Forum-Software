import { forwextBackendUrl } from "@/lib/runtime-config";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    const apiUrl = new URL("api/v1", forwextBackendUrl());
    const response = await fetch(apiUrl, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(3000),
    });
    if (!response.ok) {
      return Response.json(
        { status: "unhealthy", backend: "unavailable" },
        { status: 503, headers: noStoreHeaders() },
      );
    }

    return Response.json(
      { status: "healthy", backend: "ok" },
      { status: 200, headers: noStoreHeaders() },
    );
  } catch {
    return Response.json(
      { status: "unhealthy", backend: "unavailable" },
      { status: 503, headers: noStoreHeaders() },
    );
  }
}

function noStoreHeaders(): HeadersInit {
  return {
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
  };
}
