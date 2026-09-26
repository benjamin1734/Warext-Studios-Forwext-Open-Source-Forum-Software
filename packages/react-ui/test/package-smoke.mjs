import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile, readdir } from "node:fs/promises";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const testDir = dirname(fileURLToPath(import.meta.url));
const packageRoot = join(testDir, "..");
const repositoryRoot = join(packageRoot, "..", "..");
const packageJson = JSON.parse(await readFile(join(packageRoot, "package.json"), "utf8"));

assert.equal(packageJson.name, "@forwext/react-ui");
assert.equal(packageJson.type, "module");
assert.equal(packageJson.exports["."].types, "./dist/index.d.ts");
assert.equal(packageJson.exports["."].browser, "./dist/index.js");
assert.equal(packageJson.exports["./styles.css"], "./styles.css");
assert.equal(packageJson.peerDependencies.react, ">=18.3.0 <20");

const module = await import("../dist/index.js");
for (const exportName of [
  "AddonReactSlotRegistry",
  "AddonSlot",
  "Can",
  "ForwextAlert",
  "ForwextButton",
  "PermissionProvider",
  "designTokenReference",
]) {
  assert.ok(exportName in module, `Missing React UI export: ${exportName}`);
}

for (const clientBoundary of ["dist/permissions.js", "dist/AddonSlot.js"]) {
  const source = await readFile(join(packageRoot, clientBoundary), "utf8");
  assert.match(
    source,
    /^["']use client["'];/u,
    `React client boundary directive missing from ${clientBoundary}`,
  );
}

const builtFiles = await walk(join(packageRoot, "dist"));
for (const file of builtFiles.filter((path) => path.endsWith(".js"))) {
  const source = await readFile(file, "utf8");
  assert.doesNotMatch(source, /from\s+["']node:/u, `Node-only import leaked into ${relative(packageRoot, file)}`);
  assert.doesNotMatch(source, /require\s*\(/u, `CommonJS require leaked into ${relative(packageRoot, file)}`);
}

const packed = JSON.parse(
  execFileSync(
    process.platform === "win32" ? "npm.cmd" : "npm",
    ["pack", "--dry-run", "--json", packageRoot],
    { cwd: repositoryRoot, encoding: "utf8" },
  ),
);
assert.ok(Array.isArray(packed) && packed.length === 1);
const paths = new Set(packed[0].files.map((entry) => entry.path));
for (const required of ["package.json", "README.md", "styles.css", "dist/index.js", "dist/index.d.ts"]) {
  assert.ok(paths.has(required), `Packed React UI is missing ${required}`);
}
for (const path of paths) {
  assert.ok(!path.startsWith("src/"), `React UI source unexpectedly published: ${path}`);
  assert.ok(!path.startsWith("test/"), `React UI tests unexpectedly published: ${path}`);
}

console.log("Forwext React UI package contract smoke passed.");

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
