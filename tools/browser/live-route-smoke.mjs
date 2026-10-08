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

    const duplicateIds = await page.evaluate(() => {
      const counts = new Map();
      for (const element of document.querySelectorAll("[id]")) {
        const id = element.getAttribute("id");
        if (!id) continue;
        counts.set(id, (counts.get(id) ?? 0) + 1);
      }
      return [...counts.entries()].filter(([, count]) => count > 1).slice(0, 8);
    });
    if (duplicateIds.length > 0) fail(`${label}: duplicate DOM ids ${JSON.stringify(duplicateIds)}`);
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

  response = await page.goto(baseUrl + "/account/preferences", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("account preferences: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Tercihler ve Gizlilik", exact: true }).waitFor();
  const preferenceState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    accountActive: document.querySelector(".nav-account-menu")?.getAttribute("data-active") ?? "",
    preferenceCurrent: document.querySelector('[data-nav-key="preferences.own"]')?.getAttribute("aria-current") ?? "",
    cards: document.querySelectorAll(".account-preference-card").length,
    presenceForm: document.querySelectorAll('.account-presence-preference-form select[name="presence_visibility"]').length,
  }));
  if (
    preferenceState.section !== "account"
    || preferenceState.accountActive !== "1"
    || preferenceState.preferenceCurrent !== "page"
    || preferenceState.cards !== 4
    || preferenceState.presenceForm !== 1
  ) {
    fail(`account preferences: active/layout contract failed ${JSON.stringify(preferenceState)}`);
  }
  await assertHealthyDocument("account preferences");

  const visibilitySelect = page.locator('.account-presence-preference-form select[name="presence_visibility"]');
  await visibilitySelect.selectOption("members");
  const [preferencePost, preferenceRedirect] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/account/preferences";
    }),
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "GET"
        && url.pathname === "/account/preferences"
        && url.searchParams.get("updated") === "presence";
    }),
    page.locator(".account-presence-preference-form button[type='submit']").click(),
  ]);
  if (preferencePost.status() !== 303 || preferenceRedirect.status() !== 200) {
    fail(`account preferences: save flow returned POST ${preferencePost.status()} / GET ${preferenceRedirect.status()}`);
  }
  await page.getByRole("status").filter({ hasText: "Çevrimiçi görünürlük tercihin kaydedildi." }).waitFor();
  await assertHealthyDocument("account preferences POST");

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

  response = await page.goto(baseUrl + "/account/conversations", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("direct messages: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Özel Mesajlar", exact: true }).waitFor();
  const conversationState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    accountActive: document.querySelector(".nav-account-menu")?.getAttribute("data-active") ?? "",
    filters: document.querySelectorAll(".conversation-filters a").length,
    currentFilter: (document.querySelector(".conversation-filters [aria-current='page']")?.textContent ?? "").trim(),
    startForms: document.querySelectorAll("#new-conversation form").length,
    listPanels: document.querySelectorAll(".conversation-list-panel").length,
  }));
  if (
    conversationState.section !== "account"
    || conversationState.accountActive !== "1"
    || conversationState.filters !== 2
    || conversationState.currentFilter !== "Tümü"
    || conversationState.startForms !== 1
    || conversationState.listPanels !== 1
  ) {
    fail(`direct messages: active/density contract failed ${JSON.stringify(conversationState)}`);
  }
  await assertHealthyDocument("direct messages");

  response = await page.goto(baseUrl + "/account/conversations?filter=starred", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("direct messages starred: real route did not return HTTP 200");
  const starredFilter = (await page.locator(".conversation-filters [aria-current='page']").textContent() ?? "").trim();
  if (starredFilter !== "Yıldızlı") {
    fail(`direct messages starred: expected active starred filter, got "${starredFilter}"`);
  }
  await assertHealthyDocument("direct messages starred");

  response = await page.goto(baseUrl + "/account/notifications", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("notifications: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Bildirimler", exact: true }).waitFor();
  const notificationState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    accountActive: document.querySelector(".nav-account-menu")?.getAttribute("data-active") ?? "",
    panels: document.querySelectorAll(".notification-panel").length,
    unreadCounters: document.querySelectorAll(".notification-unread").length,
    settingsLinks: document.querySelectorAll('.notification-head-actions a[href*="/account/notification-settings"]').length,
  }));
  if (
    notificationState.section !== "account"
    || notificationState.accountActive !== "1"
    || notificationState.panels !== 1
    || notificationState.unreadCounters !== 1
    || notificationState.settingsLinks !== 1
  ) {
    fail(`notifications: active/density contract failed ${JSON.stringify(notificationState)}`);
  }
  await assertHealthyDocument("notifications");

  response = await page.goto(baseUrl + "/servers", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft servers: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Minecraft Sunucuları", exact: true }).waitFor();
  const serverDirectoryState = await page.evaluate(() => ({
    filters: document.querySelectorAll(".minecraft-server-tabs a").length,
    searchForms: document.querySelectorAll(".minecraft-server-filter").length,
    panels: document.querySelectorAll(".minecraft-server-directory").length,
    listRows: document.querySelectorAll(".minecraft-server-row").length,
    emptyStates: document.querySelectorAll(".minecraft-server-empty").length,
  }));
  if (
    serverDirectoryState.filters !== 4
    || serverDirectoryState.searchForms !== 1
    || serverDirectoryState.panels !== 1
    || serverDirectoryState.listRows < 2
    || serverDirectoryState.emptyStates !== 0
  ) {
    fail(`minecraft servers: directory contract failed ${JSON.stringify(serverDirectoryState)}`);
  }
  await assertHealthyDocument("minecraft servers");

  let firstServerHref = null;
  if (serverDirectoryState.listRows > 0) {
    firstServerHref = await page.locator(".minecraft-server-row a").first().getAttribute("href");
    if (!firstServerHref) fail("minecraft server voting: published server row has no detail href");
    response = await page.goto(new URL(firstServerHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server voting: detail route did not return HTTP 200");
    const votePanel = page.locator(".minecraft-server-vote-panel");
    if ((await votePanel.count()) !== 1) fail("minecraft server voting: vote summary panel is missing");
    const voteButton = page.getByRole("button", { name: "Bugün oy ver", exact: true });
    if ((await voteButton.count()) === 1) {
      const [voteResponse] = await Promise.all([
        page.waitForResponse((candidate) => {
          const url = new URL(candidate.url());
          return candidate.request().method() === "POST" && /\/servers\/[a-f0-9]{32}\/vote$/.test(url.pathname);
        }),
        voteButton.click(),
      ]);
      if (voteResponse.status() !== 303) {
        fail(`minecraft server voting: vote POST returned HTTP ${voteResponse.status()} instead of 303`);
      }
      await page.waitForLoadState("domcontentloaded");
      if (!new URL(page.url()).searchParams.has("vote")) {
        fail("minecraft server voting: vote redirect status is missing");
      }
      await page.getByText("Oyun kaydedildi.", { exact: true }).waitFor();
      await assertHealthyDocument("minecraft server voting");
    }

    const serverDetailUrl = new URL(firstServerHref, baseUrl);
    response = await page.goto(serverDetailUrl.toString().replace(/\/$/, "") + "/updates", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server updates: real route did not return HTTP 200");
    await page.getByRole("heading", { name: "Sunucu Güncellemeleri", exact: true }).waitFor();
    const updateFeedState = await page.evaluate(() => ({
      panels: document.querySelectorAll(".minecraft-update-list").length,
      rows: document.querySelectorAll(".minecraft-update-row").length,
      emptyStates: document.querySelectorAll(".minecraft-update-empty").length,
    }));
    if (
      updateFeedState.panels !== 1
      || (updateFeedState.rows === 0 && updateFeedState.emptyStates !== 1)
    ) {
      fail(`minecraft server updates: route contract failed ${JSON.stringify(updateFeedState)}`);
    }
    await assertHealthyDocument("minecraft server updates");

    response = await page.goto(serverDetailUrl.toString().replace(/\/$/, "") + "/stats", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server statistics: real route did not return HTTP 200");
    await page.getByRole("heading", { name: "Sunucu İstatistikleri", exact: true }).waitFor();
    const serverStatisticsState = await page.evaluate(() => ({
      cards: document.querySelectorAll(".minecraft-stat-card").length,
      trendRows: document.querySelectorAll(".minecraft-vote-trend li").length,
    }));
    if (serverStatisticsState.cards !== 6 || serverStatisticsState.trendRows !== 30) {
      fail(`minecraft server statistics: metric contract failed ${JSON.stringify(serverStatisticsState)}`);
    }
    await assertHealthyDocument("minecraft server statistics");

    response = await page.goto(serverDetailUrl.toString().replace(/\/$/, "") + "/team", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server team: real route did not return HTTP 200");
    const teamState = await page.evaluate(() => ({
      lists: document.querySelectorAll(".minecraft-team-list").length,
      members: document.querySelectorAll(".minecraft-team-member").length,
    }));
    if (teamState.lists !== 1 || teamState.members < 1) {
      fail(`minecraft server team: route contract failed ${JSON.stringify(teamState)}`);
    }
    await assertHealthyDocument("minecraft server team");
  }

  const unownedServerId = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
  response = await page.goto(baseUrl + "/servers/" + unownedServerId + "/verify", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) {
    fail("minecraft server verify: canonical reference route did not return HTTP 200");
  }
  await page.getByRole("heading", { name: "Sahipliği talep et", exact: true }).waitFor();
  if ((await page.locator(".minecraft-claim-form").count()) !== 1) {
    fail("minecraft server verify: verification form is missing");
  }
  await assertHealthyDocument("minecraft server verify");

  await page.locator('.minecraft-claim-form textarea[name="proof_note"]').fill(
    "CI kabul testi için doğrulanabilir sahiplik kanıtı açıklaması.",
  );
  const [verifyPostResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && /\/servers\/[a-f0-9]{32}\/verify$/.test(url.pathname);
    }),
    page.getByRole("button", { name: "Talebi gönder", exact: true }).click(),
  ]);
  if (verifyPostResponse.status() !== 303) {
    fail(`minecraft server verify: claim POST returned HTTP ${verifyPostResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  if (!new URL(page.url()).pathname.endsWith("/verify") || new URL(page.url()).searchParams.get("submitted") !== "1") {
    fail("minecraft server verify: canonical claim redirect is invalid");
  }
  await page.getByText("Sahiplik talebin incelemeye gönderildi.", { exact: true }).waitFor();
  await assertHealthyDocument("minecraft server verify POST");

  response = await page.goto(baseUrl + "/servers/compare", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft server compare: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Sunucu Karşılaştırma", exact: true }).waitFor();
  const serverCompareState = await page.evaluate(() => ({
    forms: document.querySelectorAll(".minecraft-compare-form").length,
    options: document.querySelectorAll(".minecraft-compare-option").length,
    emptyStates: document.querySelectorAll(".minecraft-compare-form .surface-empty").length,
  }));
  if (
    serverCompareState.forms !== 1
    || (serverCompareState.options === 0 && serverCompareState.emptyStates !== 1)
  ) {
    fail(`minecraft server compare: form contract failed ${JSON.stringify(serverCompareState)}`);
  }
  await assertHealthyDocument("minecraft server compare");

  response = await page.goto(baseUrl + "/servers/seasons", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft server seasons: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Sunucu Sezonları", exact: true }).waitFor();
  const serverSeasonState = await page.evaluate(() => ({
    filters: document.querySelectorAll(".minecraft-season-tabs a").length,
    rows: document.querySelectorAll(".minecraft-season-row").length,
    emptyStates: document.querySelectorAll(".minecraft-season-empty").length,
  }));
  if (
    serverSeasonState.filters !== 4
    || (serverSeasonState.rows === 0 && serverSeasonState.emptyStates !== 1)
  ) {
    fail(`minecraft server seasons: route contract failed ${JSON.stringify(serverSeasonState)}`);
  }
  await assertHealthyDocument("minecraft server seasons");

  response = await page.goto(baseUrl + "/servers/manage", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft server management: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Sunucu Yönetimi", exact: true }).waitFor();
  const serverManagementState = await page.evaluate(() => ({
    panels: document.querySelectorAll(".minecraft-manage-list").length,
    rows: document.querySelectorAll(".minecraft-manage-row").length,
    emptyStates: document.querySelectorAll(".minecraft-manage-list .surface-empty").length,
    navLinks: document.querySelectorAll('a[href$="/servers/manage"]').length,
  }));
  if (
    serverManagementState.panels !== 1
    || serverManagementState.rows < 1
    || serverManagementState.emptyStates !== 0
    || serverManagementState.navLinks < 1
  ) {
    fail(`minecraft server management: route contract failed ${JSON.stringify(serverManagementState)}`);
  }
  await assertHealthyDocument("minecraft server management");

  if (serverManagementState.rows > 0) {
    const ownedManageRow = page.locator(".minecraft-manage-row").filter({ hasText: "Sahipli" }).first();
    if ((await ownedManageRow.count()) !== 1) fail("minecraft server integration: owned management fixture is missing");
    const editHref = await ownedManageRow.locator('a[href$="/edit"]').getAttribute("href");
    if (!editHref) fail("minecraft server integration: owned row has no canonical edit href");
    response = await page.goto(new URL(editHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server edit: canonical route did not return HTTP 200");
    if ((await page.locator(".minecraft-manage-form").count()) !== 1) {
      fail("minecraft server edit: edit form is missing");
    }
    await assertHealthyDocument("minecraft server edit");

    const [editPostResponse] = await Promise.all([
      page.waitForResponse((candidate) => {
        const url = new URL(candidate.url());
        return candidate.request().method() === "POST" && /\/servers\/[a-f0-9]{32}\/edit$/.test(url.pathname);
      }),
      page.getByRole("button", { name: "Değişiklikleri kaydet", exact: true }).click(),
    ]);
    if (editPostResponse.status() !== 303) {
      fail(`minecraft server edit: save POST returned HTTP ${editPostResponse.status()} instead of 303`);
    }
    await page.waitForLoadState("domcontentloaded");
    if (!new URL(page.url()).pathname.endsWith("/edit") || new URL(page.url()).searchParams.get("updated") !== "1") {
      fail("minecraft server edit: canonical save redirect is invalid");
    }
    await page.getByText("Sunucu yönetim değişikliği kaydedildi.", { exact: true }).waitFor();
    await assertHealthyDocument("minecraft server edit POST");

    const editUrl = new URL(editHref, baseUrl);
    const manageBase = editUrl.pathname.replace(/\/edit$/, "");
    response = await page.goto(new URL(manageBase + "/transfer", baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) {
      fail("minecraft server transfer: canonical reference route did not return HTTP 200");
    }
    if ((await page.locator(".minecraft-ownership-panel").count()) !== 1) {
      fail("minecraft server transfer: ownership controls are missing");
    }
    await assertHealthyDocument("minecraft server transfer");

    response = await page.goto(new URL(editHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server integration: edit route reload failed");

    const settingsLink = page.locator('a[href$="/vote-settings"]').first();
    if ((await settingsLink.count()) !== 1) {
      fail("minecraft server integration: settings link is missing for the owned fixture");
    }
    const settingsHref = await settingsLink.getAttribute("href");
    if (!settingsHref) fail("minecraft server integration: settings href is missing");
    response = await page.goto(new URL(settingsHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server integration: settings route did not return HTTP 200");
    if ((await page.locator(".minecraft-vote-settings-summary").count()) !== 1) {
      fail("minecraft server integration: settings summary is missing");
    }

    const rotateButton = page.getByRole("button", { name: /Token(ı yenile| oluştur)/ }).first();
    if ((await rotateButton.count()) !== 1) fail("minecraft server integration: token action is missing");
    const [rotateResponse] = await Promise.all([
      page.waitForResponse((candidate) => {
        const url = new URL(candidate.url());
        return candidate.request().method() === "POST" && /\/servers\/[a-f0-9]{32}\/vote-settings$/.test(url.pathname);
      }),
      rotateButton.click(),
    ]);
    if (rotateResponse.status() !== 200) {
      fail(`minecraft server integration: rotate token returned HTTP ${rotateResponse.status()}`);
    }
    const token = (await page.locator(".minecraft-vote-secret code").textContent())?.trim() ?? "";
    if (!/^[A-Za-z0-9_-]{40,128}$/.test(token)) {
      fail("minecraft server integration: one-time token was not rendered");
    }

    const enabledCheckbox = page.locator('.minecraft-vote-settings-toggle input[name="enabled"]');
    if ((await enabledCheckbox.count()) !== 1) {
      fail("minecraft server integration: enabled toggle is missing after token creation");
    }
    if (!(await enabledCheckbox.isChecked())) {
      await enabledCheckbox.check();
      const [toggleResponse] = await Promise.all([
        page.waitForResponse((candidate) => {
          const url = new URL(candidate.url());
          return candidate.request().method() === "POST" && /\/servers\/[a-f0-9]{32}\/vote-settings$/.test(url.pathname);
        }),
        page.getByRole("button", { name: "Durumu kaydet", exact: true }).click(),
      ]);
      if (toggleResponse.status() !== 303) {
        fail(`minecraft server integration: enable toggle returned HTTP ${toggleResponse.status()}`);
      }
      await page.waitForLoadState("domcontentloaded");
    }

    const endpointText = (await page.locator(".minecraft-vote-settings-endpoint > code").textContent())?.trim() ?? "";
    const endpointPath = endpointText.replace(/^GET\s+/, "");
    if (!/\/servers\/[a-f0-9]{32}\/vote-feed\?limit=100$/.test(endpointPath)) {
      fail(`minecraft server integration: endpoint contract failed "${endpointPath}"`);
    }
    const feedResponse = await page.request.get(new URL(endpointPath, baseUrl).toString(), {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (feedResponse.status() !== 200) {
      fail(`minecraft server integration: bearer feed returned HTTP ${feedResponse.status()}`);
    }
    const feedPayload = await feedResponse.json();
    if (
      feedPayload?.order !== "newest_first"
      || !Array.isArray(feedPayload?.votes)
      || typeof feedPayload?.count !== "number"
    ) {
      fail(`minecraft server integration: feed payload contract failed ${JSON.stringify(feedPayload)}`);
    }
    await assertHealthyDocument("minecraft server vote integration");
  }

  response = await page.goto(baseUrl + "/portfolio?category=general&featured=1", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio: dense browse route did not return HTTP 200");
  await page.getByRole("heading", { name: "Portfolyo", exact: true }).waitFor();
  const portfolioBrowseState = await page.evaluate(() => ({
    browseBars: document.querySelectorAll(".portfolio-browse-bar").length,
    activeCategory: document.querySelectorAll(".portfolio-filter-tab.is-active").length,
    activeFeatured: document.querySelectorAll(".portfolio-featured-toggle.is-active").length,
    cards: document.querySelectorAll(".portfolio-card").length,
    tagGroups: document.querySelectorAll(".portfolio-card-tags").length,
    footers: document.querySelectorAll(".portfolio-card-footer").length,
  }));
  if (
    portfolioBrowseState.browseBars !== 1
    || portfolioBrowseState.activeCategory !== 1
    || portfolioBrowseState.activeFeatured !== 1
    || portfolioBrowseState.cards < 1
    || portfolioBrowseState.tagGroups < 1
    || portfolioBrowseState.footers < 1
  ) {
    fail(`portfolio: browse density contract failed ${JSON.stringify(portfolioBrowseState)}`);
  }
  await assertHealthyDocument("portfolio browse");

  response = await page.goto(baseUrl + "/portfolio/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio detail: fixture route did not return HTTP 200");
  await page.getByRole("heading", { name: "Phase 8 Tarayıcı Projesi", exact: true }).waitFor();
  const portfolioDetailState = await page.evaluate(() => ({
    mainGrid: document.querySelectorAll(".portfolio-project-main-grid").length,
    contentHeads: document.querySelectorAll(".portfolio-project-content-head").length,
    engagement: document.querySelectorAll(".portfolio-engagement").length,
    comments: document.querySelectorAll(".portfolio-comments").length,
  }));
  if (
    portfolioDetailState.mainGrid !== 1
    || portfolioDetailState.contentHeads !== 1
    || portfolioDetailState.engagement !== 1
    || portfolioDetailState.comments !== 1
  ) {
    fail(`portfolio detail: density contract failed ${JSON.stringify(portfolioDetailState)}`);
  }
  await assertHealthyDocument("portfolio detail");

  response = await page.goto(baseUrl + "/portfolio/manage?project=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio manage: fixture route did not return HTTP 200");
  const portfolioManageState = await page.evaluate(() => ({
    grids: document.querySelectorAll(".portfolio-manage-grid").length,
    summaries: document.querySelectorAll(".portfolio-manage-summary").length,
    mediaSections: document.querySelectorAll(".module-manage-section").length,
  }));
  if (portfolioManageState.grids !== 1 || portfolioManageState.summaries !== 1 || portfolioManageState.mediaSections < 1) {
    fail(`portfolio manage: density contract failed ${JSON.stringify(portfolioManageState)}`);
  }
  await assertHealthyDocument("portfolio manage");

  response = await page.goto(baseUrl + "/faq?lang=tr", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("faq: dense browse route did not return HTTP 200");
  await page.getByRole("heading", { name: "Sık Sorulan Sorular", exact: true }).waitFor();
  const faqBrowseState = await page.evaluate(() => ({
    overviews: document.querySelectorAll(".faq-overview").length,
    activeTabs: document.querySelectorAll(".faq-tab.is-active").length,
    categoryHeads: document.querySelectorAll(".faq-category-head").length,
    rows: document.querySelectorAll(".faq-row").length,
    tagGroups: document.querySelectorAll(".faq-row-tags").length,
  }));
  if (
    faqBrowseState.overviews !== 1
    || faqBrowseState.activeTabs !== 1
    || faqBrowseState.categoryHeads < 1
    || faqBrowseState.rows < 1
    || faqBrowseState.tagGroups < 1
  ) {
    fail(`faq: browse density contract failed ${JSON.stringify(faqBrowseState)}`);
  }
  await assertHealthyDocument("faq browse");

  response = await page.goto(baseUrl + "/faq/tr/phase8-browser-faq", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("faq article: fixture route did not return HTTP 200");
  const faqArticleState = await page.evaluate(() => ({
    grids: document.querySelectorAll(".faq-article-grid").length,
    answers: document.querySelectorAll(".faq-answer").length,
    tagGroups: document.querySelectorAll(".faq-article-tags").length,
    feedbackPanels: document.querySelectorAll(".faq-feedback-panel").length,
    feedbackActions: document.querySelectorAll(".faq-feedback-actions").length,
  }));
  if (
    faqArticleState.grids !== 1
    || faqArticleState.answers !== 1
    || faqArticleState.tagGroups !== 1
    || faqArticleState.feedbackPanels !== 1
    || faqArticleState.feedbackActions !== 1
  ) {
    fail(`faq article: density contract failed ${JSON.stringify(faqArticleState)}`);
  }
  await assertHealthyDocument("faq article");

  response = await page.goto(baseUrl + "/account/referrals", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("referrals account: route did not return HTTP 200");
  const referralAccountState = await page.evaluate(() => ({
    accounts: document.querySelectorAll(".referral-account").length,
    stats: document.querySelectorAll(".referral-stats").length,
    panels: document.querySelectorAll(".referral-panel").length,
  }));
  if (referralAccountState.accounts !== 1 || referralAccountState.stats !== 1 || referralAccountState.panels < 1) {
    fail(`referrals account: density contract failed ${JSON.stringify(referralAccountState)}`);
  }
  await assertHealthyDocument("referrals account");

  response = await page.goto(baseUrl + "/referrals/manage", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("referrals manage: route did not return HTTP 200");
  const referralManageState = await page.evaluate(() => ({
    pages: document.querySelectorAll(".referral-manage-page").length,
    stats: document.querySelectorAll(".referral-manage-stats").length,
    qualifications: document.querySelectorAll(".referral-qualification-panel").length,
    campaigns: document.querySelectorAll(".referral-campaign-editor").length,
    reviews: document.querySelectorAll(".referral-review-panel").length,
  }));
  if (
    referralManageState.pages !== 1
    || referralManageState.stats !== 1
    || referralManageState.qualifications !== 1
    || referralManageState.campaigns < 1
    || referralManageState.reviews !== 1
  ) {
    fail(`referrals manage: density contract failed ${JSON.stringify(referralManageState)}`);
  }
  await assertHealthyDocument("referrals manage");

  response = await page.goto(baseUrl + "/bugs", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("bugs: personal report route did not return HTTP 200");
  await page.getByRole("heading", { name: "Hata Bildirimlerim", exact: true }).waitFor();
  const bugListState = await page.evaluate(() => ({
    overviews: document.querySelectorAll(".bug-list-overview").length,
    stats: document.querySelectorAll(".bug-list-stat").length,
    panels: document.querySelectorAll(".bug-list-panel").length,
    rows: document.querySelectorAll(".bug-report-row").length,
    badgeGroups: document.querySelectorAll(".bug-report-row-badges").length,
  }));
  if (
    bugListState.overviews !== 1
    || bugListState.stats !== 4
    || bugListState.panels !== 1
    || bugListState.rows < 1
    || bugListState.badgeGroups < 1
  ) {
    fail(`bugs: list density contract failed ${JSON.stringify(bugListState)}`);
  }
  await assertHealthyDocument("bugs list");

  response = await page.goto(baseUrl + "/bugs/abababababababababababababababab", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("bug detail: fixture route did not return HTTP 200");
  const bugDetailState = await page.evaluate(() => ({
    primary: document.querySelectorAll(".bug-detail-primary-grid").length,
    secondary: document.querySelectorAll(".bug-detail-secondary-grid").length,
    conversations: document.querySelectorAll(".ticket-conversation").length,
  }));
  if (bugDetailState.primary !== 1 || bugDetailState.secondary !== 1 || bugDetailState.conversations !== 1) {
    fail(`bug detail: density contract failed ${JSON.stringify(bugDetailState)}`);
  }
  await assertHealthyDocument("bug detail");

  response = await page.goto(baseUrl + "/bugs/report", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("bug report form: route did not return HTTP 200");
  const bugFormState = await page.evaluate(() => ({
    grids: document.querySelectorAll(".bug-form-grid").length,
    forms: document.querySelectorAll(".bug-form-panel .support-intake-form").length,
    guidance: document.querySelectorAll(".bug-form-guidance").length,
    checklist: document.querySelectorAll(".bug-form-checklist").length,
  }));
  if (bugFormState.grids !== 1 || bugFormState.forms !== 1 || bugFormState.guidance !== 1 || bugFormState.checklist !== 1) {
    fail(`bug report form: density contract failed ${JSON.stringify(bugFormState)}`);
  }
  await assertHealthyDocument("bug report form");

  const oversightCaseId = "cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd";
  response = await page.goto(baseUrl + "/moderation", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) {
    const moderationFailureBody = response ? (await response.text()).slice(0, 800) : "<no response>";
    fail(`moderation: workspace route returned HTTP ${response?.status() ?? "none"}; body=${moderationFailureBody}`);
  }
  await page.getByRole("heading", { name: "Çalışma alanı", exact: true }).waitFor();
  const moderationWorkspaceState = await page.evaluate(() => ({
    navs: document.querySelectorAll(".moderation-nav").length,
    stats: document.querySelectorAll(".moderation-stat").length,
    sections: document.querySelectorAll(".moderation-section").length,
  }));
  if (moderationWorkspaceState.navs !== 1 || moderationWorkspaceState.stats < 1 || moderationWorkspaceState.sections < 1) {
    fail(`moderation: workspace contract failed ${JSON.stringify(moderationWorkspaceState)}`);
  }
  await assertHealthyDocument("moderation workspace");

  response = await page.goto(baseUrl + "/moderation/approval", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("moderation approval: route did not return HTTP 200");
  const approvalNavigationState = await page.evaluate(() => ({
    navs: document.querySelectorAll(".moderation-nav").length,
    active: document.querySelectorAll('.moderation-nav a[aria-current="page"]').length,
  }));
  if (approvalNavigationState.navs !== 1 || approvalNavigationState.active !== 1) {
    fail(`moderation approval: navigation contract failed ${JSON.stringify(approvalNavigationState)}`);
  }
  await assertHealthyDocument("moderation approval");

  response = await page.goto(baseUrl + "/moderation/audit", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("moderation audit: route did not return HTTP 200");
  if ((await page.locator('.moderation-nav a[aria-current="page"]').count()) !== 1) {
    fail("moderation audit: active navigation item is missing");
  }
  await assertHealthyDocument("moderation audit");

  response = await page.goto(baseUrl + "/moderation/oversight", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("moderation oversight: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Bağımsız Moderasyon Denetimi", exact: true }).waitFor();
  const oversightState = await page.evaluate(() => ({
    navs: document.querySelectorAll(".moderation-nav").length,
    pools: document.querySelectorAll(".oversight-reviewer-panel").length,
    reviewers: document.querySelectorAll(".oversight-reviewer").length,
    caseLinks: document.querySelectorAll('.moderation-note a[href*="/moderation/oversight/cases/"]').length,
  }));
  if (oversightState.navs !== 1 || oversightState.pools !== 1 || oversightState.reviewers < 1 || oversightState.caseLinks < 1) {
    fail(`moderation oversight: pool/case contract failed ${JSON.stringify(oversightState)}`);
  }
  await assertHealthyDocument("moderation oversight");

  response = await page.goto(baseUrl + "/moderation/oversight/cases/" + oversightCaseId, { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("moderation oversight case: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Phase 9 tarayıcı denetim vakası", exact: true }).waitFor();
  await mkdir(path.join(artifactDir, "visual-review"), { recursive: true });
  await page.screenshot({
    path: path.join(artifactDir, "visual-review", "037-moderation-oversight-case-desktop.jpg"),
    fullPage: true,
    type: "jpeg",
    quality: 84,
  });
  const oversightCaseState = await page.evaluate(() => ({
    grids: document.querySelectorAll(".oversight-case-grid").length,
    cards: document.querySelectorAll(".oversight-case-card").length,
    resolution: document.querySelectorAll(".oversight-case-resolution form").length,
  }));
  if (oversightCaseState.grids !== 1 || oversightCaseState.cards !== 2 || oversightCaseState.resolution !== 1) {
    fail(`moderation oversight case: detail contract failed ${JSON.stringify(oversightCaseState)}`);
  }
  await page.locator('.oversight-case-resolution textarea[name="resolution"]').fill("Phase 9 Chromium doğrulaması ile sonuçlandırıldı.");
  const [oversightResolveResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST"
        && url.pathname === "/moderation/oversight/cases/" + oversightCaseId + "/resolve";
    }),
    page.getByRole("button", { name: "Vakayı kapat", exact: true }).click(),
  ]);
  if (oversightResolveResponse.status() !== 303) {
    fail(`moderation oversight case: resolve POST returned HTTP ${oversightResolveResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  await page.getByRole("heading", { name: "Vaka sonucu", exact: true }).waitFor();
  await assertHealthyDocument("moderation oversight case resolved");

  response = await page.goto(baseUrl + "/help", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help: landing route did not return HTTP 200");
  await page.getByRole("heading", { name: "Forwext yardım ve referans", exact: true }).waitFor();
  const helpState = await page.evaluate(() => ({
    grids: document.querySelectorAll(".public-reference-grid").length,
    cards: document.querySelectorAll(".public-reference-card").length,
    footerLinks: document.querySelectorAll(".footer-reference-links a").length,
  }));
  if (helpState.grids !== 1 || helpState.cards < 9 || helpState.footerLinks < 6) {
    fail(`help: landing contract failed ${JSON.stringify(helpState)}`);
  }
  await assertHealthyDocument("help landing");

  for (const [route, heading] of [
    ["/help/contact", "İletişim ve destek"],
    ["/help/terms", "Kullanım koşulları"],
    ["/help/privacy", "Gizlilik"],
    ["/help/cookies", "Çerez kullanımı"],
  ]) {
    response = await page.goto(baseUrl + route, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`help document ${route}: route did not return HTTP 200`);
    await page.getByRole("heading", { name: heading, exact: true }).waitFor();
    if ((await page.locator(".public-document-section").count()) < 3) {
      fail(`help document ${route}: document sections are missing`);
    }
    await assertHealthyDocument("help document " + route);
  }

  response = await page.goto(baseUrl + "/help/bb-codes", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help bb-codes: route did not return HTTP 200");
  if ((await page.locator(".reference-row").count()) < 10) fail("help bb-codes: reference rows are incomplete");
  await assertHealthyDocument("help bb-codes");

  response = await page.goto(baseUrl + "/help/smilies", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help smilies: route did not return HTTP 200");
  if ((await page.locator(".emoji-reference").count()) < 16) fail("help smilies: emoji catalog is incomplete");
  await assertHealthyDocument("help smilies");

  response = await page.goto(baseUrl + "/help/trophies", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help trophies: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Kupalar ve rozetler", exact: true }).waitFor();
  await assertHealthyDocument("help trophies");

  response = await page.goto(baseUrl + "/help/rss", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help feeds: route did not return HTTP 200");
  if ((await page.locator(".public-reference-card").count()) !== 2) fail("help feeds: RSS/Atom cards are missing");
  await assertHealthyDocument("help feeds");

  const rssResponse = await page.request.get(baseUrl + "/feed.rss");
  if (rssResponse.status() !== 200 || !(rssResponse.headers()["content-type"] || "").includes("application/rss+xml")) {
    fail(`RSS feed contract failed: ${rssResponse.status()} ${rssResponse.headers()["content-type"] || ""}`);
  }
  const atomResponse = await page.request.get(baseUrl + "/feed.atom");
  if (atomResponse.status() !== 200 || !(atomResponse.headers()["content-type"] || "").includes("application/atom+xml")) {
    fail(`Atom feed contract failed: ${atomResponse.status()} ${atomResponse.headers()["content-type"] || ""}`);
  }

  response = await page.goto(baseUrl + "/groups", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("community groups: directory route did not return HTTP 200");
  await page.getByRole("heading", { name: "Klanlar & Gruplar", exact: true }).waitFor();
  const groupDirectoryState = await page.evaluate(() => ({
    searchForms: document.querySelectorAll(".group-search").length,
    directories: document.querySelectorAll(".group-directory").length,
    cards: document.querySelectorAll(".group-card").length,
  }));
  if (groupDirectoryState.searchForms !== 1 || groupDirectoryState.directories !== 1 || groupDirectoryState.cards < 1) {
    fail(`community groups: directory contract failed ${JSON.stringify(groupDirectoryState)}`);
  }
  await assertHealthyDocument("community groups");

  response = await page.goto(baseUrl + "/groups/mine", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("community groups mine: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Klanlarım", exact: true }).waitFor();
  if ((await page.locator(".group-create-panel").count()) !== 1) {
    fail("community groups mine: create form is missing for administrator");
  }
  await page.locator('.group-create-panel input[name="name"]').fill("CI Admin Klanı");
  await page.locator('.group-create-panel input[name="slug"]').fill("ci-admin-klani");
  await page.locator('.group-create-panel input[name="tagline"]').fill("Gerçek create akışı");
  await page.locator('.group-create-panel textarea[name="description"]').fill(
    "Phase 8 gerçek tarayıcı kabul testi tarafından oluşturulan topluluk grubu.",
  );
  await page.locator('.group-create-panel select[name="join_policy"]').selectOption("open");
  const [groupCreateResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/groups/mine";
    }),
    page.getByRole("button", { name: "Grubu oluştur", exact: true }).click(),
  ]);
  if (groupCreateResponse.status() !== 303) {
    fail(`community groups mine: create POST returned HTTP ${groupCreateResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  if (new URL(page.url()).pathname !== "/groups/mine" || new URL(page.url()).searchParams.get("created") !== "1") {
    fail("community groups mine: create redirect is invalid");
  }
  await page.getByText("Grup oluşturuldu.", { exact: true }).waitFor();
  if ((await page.locator(".group-mine-row").count()) < 1) {
    fail("community groups mine: created group is missing from membership list");
  }
  await assertHealthyDocument("community groups mine create");

  const approvalGroupId = "dddddddddddddddddddddddddddddddd";
  response = await page.goto(baseUrl + "/groups/" + approvalGroupId, { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("community group detail: route did not return HTTP 200");
  await page.getByRole("heading", { name: "CI Onaylı Grup", exact: true }).waitFor();
  const joinButton = page.getByRole("button", { name: "Katılım isteği gönder", exact: true });
  if ((await joinButton.count()) !== 1) fail("community group detail: approval join action is missing");
  const [groupJoinResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/groups/" + approvalGroupId;
    }),
    joinButton.click(),
  ]);
  if (groupJoinResponse.status() !== 303) {
    fail(`community group detail: join POST returned HTTP ${groupJoinResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  await page.getByText("Üyelik isteğin gönderildi.", { exact: true }).waitFor();

  const approveButton = page.getByRole("button", { name: "Onayla", exact: true });
  if ((await approveButton.count()) !== 1) {
    fail("community group detail: pending membership management action is missing");
  }
  const [groupApproveResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/groups/" + approvalGroupId;
    }),
    approveButton.click(),
  ]);
  if (groupApproveResponse.status() !== 303) {
    fail(`community group detail: approval POST returned HTTP ${groupApproveResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  await page.getByText("Üyelik güncellendi.", { exact: true }).waitFor();

  const leaveGroupButton = page.getByRole("button", { name: "Gruptan ayrıl", exact: true });
  if ((await leaveGroupButton.count()) !== 1) fail("community group detail: leave action is missing after approval");
  const [groupLeaveResponse] = await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "POST" && url.pathname === "/groups/" + approvalGroupId;
    }),
    leaveGroupButton.click(),
  ]);
  if (groupLeaveResponse.status() !== 303) {
    fail(`community group detail: leave POST returned HTTP ${groupLeaveResponse.status()} instead of 303`);
  }
  await page.waitForLoadState("domcontentloaded");
  await page.getByText("Grup üyeliğin sona erdi.", { exact: true }).waitFor();
  await assertHealthyDocument("community group membership lifecycle");

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

  response = await page.goto(baseUrl + "/members/online", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("online members: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Çevrimiçi Kullanıcılar", exact: true }).waitFor();
  const onlineDirectoryState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    summaryCount: document.querySelectorAll(".online-users-results .member-directory-summary").length,
    settingsCount: document.querySelectorAll("[data-presence-settings]").length,
    communityActions: document.querySelectorAll(".online-users-head .member-directory-head-actions a").length,
  }));
  if (
    onlineDirectoryState.section !== "members"
    || onlineDirectoryState.summaryCount !== 1
    || onlineDirectoryState.settingsCount !== 1
    || onlineDirectoryState.communityActions !== 2
  ) {
    fail(`online members: active/layout contract failed ${JSON.stringify(onlineDirectoryState)}`);
  }
  await assertHealthyDocument("online members");

  response = await page.goto(baseUrl + "/members/ci-admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("member profile: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "ci-admin", exact: true }).waitFor();
  const memberProfileState = await page.evaluate(() => ({
    section: document.querySelector(".top")?.getAttribute("data-active-nav-section") ?? "",
    profileCount: document.querySelectorAll(".profile-reference-shell").length,
    actionGroups: document.querySelectorAll(".profile-head-actions").length,
    factRows: document.querySelectorAll(".profile-overview-facts > div").length,
    controlledTabs: document.querySelectorAll(".profile-tabs a[aria-controls]").length,
  }));
  if (
    memberProfileState.section !== "members"
    || memberProfileState.profileCount !== 1
    || memberProfileState.actionGroups !== 1
    || memberProfileState.factRows !== 2
    || memberProfileState.controlledTabs < 1
  ) {
    fail(`member profile: density contract failed ${JSON.stringify(memberProfileState)}`);
  }
  await assertHealthyDocument("member profile");

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/help", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help mobile: route did not return HTTP 200");
  const helpMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".public-reference-grid");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (helpMobileState.columns !== 1 || helpMobileState.width > helpMobileState.viewportWidth) {
    fail(`help mobile: responsive contract failed ${JSON.stringify(helpMobileState)}`);
  }
  await assertHealthyDocument("help mobile");

  response = await page.goto(baseUrl + "/help/smilies", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("help smilies mobile: route did not return HTTP 200");
  const emojiMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".emoji-reference-grid");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (emojiMobileState.columns !== 1 || emojiMobileState.width > emojiMobileState.viewportWidth) {
    fail(`help smilies mobile: responsive contract failed ${JSON.stringify(emojiMobileState)}`);
  }
  await assertHealthyDocument("help smilies mobile");

  response = await page.goto(baseUrl + "/moderation/oversight/cases/cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("moderation oversight case mobile: route did not return HTTP 200");
  await page.screenshot({
    path: path.join(artifactDir, "visual-review", "037-moderation-oversight-case-mobile.jpg"),
    fullPage: true,
    type: "jpeg",
    quality: 84,
  });
  const oversightCaseMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".oversight-case-grid");
    const nav = document.querySelector(".moderation-nav");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      gridWidth: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      navWidth: nav instanceof HTMLElement ? Math.round(nav.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    oversightCaseMobileState.columns !== 1
    || oversightCaseMobileState.gridWidth > oversightCaseMobileState.viewportWidth
    || oversightCaseMobileState.navWidth > oversightCaseMobileState.viewportWidth
  ) {
    fail(`moderation oversight case mobile: responsive contract failed ${JSON.stringify(oversightCaseMobileState)}`);
  }
  await assertHealthyDocument("moderation oversight case mobile");

  response = await page.goto(baseUrl + "/portfolio?category=general&featured=1", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio mobile: browse route did not return HTTP 200");
  const portfolioMobileState = await page.evaluate(() => {
    const browse = document.querySelector(".portfolio-browse-bar");
    return {
      columns: browse instanceof HTMLElement ? getComputedStyle(browse).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: browse instanceof HTMLElement ? Math.round(browse.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (portfolioMobileState.columns !== 1 || portfolioMobileState.width > portfolioMobileState.viewportWidth) {
    fail(`portfolio mobile: browse responsive contract failed ${JSON.stringify(portfolioMobileState)}`);
  }
  await assertHealthyDocument("portfolio mobile");

  response = await page.goto(baseUrl + "/portfolio/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio detail mobile: route did not return HTTP 200");
  const portfolioDetailMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".portfolio-project-main-grid");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (portfolioDetailMobileState.columns !== 1 || portfolioDetailMobileState.width > portfolioDetailMobileState.viewportWidth) {
    fail(`portfolio detail mobile: responsive contract failed ${JSON.stringify(portfolioDetailMobileState)}`);
  }
  await assertHealthyDocument("portfolio detail mobile");

  response = await page.goto(baseUrl + "/portfolio/manage?project=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("portfolio manage mobile: route did not return HTTP 200");
  const portfolioManageMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".portfolio-manage-grid");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (portfolioManageMobileState.columns !== 1 || portfolioManageMobileState.width > portfolioManageMobileState.viewportWidth) {
    fail(`portfolio manage mobile: responsive contract failed ${JSON.stringify(portfolioManageMobileState)}`);
  }
  await assertHealthyDocument("portfolio manage mobile");

  response = await page.goto(baseUrl + "/faq?lang=tr", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("faq mobile: route did not return HTTP 200");
  const faqMobileState = await page.evaluate(() => {
    const overview = document.querySelector(".faq-overview");
    return {
      columns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: overview instanceof HTMLElement ? Math.round(overview.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (faqMobileState.columns !== 1 || faqMobileState.width > faqMobileState.viewportWidth) {
    fail(`faq mobile: responsive contract failed ${JSON.stringify(faqMobileState)}`);
  }
  await assertHealthyDocument("faq mobile");

  response = await page.goto(baseUrl + "/referrals/manage", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("referrals manage mobile: route did not return HTTP 200");
  const referralMobileState = await page.evaluate(() => {
    const qualification = document.querySelector(".referral-qualification-panel");
    return {
      columns: qualification instanceof HTMLElement ? getComputedStyle(qualification).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: qualification instanceof HTMLElement ? Math.round(qualification.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (referralMobileState.columns !== 1 || referralMobileState.width > referralMobileState.viewportWidth) {
    fail(`referrals manage mobile: responsive contract failed ${JSON.stringify(referralMobileState)}`);
  }
  await assertHealthyDocument("referrals manage mobile");

  response = await page.goto(baseUrl + "/bugs", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("bugs mobile: route did not return HTTP 200");
  const bugListMobileState = await page.evaluate(() => {
    const overview = document.querySelector(".bug-list-overview");
    return {
      columns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: overview instanceof HTMLElement ? Math.round(overview.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (bugListMobileState.columns !== 2 || bugListMobileState.width > bugListMobileState.viewportWidth) {
    fail(`bugs mobile: list responsive contract failed ${JSON.stringify(bugListMobileState)}`);
  }
  await assertHealthyDocument("bugs mobile");

  response = await page.goto(baseUrl + "/bugs/report", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("bug report form mobile: route did not return HTTP 200");
  const bugFormMobileState = await page.evaluate(() => {
    const grid = document.querySelector(".bug-form-grid");
    return {
      columns: grid instanceof HTMLElement ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      width: grid instanceof HTMLElement ? Math.round(grid.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (bugFormMobileState.columns !== 1 || bugFormMobileState.width > bugFormMobileState.viewportWidth) {
    fail(`bug report form mobile: responsive contract failed ${JSON.stringify(bugFormMobileState)}`);
  }
  await assertHealthyDocument("bug report form mobile");

  response = await page.goto(baseUrl + "/members/ci-admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("member profile mobile: real route did not return HTTP 200");
  const memberProfileMobileState = await page.evaluate(() => {
    const links = [...document.querySelectorAll(".profile-forum-content-actions .fx-btn")];
    const facts = document.querySelector(".profile-overview-facts");
    return {
      linkCount: links.length,
      shortTargets: links.filter((link) => link.getBoundingClientRect().height < 43).length,
      factColumns: facts instanceof HTMLElement
        ? getComputedStyle(facts).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
    };
  });
  if (
    memberProfileMobileState.linkCount !== 2
    || memberProfileMobileState.shortTargets !== 0
    || memberProfileMobileState.factColumns !== 1
  ) {
    fail(`member profile mobile: responsive contract failed ${JSON.stringify(memberProfileMobileState)}`);
  }
  await assertHealthyDocument("member profile mobile");

  response = await page.goto(baseUrl + "/account/conversations", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("direct messages mobile: real route did not return HTTP 200");
  const conversationMobileState = await page.evaluate(() => {
    const headerAction = document.querySelector('.conversation-head > .fx-btn');
    const filters = document.querySelector(".conversation-filters");
    const layout = document.querySelector(".conversation-layout");
    return {
      headerActionWidth: headerAction instanceof HTMLElement ? Math.round(headerAction.getBoundingClientRect().width) : 0,
      filterWidth: filters instanceof HTMLElement ? Math.round(filters.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
      layoutColumns: layout instanceof HTMLElement
        ? getComputedStyle(layout).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
    };
  });
  if (
    conversationMobileState.headerActionWidth < 250
    || conversationMobileState.filterWidth > conversationMobileState.viewportWidth
    || conversationMobileState.layoutColumns !== 1
  ) {
    fail(`direct messages mobile: responsive contract failed ${JSON.stringify(conversationMobileState)}`);
  }
  await assertHealthyDocument("direct messages mobile");

  response = await page.goto(baseUrl + "/servers", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft servers mobile: real route did not return HTTP 200");
  const serverMobileState = await page.evaluate(() => {
    const filter = document.querySelector(".minecraft-server-filter");
    const list = document.querySelector(".minecraft-server-list");
    return {
      filterColumns: filter instanceof HTMLElement
        ? getComputedStyle(filter).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
      listWidth: list instanceof HTMLElement ? Math.round(list.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    serverMobileState.filterColumns !== 1
    || serverMobileState.listWidth > serverMobileState.viewportWidth
  ) {
    fail(`minecraft servers mobile: responsive contract failed ${JSON.stringify(serverMobileState)}`);
  }
  await assertHealthyDocument("minecraft servers mobile");

  if (firstServerHref) {
    const mobileServerUrl = new URL(firstServerHref, baseUrl).toString().replace(/\/$/, "");
    response = await page.goto(mobileServerUrl + "/updates", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server updates mobile: real route did not return HTTP 200");
    const updateMobileState = await page.evaluate(() => ({
      width: document.querySelector(".minecraft-update-list") instanceof HTMLElement
        ? Math.round(document.querySelector(".minecraft-update-list").getBoundingClientRect().width)
        : 0,
      viewportWidth: window.innerWidth,
    }));
    if (updateMobileState.width > updateMobileState.viewportWidth) {
      fail(`minecraft server updates mobile: overflow contract failed ${JSON.stringify(updateMobileState)}`);
    }
    await assertHealthyDocument("minecraft server updates mobile");

    response = await page.goto(mobileServerUrl + "/stats", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server statistics mobile: real route did not return HTTP 200");
    const statisticsMobileState = await page.evaluate(() => {
      const grid = document.querySelector(".minecraft-stat-grid");
      const trend = document.querySelector(".minecraft-vote-trend");
      return {
        columns: grid instanceof HTMLElement
          ? getComputedStyle(grid).gridTemplateColumns.split(" ").filter(Boolean).length
          : 0,
        trendWidth: trend instanceof HTMLElement ? Math.round(trend.getBoundingClientRect().width) : 0,
        viewportWidth: window.innerWidth,
      };
    });
    if (
      statisticsMobileState.columns !== 1
      || statisticsMobileState.trendWidth > statisticsMobileState.viewportWidth
    ) {
      fail(`minecraft server statistics mobile: responsive contract failed ${JSON.stringify(statisticsMobileState)}`);
    }
    await assertHealthyDocument("minecraft server statistics mobile");

    response = await page.goto(mobileServerUrl + "/team", { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server team mobile: real route did not return HTTP 200");
    const teamMobileState = await page.evaluate(() => {
      const list = document.querySelector(".minecraft-team-list");
      const member = document.querySelector(".minecraft-team-member");
      return {
        listWidth: list instanceof HTMLElement ? Math.round(list.getBoundingClientRect().width) : 0,
        memberColumns: member instanceof HTMLElement
          ? getComputedStyle(member).gridTemplateColumns.split(" ").filter(Boolean).length
          : 0,
        viewportWidth: window.innerWidth,
      };
    });
    if (
      teamMobileState.listWidth > teamMobileState.viewportWidth
      || (teamMobileState.memberColumns !== 0 && teamMobileState.memberColumns !== 2)
    ) {
      fail(`minecraft server team mobile: responsive contract failed ${JSON.stringify(teamMobileState)}`);
    }
    await assertHealthyDocument("minecraft server team mobile");
  }

  response = await page.goto(baseUrl + "/groups", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("community groups mobile: directory route did not return HTTP 200");
  const groupsMobileState = await page.evaluate(() => {
    const directory = document.querySelector(".group-directory");
    const search = document.querySelector(".group-search");
    return {
      directoryWidth: directory instanceof HTMLElement ? Math.round(directory.getBoundingClientRect().width) : 0,
      searchColumns: search instanceof HTMLElement
        ? getComputedStyle(search).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    groupsMobileState.directoryWidth > groupsMobileState.viewportWidth
    || groupsMobileState.searchColumns !== 1
  ) {
    fail(`community groups mobile: responsive contract failed ${JSON.stringify(groupsMobileState)}`);
  }
  await assertHealthyDocument("community groups mobile");

  response = await page.goto(baseUrl + "/groups/mine", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("community groups mine mobile: route did not return HTTP 200");
  const groupsMineMobileState = await page.evaluate(() => {
    const form = document.querySelector(".group-create-panel form");
    const list = document.querySelector(".group-mine-list");
    return {
      formColumns: form instanceof HTMLElement
        ? getComputedStyle(form).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
      listWidth: list instanceof HTMLElement ? Math.round(list.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    groupsMineMobileState.formColumns !== 1
    || groupsMineMobileState.listWidth > groupsMineMobileState.viewportWidth
  ) {
    fail(`community groups mine mobile: responsive contract failed ${JSON.stringify(groupsMineMobileState)}`);
  }
  await assertHealthyDocument("community groups mine mobile");

  response = await page.goto(baseUrl + "/servers/manage", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("minecraft server management mobile: real route did not return HTTP 200");
  const serverManagementMobileState = await page.evaluate(() => {
    const panel = document.querySelector(".minecraft-manage-list");
    const row = document.querySelector(".minecraft-manage-row");
    return {
      panelWidth: panel instanceof HTMLElement ? Math.round(panel.getBoundingClientRect().width) : 0,
      rowColumns: row instanceof HTMLElement
        ? getComputedStyle(row).gridTemplateColumns.split(" ").filter(Boolean).length
        : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    serverManagementMobileState.panelWidth > serverManagementMobileState.viewportWidth
    || (serverManagementMobileState.rowColumns !== 0 && serverManagementMobileState.rowColumns !== 1)
  ) {
    fail(`minecraft server management mobile: responsive contract failed ${JSON.stringify(serverManagementMobileState)}`);
  }
  await assertHealthyDocument("minecraft server management mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });

  response = await page.goto(baseUrl + "/admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail(`admin: real route returned HTTP ${response?.status() ?? "no response"}`);
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();
  const adminDensityState = await page.evaluate(() => ({
    overview: document.querySelectorAll(".acp-overview-stat").length,
    sectionIndex: document.querySelectorAll(".acp-section-index a").length,
    directoryRows: document.querySelectorAll(".acp-directory-row").length,
    queueRows: document.querySelectorAll(".acp-queue-row").length,
  }));
  if (adminDensityState.overview !== 4 || adminDensityState.sectionIndex < 1 || adminDensityState.directoryRows < 1) {
    fail(`admin: dense dashboard contract failed ${JSON.stringify(adminDensityState)}`);
  }
  const dashboardRailDesktop = await page.evaluate(() => {
    const workspace = document.querySelector(".acp-dashboard-workspace");
    const rail = document.querySelector(".acp-dashboard-rail");
    return {
      columns: workspace instanceof HTMLElement
        ? getComputedStyle(workspace).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      railLinks: rail?.querySelectorAll(".acp-section-index a").length ?? 0,
      width: workspace instanceof HTMLElement ? Math.round(workspace.getBoundingClientRect().width) : 0,
      viewport: window.innerWidth,
    };
  });
  if (
    dashboardRailDesktop.columns !== 2
    || dashboardRailDesktop.railLinks !== adminDensityState.sectionIndex
    || dashboardRailDesktop.width > dashboardRailDesktop.viewport
  ) fail(`admin: reference-review navigation rail contract failed ${JSON.stringify(dashboardRailDesktop)}`);
  await assertHealthyDocument("admin GET");

  response = await page.goto(baseUrl + "/admin?q=user", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin search: route did not return HTTP 200");
  const adminSearchState = await page.evaluate(() => ({
    panel: document.querySelectorAll(".acp-search-results").length,
    rows: document.querySelectorAll(".acp-search-results .acp-directory-row").length,
    clear: document.querySelectorAll('.acp-search-results a[href$="/admin"]').length,
  }));
  if (adminSearchState.panel !== 1 || adminSearchState.rows < 1 || adminSearchState.clear !== 1) {
    fail(`admin search: dense results contract failed ${JSON.stringify(adminSearchState)}`);
  }
  await assertHealthyDocument("admin search");

  response = await page.goto(baseUrl + "/admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin reset: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();

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

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin mobile: route did not return HTTP 200");
  const adminMobileState = await page.evaluate(() => {
    const overview = document.querySelector(".acp-overview");
    const row = document.querySelector(".acp-directory-row");
    const dashboard = document.querySelector(".acp-dashboard");
    return {
      overviewColumns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      rowColumns: row instanceof HTMLElement ? getComputedStyle(row).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      dashboardWidth: dashboard instanceof HTMLElement ? Math.round(dashboard.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    adminMobileState.overviewColumns !== 2
    || adminMobileState.rowColumns !== 1
    || adminMobileState.dashboardWidth > adminMobileState.viewportWidth
  ) {
    fail(`admin mobile: dense dashboard responsive contract failed ${JSON.stringify(adminMobileState)}`);
  }
  const dashboardRailMobile = await page.evaluate(() => {
    const workspace = document.querySelector(".acp-dashboard-workspace");
    const rail = document.querySelector(".acp-dashboard-rail");
    const first = rail?.querySelector(".acp-section-index a");
    return {
      columns: workspace instanceof HTMLElement
        ? getComputedStyle(workspace).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      linkHeight: first instanceof HTMLElement ? Math.round(first.getBoundingClientRect().height) : 0,
    };
  });
  if (dashboardRailMobile.columns !== 1 || dashboardRailMobile.linkHeight < 44) {
    fail(`admin mobile: reference-review navigation rail reflow contract failed ${JSON.stringify(dashboardRailMobile)}`);
  }
  await assertHealthyDocument("admin mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });
  response = await page.goto(baseUrl + "/admin/users?q=phase12", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin users: search route did not return HTTP 200");
  await page.getByRole("heading", { name: "Kullanıcı Yönetimi", exact: true }).waitFor();
  const userDirectoryState = await page.evaluate(() => ({
    shell: document.querySelectorAll(".ac-users-shell").length,
    rows: document.querySelectorAll(".ac-user-table tbody tr").length,
    search: document.querySelector('.ac-users-toolbar input[name="q"]')?.value ?? "",
  }));
  if (userDirectoryState.shell !== 1 || userDirectoryState.rows < 1 || userDirectoryState.search !== "phase12") {
    fail(`admin users: directory contract failed ${JSON.stringify(userDirectoryState)}`);
  }
  const memberLink = page.getByRole("link", { name: /phase12-member/ });
  if ((await memberLink.count()) !== 1) fail("admin users: seeded member link is missing");
  const memberHref = await memberLink.getAttribute("href");
  if (!memberHref || !memberHref.includes("user=12121212121212121212121212121212")) {
    fail(`admin users: seeded member target is invalid: ${memberHref ?? "<missing>"}`);
  }
  response = await page.goto(
    baseUrl + "/admin/users?user=12121212121212121212121212121212&q=phase12",
    { waitUntil: "domcontentloaded" },
  );
  if (!response || response.status() !== 200) {
    const body = response ? (await response.text()).slice(0, 800) : "<no response>";
    fail(`admin users selected: route returned HTTP ${response?.status() ?? "none"}; body=${body}`);
  }
  const userDetailState = await page.evaluate(() => ({
    detail: document.querySelectorAll(".ac-user-detail").length,
    facts: document.querySelectorAll(".ac-user-fact").length,
    accessFacts: document.querySelectorAll(".ac-user-access-fact").length,
    historyRows: document.querySelectorAll(".ac-user-history tbody tr").length,
    accessSubmitDisabled: document.querySelector('form input[name="action"][value="replace_access"]')?.closest("form")?.querySelector('button[type="submit"]')?.disabled ?? true,
    statusSubmitDisabled: document.querySelector('form input[name="action"][value="change_status"]')?.closest("form")?.querySelector('button[type="submit"]')?.disabled ?? true,
  }));
  if (
    userDetailState.detail !== 1
    || userDetailState.facts < 6
    || userDetailState.accessFacts !== 3
    || userDetailState.historyRows < 1
    || userDetailState.accessSubmitDisabled
    || userDetailState.statusSubmitDisabled
  ) {
    fail(`admin users: selected user contract failed ${JSON.stringify(userDetailState)}`);
  }
  await assertHealthyDocument("admin users desktop");

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin/users?user=12121212121212121212121212121212&q=phase12", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin users mobile: route did not return HTTP 200");
  const userMobileState = await page.evaluate(() => {
    const edit = document.querySelector(".ac-user-edit-grid");
    const facts = document.querySelector(".ac-user-facts");
    const shell = document.querySelector(".ac-users-shell");
    return {
      editColumns: edit instanceof HTMLElement ? getComputedStyle(edit).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      factColumns: facts instanceof HTMLElement ? getComputedStyle(facts).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      shellWidth: shell instanceof HTMLElement ? Math.round(shell.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    userMobileState.editColumns !== 1
    || userMobileState.factColumns !== 1
    || userMobileState.shellWidth > userMobileState.viewportWidth
  ) {
    fail(`admin users mobile: responsive contract failed ${JSON.stringify(userMobileState)}`);
  }
  await assertHealthyDocument("admin users mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });
  response = await page.goto(baseUrl + "/admin/access?q=phase13", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin access: filter route did not return HTTP 200");
  await page.getByRole("heading", { name: "Grup, Rol ve Yetki Yönetimi", exact: true }).waitFor();
  const accessDirectoryState = await page.evaluate(() => ({
    overview: document.querySelectorAll(".ac-access-stat").length,
    groupRows: document.querySelectorAll(".ac-access-directory-grid section:first-child tbody tr").length,
    roleRows: document.querySelectorAll(".ac-access-directory-grid section:last-child tbody tr").length,
    filter: document.querySelector('.ac-access-filter input[name="q"]')?.value ?? "",
  }));
  if (
    accessDirectoryState.overview !== 4
    || accessDirectoryState.groupRows !== 1
    || accessDirectoryState.roleRows !== 1
    || accessDirectoryState.filter !== "phase13"
  ) {
    fail(`admin access: directory contract failed ${JSON.stringify(accessDirectoryState)}`);
  }

  const phase13Group = page.getByRole("link", { name: "Phase 13 Browser Group", exact: true });
  if ((await phase13Group.count()) !== 1) fail("admin access: seeded group link is missing");
  await phase13Group.click();
  await page.waitForLoadState("domcontentloaded");
  const groupEditorState = await page.evaluate(() => ({
    editor: document.querySelectorAll(".ac-access-editor").length,
    saveGroup: document.querySelectorAll('.ac-access-editor form input[name="action"][value="save_group"]').length,
    facts: document.querySelectorAll(".ac-access-facts .ac-user-access-fact").length,
  }));
  if (groupEditorState.editor < 1 || groupEditorState.saveGroup !== 1 || groupEditorState.facts !== 3) {
    fail(`admin access: selected group contract failed ${JSON.stringify(groupEditorState)}`);
  }
  await assertHealthyDocument("admin access group");

  response = await page.goto(baseUrl + "/admin/access?q=phase13", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin access role reset: route did not return HTTP 200");
  const phase13Role = page.getByRole("link", { name: "Phase 13 Browser Role", exact: true });
  if ((await phase13Role.count()) !== 1) fail("admin access: seeded role link is missing");
  await phase13Role.click();
  await page.waitForLoadState("domcontentloaded");
  const roleEditorState = await page.evaluate(() => ({
    roleGrid: document.querySelectorAll(".ac-access-role-grid").length,
    saveRole: document.querySelectorAll('.ac-access-role-grid form input[name="action"][value="save_role"]').length,
    saveAppearance: document.querySelectorAll('.ac-access-role-grid form input[name="action"][value="save_appearance"]').length,
  }));
  if (roleEditorState.roleGrid !== 1 || roleEditorState.saveRole !== 1 || roleEditorState.saveAppearance !== 1) {
    fail(`admin access: selected role contract failed ${JSON.stringify(roleEditorState)}`);
  }
  await assertHealthyDocument("admin access role");

  response = await page.goto(baseUrl + "/admin/access", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin access analyzer: base route did not return HTTP 200");
  const analyzerForm = page.locator(".ac-permission-analyzer form");
  await analyzerForm.locator('select[name="analyze_user"]').selectOption({ label: "phase12-member" });
  await analyzerForm.locator('select[name="permission"]').selectOption("acp.access");
  await Promise.all([
    page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      return candidate.request().method() === "GET"
        && url.pathname === "/admin/access"
        && url.searchParams.get("permission") === "acp.access";
    }),
    analyzerForm.getByRole("button", { name: "Analiz et", exact: true }).click(),
  ]);
  await page.waitForLoadState("domcontentloaded");
  const analyzerState = await page.evaluate(() => ({
    result: document.querySelectorAll(".ac-permission-result").length,
    user: document.querySelector('.ac-permission-analyzer select[name="analyze_user"]')?.value ?? "",
    permission: document.querySelector('.ac-permission-analyzer select[name="permission"]')?.value ?? "",
    layers: document.querySelectorAll(".ac-analysis-layer").length,
  }));
  if (
    analyzerState.result !== 1
    || analyzerState.user !== "12121212121212121212121212121212"
    || analyzerState.permission !== "acp.access"
    || analyzerState.layers < 1
  ) {
    fail(`admin access: analyzer contract failed ${JSON.stringify(analyzerState)}`);
  }
  await assertHealthyDocument("admin access analyzer");

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin/access?role=31313131313131313131313131313131", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin access mobile: route did not return HTTP 200");
  const accessMobileState = await page.evaluate(() => {
    const overview = document.querySelector(".ac-access-overview");
    const roleGrid = document.querySelector(".ac-access-role-grid");
    const shell = document.querySelector(".ac-access-shell");
    return {
      overviewColumns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      roleColumns: roleGrid instanceof HTMLElement ? getComputedStyle(roleGrid).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      shellWidth: shell instanceof HTMLElement ? Math.round(shell.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    accessMobileState.overviewColumns !== 2
    || accessMobileState.roleColumns !== 1
    || accessMobileState.shellWidth > accessMobileState.viewportWidth
  ) {
    fail(`admin access mobile: responsive contract failed ${JSON.stringify(accessMobileState)}`);
  }
  await assertHealthyDocument("admin access mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });
  const starterForumId = "00000000000000000000000000000002";
  response = await page.goto(baseUrl + "/admin/forums?node=" + starterForumId + "&q=Genel", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin forums: selected node route did not return HTTP 200");
  await page.getByRole("heading", { name: "Forum ve Node Yönetimi", exact: true }).waitFor();
  const forumAdminState = await page.evaluate(() => ({
    overview: document.querySelectorAll(".ac-forum-stat").length,
    rows: document.querySelectorAll(".ac-forum-table tbody tr").length,
    facts: document.querySelectorAll(".ac-forum-fact").length,
    editor: document.querySelectorAll(".ac-forum-editor-form").length,
    selectedId: document.querySelector('.ac-forum-editor-form input[name="node_id"]')?.value ?? "",
    search: document.querySelector('.ac-forum-filter input[name="q"]')?.value ?? "",
  }));
  if (
    forumAdminState.overview !== 4
    || forumAdminState.rows < 2
    || forumAdminState.facts !== 8
    || forumAdminState.editor !== 1
    || forumAdminState.selectedId !== starterForumId
    || forumAdminState.search !== "Genel"
  ) {
    fail(`admin forums: dense node workspace contract failed ${JSON.stringify(forumAdminState)}`);
  }
  await assertHealthyDocument("admin forums desktop");

  response = await page.goto(baseUrl + "/admin/content", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin content: route did not return HTTP 200");
  await page.getByRole("heading", { name: "İçerik ACP", exact: true }).waitFor();
  const contentAdminState = await page.evaluate(() => ({
    metrics: document.querySelectorAll(".ac-content-metric").length,
    operations: document.querySelectorAll(".ac-content-operation").length,
    available: document.querySelectorAll('.ac-content-operation[data-available="1"]').length,
    unavailable: document.querySelectorAll('.ac-content-operation[data-available="0"]').length,
  }));
  if (contentAdminState.metrics !== 4 || contentAdminState.operations !== 4 || contentAdminState.available < 1) {
    fail(`admin content: operations workspace contract failed ${JSON.stringify(contentAdminState)}`);
  }
  await assertHealthyDocument("admin content desktop");

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin/forums?node=" + starterForumId, { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin forums mobile: route did not return HTTP 200");
  const forumAdminMobile = await page.evaluate(() => {
    const overview = document.querySelector(".ac-forum-overview");
    const facts = document.querySelector(".ac-forum-facts");
    const row = document.querySelector(".ac-forum-table tbody tr");
    const shell = document.querySelector(".ac-forums-shell");
    return {
      overviewColumns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      factColumns: facts instanceof HTMLElement ? getComputedStyle(facts).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      rowColumns: row instanceof HTMLElement ? getComputedStyle(row).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      shellWidth: shell instanceof HTMLElement ? Math.round(shell.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    forumAdminMobile.overviewColumns !== 1
    || forumAdminMobile.factColumns !== 1
    || forumAdminMobile.rowColumns !== 1
    || forumAdminMobile.shellWidth > forumAdminMobile.viewportWidth
  ) {
    fail(`admin forums mobile: responsive contract failed ${JSON.stringify(forumAdminMobile)}`);
  }
  await assertHealthyDocument("admin forums mobile");

  response = await page.goto(baseUrl + "/admin/content", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin content mobile: route did not return HTTP 200");
  const contentAdminMobile = await page.evaluate(() => {
    const overview = document.querySelector(".ac-content-overview");
    const operation = document.querySelector(".ac-content-operation");
    const shell = document.querySelector(".ac-content-shell");
    return {
      overviewColumns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      operationColumns: operation instanceof HTMLElement ? getComputedStyle(operation).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      shellWidth: shell instanceof HTMLElement ? Math.round(shell.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    contentAdminMobile.overviewColumns !== 1
    || contentAdminMobile.operationColumns !== 1
    || contentAdminMobile.shellWidth > contentAdminMobile.viewportWidth
  ) {
    fail(`admin content mobile: responsive contract failed ${JSON.stringify(contentAdminMobile)}`);
  }
  await assertHealthyDocument("admin content mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });
  response = await page.goto(baseUrl + "/admin/appearance/themes?theme=forwext-balanced", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) {
    const themeFailureBody = response ? (await response.text()).slice(0, 800) : "<no response>";
    fail(`admin theme: route returned HTTP ${response?.status() ?? "none"}; body=${themeFailureBody}`);
  }
  await page.getByRole("heading", { name: "Temalar", exact: true }).waitFor();
  const themeAdminState = await page.evaluate(() => ({
    overview: document.querySelectorAll(".theme-stat").length,
    directory: document.querySelectorAll(".theme-directory .theme-list-item").length,
    facts: document.querySelectorAll(".theme-fact").length,
    groups: document.querySelectorAll(".theme-editor-group").length,
    revisions: document.querySelectorAll(".theme-revision").length,
    templates: document.querySelector('textarea[name="templates_json"]')?.value.length ?? 0,
    phrases: document.querySelector('textarea[name="phrases_json"]')?.value.length ?? 0,
  }));
  if (
    themeAdminState.overview !== 4
    || themeAdminState.directory < 2
    || themeAdminState.facts !== 6
    || themeAdminState.groups !== 3
    || themeAdminState.revisions < 1
    || themeAdminState.templates < 2
    || themeAdminState.phrases < 2
  ) {
    fail(`admin theme: dense workspace contract failed ${JSON.stringify(themeAdminState)}`);
  }
  await assertHealthyDocument("admin theme desktop");

  response = await page.goto(baseUrl + "/admin/appearance/layout", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin layout: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Layout Builder", exact: true }).waitFor();
  if ((await page.locator("[data-layout-builder]").count()) !== 1) {
    fail("admin layout: builder root is missing");
  }
  await assertHealthyDocument("admin layout desktop");

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin/appearance/themes?theme=forwext-balanced", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin theme mobile: route did not return HTTP 200");
  const themeMobileState = await page.evaluate(() => {
    const overview = document.querySelector(".theme-overview");
    const directory = document.querySelector(".theme-directory");
    const workspace = document.querySelector(".theme-workspace-grid");
    const admin = document.querySelector(".theme-admin");
    return {
      overviewColumns: overview instanceof HTMLElement ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      directoryColumns: directory instanceof HTMLElement ? getComputedStyle(directory).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      workspaceColumns: workspace instanceof HTMLElement ? getComputedStyle(workspace).gridTemplateColumns.split(" ").filter(Boolean).length : 0,
      adminWidth: admin instanceof HTMLElement ? Math.round(admin.getBoundingClientRect().width) : 0,
      viewportWidth: window.innerWidth,
    };
  });
  if (
    themeMobileState.overviewColumns !== 1
    || themeMobileState.directoryColumns !== 1
    || themeMobileState.workspaceColumns !== 1
    || themeMobileState.adminWidth > themeMobileState.viewportWidth
  ) {
    fail(`admin theme mobile: responsive contract failed ${JSON.stringify(themeMobileState)}`);
  }
  await assertHealthyDocument("admin theme mobile");

  await page.setViewportSize({ width: 1440, height: 1000 });
  response = await page.goto(baseUrl + "/admin/navigation?state=all&placement=all", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin navigation: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Navigasyon Yönetimi", exact: true }).waitFor();
  const platformNavigation = await page.evaluate(() => ({
    tabs: document.querySelectorAll(".platform-tabs .platform-tab").length,
    stats: document.querySelectorAll(".platform-overview .platform-stat").length,
    rows: document.querySelectorAll(".nav-admin-list .nav-admin-row").length,
    filter: document.querySelectorAll(".nav-admin-filter").length,
  }));
  if (
    platformNavigation.tabs !== 3
    || platformNavigation.stats !== 4
    || platformNavigation.rows < 1
    || platformNavigation.filter !== 1
  ) {
    fail(`admin navigation: platform workspace contract failed ${JSON.stringify(platformNavigation)}`);
  }
  await assertHealthyDocument("admin navigation desktop");

  response = await page.goto(baseUrl + "/admin/modules?state=all", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin modules: route did not return HTTP 200");
  await page.getByRole("heading", { name: "First-party Modüller", exact: true }).waitFor();
  const platformModules = await page.evaluate(() => ({
    tabs: document.querySelectorAll(".platform-tabs .platform-tab").length,
    stats: document.querySelectorAll(".platform-overview .platform-stat").length,
    modules: document.querySelectorAll(".mod-sidebar .mod-list-item").length,
    shell: document.querySelectorAll(".mod-shell").length,
  }));
  if (
    platformModules.tabs !== 3
    || platformModules.stats !== 4
    || platformModules.modules < 1
    || platformModules.shell !== 1
  ) {
    fail(`admin modules: platform workspace contract failed ${JSON.stringify(platformModules)}`);
  }
  await assertHealthyDocument("admin modules desktop");

  response = await page.goto(baseUrl + "/admin/integrations", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("admin integrations: route did not return HTTP 200");
  await page.getByRole("heading", { name: "Sistem ve Entegrasyonlar", exact: true }).waitFor();
  const platformIntegrations = await page.evaluate(() => ({
    tabs: document.querySelectorAll(".platform-tabs .platform-tab").length,
    stats: document.querySelectorAll(".platform-overview .platform-stat").length,
    cards: document.querySelectorAll(".int-grid .int-card").length,
    capabilities: document.querySelectorAll(".int-capability").length,
  }));
  if (
    platformIntegrations.tabs !== 3
    || platformIntegrations.stats !== 4
    || platformIntegrations.cards < 1
    || platformIntegrations.capabilities < 1
  ) {
    fail(`admin integrations: platform workspace contract failed ${JSON.stringify(platformIntegrations)}`);
  }
  await assertHealthyDocument("admin integrations desktop");

  await page.setViewportSize({ width: 390, height: 844 });
  for (const [label, path, extraSelector] of [
    ["navigation", "/admin/navigation", ".nav-admin"],
    ["modules", "/admin/modules?state=all", ".module-manager"],
    ["integrations", "/admin/integrations", ".integration-admin"],
  ]) {
    response = await page.goto(baseUrl + path, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`admin ${label} mobile: route did not return HTTP 200`);
    const mobile = await page.evaluate((selector) => {
      const overview = document.querySelector(".platform-overview");
      const workspace = document.querySelector(selector);
      return {
        overviewColumns: overview instanceof HTMLElement
          ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length
          : 0,
        workspaceWidth: workspace instanceof HTMLElement ? Math.round(workspace.getBoundingClientRect().width) : 0,
        viewportWidth: window.innerWidth,
      };
    }, extraSelector);
    if (mobile.overviewColumns !== 1 || mobile.workspaceWidth > mobile.viewportWidth) {
      fail(`admin ${label} mobile: responsive contract failed ${JSON.stringify(mobile)}`);
    }
    await assertHealthyDocument(`admin ${label} mobile`);
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  const analyticsRoutes = [
    ["overview", "/admin/analytics", "Forum Analiz Dashboardu", true],
    ["content", "/admin/analytics/content", "İçerik ve Engagement Analizleri", true],
    ["operations", "/admin/analytics/operations", "Moderasyon, Destek ve Hata Analizleri", true],
    ["commerce", "/admin/analytics/commerce", "Marketplace, Gelir, Referral ve Giveaway Analizleri", true],
    ["reports", "/admin/analytics/reports", "Analytics Report Builder", false],
  ];
  for (const [label, analyticsPath, heading, hasRange] of analyticsRoutes) {
    response = await page.goto(baseUrl + analyticsPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`admin analytics ${label}: route did not return HTTP 200`);
    await page.getByRole("heading", { name: heading, exact: true }).waitFor();
    const state = await page.evaluate((rangeExpected) => ({
      tabs: document.querySelectorAll(".analytics-tabs a").length,
      activeTabs: document.querySelectorAll('.analytics-tabs a[aria-current="page"]').length,
      ranges: document.querySelectorAll(".analytics-range a").length,
      stats: document.querySelectorAll(".stats-grid .stat").length,
      reportForms: document.querySelectorAll('form[action*="/admin/analytics/reports"]').length,
      rangeExpected,
    }), hasRange);
    if (
      state.tabs !== 6
      || state.activeTabs !== 1
      || (state.rangeExpected && state.ranges !== 3)
      || (!state.rangeExpected && state.ranges !== 0)
      || (label !== "reports" && state.stats < 1)
      || (label === "reports" && state.reportForms < 1)
    ) {
      fail(`admin analytics: observability workspace contract failed ${label} ${JSON.stringify(state)}`);
    }
    await assertHealthyDocument(`admin analytics ${label} desktop`);
  }

  await page.setViewportSize({ width: 390, height: 844 });
  for (const [label, analyticsPath] of analyticsRoutes) {
    response = await page.goto(baseUrl + analyticsPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`admin analytics ${label} mobile: route did not return HTTP 200`);
    const mobile = await page.evaluate(() => {
      const tabs = document.querySelector(".analytics-tabs");
      const head = document.querySelector(".analytics-head");
      return {
        tabColumns: tabs instanceof HTMLElement
          ? getComputedStyle(tabs).gridTemplateColumns.split(" ").filter(Boolean).length
          : 0,
        headWidth: head instanceof HTMLElement ? Math.round(head.getBoundingClientRect().width) : 0,
        viewportWidth: window.innerWidth,
      };
    });
    if (mobile.tabColumns !== 1 || mobile.headWidth > mobile.viewportWidth) {
      fail(`admin analytics ${label} mobile: responsive contract failed ${JSON.stringify(mobile)}`);
    }
    await assertHealthyDocument(`admin analytics ${label} mobile`);
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  const systemOperationRoutes = [
    ["all", "/admin/system/operations", "Sistem Operasyon Merkezi"],
    ["health", "/admin/system/operations?section=health", "Health ve integrity"],
    ["maintenance", "/admin/system/operations?section=maintenance", "Maintenance"],
    ["updates", "/admin/system/operations?section=updates", "Forwext Updater"],
    ["jobs", "/admin/system/operations?section=jobs", "Queue, failed jobs ve cron"],
    ["backups", "/admin/system/operations?section=backups", "Backups"],
    ["logs", "/admin/system/operations?section=logs&logs=50", "Structured logs"],
    ["repairs", "/admin/system/operations?section=repairs", "Maintenance ve repair tools"],
  ];
  for (const [label, operationPath, heading] of systemOperationRoutes) {
    response = await page.goto(baseUrl + operationPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`admin system operations ${label}: route did not return HTTP 200`);
    await page.getByRole("heading", { name: heading, exact: true }).waitFor();
    if (label === "all") {
      const state = await page.evaluate(() => ({
        tabs: document.querySelectorAll(".ops-section-tabs a").length,
        activeTabs: document.querySelectorAll('.ops-section-tabs a[aria-current="page"]').length,
        stats: document.querySelectorAll(".ops-overview .ops-overview-stat").length,
        panels: document.querySelectorAll(".ops-panel").length,
      }));
      if (state.tabs !== 8 || state.activeTabs !== 1 || state.stats !== 4 || state.panels < 5) {
        fail(`admin system operations: dense workspace contract failed ${JSON.stringify(state)}`);
      }
    }
    await assertHealthyDocument(`admin system operations ${label} desktop`);
  }

  await page.setViewportSize({ width: 390, height: 844 });
  for (const [label, operationPath] of systemOperationRoutes) {
    response = await page.goto(baseUrl + operationPath, { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail(`admin system operations ${label} mobile: route did not return HTTP 200`);
    const mobile = await page.evaluate(() => {
      const overview = document.querySelector(".ops-overview");
      const workspace = document.querySelector(".ops--dense");
      return {
        overviewColumns: overview instanceof HTMLElement
          ? getComputedStyle(overview).gridTemplateColumns.split(" ").filter(Boolean).length
          : 0,
        workspaceWidth: workspace instanceof HTMLElement ? Math.round(workspace.getBoundingClientRect().width) : 0,
        viewportWidth: window.innerWidth,
      };
    });
    if (mobile.overviewColumns !== 1 || mobile.workspaceWidth > mobile.viewportWidth) {
      fail(`admin system operations ${label} mobile: responsive contract failed ${JSON.stringify(mobile)}`);
    }
    await assertHealthyDocument(`admin system operations ${label} mobile`);
  }

  const phase19Routes = [
    ["home", "/"],
    ["account-preferences", "/account/preferences"],
    ["account-security", "/account/security"],
    ["conversations", "/account/conversations"],
    ["notifications", "/account/notifications"],
    ["servers", "/servers"],
    ["server-management", "/servers/manage"],
    ["portfolio", "/portfolio?category=general&featured=1"],
    ["faq", "/faq?lang=tr"],
    ["bugs", "/bugs"],
    ["groups", "/groups"],
    ["members", "/members"],
    ["moderation", "/moderation"],
    ["moderation-approval", "/moderation/approval"],
    ["moderation-audit", "/moderation/audit"],
    ["moderation-oversight", "/moderation/oversight"],
    ["admin", "/admin"],
    ["admin-users", "/admin/users?q=phase12"],
    ["admin-access", "/admin/access"],
    ["admin-content", "/admin/content"],
    ["admin-themes", "/admin/appearance/themes?theme=forwext-balanced"],
    ["admin-layout", "/admin/appearance/layout"],
    ["admin-navigation", "/admin/navigation?state=all&placement=all"],
    ["admin-modules", "/admin/modules?state=all"],
    ["admin-integrations", "/admin/integrations"],
    ["admin-analytics", "/admin/analytics"],
    ["admin-analytics-operations", "/admin/analytics/operations"],
    ["admin-system-operations", "/admin/system/operations"],
  ];
  for (const viewport of [
    { width: 390, height: 844, label: "390" },
    { width: 768, height: 900, label: "768" },
    { width: 1024, height: 900, label: "1024" },
  ]) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const [label, routePath] of phase19Routes) {
      response = await page.goto(baseUrl + routePath, { waitUntil: "domcontentloaded" });
      if (!response || response.status() !== 200) {
        fail(`phase19 route corpus ${label}@${viewport.label}: HTTP ${response?.status() ?? "none"}`);
      }
      await assertHealthyDocument(`phase19 route corpus ${label}@${viewport.label}`);
    }
  }

  await page.setViewportSize({ width: 390, height: 844 });
  response = await page.goto(baseUrl + "/admin", { waitUntil: "domcontentloaded" });
  if (!response || response.status() !== 200) fail("phase19 focus audit: admin route did not return HTTP 200");
  await page.locator("body").click({ position: { x: 1, y: 1 } });
  await page.keyboard.press("Tab");
  const focusAudit = await page.evaluate(() => {
    const active = document.activeElement;
    if (!(active instanceof HTMLElement) || active === document.body) return { valid: false };
    const rect = active.getBoundingClientRect();
    const style = getComputedStyle(active);
    return {
      valid: rect.width > 0 && rect.height > 0,
      left: Math.round(rect.left),
      right: Math.round(rect.right),
      top: Math.round(rect.top),
      bottom: Math.round(rect.bottom),
      outline: style.outlineStyle,
    };
  });
  if (
    !focusAudit.valid
    || focusAudit.left < -1
    || focusAudit.right > 391
    || focusAudit.top < -1
    || focusAudit.bottom > 845
  ) {
    fail(`phase19 focus audit: first keyboard target is not viewport-contained ${JSON.stringify(focusAudit)}`);
  }

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

console.log("Forwext live-route browser acceptance passed for login, grouped account tools, message/notification routes, Minecraft server directory/voting/management, community groups create/membership flows, active account navigation, watched content, member content, thread discovery, members subnav and ACP GET/POST.");
