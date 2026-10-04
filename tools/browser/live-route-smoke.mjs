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
    || (serverDirectoryState.listRows === 0 && serverDirectoryState.emptyStates !== 1)
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
    || (serverManagementState.rows === 0 && serverManagementState.emptyStates !== 1)
    || serverManagementState.navLinks < 1
  ) {
    fail(`minecraft server management: route contract failed ${JSON.stringify(serverManagementState)}`);
  }
  await assertHealthyDocument("minecraft server management");

  if (serverManagementState.rows > 0) {
    const manageHref = await page.locator('.minecraft-manage-row a[href$="/manage"]').first().getAttribute("href");
    if (!manageHref) fail("minecraft server integration: manageable row has no management href");
    response = await page.goto(new URL(manageHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server integration: management detail did not return HTTP 200");

    const manageUrl = new URL(manageHref, baseUrl);
    const manageBase = manageUrl.pathname.replace(/\/manage$/, "");
    for (const [suffix, label] of [["/edit", "edit"], ["/transfer", "transfer"]]) {
      response = await page.goto(new URL(manageBase + suffix, baseUrl).toString(), { waitUntil: "domcontentloaded" });
      if (!response || response.status() !== 200) {
        fail(`minecraft server ${label}: canonical reference route did not return HTTP 200`);
      }
      await assertHealthyDocument(`minecraft server ${label}`);
    }
    response = await page.goto(new URL(manageHref, baseUrl).toString(), { waitUntil: "domcontentloaded" });
    if (!response || response.status() !== 200) fail("minecraft server integration: management detail reload failed");

    const settingsLink = page.locator('a[href$="/vote-settings"]').first();
    if ((await settingsLink.count()) === 1) {
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
  }

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

console.log("Forwext live-route browser acceptance passed for login, grouped account tools, message/notification routes, Minecraft server directory/voting/management, active account navigation, watched content, member content, thread discovery, members subnav and ACP GET/POST.");
