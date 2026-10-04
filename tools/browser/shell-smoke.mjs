import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import path from "node:path";

const baseUrl = process.env.FORWEXT_BROWSER_BASE_URL ?? "http://127.0.0.1:8123";
const artifactDir = path.resolve("build/browser-artifacts");
await mkdir(artifactDir, { recursive: true });

const cases = [
  { name: "guest-mobile", fixture: "guest", width: 390, height: 844, touch: true, reducedMotion: "reduce" },
  { name: "guest-tablet", fixture: "guest", width: 768, height: 1024, touch: true, reducedMotion: "no-preference" },
  { name: "member-mobile", fixture: "member", width: 390, height: 844, touch: true, reducedMotion: "no-preference" },
  { name: "member-1024", fixture: "member", width: 1024, height: 900, touch: false, reducedMotion: "no-preference" },
  { name: "member-desktop", fixture: "member", width: 1440, height: 1000, touch: false, reducedMotion: "no-preference" },
  { name: "moderator-desktop", fixture: "moderator", width: 1440, height: 1000, touch: false, reducedMotion: "no-preference" },
  { name: "admin-mobile", fixture: "admin", width: 390, height: 844, touch: true, reducedMotion: "no-preference", admin: true },
  { name: "admin-desktop", fixture: "admin", width: 1440, height: 1000, touch: false, reducedMotion: "no-preference", admin: true },
];

const fail = (message) => {
  throw new Error(message);
};

const durationToMs = (value) => {
  const trimmed = value.trim();
  if (trimmed.endsWith("ms")) return Number.parseFloat(trimmed);
  if (trimmed.endsWith("s")) return Number.parseFloat(trimmed) * 1000;
  return Number.NaN;
};

const browser = await chromium.launch({ headless: true });

