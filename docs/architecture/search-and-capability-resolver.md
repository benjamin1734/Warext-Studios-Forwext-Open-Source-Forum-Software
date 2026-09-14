# Forwext Search Driver & Capability Resolver Contract

Status: **Normative implementation baseline**  
Roadmap step: **03.07 — Search driver temeli ve capability resolver**

## Search boundary

Forwext search is accessed through `SearchDriver`; forum features do not build provider-specific queries directly. The default shared-host/cPanel path is the native MySQL/MariaDB driver. Advanced deployments can supply an external provider through the same contract.

The search layer is deliberately a **candidate discovery layer**, not an authorization layer. A search hit contains only stable document type/id plus score. The application layer must reload the corresponding domain object and run the normal shared backend permission engine before showing title, body, snippet, attachment metadata or other protected content.

This prevents stale indexes, external-provider mistakes or guessed identifiers from becoming permission bypasses.

## Search documents and access scopes

`SearchDocument` contains:

- stable document type/id;
- title/body index text;
- one or more opaque access-scope tokens;
- UTC updated timestamp;
- optional locale.

Access scopes are an early candidate filter. Typical future scopes may represent public visibility, a forum/node visibility cohort or another bounded permission cohort. They must not contain passwords, secrets, raw session identifiers or sensitive profile data.

A document can carry multiple scopes; a query must carry at least one scope. The native driver requires at least one matching scope before a row can become a candidate hit.

Scope matching does **not** replace final object authorization. Permission changes may race index updates, so final domain reload + permission evaluation remains mandatory.

## Native MySQL/MariaDB driver

`NativeDatabaseSearchDriver` uses an InnoDB FULLTEXT index over title/body and natural-language ranking. User query text, document filters, locale and scope tokens are bound parameters. Only validated numeric pagination values are interpolated into `LIMIT/OFFSET`.

Index writes are transactional at the data-manipulation layer:

1. upsert the document row;
2. replace its scope rows;
3. commit together.

Deletion removes scope rows and document data together. Scope rows also have a cascading FK backstop.

The native driver returns only `SearchHit(documentType, documentId, score)`.

## External search adapter

`ExternalSearchClient` is the provider-neutral port for Elasticsearch/OpenSearch/Meilisearch/Typesense or another approved external service. The core does not require any of those products or SDKs.

A concrete external adapter must:

- preserve the `SearchDocument` access-scope model;
- apply `SearchQuery` scope/type/locale/limit semantics;
- return only validated `SearchHit` candidates;
- avoid returning provider snippets as trusted protected content;
- use the Forwext secret store for credentials;
- use bounded request timeouts and provider-specific TLS verification;
- fail visibly rather than silently widening access or falling back to unscoped search.

Provider packages/dependencies still require the dependency/license/security review established in 01.05.

## Capability resolver

`CapabilityResolver` creates a machine-readable `CapabilityMatrix` from a pluggable probe source. This lets installer/ACP/health/deployment code answer “what can this server actually support?” without scattered `extension_loaded()` and `function_exists()` checks.

Minimum-profile capabilities include:

- PHP >= 8.4;
- OpenSSL;
- PDO;
- PDO MySQL;
- MySQL PDO driver;
- `random_bytes`;
- JSON encoding;
- password hashing;
- file uploads enabled.

Optional capabilities are reported independently, including Redis, Intl, Fileinfo, GD, Imagick, Sodium, `finfo_open`, `proc_open`, `exec` and CLI SAPI.

A missing optional capability must disable or route around only the feature that requires it; it must not make the cPanel baseline falsely fail installation.

## Database server capabilities

`DatabaseServerCapabilityProbe` reads server version/comment and classifies MySQL, MariaDB or unknown. It reports InnoDB FULLTEXT capability conservatively using minimum known version thresholds.

The native search driver should only be selected for an actual installation when the database probe confirms native FULLTEXT support. External search remains a possible advanced alternative; therefore native FULLTEXT support itself is not marked as an unconditional minimum-profile PHP capability.

Unknown database server identity fails closed for native FULLTEXT capability rather than guessing support.

## Capability metadata safety

The matrix exposes operational metadata useful for installer/ACP diagnostics: PHP version, SAPI, selected INI size/time limits, PDO driver names and database vendor/version when available.

Capability probes must not expose:

- database credentials;
- environment secrets;
- master/secret-store keys;
- full environment-variable dumps;
- arbitrary filesystem contents;
- raw command output.

Function availability such as `exec`/`proc_open` is reported only as a boolean capability, not exercised by the resolver.

## Database schema

Core migration `20260914223000_search_index` creates:

- `forwext_search_documents` with a FULLTEXT `(title, body)` index;
- `forwext_search_document_scopes` with scope lookup index and cascading document FK.

Migration verification requires both tables and the FULLTEXT index to exist.

## Deployment default

The default configuration is:

- `search.driver = native`.

This keeps the minimum deployment free from a mandatory external search daemon. Install/bootstrap capability selection must refuse native search when the actual database server cannot satisfy its required FULLTEXT capability rather than silently running a broken driver.

## Security / permission review

- Search hits never grant content access.
- Driver results intentionally omit indexed title/body/snippet content.
- Final domain authorization is mandatory after candidate discovery.
- Access scopes reduce candidate leakage but are not the final permission decision.
- Query text and scope/type/locale filters use bound parameters.
- External providers receive only data intentionally selected for indexing and must preserve scope semantics.
- Capability inspection does not execute dangerous functions merely to test them.

## Acceptance status

03.07 is complete when native MySQL/MariaDB search, provider-neutral external search adapter, versioned FULLTEXT schema migration, PHP extension/function/runtime/server capability matrix and tests for parameterization, scope filtering, adapter contract, migration verification and capability resolution exist as real code/configuration/documentation.
