# Official TypeScript SDK (19.04)

The official `@forwext/sdk` package is a dependency-free TypeScript/ESM client for the Forwext REST API v1.

## Shared API contract

`tools/generate-api-v1-sdk-contract.php` reads the PHP `ApiV1EndpointRegistry`, resource enum and scope enum and generates `packages/sdk/src/generated/contract.ts`.

CI runs the generator in check mode. Any PHP endpoint/resource/scope change that does not update the generated SDK contract fails the build.

The generated contract exposes route names, paths, methods, operations, resources, scopes and public/protected status as literal TypeScript types. Response DTOs use typed SDK interfaces matched to the server v1 response shapes.

## Client

`ForwextClient` exposes typed read methods for all 19.01 v1 resources. It normalizes a credential-free installation root, including subfolder installations, and resolves API paths relative to that root.

Networking uses WHATWG `fetch`, `URL` and `AbortSignal`. The SDK has no runtime package dependency.

## Authentication

`bearerAuth()` is used for PAT/OAuth-type bearer credentials.

`apiKeyAuth()` is used for `X-API-Key` credentials.

Authorization, API-key, Host and Content-Length headers are SDK-managed and cannot be overwritten through custom header configuration.

## Pagination and errors

Collection methods return `ApiPage<T>` values matching the server's bounded pagination contract.

`nextPageParams()` returns the next cursor-like page parameters or `null`. `ForwextClient.paginate()` provides an async iterator over item arrays.

`ForwextApiError` retains HTTP status, stable API error code, optional details and numeric Retry-After metadata when present.

## Version compatibility

The SDK embeds the supported API major contract as `FORWEXT_API_VERSION = "v1"`.

`assertCompatible()` reads the service document and throws `ForwextCompatibilityError` when a server exposes a different API contract version. This protects consumers from silently treating a future incompatible API as v1.

## Browser and server support

The package is ESM and publishes `browser`, `import` and `default` conditions that resolve to the same standards-based runtime.

CI verifies:

- TypeScript strict typecheck;
- declaration/runtime build;
- live client runtime smoke using Node's Web Platform APIs;
- subfolder URL behavior;
- absence of Node-only imports/CommonJS/process APIs in compiled runtime files;
- `npm pack --dry-run` contains the runtime, declarations, package metadata and README while excluding source/tests.

Node/npm are SDK tooling and consumer concerns. They are not required to run Forwext's native PHP frontend or REST API on standard cPanel hosting.
