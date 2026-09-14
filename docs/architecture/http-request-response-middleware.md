# Forwext HTTP Request, Response & Middleware Contract

Status: **Normative implementation baseline**  
Roadmap step: **02.05 — Request/Response/Middleware**

## Request model

`Request` is a typed HTTP boundary carrying method, raw URI, validated headers, query values, parsed form data, raw body, request cookies, normalized uploads, server metadata and immutable-style attributes.

At this step the request deliberately does **not** decide canonical host/scheme/client IP from proxy headers. Trusted-proxy and canonical-URL semantics belong to 02.06 so untrusted forwarded headers cannot become implicit truth early.

`Request::fromGlobals()` is the PHP-SAPI adapter. Other frontends/tests may construct the same request model directly.

## Headers

`HeaderBag` treats header names case-insensitively, validates token syntax and rejects CR/LF/NUL in values. This prevents request/response splitting primitives from being introduced through application header APIs.

Multiple values remain distinct internally. Code must not parse `Set-Cookie` by comma joining; response cookie handling appends distinct `Set-Cookie` values.

## JSON

Request JSON parsing requires `application/json` or a `+json` media type and enforces a caller-selected byte limit before decoding. Invalid JSON fails explicitly; it does not silently become null/empty input.

Response JSON uses throwing encoding and explicit UTF-8 JSON content type.

A later route/controller layer should select endpoint-specific body limits rather than trusting client `Content-Length` alone.

## Cookies

Request cookies are normalized as string key/value data. Response cookies are serialized through `ResponseCookie`, with CRLF/NUL rejection, secure defaults, SameSite support and browser prefix invariants:

- `SameSite=None` requires `Secure`;
- `__Secure-` requires `Secure`;
- `__Host-` requires `Secure`, `Path=/` and no `Domain`.

Authentication/session code added later is responsible for rotation, signing/token semantics and session fixation protection.

## Uploads

`UploadNormalizer` converts PHP's nested `$_FILES` shape into typed `UploadedFile` objects and rejects malformed/mismatched trees.

`UploadedFile::moveTo()` only moves a successful file verified by PHP as an HTTP upload. Client filename/media type are metadata only and are never treated as trusted filesystem paths or trusted MIME classification.

Full MIME/signature/quota/image/orphan-cleanup policy belongs to the media pipeline at 07.01; this step provides the secure typed transport primitive without pretending client metadata is validation.

## Response model

`Response` validates HTTP status range, carries body/headers and returns modified copies for status/header/cookie changes. Helpers exist for text, HTML and JSON responses.

Actual SAPI emission is intentionally kept separate so tests, APIs and future realtime/worker contexts can use response objects without calling global `header()`/`echo` functions.

## Middleware pipeline

Middleware follows a request-handler chain. The pipeline is index-free per invocation, so the same pipeline object can process multiple requests without leaking cursor state between requests.

Middleware can transform the request passed inward and the response returned outward. Router dispatch becomes a terminal/middleware participant in later steps rather than duplicating the pipeline.

## Request ID correlation

`RequestIdMiddleware` accepts an incoming ID only when it matches a strict printable identifier policy (`8..128` safe token characters). Invalid/untrusted formatting is discarded and a 128-bit random hexadecimal ID is generated.

The chosen ID is stored in the request attribute `request_id` and returned as `X-Request-ID`, giving later logs, audit events, errors, jobs and diagnostics one correlation value without allowing CRLF/log-control injection.

Request IDs identify/correlate work; they are not authentication tokens and must never grant authorization.

## Security boundaries

- Header/cookie APIs reject control-character injection.
- Body parsing uses explicit limits and throwing failures.
- Forwarded/proxy headers are not trusted yet.
- Client upload names/types are untrusted metadata.
- Upload movement requires PHP HTTP-upload verification.
- Middleware does not replace backend permission checks.
- Request IDs are correlation only.

No database migration is required for 02.05.
