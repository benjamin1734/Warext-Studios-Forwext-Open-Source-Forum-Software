import { createHash, timingSafeEqual } from "node:crypto";
import { revalidatePath, revalidateTag } from "next/cache";

import { revalidationSecret } from "@/lib/runtime-config";

export const dynamic = "force-dynamic";

const EVENT_POLICY: Readonly<Record<string, { tags: readonly string[]; paths: readonly string[] }>> = {
  "forum.changed": { tags: ["forwext:forums"], paths: ["/forums"] },
  "thread.changed": { tags: ["forwext:threads"], paths: ["/forums"] },
  "marketplace.changed": { tags: ["forwext:marketplace"], paths: ["/marketplace"] },
  "module.changed": { tags: ["forwext:modules"], paths: ["/modules"] },
  "support.changed": { tags: ["forwext:support"], paths: ["/support"] },
  "user.changed": { tags: ["forwext:users"], paths: [] },
  "public.changed": { tags: ["forwext:public"], paths: ["/"] },
};

export async function POST(request: Request) {
  const configuredSecret = revalidationSecret();
  const suppliedSecret = request.headers.get("x-forwext-revalidation-secret");
  if (
    configuredSecret === null ||
    suppliedSecret === null ||
    !sameSecret(configuredSecret, suppliedSecret)
  ) {
    return Response.json(
      { error: { code: "unauthorized", message: "Revalidation authentication failed." } },
      { status: 401, headers: noStoreHeaders() },
    );
  }

  const declaredLength = request.headers.get("content-length");
  if (declaredLength !== null && (!/^[0-9]{1,5}$/u.test(declaredLength) || Number(declaredLength) > 4096)) {
    return Response.json(
      { error: { code: "payload_too_large", message: "Revalidation payload is too large." } },
      { status: 413, headers: noStoreHeaders() },
    );
  }

  let body: unknown;
  try {
    const raw = await request.text();
    if (raw.length > 4096) throw new Error("oversized");
    body = JSON.parse(raw);
  } catch {
    return Response.json(
      { error: { code: "invalid_request", message: "Invalid revalidation payload." } },
      { status: 400, headers: noStoreHeaders() },
    );
  }

  const event = eventName(body);
  const policy = event === null ? undefined : EVENT_POLICY[event];
  if (event === null || policy === undefined) {
    return Response.json(
      { error: { code: "unsupported_event", message: "Unsupported revalidation event." } },
      { status: 400, headers: noStoreHeaders() },
    );
  }

  for (const tag of policy.tags) revalidateTag(tag, "max");
  for (const path of policy.paths) revalidatePath(path);

  return Response.json(
    { data: { event, tags: policy.tags, paths: policy.paths } },
    { status: 200, headers: noStoreHeaders() },
  );
}

function eventName(value: unknown): string | null {
  if (typeof value !== "object" || value === null) return null;
  const event = (value as { event?: unknown }).event;
  return typeof event === "string" && /^[a-z][a-z0-9_.-]{2,80}$/u.test(event) ? event : null;
}

function sameSecret(expected: string, actual: string): boolean {
  if (actual.length < 1 || actual.length > 512 || /[\r\n\0]/u.test(actual)) return false;
  const left = createHash("sha256").update(expected, "utf8").digest();
  const right = createHash("sha256").update(actual, "utf8").digest();
  return timingSafeEqual(left, right);
}

function noStoreHeaders(): HeadersInit {
  return {
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
  };
}
