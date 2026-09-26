import "server-only";

const LOCAL_BACKEND = "http://127.0.0.1:8080/";
const LOCAL_FRONTEND = "http://127.0.0.1:3000/";

export function forwextBackendUrl(): URL {
  return normalizedUrl(process.env.FORWEXT_BACKEND_URL, LOCAL_BACKEND, "FORWEXT_BACKEND_URL");
}

export function forwextPublicUrl(): URL {
  return normalizedUrl(process.env.FORWEXT_PUBLIC_URL, LOCAL_FRONTEND, "FORWEXT_PUBLIC_URL");
}

export function authBridgeSecret(): string | null {
  return boundedSecret(process.env.FORWEXT_NEXT_AUTH_SECRET, 32, 512);
}

export function revalidationSecret(): string | null {
  return boundedSecret(process.env.FORWEXT_NEXT_REVALIDATION_SECRET, 32, 512);
}

function normalizedUrl(raw: string | undefined, fallback: string, label: string): URL {
  const value = raw?.trim() || fallback;
  const url = new URL(value);

  if (!["http:", "https:"].includes(url.protocol) || url.username !== "" || url.password !== "") {
    throw new Error(label + " must be a credential-free HTTP(S) URL.");
  }
  if (
    process.env.NODE_ENV === "production" &&
    url.protocol !== "https:" &&
    !["127.0.0.1", "localhost"].includes(url.hostname)
  ) {
    throw new Error(label + " must use HTTPS outside localhost.");
  }

  url.hash = "";
  url.search = "";
  if (!url.pathname.endsWith("/")) url.pathname += "/";
  return url;
}

function boundedSecret(
  value: string | undefined,
  minimum: number,
  maximum: number,
): string | null {
  if (value === undefined || value.trim() === "") return null;
  if (value.length < minimum || value.length > maximum || /[\r\n\0]/u.test(value)) {
    throw new Error("Forwext Next.js secret configuration is invalid.");
  }
  return value;
}
