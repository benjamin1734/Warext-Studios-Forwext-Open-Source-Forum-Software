import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile, readdir } from "node:fs/promises";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const testDir = dirname(fileURLToPath(import.meta.url));
const sdkRoot = join(testDir, "..");
const repositoryRoot = join(sdkRoot, "..", "..");
const packageJson = JSON.parse(await readFile(join(sdkRoot, "package.json"), "utf8"));

assert.equal(packageJson.name, "@forwext/sdk");
assert.equal(packageJson.type, "module");
assert.equal(packageJson.exports["."].types, "./dist/index.d.ts");
assert.equal(packageJson.exports["."].browser, "./dist/index.js");
assert.equal(packageJson.exports["."].import, "./dist/index.js");
assert.equal(packageJson.exports["."].default, "./dist/index.js");

const module = await import("../dist/index.js");
for (const exportName of [
  "ForwextClient",
  "ForwextApiError",
  "ForwextCompatibilityError",
  "bearerAuth",
  "apiKeyAuth",
  "nextPageParams",
]) {
  assert.ok(exportName in module, `Missing SDK export: ${exportName}`);
}

const builtFiles = await walk(join(sdkRoot, "dist"));
for (const file of builtFiles.filter((path) => path.endsWith(".js"))) {
  const source = await readFile(file, "utf8");
  assert.doesNotMatch(source, /from\s+["']node:/u, `Node-only import leaked into ${relative(sdkRoot, file)}`);
  assert.doesNotMatch(source, /require\s*\(/u, `CommonJS require leaked into ${relative(sdkRoot, file)}`);
  assert.doesNotMatch(source, /\bprocess\.(?:env|versions|platform)\b/u, `Node process API leaked into ${relative(sdkRoot, file)}`);
}

const packed = JSON.parse(
  execFileSync(
    process.platform === "win32" ? "npm.cmd" : "npm",
    ["pack", "--dry-run", "--json", sdkRoot],
    { cwd: repositoryRoot, encoding: "utf8" },
  ),
);
assert.ok(Array.isArray(packed) && packed.length === 1, "npm pack dry-run did not return one package.");
const packedFiles = new Set(packed[0].files.map((entry) => entry.path));
for (const required of ["package.json", "README.md", "dist/index.js", "dist/index.d.ts"]) {
  assert.ok(packedFiles.has(required), `Packed SDK is missing ${required}`);
}
for (const path of packedFiles) {
  assert.ok(!path.startsWith("src/"), `SDK source file unexpectedly published: ${path}`);
  assert.ok(!path.startsWith("test/"), `SDK test file unexpectedly published: ${path}`);
}

console.log("Forwext SDK package/browser-server contract smoke passed.");

async function walk(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) files.push(...(await walk(path)));
    else if (entry.isFile()) files.push(path);
  }
  return files;
}
