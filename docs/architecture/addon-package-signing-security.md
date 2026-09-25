# Add-on package signing and security

Roadmap step 18.06 adds a distribution-security layer on top of the add-on manifest, lifecycle and developer tooling.

## Checksums are always present

`addon:build` produces a deterministic ZIP and a sibling `.sha256` file. The ZIP still contains `forwext-build.json`, whose per-file SHA-256 inventory covers packaged source files.

The sidecar checksum is intended for mirrors, release pages and quick integrity verification. A checksum proves integrity against a known expected digest; it does not identify the publisher.

## Signatures and trust

Signatures are separate from checksums.

`AddonArtifactSignature` signs a versioned canonical payload containing the SHA-256 digest of the built artifact. Signing and verification reuse the already-required OpenSSL extension and therefore add no new production PHP extension.

Trust is explicit:

- `unsigned`: no signature was supplied and policy allowed it;
- `signed`: the signature is valid and its key is present in the supplied trusted keyring;
- `official`: the signature is valid and the trusted key entry is explicitly marked official.

The `official` flag is trust-store metadata, not a claim made by an add-on package. An arbitrary publisher cannot make its own signature official by setting data inside the package.

If an explicit signature path is supplied but missing, unsafe or invalid, verification fails even under the optional policy. This prevents a typo or missing file from silently downgrading a requested verification to unsigned.

## Policies

`AddonSignaturePolicy` supports:

- `optional`: unsigned artifacts are allowed, but any supplied signature must validate;
- `signed`: a valid trusted signature is required;
- `official`: a valid signature from a key marked official in the trusted keyring is required.

The example trusted-key configuration is intentionally empty. Forwext does not invent or silently trust a Warext Studios release key. Official public keys must be distributed through a separately authenticated release channel before being marked `official: true`.

## Capability disclosure

`addon.json` may declare typed capabilities such as database access, filesystem access, outbound network access, background work, ACP/UI extensions, permission definitions and webhooks.

Capability disclosure is used to produce administrator/developer warnings. It is **not** an authorization grant and it is not a sandbox. Runtime permissions, route authorization, lifecycle state and all existing security boundaries remain authoritative.

Unknown capability names fail manifest validation.

## Dependency planning

The existing dependency resolver now exposes deterministic closed-set install ordering. It validates required packages, version constraints, conflicts and cycles before returning dependency-first order.

This planning API does not download arbitrary packages and does not weaken install/upgrade checks.

## Deployment

- PHP minimum remains 8.4.
- OpenSSL was already a required Forwext extension.
- No Node/npm, Redis, Docker, Supervisor, worker or SSH requirement is introduced.
- Signing private keys are developer/release secrets and must never be bundled into production packages.