try {
  for (const testCase of cases) {
    const context = await browser.newContext({
      viewport: { width: testCase.width, height: testCase.height },
      hasTouch: testCase.touch,
      isMobile: testCase.touch,
      reducedMotion: testCase.reducedMotion,
    });
    const page = await context.newPage();
    const consoleErrors = [];
    const failedRequests = [];
    page.on("console", (message) => {
      if (message.type() === "error") consoleErrors.push(message.text());
    });
    page.on("pageerror", (error) => consoleErrors.push(error.message));
    page.on("requestfailed", (request) => {
      failedRequests.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText ?? "unknown"}`);
    });

    const response = await page.goto(
      `${baseUrl}/build/browser-fixtures/${testCase.fixture}.html`,
      { waitUntil: "networkidle" },
    );
    if (!response || !response.ok()) {
      fail(`${testCase.name}: fixture failed to load`);
    }

    const requiredStylesheets = [
      "/public/assets/site-base.css",
      "/public/assets/site-components.css",
      "/public/assets/site-pages.css",
    ];
    if (testCase.admin) requiredStylesheets.push("/public/assets/admin.css");
    const loadedStylesheets = await page.evaluate(() =>
      [...document.styleSheets]
        .map((sheet) => sheet.href)
        .filter((href) => typeof href === "string"),
    );
    for (const stylesheet of requiredStylesheets) {
      if (!loadedStylesheets.some((href) => href.includes(stylesheet))) {
        fail(`${testCase.name}: ${stylesheet} did not load`);
      }
    }

    const scriptLoaded = await page.evaluate(() =>
      [...document.scripts].some((script) => script.src.includes("/public/assets/mobile-nav.js")),
    );
    if (!scriptLoaded) fail(`${testCase.name}: mobile-nav.js did not load`);

    const overflow = await page.evaluate(() => {
      const limit = window.innerWidth + 1;
      return [...document.body.querySelectorAll("*")]
        .filter((element) => {
          if (!(element instanceof HTMLElement)) return false;
          if (element.closest('[hidden],[aria-hidden="true"]')) return false;
          const style = getComputedStyle(element);
          if (style.display === "none" || style.visibility === "hidden") return false;
          const rect = element.getBoundingClientRect();
          if (rect.width <= 0 || rect.height <= 0) return false;
          return rect.left < -1 || rect.right > limit;
        })
        .slice(0, 8)
        .map((element) => {
          const rect = element.getBoundingClientRect();
          return {
            tag: element.tagName.toLowerCase(),
            className: element.className,
            left: Math.round(rect.left),
            right: Math.round(rect.right),
            viewport: window.innerWidth,
          };
        });
    });
    if (overflow.length > 0) {
      fail(`${testCase.name}: horizontal overflow: ${JSON.stringify(overflow)}`);
    }

    if (testCase.admin) {
      const adminSurfaceVisible = await page.locator('[data-browser-fixture="admin"]').isVisible();
      if (!adminSurfaceVisible) fail(`${testCase.name}: ACP fixture is not visible`);
      const adminBreadcrumbCount = await page.locator('nav.acp-breadcrumbs[aria-label="Breadcrumb"]').count();
      const publicBreadcrumbCount = await page.locator('nav.breadcrumbs[aria-label="Breadcrumb"]').count();
      if (adminBreadcrumbCount !== 1 || publicBreadcrumbCount !== 0) {
        fail(`${testCase.name}: ACP breadcrumb contract failed (admin=${adminBreadcrumbCount}, public=${publicBreadcrumbCount})`);
      }

      const layoutColumns = await page.evaluate(() => {
        const columns = (selector) => {
          const element = document.querySelector(selector);
          if (!(element instanceof HTMLElement)) return 0;
          return getComputedStyle(element).gridTemplateColumns.split(" ").filter(Boolean).length;
        };
        return {
          module: columns("[data-browser-acp-module] .mod-shell"),
          builder: columns("[data-browser-acp-builder] .builder-grid"),
          theme: columns("[data-browser-acp-theme]"),
          legacyForm: columns("[data-browser-acp-legacy] .search-form"),
        };
      });
      const expectedColumns = testCase.width <= 760 ? 1 : 2;
      for (const [surface, columns] of Object.entries(layoutColumns)) {
        if (columns !== expectedColumns) {
          fail(`${testCase.name}: ${surface} ACP layout expected ${expectedColumns} column(s), got ${columns}`);
        }
      }

      if (testCase.touch) {
        for (const selector of [
          "[data-browser-acp-module] .mod-button",
          "[data-browser-acp-builder] .builder-toolbar button",
          "[data-browser-acp-theme] button",
          "[data-browser-acp-legacy] .search-actions button",
        ]) {
          const height = await page.$eval(selector, (element) => element.getBoundingClientRect().height);
          if (height < 43.5) fail(`${testCase.name}: ACP touch target below 44px for ${selector}`);
        }
      }
    }


    await page.keyboard.press("Tab");
    const skipLinkFocused = await page.evaluate(
      () => document.activeElement instanceof HTMLElement && document.activeElement.classList.contains("skip-link"),
    );
    if (!skipLinkFocused) fail(`${testCase.name}: skip link is not first in keyboard order`);

    await page.waitForFunction(() => {
      const element = document.querySelector(".skip-link");
      if (!(element instanceof HTMLElement)) return false;
      const style = getComputedStyle(element);
      return Number.parseFloat(style.opacity) > 0.9 && element.getBoundingClientRect().top >= 0;
    });
    const skipLinkVisible = await page.$eval(".skip-link", (element) => {
      const style = getComputedStyle(element);
      return Number.parseFloat(style.opacity) > 0.9 && element.getBoundingClientRect().top >= 0;
    });
    if (!skipLinkVisible) fail(`${testCase.name}: focused skip link is not visible`);

    const mobile = testCase.width < 921;
    if (mobile) {
      const toggle = page.locator("[data-forwext-nav-toggle]");
      if (!(await toggle.isVisible())) fail(`${testCase.name}: mobile nav toggle is not visible`);

      const initiallyInert = await page.$eval(
        "[data-forwext-primary-navigation]",
        (element) => element.hasAttribute("inert") && element.getAttribute("aria-hidden") === "true",
      );
      if (!initiallyInert) fail(`${testCase.name}: closed mobile navigation remains keyboard reachable`);

      await toggle.click();
      if ((await toggle.getAttribute("aria-expanded")) !== "true") {
        fail(`${testCase.name}: mobile nav did not expose expanded state`);
      }

      await page.waitForFunction(() => {
        const navigation = document.querySelector("[data-forwext-primary-navigation]");
        return navigation instanceof HTMLElement && navigation.contains(document.activeElement);
      });
      const activeInsideNav = await page.evaluate(() => {
        const navigation = document.querySelector("[data-forwext-primary-navigation]");
        return navigation instanceof HTMLElement && navigation.contains(document.activeElement);
      });
      if (!activeInsideNav) fail(`${testCase.name}: opening mobile nav did not move focus into navigation`);

      const navTouchHeight = await toggle.evaluate((element) => element.getBoundingClientRect().height);
      if (navTouchHeight < 43.5) fail(`${testCase.name}: mobile toggle touch target is below 44px`);

      const inputTouchHeight = await page.$eval("#fixture-form input", (element) => element.getBoundingClientRect().height);
      if (inputTouchHeight < 43.5) fail(`${testCase.name}: form control touch target is below 44px`);

      const accountSummary = page.locator(".nav-account-menu > summary");
      if ((await accountSummary.count()) > 0) {
        await accountSummary.click();
        const groups = await page.locator(".nav-account-group").count();
        if (groups !== 4) fail(`${testCase.name}: grouped account menu expected 4 groups, got ${groups}`);

        const labels = await page.locator(".nav-account-group-title").allTextContents();
        for (const expected of ["Hesap", "İletişim", "Topluluk", "Diğer"]) {
          if (!labels.includes(expected)) fail(`${testCase.name}: account group "${expected}" is missing`);
        }

        const accountLinkHeight = await page.locator(".nav-account-link").first().evaluate(
          (element) => element.getBoundingClientRect().height,
        );
        if (accountLinkHeight < 43.5) {
          fail(`${testCase.name}: mobile account link touch target is below 44px`);
        }
      }

      await page.keyboard.press("Escape");
      if ((await toggle.getAttribute("aria-expanded")) !== "false") {
        fail(`${testCase.name}: Escape did not close mobile navigation`);
      }
      const focusReturned = await page.evaluate(
        () => document.activeElement instanceof HTMLElement && document.activeElement.matches("[data-forwext-nav-toggle]"),
      );
      if (!focusReturned) fail(`${testCase.name}: Escape did not return focus to mobile nav toggle`);
    } else {
      const navState = await page.$eval("[data-forwext-primary-navigation]", (element) => ({
        inert: element.hasAttribute("inert"),
        ariaHidden: element.getAttribute("aria-hidden"),
      }));
      if (navState.inert || navState.ariaHidden === "true") {
        fail(`${testCase.name}: desktop navigation is incorrectly inert/hidden`);
      }

      const menuSummary = page.locator(".nav-primary-menu > summary");
      await menuSummary.focus();
      await page.keyboard.press("Enter");
      const opened = await page.$eval(".nav-primary-menu", (element) => element.hasAttribute("open"));
      if (!opened) fail(`${testCase.name}: details navigation did not open from keyboard`);
      await page.keyboard.press("Escape");
      const closed = await page.$eval(".nav-primary-menu", (element) => !element.hasAttribute("open"));
      if (!closed) fail(`${testCase.name}: Escape did not close navigation details`);
    }

    const postSummary = page.locator("[data-browser-post-menu] > summary");
    if ((await postSummary.count()) > 0) {
      await postSummary.focus();
      await page.keyboard.press("Enter");
      const postMenuOpened = await page.$eval("[data-browser-post-menu]", (element) => element.hasAttribute("open"));
      if (!postMenuOpened) fail(`${testCase.name}: post action details is not keyboard operable`);
      await page.keyboard.press("Enter");
    }

    if (testCase.reducedMotion === "reduce") {
      const reducedMotion = await page.evaluate(() => matchMedia("(prefers-reduced-motion: reduce)").matches);
      if (!reducedMotion) fail(`${testCase.name}: reduced-motion emulation did not activate`);
      const durations = await page.$eval(".fx-btn", (element) =>
        getComputedStyle(element).transitionDuration.split(","),
      );
      const maxDuration = Math.max(...durations.map(durationToMs).filter(Number.isFinite));
      if (!(maxDuration <= 1)) fail(`${testCase.name}: reduced-motion transition remains too long: ${maxDuration}ms`);
    }

    if (failedRequests.length > 0) {
      fail(`${testCase.name}: failed browser requests: ${failedRequests.join(" | ")}`);
    }
    if (consoleErrors.length > 0) {
      fail(`${testCase.name}: browser console errors: ${consoleErrors.join(" | ")}`);
    }

    await page.screenshot({
      path: path.join(artifactDir, `${testCase.name}.png`),
      fullPage: true,
    });
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`Forwext browser smoke passed for ${cases.length} representative layouts including member mobile account navigation.`);
