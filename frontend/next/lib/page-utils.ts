import "server-only";

import { ForwextApiError } from "@forwext/sdk";
import { notFound, redirect } from "next/navigation";

import { bridgedAuthCredential } from "@/lib/auth-bridge";
import { createForwextClient } from "@/lib/forwext";

export function pageNumber(value: string | string[] | undefined): number {
  const raw = Array.isArray(value) ? value[0] : value;
  if (raw === undefined) return 1;
  if (!/^[1-9][0-9]{0,5}$/u.test(raw)) return 1;
  return Math.min(Number.parseInt(raw, 10), 100000);
}

export function notFoundOn404(error: unknown): never {
  if (error instanceof ForwextApiError && error.status === 404) {
    notFound();
  }
  throw error;
}

export async function protectedClient(nextPath: string) {
  const auth = await bridgedAuthCredential();
  if (auth === undefined) {
    redirect("/auth/connect?next=" + encodeURIComponent(nextPath));
  }
  return createForwextClient(auth);
}

export function reconnectOnUnauthorized(error: unknown, nextPath: string): never {
  if (error instanceof ForwextApiError && error.status === 401) {
    redirect(
      "/auth/connect?error=credential&next=" + encodeURIComponent(nextPath),
    );
  }
  throw error;
}
