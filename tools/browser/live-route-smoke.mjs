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
    failedRequests.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText ?? "unknown"}`);
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

  let response = await page.goto(baseUrl + "/", { waitUntil: "networkidle" });
  if (!response || response.status() !== 200) fail("home: real route did not return HTTP 200");
  await page.getByRole("heading", { name: "Forumlar", exact: true }).waitFor();
  await assertHealthyDocument("home");

  response = await page.goto(baseUrl + "/login", { waitUntil: "networkidle" });
  if (!response || response.status() !== 200) fail("login: real route did not return HTTP 200");
  await page.locator('input[name="identifier"]').fill("ci-admin");
  await page.locator('input[name="password"]').fill("Forwext-CI-Admin-Password-2026");
  await Promise.all([
    page.waitForURL((url) => url.pathname === "/" || url.pathname.startsWith("/mfa/")),
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
  const popoverCovered = await page.evaluate(() => {
    const popover = document.querySelector(".nav-account-popover");
    if (!(popover instanceof HTMLElement)) return true;
    const links = [...popover.querySelectorAll("a")].filter((link) => link instanceof HTMLElement);
    return links.some((link) => {
      const rect = link.getBoundingClientRect();
      const top = document.elementFromPoint(rect.left + Math.min(18, rect.width / 2), rect.top + rect.height / 2);
      return top !== null && !popover.contains(top);
    });
  });
  if (popoverCovered) fail("header: account popover is covered by page content or another stacking context");
  await page.keyboard.press("Escape");

  response = await page.goto(baseUrl + "/members", { waitUntil: "networkidle" });
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

  response = await page.goto(baseUrl + "/admin", { waitUntil: "networkidle" });
  if (!response || response.status() !== 200) fail(`admin: real route returned HTTP ${response?.status() ?? "no response"}`);
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();
  await assertHealthyDocument("admin GET");

  const favorite = page.getByRole("button", { name: "Favoriye ekle" }).first();
  if (!(await favorite.count())) fail("admin: no CSRF-protected favorite action was rendered");
  await Promise.all([
    page.waitForLoadState("networkidle"),
    favorite.click(),
  ]);
  await page.getByRole("heading", { name: "Administration", exact: true }).waitFor();
  await assertHealthyDocument("admin POST");

  await page.screenshot({
    path: path.join(artifactDir, "admin-live-desktop.png"),
    fullPage: true,
  });

  await page.goto(baseUrl + "/", { waitUntil: "networkidle" });
  await page.evaluate(() => {
    document.documentElement.style.zoom = "1.25";
  });
  await assertHealthyDocument("authenticated home 125% zoom");

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

console.log("Forwext live-route browser acceptance passed for login, header, members subnav and ACP GET/POST.");
