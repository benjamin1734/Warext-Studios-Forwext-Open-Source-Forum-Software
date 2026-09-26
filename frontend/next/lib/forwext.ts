import "server-only";

import {
  ForwextClient,
  type ForwextAuth,
} from "@forwext/sdk";

import { forwextBackendUrl } from "@/lib/runtime-config";

type NextFetchInit = RequestInit & {
  next?: {
    revalidate?: number;
    tags?: string[];
  };
};

export function createForwextClient(auth?: ForwextAuth): ForwextClient {
  const authenticated = auth !== undefined;
  const wrappedFetch: typeof fetch = async (input, init) => {
    if (authenticated) {
      return fetch(input, {
        ...init,
        cache: "no-store",
      });
    }

    const nextInit: NextFetchInit = {
      ...init,
      next: {
        revalidate: 30,
        tags: publicCacheTags(input),
      },
    };
    return fetch(input, nextInit);
  };

  return new ForwextClient({
    baseUrl: forwextBackendUrl(),
    ...(auth === undefined ? {} : { auth }),
    fetch: wrappedFetch,
    headers: {
      "X-Forwext-Frontend": "next",
    },
  });
}

function publicCacheTags(input: RequestInfo | URL): string[] {
  const href =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.href
        : input.url;
  const path = new URL(href).pathname;
  const tags = new Set<string>(["forwext:public"]);

  if (path.includes("/forums")) tags.add("forwext:forums");
  if (path.includes("/threads") || path.includes("/posts")) tags.add("forwext:threads");
  if (path.includes("/marketplace")) tags.add("forwext:marketplace");
  if (path.includes("/modules")) tags.add("forwext:modules");
  if (path.includes("/support")) tags.add("forwext:support");
  if (path.includes("/users")) tags.add("forwext:users");

  return [...tags];
}
