# @forwext/sdk

Official dependency-free TypeScript client for the Forwext versioned REST API.

The SDK uses Web Platform APIs (`fetch`, `URL`, `AbortSignal`) so the same client works in modern browsers and Node.js 20+ without a runtime dependency.

## Basic use

```ts
import { ForwextClient, bearerAuth } from "@forwext/sdk";

const client = new ForwextClient({
  baseUrl: "https://forum.example.com/",
  auth: bearerAuth(process.env.FORWEXT_TOKEN!),
});

await client.assertCompatible();

const firstPage = await client.notifications({ perPage: 50 });
for (const item of firstPage.items) {
  console.log(item.title);
}
```

API keys use `apiKeyAuth(...)`. The client rejects attempts to override managed authentication headers through custom headers.

`ForwextApiError` exposes the HTTP status, stable API error code, optional details and parsed `Retry-After` value.

Collection methods return typed `ApiPage<T>` values. `client.paginate(...)` can stream page item arrays while respecting server pagination metadata.

Node/npm are SDK development/consumer concerns only. The Forwext PHP application and cPanel production runtime do not require Node.js to serve the REST API.


## Runtime compatibility

The package is ESM and exposes the same dependency-free build for browser and server consumers. It does not import Node.js built-ins; runtime networking uses the WHATWG `fetch`, `URL` and `AbortSignal` APIs.

- Modern browsers: use the `browser`/ES module export.
- Node.js 20+: use the ESM `import` export.
- Subfolder installations are supported by passing the installation root as `baseUrl`, for example `https://forum.example.com/community/`.

`assertCompatible()` verifies the live Forwext API major contract before application code relies on the client. A mismatch throws `ForwextCompatibilityError`.
