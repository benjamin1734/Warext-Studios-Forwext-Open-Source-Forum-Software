"use server";

import {
  apiKeyAuth,
  bearerAuth,
  type ForwextAuth,
} from "@forwext/sdk";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import {
  AUTH_BRIDGE_COOKIE,
  AUTH_BRIDGE_MAX_AGE_SECONDS,
  authBridgeConfigured,
  sealAuthCredential,
} from "@/lib/auth-bridge";
import { createForwextClient } from "@/lib/forwext";

export async function connectCredential(formData: FormData): Promise<never> {
  if (!authBridgeConfigured()) {
    redirect("/auth/connect?error=configuration");
  }

  const type = formData.get("type");
  const raw = formData.get("credential");
  const next = safeNext(formData.get("next"));

  if ((type !== "bearer" && type !== "apiKey") || typeof raw !== "string") {
    redirect("/auth/connect?error=credential");
  }

  let auth: ForwextAuth;
  try {
    auth = type === "bearer" ? bearerAuth(raw) : apiKeyAuth(raw);
    await createForwextClient(auth).serviceDocument();
  } catch {
    redirect("/auth/connect?error=credential");
  }

  const cookieStore = await cookies();
  cookieStore.set(AUTH_BRIDGE_COOKIE, sealAuthCredential(auth), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    path: "/",
    maxAge: AUTH_BRIDGE_MAX_AGE_SECONDS,
  });

  redirect(next);
}

export async function disconnectCredential(): Promise<never> {
  const cookieStore = await cookies();
  cookieStore.delete(AUTH_BRIDGE_COOKIE);
  redirect("/");
}

function safeNext(value: FormDataEntryValue | null): string {
  if (
    typeof value === "string" &&
    value.startsWith("/") &&
    !value.startsWith("//") &&
    value.length <= 256
  ) {
    return value;
  }
  return "/account/notifications";
}
