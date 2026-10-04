import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import path from "node:path";

const baseUrl = process.env.FORWEXT_BROWSER_LIVE_BASE_URL ?? "http://localhost:8124";
const artifactDir = path.resolve("build/browser-live-artifacts");
await mkdir(artifactDir, { recursive: true });

const fail = (message) => {
  throw new Error(message);
};

const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    reducedMotion: "no-preference",
  });
  const page = await context.newPage();
  const consoleErrors = [];
  const failedRequests = [];

  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });
  page.on("pageerror", (error) => consoleErrors.push(error.message));
  page.on("requestfailed", (request) => {
    const errorText = request.failure()?.errorText ?? "unknown";
    if (errorText.includes("ERR_ABORTED")) return;
    failedRequests.push(`${request.method()} ${request.url()} :: ${errorText}`);
  });

  const assertHealthyDocument = async (label) => {
    const body = await page.locator("body").innerText();
    if (body.includes("Internal Server Error")) fail(`${label}: rendered Internal Server Error`);

    const overflow = await page.evaluate(() => {
      const limit = window.innerWidth + 1;
      return [...document.body.querySelectorAll("*")]
        .filter((element) => {
          if (!(element instanceof HTMLElement)) return false;
          if (element.closest("[hidden]")) return false;
          const style = getComputedStyle(element);
          if (style.display === "none" || style.visibility === "hidden") return false;
          const rect = element.getBoundingClientRect();
          if (rect.width <= 0 || rect.height <= 0) return false;
          return rect.left < -1 || rect.right > limit;
        })
        .slice(0, 8)
        .map((element) => ({
          tag: element.tagName.toLowerCase(),
          className: element.className,
          left: Math.round(element.getBoundingClientRect().left),
          right: Math.round(element.getBoundingClientRect().right),
        }));
    });
    if (overflow.length > 0) fail(`${label}: horizontal overflow ${JSON.stringify(overflow)}`);
  };

  let response = await page.goto(baseUrl + "/", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("home: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Forumlar", exact: true }).waitFor();
  const forumHomeLayout = await page.evaluate(() => {
    const layout = document.querySelector(".forum-home-layout");
    const stats = document.querySelector(".forum-mini-stats");
    const emptyRecent = document.querySelector(".forum-side-card--empty");
    const columns = (element) => {
      if (!(element instanceof HTMLElement)) return [];
      return getComputedStyle(element).gridTemplateColumns.split(" ").filter(Boolean);
    };
    return {
      layoutDisplay: layout instanceof HTMLElement ? getComputedStyle(layout).display : "",
      layoutColumns: columns(layout).length,
      statsDisplay: stats instanceof HTMLElement ? getComputedStyle(stats).display : "",
      statsColumns: columns(stats).length,
      emptyRecentHeight: emptyRecent instanceof HTMLElement ? Math.round(emptyRecent.getBoundingClientRect().height) : null,
    };
  });
  if (forumHomeLayout.layoutDisplay !== "grid" || forumHomeLayout.layoutColumns !== 2) {
    fail(`home: forum/sidebar layout is not a two-column desktop grid ${JSON.stringify(forumHomeLayout)}`);
  }
  if (forumHomeLayout.statsDisplay !== "grid" || forumHomeLayout.statsColumns !== 3) {
    fail(`home: community statistics are not a three-column grid ${JSON.stringify(forumHomeLayout)}`);
  }
  if (forumHomeLayout.emptyRecentHeight !== null && forumHomeLayout.emptyRecentHeight > 110) {
    fail(`home: empty recent-activity panel is unnecessarily tall (${forumHomeLayout.emptyRecentHeight}px)`);
  }
  await assertHealthyDocument("home");

  response = await page.goto(baseUrl + "/login", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("login: real route did not return HTTP 200");
  await page.locator('input[name="identifier"]').fill("ci-admin");
  await page.locator('input[name="password"]').fill("Forwext-CI-Admin-Password-2026");
  await Promise.all([
    page.waitForURL((url) => url.pathname === "/" || url.pathname.startsWith("/mfa/"), { waitUntil: "domcontentloaded" }),
    page.locator('form.auth-entry-form button[type="submit"]').click(),
  ]);
  if (new URL(page.url()).pathname.startsWith("/mfa/")) {
    fail("login: bootstrap administrator unexpectedly requires MFA in the baseline live acceptance profile");
  }
  await page.locator(".nav-account-menu").waitFor({ state: "visible" });
  await assertHealthyDocument("authenticated home");

  const srOnlyVisible = await page.evaluate(() =>
    [...document.querySelectorAll(".nav-icon-link .sr-only")].some((element) => {
      if (!(element instanceof HTMLElement)) return false;
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return style.visibility !== "hidden" && style.display !== "none" && (rect.width > 2 || rect.height > 2);
    }),
  );
  if (srOnlyVisible) fail("header: assistive-only icon labels are visibly rendered");

  const accountSummary = page.locator(".nav-account-menu > summary");
  await accountSummary.click();
  const accountPopover = page.locator(".nav-account-popover");
  await accountPopover.waitFor({ state: "visible" });
  const topLayerState = await accountPopover.evaluate((popover) => ({
    supported: typeof popover.showPopover === "function",
    upgraded: popover.getAttribute("popover") === "manual",
    open: popover.matches(":popover-open"),
  }));
  if (topLayerState.supported && (!topLayerState.upgraded || !topLayerState.open)) {
    fail(`header: account menu was not promoted to the browser top layer ${JSON.stringify(topLayerState)}`);
  }
  await page.screenshot({
    path: path.join(artifactDir, "header-account-popover.png"),
    fullPage: false,
  });
  const popoverCoverage = await page.evaluate(() => {
    const popover = document.querySelector(".nav-account-popover");
    if (!(popover instanceof HTMLElement)) return [{ reason: "popover-missing" }];
    const links = [...popover.querySelectorAll("a")].filter((link) => link instanceof HTMLElement);
    return links.flatMap((link) => {
      const rect = link.getBoundingClientRect();
      const x = rect.left + Math.min(18, rect.width / 2);
      const y = rect.top + rect.height / 2;
      const top = document.elementFromPoint(x, y);
      if (top === null || popover.contains(top)) return [];
      const style = top instanceof HTMLElement ? getComputedStyle(top) : null;
      return [{
        link: (link.textContent ?? "").trim(),
        x: Math.round(x),
        y: Math.round(y),
        coveringTag: top instanceof Element ? top.tagName.toLowerCase() : String(top),
        coveringClass: top instanceof Element ? top.getAttribute("class") ?? "" : "",
        coveringPosition: style?.position ?? "",
        coveringZIndex: style?.zIndex ?? "",
        coveringText: top instanceof HTMLElement ? (top.innerText ?? "").trim().slice(0, 120) : "",
      }];
    });
  });
  if (popoverCoverage.length > 0) {
    fail(`header: account popover coverage ${JSON.stringify(popoverCoverage)}`);
  }
  const accountGroups = await page.locator(".nav-account-group").count();
  if (accountGroups !== 4) fail(`header: expected 4 account groups, got ${accountGroups}`);
  const accountLabels = await page.locator(".nav-account-group-title").allTextContents();
  for (const expected of ["Hesap", "İletişim", "Topluluk", "Diğer"]) {
    if (!accountLabels.includes(expected)) fail(`header: account group "${expected}" is missing`);
  }
  await page.keyboard.press("Escape");

  const messageSummary = page.locator(".nav-tool-menu--messages > summary");
  if (!(await messageSummary.count())) fail("header: message tool trigger is missing");
  const messagePreviewResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    return candidate.request().method() === "GET"
      && url.pathname === "/account/conversations"
      && url.searchParams.get("preview") === "1";
  });
  await messageSummary.click();
  const messageResponse = await messagePreviewResponse;
  if (messageResponse.status() !== 200) fail(`header: message preview returned HTTP ${messageResponse.status()}`);
  await page.waitForFunction(() => document.querySelector('[data-nav-preview="messages"]')?.dataset.loaded === "1");
  if (!(await page.locator('[data-nav-preview="messages"]').isVisible())) {
    fail("header: message preview did not become visible");
  }
  await page.keyboard.press("Escape");

  const alertSummary = page.locator(".nav-tool-menu--alerts > summary");
  if (!(await alertSummary.count())) fail("header: alert tool trigger is missing");
  const alertPreviewResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    return candidate.request().method() === "GET"
      && url.pathname === "/account/notifications"
      && url.searchParams.get("preview") === "1";
  });
  await alertSummary.click();
  const alertResponse = await alertPreviewResponse;
  if (alertResponse.status() !== 200) fail(`header: alert preview returned HTTP ${alertResponse.status()}`);
  await page.waitForFunction(() => document.querySelector('[data-nav-preview="alerts"]')?.dataset.loaded === "1");
  if (!(await page.locator('[data-nav-preview="alerts"]').isVisible())) {
    fail("header: alert preview did not become visible");
  }
  await page.keyboard.press("Escape");

  response = await page.goto(baseUrl + "/account/security", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("account security: real route did not return HTTP 200");
  const accountActiveState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    accountActive: document.querySelector(".nav-account-menu")?.getAttribute("data-active") ?? "",
    securityCurrent: document.querySelector('[data-nav-key="security.own"]')?.getAttribute("aria-current") ?? "",
    accountSubnavVisible: !Boolean(document.querySelector('[data-nav-section="account"]')?.hasAttribute("hidden")),
  }));
  if (
    accountActiveState.section !== "account"
    || accountActiveState.accountActive !== "1"
    || accountActiveState.securityCurrent !== "page"
    || !accountActiveState.accountSubnavVisible
  ) {
    fail(`account security: active navigation contract failed ${JSON.stringify(accountActiveState)}`);
  }
  await assertHealthyDocument("account security");

  response = await page.goto(baseUrl + "/activity/profile-posts", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) {
    fail(`profile post discovery: real route returned HTTP ${response?.status() ?? "no response"}`);
  }
  await page.getByRole("heading", { name: "Yeni profil gönderileri", exact: true }).waitFor();
  const profileDiscoveryState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    panelCount: document.querySelectorAll(".activity-feed-panel").length,
  }));
  if (profileDiscoveryState.section !== "whatsnew" || profileDiscoveryState.panelCount !== 1) {
    fail(`profile post discovery: active/layout contract failed ${JSON.stringify(profileDiscoveryState)}`);
  }
  await assertHealthyDocument("profile post discovery");

  response = await page.goto(baseUrl + "/activity/threads/featured", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) {
    fail(`thread discovery: real route returned HTTP ${response?.status() ?? "no response"}`);
  }
  await page.getByRole("heading", { name: "Öne çıkan konular", exact: true }).waitFor();
  const discoveryState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    currentTab: (document.querySelector(".thread-discovery-tabs [aria-current='page']")?.textContent ?? "").trim(),
    panelCount: document.querySelectorAll(".thread-discovery-panel").length,
    rowColumns: (() => {
      const row = document.querySelector(".thread-discovery-row");
      if (!(row instanceof HTMLElement)) return 0;
      return getComputedStyle(row).gridTemplateColumns.split(" ").filter(Boolean).length;
    })(),
  }));
  if (
    discoveryState.section !== "whatsnew"
    || discoveryState.currentTab !== "Öne çıkanlar"
    || discoveryState.panelCount !== 1
    || (discoveryState.rowColumns !== 0 && discoveryState.rowColumns !== 2)
  ) {
    fail(`thread discovery: active/layout contract failed ${JSON.stringify(discoveryState)}`);
  }
  await assertHealthyDocument("thread discovery");

  for (const [watchedPath, watchedHeading] of [
    ["/account/watched/threads", "Takip edilen konular"],
    ["/account/watched/forums", "Takip edilen forumlar"],
  ]) {
    response = await page.goto(baseUrl + watchedPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) {
      fail(`watched content: ${watchedPath} returned HTTP ${response?.status() ?? "no response"}`);
    }
    await page.getByRole("heading", { name: watchedHeading, exact: true }).waitFor();
    const watchedState = await page.evaluate(() => ({
      section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
      accountActive: document.querySelector(".nav-account-menu")?.getAttribute("data-active") ?? "",
      panelCount: document.querySelectorAll(".watched-panel").length,
    }));
    if (
      watchedState.section !== "account"
      || watchedState.accountActive !== "1"
      || watchedState.panelCount !== 1
    ) {
      fail(`watched content: active/layout contract failed ${JSON.stringify(watchedState)}`);
    }
    await assertHealthyDocument(`watched content ${watchedPath}`);
  }

  for (const memberContentPath of [
    "/members/ci-admin/content/threads",
    "/members/ci-admin/content/posts",
  ]) {
    response = await page.goto(baseUrl + memberContentPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) {
      fail(`member content: ${memberContentPath} returned HTTP ${response?.status() ?? "no response"}`);
    }
    await page.getByRole("heading", { name: "ci-admin", exact: true }).waitFor();
    const memberContentState = await page.evaluate(() => ({
      section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
      panelCount: document.querySelectorAll(".profile-forum-content-panel").length,
      currentTabs: document.querySelectorAll(".profile-content-tabs a[aria-current='page']").length,
    }));
    if (
      memberContentState.section !== "members"
      || memberContentState.panelCount !== 1
      || memberContentState.currentTabs !== 1
    ) {
      fail(`member content: active/layout contract failed ${JSON.stringify(memberContentState)}`);
    }
    await assertHealthyDocument(`member content ${memberContentPath}`);
  }

  response = await page.goto(baseUrl + "/members", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("members: real route did not return HTTP 200");
  const subnavState = await page.evaluate(() => {
    const shell = document.querySelector("[data-forwext-subnav-shell]");
    const visible = document.querySelector("[data-forwext-subnav] [data-nav-section]:not([hidden])");
    if (!(shell instanceof HTMLElement) || !(visible instanceof HTMLElement)) {
      return { shellHidden: true, height: 0, labels: [] };
    }
    return {
      shellHidden: shell.hidden,
      height: shell.getBoundingClientRect().height,
      labels: [...visible.querySelectorAll("a")].map((a) => (a.textContent ?? "").trim()).filter(Boolean),
    };
  });
  if (subnavState.shellHidden || subnavState.height < 28 || subnavState.labels.length < 2) {
    fail(`members: secondary navigation is blank or collapsed ${JSON.stringify(subnavState)}`);
  }
  await assertHealthyDocument("members");

  response = await page.goto(baseUrl + "/members/staff", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("staff members: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Yetkili Ekip", exact: true }).waitFor();
  const staffDirectoryState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    panelCount: document.querySelectorAll(".member-directory-results").length,
    currentLinks: document.querySelectorAll("[data-forwext-subnav] a[aria-current='page']").length,
  }));
  if (
    staffDirectoryState.section !== "members"
    || staffDirectoryState.panelCount !== 1
    || staffDirectoryState.currentLinks !== 1
  ) {
    fail(`staff members: active/layout contract failed ${JSON.stringify(staffDirectoryState)}`);
  }
  await assertHealthyDocument("staff members");

  response = await page.goto(baseUrl + "/admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail(`admin: real route returned HTTP ${response?.status() ?? "no response"}`);
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();
  await assertHealthyDocument("admin GET");

  const favorite = page.getByRole("button", { name: "Favoriye ekle" }).first();
  if (!(await favorite.count())) fail("admin: no CSRF-protected favorite action was rendered");
  const [favoriteResponse, redirectedAdminResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/admin";
    }),
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "GET" && url.pathname === "/admin";
    }),
    favorite.click(),
  ]);
  if (favoriteResponse.status() !== 303) {
    fail(`admin POST: favorite mutation returned HTTP ${favoriteResponse.status()} instead of 303`);
  }
  if (redirectedAdminResponse.status() !== 200) {
    fail(`admin POST redirect: dashboard returned HTTP ${redirectedAdminResponse.status()}`);
  }
  await page.waitForLoadState("domcontentloaded");
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();
  await assertHealthyDocument("admin POST");

  await page.screenshot({
    path: path.join(artifactDir, "admin-live-desktop.png"),
    fullPage: true,
  });

  await page.setViewportSize({ width: 1152, height: 800 });
  await page.goto(baseUrl + "/", { waitUntil: "domcontentloaded" });
  await assertHealthyDocument("authenticated home 125% reflow equivalent");
  await page.screenshot({
    path: path.join(artifactDir, "home-125-percent-reflow.png"),
    fullPage: true,
  });

  if (failedRequests.length > 0) {
    fail(`live routes: failed browser requests: ${failedRequests.join(" | ")}`);
  }
  if (consoleErrors.length > 0) {
    fail(`live routes: browser console errors: ${consoleErrors.join(" | ")}`);
  }

  await context.close();
} finally {
  await browser.close();
}

console.log("Forwext live-route browser acceptance passed for login, grouped account tools, inbox previews, active account navigation, watched content, member content, thread discovery, members subnav and ACP GET/POST.");
