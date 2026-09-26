import "server-only";

import {
  createCipheriv,
  createDecipheriv,
  createHash,
  randomBytes,
} from "node:crypto";
import { cookies } from "next/headers";
import type { ForwextAuth } from "@forwext/sdk";

import { authBridgeSecret } from "@/lib/runtime-config";

export const AUTH_BRIDGE_COOKIE = "forwext_next_auth";
export const AUTH_BRIDGE_MAX_AGE_SECONDS = 8 * 60 * 60;

interface SealedCredential {
  v: 1;
  type: "bearer" | "apiKey";
  value: string;
  exp: number;
}

export function authBridgeConfigured(): boolean {
  return authBridgeSecret() !== null;
}

export function sealAuthCredential(auth: ForwextAuth): string {
  const key = encryptionKey();
  if (key === null) {
    throw new Error("Forwext Next.js auth bridge is not configured.");
  }

  const payload: SealedCredential = {
    v: 1,
    type: auth.type,
    value: auth.type === "bearer" ? auth.token : auth.key,
    exp: Math.floor(Date.now() / 1000) + AUTH_BRIDGE_MAX_AGE_SECONDS,
  };
  const nonce = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", key, nonce);
  const encrypted = Buffer.concat([
    cipher.update(JSON.stringify(payload), "utf8"),
    cipher.final(),
  ]);
  const tag = cipher.getAuthTag();

  return [nonce, tag, encrypted].map((part) => part.toString("base64url")).join(".");
}

export async function bridgedAuthCredential(): Promise<ForwextAuth | undefined> {
  const raw = (await cookies()).get(AUTH_BRIDGE_COOKIE)?.value;
  if (raw === undefined) return undefined;

  const key = encryptionKey();
  if (key === null) return undefined;

  try {
    const parts = raw.split(".");
    if (parts.length !== 3) return undefined;
    const nonce = Buffer.from(parts[0] ?? "", "base64url");
    const tag = Buffer.from(parts[1] ?? "", "base64url");
    const encrypted = Buffer.from(parts[2] ?? "", "base64url");
    if (nonce.length !== 12 || tag.length !== 16 || encrypted.length < 1 || encrypted.length > 4096) {
      return undefined;
    }

    const decipher = createDecipheriv("aes-256-gcm", key, nonce);
    decipher.setAuthTag(tag);
    const plain = Buffer.concat([decipher.update(encrypted), decipher.final()]).toString("utf8");
    const parsed: unknown = JSON.parse(plain);
    if (!isCredential(parsed) || parsed.exp <= Math.floor(Date.now() / 1000)) {
      return undefined;
    }

    return parsed.type === "bearer"
      ? { type: "bearer", token: parsed.value }
      : { type: "apiKey", key: parsed.value };
  } catch {
    return undefined;
  }
}

function encryptionKey(): Buffer | null {
  const secret = authBridgeSecret();
  return secret === null ? null : createHash("sha256").update(secret, "utf8").digest();
}

function isCredential(value: unknown): value is SealedCredential {
  if (typeof value !== "object" || value === null) return false;
  const row = value as Record<string, unknown>;
  return (
    row.v === 1 &&
    (row.type === "bearer" || row.type === "apiKey") &&
    typeof row.value === "string" &&
    row.value.length >= 10 &&
    row.value.length <= 256 &&
    !/[\r\n\0]/u.test(row.value) &&
    typeof row.exp === "number" &&
    Number.isSafeInteger(row.exp)
  );
}
