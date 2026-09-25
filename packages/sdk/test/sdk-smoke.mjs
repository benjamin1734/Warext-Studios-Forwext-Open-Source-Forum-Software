import assert from "node:assert/strict";

import {
  ForwextApiError,
  ForwextClient,
  apiKeyAuth,
  bearerAuth,
  nextPageParams,
} from "../dist/index.js";

const calls = [];
const fetchOk = async (input, init = {}) => {
  const url = input instanceof URL ? input : new URL(String(input));
  calls.push({ url, init });

  if (url.pathname.endsWith("/api/v1/notifications")) {
    return Response.json({
      data: {
        items: [{ id: "1".repeat(32), title: "Alert" }],
        pagination: { page: 1, per_page: 25, has_more: true },
      },
    });
  }

  if (url.pathname.endsWith("/api/v1")) {
    return Response.json({
      data: {
        version: "v1",
        resources: [],
        scopes: [],
        endpoints: [],
      },
    });
  }

  return Response.json({ error: { code: "not_found", message: "No route." } }, { status: 404 });
};

const client = new ForwextClient({
  baseUrl: "https://forum.example.com/community/",
  auth: bearerAuth("fxpat_" + "A".repeat(43)),
  fetch: fetchOk,
});

await client.assertCompatible();
const page = await client.notifications({ page: 1, perPage: 25 });
assert.equal(page.items[0]?.title, "Alert");
assert.deepEqual(nextPageParams(page), { page: 2, perPage: 25 });

const notificationCall = calls.find((call) => call.url.pathname.endsWith("/api/v1/notifications"));
assert.ok(notificationCall);
assert.equal(notificationCall.url.pathname, "/community/api/v1/notifications");
assert.equal(notificationCall.url.searchParams.get("page"), "1");
assert.equal(notificationCall.url.searchParams.get("per_page"), "25");
assert.equal(notificationCall.init.headers.Authorization, "Bearer fxpat_" + "A".repeat(43));

const apiKeyClient = new ForwextClient({
  baseUrl: "https://forum.example.com/",
  auth: apiKeyAuth("fxkey_" + "B".repeat(43)),
  fetch: async (_input, init = {}) => {
    assert.equal(init.headers["X-API-Key"], "fxkey_" + "B".repeat(43));
    return Response.json({ data: { items: [], pagination: { page: 1, per_page: 20, has_more: false } } });
  },
});
await apiKeyClient.modules();

const errorClient = new ForwextClient({
  baseUrl: "https://forum.example.com/",
  fetch: async () =>
    Response.json(
      { error: { code: "rate_limited", message: "Slow down.", details: { bucket: "anonymous" } } },
      { status: 429, headers: { "Retry-After": "30" } },
    ),
});

await assert.rejects(
  () => errorClient.forums(),
  (error) =>
    error instanceof ForwextApiError &&
    error.status === 429 &&
    error.code === "rate_limited" &&
    error.retryAfter === 30,
);

const incompatible = new ForwextClient({
  baseUrl: "https://forum.example.com/",
  fetch: async () =>
    Response.json({
      data: { version: "v2", resources: [], scopes: [], endpoints: [] },
    }),
});
await assert.rejects(() => incompatible.assertCompatible(), /version mismatch/u);

console.log("Forwext TypeScript SDK runtime smoke passed.");
