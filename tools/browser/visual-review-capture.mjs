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
const stateRecords = [];
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
        if (route.startsWith("/admin")) {
          await page.locator('[data-acp-navigation-list][data-acp-navigation-loaded="1"]').waitFor({ timeout: 5000 });
        }
        if (route.startsWith("/admin")) {
          await page.evaluate(async () => {
            window.scrollTo({top:0,left:0,behavior:"instant"});
            await document.fonts.ready;
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
          });
          await page.waitForTimeout(450);
          const visibility = await page.evaluate(() => {
            const main = document.querySelector("#main-content");
            const content = main?.firstElementChild;
            const h1 = main?.querySelector("h1");
            const rect = (element) => {
              if (!element) return null;
              const r = element.getBoundingClientRect();
              const style = getComputedStyle(element);
              return {top:Math.round(r.top),left:Math.round(r.left),
                width:Math.round(r.width),height:Math.round(r.height),
                display:style.display,opacity:style.opacity,visibility:style.visibility};
            };
            return {scrollY:window.scrollY,viewportHeight:window.innerHeight,
              main:rect(main),content:rect(content),heading:rect(h1),
              headingText:h1?.textContent?.trim().slice(0,100) ?? null};
          });
          record.contentVisibility = visibility;
          console.log("ACP VISUAL ROUTE", name, viewport.label, JSON.stringify(visibility));

          if (visibility.heading && visibility.heading.width > 0 && visibility.heading.height > 0) {
            const paint = await page.screenshot({ fullPage:false, type:"png", animations:"disabled" });
            const pixels = await page.evaluate(async (dataUrl) => {
              const heading = document.querySelector("#main-content h1");
              if (!heading) return { bright:0,total:0,topmost:[] };
              const r=heading.getBoundingClientRect();
              const x=Math.max(0,Math.floor(r.x)),y=Math.max(0,Math.floor(r.y));
              const image=new Image();
              image.src=dataUrl;
              await image.decode();
              const canvas=document.createElement("canvas");
              canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
              const ctx=canvas.getContext("2d",{willReadFrequently:true});
              if (!ctx) throw new Error("Canvas context unavailable");
              ctx.drawImage(image,0,0);
              let bright=0,total=0;
              for (let yy=y;yy<Math.min(canvas.height,y+r.height);yy+=2) {
                for (let xx=x;xx<Math.min(canvas.width,x+r.width);xx+=2) {
                  const rgba=ctx.getImageData(xx,yy,1,1).data;
                  total++;
                  if (Math.max(rgba[0],rgba[1],rgba[2])>110) bright++;
                }
              }
              return {bright,total,topmost:document.elementsFromPoint(
                Math.min(innerWidth-1,x+8),Math.min(innerHeight-1,y+8)
              ).slice(0,5).map(el=>el.tagName+"."+String(el.className).slice(0,45))};
            }, "data:image/png;base64," + paint.toString("base64"));
            record.paint = pixels;
            console.log("ACP PAINT PROOF", name, viewport.label, JSON.stringify(pixels));
            if (pixels.bright < 5 && name === "admin-home" && viewport.label === "desktop") {
              const ancestors = await page.evaluate(() => {
                let node = document.querySelector("#main-content h1");
                const result=[];
                while (node) {
                  const cs=getComputedStyle(node);
                  result.push({tag:node.tagName,classes:node.className,
                    color:cs.color,background:cs.backgroundColor,display:cs.display,
                    opacity:cs.opacity,visibility:cs.visibility,filter:cs.filter,
                    transform:cs.transform,maskImage:cs.maskImage,
                    clipPath:cs.clipPath,contentVisibility:cs.contentVisibility,
                    mixBlendMode:cs.mixBlendMode,fontSize:cs.fontSize,
                    contain:cs.contain});
                  node=node.parentElement;
                }
                return result;
              });
              console.log("ACP PAINT ANCESTOR DEBUG",JSON.stringify(ancestors));
              const forced = await page.addStyleTag({ content:
                'body[data-forwext-surface="admin"] #main-content {background:rgb(250,20,20)!important;color:white!important}' +
                'body[data-forwext-surface="admin"] #main-content h1 {background:rgb(250,250,250)!important;color:black!important}'
              });
              const image = await page.screenshot({fullPage:false,type:"png",animations:"disabled"});
              const sample=await page.evaluate(async (url) => {
                const el=document.querySelector("#main-content h1"),r=el.getBoundingClientRect();
                const img=new Image();img.src=url;await img.decode();
                const can=document.createElement("canvas");can.width=img.width;can.height=img.height;
                const ctx=can.getContext("2d",{willReadFrequently:true});ctx.drawImage(img,0,0);
                return [ctx.getImageData(Math.floor(r.x+8),Math.floor(r.y+8),1,1).data.toString(),
                  ctx.getImageData(Math.floor(r.x+8),Math.floor(r.y+r.height+15),1,1).data.toString()];
              },"data:image/png;base64,"+image.toString("base64"));
              console.log("ACP FORCE PAINT TEST",JSON.stringify(sample));
              await forced.evaluate(el=>el.remove());
            }
            if (pixels.bright < 5) {
              throw new Error("ACP heading is present in DOM but not painted: " + JSON.stringify(pixels));
            }
          }

          if (!visibility.content || visibility.content.height === 0
              || visibility.content.display === "none" || visibility.content.visibility !== "visible") {
            throw new Error("ACP route has no visible main content: " + JSON.stringify(visibility));
          }
          if (visibility.content.top >= visibility.viewportHeight) {
            throw new Error("ACP content starts below viewport: " + JSON.stringify(visibility));
          }
        }
        if (name === "admin-home" && viewport.label === "desktop") {
          const diagnostics = await page.evaluate(() => {
            const targets = [".acp-dashboard", ".acp-ux-guide", ".acp-hero", ".acp-overview",
              ".acp-insights", ".acp-launchpad", ".acp-environment", ".acp-dashboard-workspace"];
            return targets.map(selector => {
              const el = document.querySelector(selector);
              if (!(el instanceof HTMLElement)) return { selector, missing: true };
              const rect = el.getBoundingClientRect();
              const cs = getComputedStyle(el);
              return { selector, top: Math.round(rect.top), height: Math.round(rect.height),
                left: Math.round(rect.left), width: Math.round(rect.width), display: cs.display,
                visibility: cs.visibility, opacity: cs.opacity, position: cs.position };
            });
          });
          console.log("ACP VISUAL GEOMETRY:", JSON.stringify(diagnostics));
          const visibilityDebug = await page.evaluate(() => {
            const selectors = [".acp-ux-guide", ".acp-hero", ".acp-hero h1",
              ".acp-overview", ".acp-overview-stat", ".acp-insights-heading",
              ".acp-launchpad", ".acp-launchpad-tile", ".acp-environment", ".acp-environment-status"];
            return selectors.map(selector => {
              const node = document.querySelector(selector);
              if (!(node instanceof HTMLElement)) return {selector, missing:true};
              const r = node.getBoundingClientRect(); const style=getComputedStyle(node);
              const px=Math.max(0,Math.min(innerWidth-1,r.left+Math.min(14,r.width/2)));
              const py=Math.max(0,Math.min(innerHeight-1,r.top+Math.min(14,r.height/2)));
              const blockers=document.elementsFromPoint(px,py).slice(0,6)
                .map(el=>({tag:el.tagName,className:typeof el.className==="string"?el.className.slice(0,80):"",id:el.id}));
              return {selector,x:px,y:py,display:style.display,color:style.color,
                background:style.backgroundColor,opacity:style.opacity,visibility:style.visibility,
                rect:{top:r.top,bottom:r.bottom,width:r.width,height:r.height},blockers,
                text:node.innerText.slice(0,65)};
            });
          });
          console.log("ACP VISIBILITY DIAGNOSTICS:",JSON.stringify(visibilityDebug));

          await page.evaluate(() => window.scrollTo({top:0,left:0,behavior:"instant"}));
          await page.screenshot({path: path.join(output, "admin-home-viewport-debug.jpg"),
            fullPage: false,type:"jpeg",quality:90});
        }
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
          path: path.join(output, record.screenshot),
          fullPage: true,
          type: "jpeg",
          quality: 84,
          animations: "disabled",
        });
        if (name === "forum-index") {
          const disclosure = page.locator("details.forum-category[data-forum-category-key]").first();
          if (await disclosure.count() === 0) {
            stateRecords.push({
              name: "forum-index-category-collapsed",
              viewport: viewport.label,
              captured: false,
              reason: "No published and visible category in this installed fixture",
              referenceIds: ["REF-0001", "REF-0066", "REF-0129", "REF-0535"],
            });
          } else {
            await disclosure.locator(":scope > summary").click();
            const hiddenRows = await disclosure.evaluate((element) =>
              !element.open && getComputedStyle(element.querySelector(".forum-node-list")).display === "none"
            );
            if (!hiddenRows) throw new Error("Category disclosure did not hide its rows");
            const file = `forum-index-category-collapsed-${viewport.label}.jpg`;
            await page.screenshot({
              path: path.join(output, file),
              fullPage: true,
              type: "jpeg",
              quality: 84,
              animations: "disabled",
            });
            stateRecords.push({
              name: "forum-index-category-collapsed",
              viewport: viewport.label,
              captured: true,
              screenshot: file,
              referenceIds: ["REF-0001", "REF-0066", "REF-0129", "REF-0535"],
              manuallySignedOff: false,
            });
            await disclosure.locator(":scope > summary").click();
          }
        }
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
    stateRecords,
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
