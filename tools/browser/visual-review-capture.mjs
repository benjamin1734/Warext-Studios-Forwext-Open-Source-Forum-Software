import { chromium } from "playwright";
import { mkdir, writeFile, stat } from "node:fs/promises";
import path from "node:path";

const baseUrl = process.env.FORWEXT_BROWSER_LIVE_BASE_URL ?? "http://localhost:8124";
const output = path.resolve("build/browser-live-artifacts/visual-review");
await mkdir(output, { recursive: true });

// Real installed CI routes, not synthetic shell fixtures. Mutating UI states
// require separate CSRF-aware test coverage; this collector only reads pages.
// A screenshot proves rendering, not visual parity or operation correctness.
const cases = [
  ["forum-index", "/"],
  ["forums", "/forums"],
  ["activity", "/activity"],
  ["activity-profile-posts", "/activity/profile-posts"],
  ["activity-featured", "/activity/threads/featured"],
  ["search", "/search"],
  ["members", "/members"],
  ["members-staff", "/members/staff"],
  ["members-online", "/members/online"],
  ["member-profile", "/members/ci-admin"],
  ["portfolio", "/portfolio?category=general&featured=1"],
  ["portfolio-project", "/portfolio/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"],
  ["portfolio-manage", "/portfolio/manage?project=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"],
  ["faq", "/faq?lang=tr"],
  ["faq-article", "/faq/tr/phase8-browser-faq"],
  ["servers", "/servers"],
  ["servers-compare", "/servers/compare"],
  ["servers-seasons", "/servers/seasons"],
  ["servers-manage", "/servers/manage"],
  ["groups", "/groups"],
  ["groups-mine", "/groups/mine"],
  ["account", "/account"],
  ["account-preferences", "/account/preferences"],
  ["account-security", "/account/security"],
  ["account-messages", "/account/conversations"],
  ["account-messages-starred", "/account/conversations?filter=starred"],
  ["account-alerts", "/account/notifications"],
  ["account-bookmarks", "/account/bookmarks"],
  ["account-relationships", "/account/relationships"],
  ["account-referrals", "/account/referrals"],
  ["bugs", "/bugs"],
  ["bug-report", "/bugs/report"],
  ["moderation", "/moderation"],
  ["moderation-approval", "/moderation/approval"],
  ["moderation-audit", "/moderation/audit"],
  ["moderation-oversight", "/moderation/oversight"],
  ["moderation-oversight-case", "/moderation/oversight/cases/{real-case-id}"],
  ["help", "/help"],
  ["help-codes", "/help/bb-codes"],
  ["help-smilies", "/help/smilies"],
  ["help-trophies", "/help/trophies"],
  ["help-rss", "/help/rss"],
  ["contact", "/help/contact"],
  ["terms", "/help/terms"],
  ["privacy", "/help/privacy"],
  ["admin-home", "/admin"],
  ["admin-home-search", "/admin?q=user"],
  ["admin-users", "/admin/users?q=phase12"],
  ["admin-user-detail", "/admin/users?user=12121212121212121212121212121212&q=phase12"],
  ["admin-access", "/admin/access"],
  ["admin-access-search", "/admin/access?q=phase13"],
  ["admin-access-role", "/admin/access?role=31313131313131313131313131313131"],
  ["admin-forums", "/admin/forums"],
  ["admin-content", "/admin/content"],
  ["admin-theme", "/admin/appearance/themes?theme=forwext-balanced"],
  ["admin-layout", "/admin/appearance/layout"],
  ["admin-navigation", "/admin/navigation?state=all&placement=all"],
  ["admin-modules", "/admin/modules?state=all"],
  ["admin-integrations", "/admin/integrations"],
  ["admin-analytics", "/admin/analytics"],
  ["admin-analytics-operations", "/admin/analytics/operations"],
  ["admin-operations", "/admin/system/operations"],
];

