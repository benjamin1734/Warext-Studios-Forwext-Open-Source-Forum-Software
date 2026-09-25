export type ForwextAuth =
  | { type: "bearer"; token: string }
  | { type: "apiKey"; key: string };

function assertCredential(value: string): string {
  const normalized = value.trim();
  if (normalized.length < 10 || normalized.length > 256 || /[\r\n\0]/u.test(normalized)) {
    throw new Error("Invalid Forwext API credential.");
  }

  return normalized;
}

export function bearerAuth(token: string): ForwextAuth {
  return { type: "bearer", token: assertCredential(token) };
}

export function apiKeyAuth(key: string): ForwextAuth {
  return { type: "apiKey", key: assertCredential(key) };
}

export function authHeaders(auth?: ForwextAuth): Record<string, string> {
  if (auth === undefined) {
    return {};
  }

  return auth.type === "bearer"
    ? { Authorization: `Bearer ${assertCredential(auth.token)}` }
    : { "X-API-Key": assertCredential(auth.key) };
}
