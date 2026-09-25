# Package checksums and signing

## Build checksum

`php tools/forwext.php addon:build Vendor/AddOn` produces:

- the deterministic ZIP;
- a sibling `.sha256` checksum file.

Keep both files together on release mirrors.

## Sign

Use a private OpenSSL key stored outside the repository:

```text
php tools/forwext.php addon:sign dist/addons/vendor-addon-v1.0.0.zip vendor.release.2026 /secure/private.pem
```

For encrypted private keys, provide the passphrase through `FORWEXT_SIGNING_KEY_PASSPHRASE`. Do not place the passphrase in shell history or source control.

The command writes `artifact.zip.sig.json` by default and refuses to overwrite an existing signature file.

## Trusted keyring

Verification uses an explicit JSON trust store:

```json
{
  "schema": 1,
  "keys": [
    {
      "id": "vendor.release.2026",
      "public_key_pem": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n",
      "official": false
    }
  ]
}
```

Mark a key `official: true` only when that public key was obtained from an authenticated Forwext/Warext Studios release channel.

## Verify

```text
php tools/forwext.php addon:verify artifact.zip artifact.zip.sig.json trusted-keys.json signed
```

Policy may be `optional`, `signed` or `official`.

If a signature path is explicitly supplied, missing/invalid/tampered signatures fail closed even when the policy is optional.