const highPriority = new Set([
  "forum-index", "forums", "activity", "search", "members", "member-profile",
  "account", "account-security", "moderation", "admin-home", "admin-access",
  "admin-forums", "admin-theme", "admin-layout", "admin-operations",
]);

const viewports = [
  { label: "desktop", width: 1440, height: 900 },
  { label: "mobile", width: 390, height: 844 },
  { label: "tablet", width: 768, height: 900, priorityOnly: true },
  { label: "compact", width: 1024, height: 900, priorityOnly: true },
];

const browser = await chromium.launch({ headless: true });
const records = [];
let failures = 0;

try {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    reducedMotion: "reduce",
  });
  const page = await context.newPage();
  const login = await page.goto(baseUrl + "/login", { waitUntil: "domcontentloaded" });
  if (!login || login.status() !== 200) throw new Error("Visual review login route unavailable.");
  await page.locator('input[name="identifier"]').fill("ci-admin");
  await page.locator('input[name="password"]').fill("Forwext-CI-Admin-Password-2026");
  await Promise.all([
    page.waitForURL((url) => url.pathname === "/" || url.pathname.startsWith("/mfa/"), { waitUntil: "domcontentloaded" }),
    page.locator('form.auth-entry-form button[type="submit"]').click(),
  ]);
  if (new URL(page.url()).pathname.startsWith("/mfa/")) throw new Error("Visual review cannot login: unexpected MFA.");
  await page.locator(".nav-account-menu").waitFor({ state: "visible" });

  for (const viewport of viewports) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const [name, route] of cases) {
      if (viewport.priorityOnly && !highPriority.has(name)) continue;

      const record = {
        name, route, viewport: viewport.label, width: viewport.width,
        screenshot: `${String(cases.findIndex((item) => item[0] === name) + 1).padStart(3, "0")}-${name}-${viewport.label}.jpg`,
        status: 0, title: "", heading: "", error: "",
      };

      try {
        if (name === "moderation-oversight-case") {
          // The primary live-route acceptance test creates and resolves a real
          // permission-scoped case. Capture its valid state there, before it
          // disappears from the active directory; never invent a case ID.
          const dedicated = await stat(path.join(output, record.screenshot));
          if (!dedicated.isFile() || dedicated.size === 0) {
            throw new Error("The dedicated moderation lifecycle screenshot is missing.");
          }
          record.status = 200;
          record.title = "Moderation case captured during lifecycle acceptance";
          records.push(record);
          continue;
        }
        const response = await page.goto(baseUrl + route, {
          waitUntil: "domcontentloaded",
          timeout: 15000,
        });
        record.status = response?.status() ?? 0;
        record.title = await page.title();
        record.heading = (await page.locator("h1").first().textContent({ timeout: 3000 }).catch(() => ""))?.trim() ?? "";
        if (record.status !== 200) {
          record.error = `HTTP ${record.status}`;
        }
        if ((await page.locator("body").innerText()).includes("Internal Server Error")) {
          record.error = "Rendered Internal Server Error";
        }
        await page.screenshot({
          path: path.join(output, record.screenshot),
          fullPage: true,
          type: "jpeg",
          quality: 84,
          animations: "disabled",
        });
      } catch (error) {
        record.error = String(error).slice(0, 800);
      }
      if (record.error) failures++;
      records.push(record);
    }
  }
  await writeFile(path.join(output, "capture-manifest.json"), JSON.stringify({
    schema: 1,
    source: "installed-runtime-github-actions",
    commit: process.env.GITHUB_SHA ?? "unknown",
    referenceImageCount: 562,
    inspectedRoutes: cases.length,
    captures: records.length,
    failures,
    capturesAreNotVisualSignoff: true,
    records,
  }, null, 2) + "\n");
  await context.close();
} finally {
  await browser.close();
}

console.log(`Visual-review capture: ${records.length} screenshots for ${cases.length} routes across four desktop/mobile viewport profiles; errors=${failures}.`);
if (failures > 0) {
  console.log("Capture failures:", records.filter((item) => item.error));
  process.exitCode = 1;
}
