import { authHeaders, type ForwextAuth } from "./auth.js";
import { ForwextCompatibilityError, parseApiError } from "./errors.js";
import { paginationQuery, type PageParams } from "./pagination.js";
import type {
  ApiEnvelope,
  ApiPage,
  ApiServiceDocument,
  ConversationSummary,
  Forum,
  MarketplaceListing,
  ModuleSummary,
  Notification,
  Post,
  SupportCategory,
  SupportTicket,
  Thread,
  User,
} from "./types.js";

export const FORWEXT_API_VERSION = "v1" as const;

export interface ForwextClientOptions {
  baseUrl: string | URL;
  auth?: ForwextAuth;
  fetch?: typeof fetch;
  headers?: Record<string, string>;
}

export class ForwextClient {
  readonly #baseUrl: URL;
  readonly #auth?: ForwextAuth;
  readonly #fetch: typeof fetch;
  readonly #headers: Record<string, string>;

  public constructor(options: ForwextClientOptions) {
    this.#baseUrl = normalizeBaseUrl(options.baseUrl);
    if (options.auth !== undefined) this.#auth = options.auth;

    const fetchImpl = options.fetch ?? globalThis.fetch;
    if (typeof fetchImpl !== "function") {
      throw new Error("Forwext SDK requires a WHATWG-compatible fetch implementation.");
    }
    this.#fetch = fetchImpl.bind(globalThis);
    this.#headers = normalizeHeaders(options.headers ?? {});
  }

  public serviceDocument(signal?: AbortSignal): Promise<ApiServiceDocument> {
    return this.#get<ApiServiceDocument>("/api/v1", undefined, signal);
  }

  public async assertCompatible(signal?: AbortSignal): Promise<ApiServiceDocument> {
    const document = await this.serviceDocument(signal);
    if (document.version !== FORWEXT_API_VERSION) {
      throw new ForwextCompatibilityError(FORWEXT_API_VERSION, String(document.version));
    }
    return document;
  }

  public user(userId: string, signal?: AbortSignal): Promise<User> {
    return this.#get<User>(`/api/v1/users/${entityId(userId)}`, undefined, signal);
  }

  public forums(params?: PageParams, signal?: AbortSignal): Promise<ApiPage<Forum>> {
    return this.#page<Forum>("/api/v1/forums", params, signal);
  }

  public forum(forumId: string, signal?: AbortSignal): Promise<Forum> {
    return this.#get<Forum>(`/api/v1/forums/${entityId(forumId)}`, undefined, signal);
  }

  public forumThreads(
    forumId: string,
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<Thread>> {
    return this.#page<Thread>(`/api/v1/forums/${entityId(forumId)}/threads`, params, signal);
  }

  public thread(threadId: string, signal?: AbortSignal): Promise<Thread> {
    return this.#get<Thread>(`/api/v1/threads/${entityId(threadId)}`, undefined, signal);
  }

  public threadPosts(
    threadId: string,
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<Post>> {
    return this.#page<Post>(`/api/v1/threads/${entityId(threadId)}/posts`, params, signal);
  }

  public post(postId: string, signal?: AbortSignal): Promise<Post> {
    return this.#get<Post>(`/api/v1/posts/${entityId(postId)}`, undefined, signal);
  }

  public conversations(
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<ConversationSummary>> {
    return this.#page<ConversationSummary>("/api/v1/conversations", params, signal);
  }

  public notifications(
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<Notification>> {
    return this.#page<Notification>("/api/v1/notifications", params, signal);
  }

  public modules(params?: PageParams, signal?: AbortSignal): Promise<ApiPage<ModuleSummary>> {
    return this.#page<ModuleSummary>("/api/v1/modules", params, signal);
  }

  public marketplace(
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<MarketplaceListing>> {
    return this.#page<MarketplaceListing>("/api/v1/marketplace", params, signal);
  }

  public marketplaceListing(
    listingId: string,
    signal?: AbortSignal,
  ): Promise<MarketplaceListing> {
    return this.#get<MarketplaceListing>(
      `/api/v1/marketplace/${entityId(listingId)}`,
      undefined,
      signal,
    );
  }

  public supportCategories(
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<SupportCategory>> {
    return this.#page<SupportCategory>("/api/v1/support/categories", params, signal);
  }

  public supportTickets(
    params?: PageParams,
    signal?: AbortSignal,
  ): Promise<ApiPage<SupportTicket>> {
    return this.#page<SupportTicket>("/api/v1/support/tickets", params, signal);
  }

  public async *paginate<T>(
    loader: (params: PageParams, signal?: AbortSignal) => Promise<ApiPage<T>>,
    params: PageParams = {},
    signal?: AbortSignal,
  ): AsyncGenerator<T[], void, undefined> {
    let current: PageParams = params;
    while (true) {
      const page = await loader(current, signal);
      yield page.items;
      if (!page.pagination.has_more) return;
      current = {
        page: page.pagination.page + 1,
        perPage: page.pagination.per_page,
      };
    }
  }

  async #page<T>(path: string, params: PageParams = {}, signal?: AbortSignal): Promise<ApiPage<T>> {
    return this.#get<ApiPage<T>>(path, paginationQuery(params), signal);
  }

  async #get<T>(
    path: string,
    query?: URLSearchParams,
    signal?: AbortSignal,
  ): Promise<T> {
    const url = new URL(path, this.#baseUrl);
    if (query !== undefined) url.search = query.toString();

    const response = await this.#fetch(url, {
      method: "GET",
      headers: {
        Accept: "application/json",
        ...this.#headers,
        ...authHeaders(this.#auth),
      },
      ...(signal === undefined ? {} : { signal }),
    });

    const payload = await parseJson(response);
    if (!response.ok) {
      throw parseApiError(response.status, payload, response.headers.get("retry-after"));
    }

    if (!isEnvelope(payload)) {
      throw new Error("Forwext API returned an invalid response envelope.");
    }

    return payload.data as T;
  }
}

function normalizeBaseUrl(value: string | URL): URL {
  const url = value instanceof URL ? new URL(value.href) : new URL(value);
  if (!["http:", "https:"].includes(url.protocol) || url.username !== "" || url.password !== "") {
    throw new Error("Forwext SDK baseUrl must be a credential-free HTTP(S) URL.");
  }
  url.hash = "";
  url.search = "";
  if (!url.pathname.endsWith("/")) url.pathname += "/";
  return url;
}

function normalizeHeaders(headers: Record<string, string>): Record<string, string> {
  const normalized: Record<string, string> = {};
  for (const [name, value] of Object.entries(headers)) {
    if (!/^[A-Za-z][A-Za-z0-9-]{0,63}$/u.test(name) || /[\r\n\0]/u.test(value)) {
      throw new Error("Invalid custom Forwext SDK header.");
    }
    const lower = name.toLowerCase();
    if (["authorization", "x-api-key", "host", "content-length"].includes(lower)) {
      throw new Error(`Header "${name}" is managed by the Forwext SDK.`);
    }
    normalized[name] = value;
  }
  return normalized;
}

function entityId(value: string): string {
  const normalized = value.trim().toLowerCase();
  if (!/^[a-f0-9]{32}$/u.test(normalized)) {
    throw new Error("Forwext entity id must be 32 lowercase hexadecimal characters.");
  }
  return normalized;
}

async function parseJson(response: Response): Promise<unknown> {
  const contentType = response.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().includes("application/json")) {
    throw new Error(`Forwext API returned non-JSON content (HTTP ${response.status}).`);
  }
  return response.json() as Promise<unknown>;
}

function isEnvelope(value: unknown): value is ApiEnvelope<unknown> {
  return typeof value === "object" && value !== null && "data" in value;
}
