import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { runInNewContext } from "node:vm";

const script = readFileSync(new URL("../../public/assets/thread-post-share.js", import.meta.url), "utf8");

function browser({ raw = "/threads/aabb?page=2#post-ccdd", mode = "native" } = {}) {
  let handler;
  let copied = null;
  let nativeAttempts = 0;
  let fallbackAttempts = 0;
  let focusRestored = false;
  let textarea;
  const location = { origin: "https://forum.example", href: "https://forum.example/threads/aabb?page=2" };

  class Element {
    closest(selector) { return selector === "[data-thread-copy-url]" ? this : null; }
  }
  class HTMLElement extends Element {
    isConnected = true;
    focus() { focusRestored = this === button; }
  }
  class HTMLButtonElement extends HTMLElement {
    disabled = false;
    parentElement = { querySelector: () => feedback };
    getAttribute(name) { return name === "data-thread-copy-url" ? raw : null; }
  }
  const button = new HTMLButtonElement();
  const feedback = new HTMLElement();
  feedback.textContent = "";
  const document = {
    activeElement: button,
    body: { append(field) { textarea = field; } },
    addEventListener(name, listener) {
      assert.equal(name, "click");
      handler = listener;
    },
    createElement(name) {
      assert.equal(name, "textarea");
      return {
        value: "", readOnly: false, style: {},
        setAttribute() {},
        focus() {},
        select() {},
        remove() {},
      };
    },
    execCommand(name) {
      assert.equal(name, "copy");
      fallbackAttempts++;
      copied = textarea.value;
      return true;
    },
  };
  const navigator = mode === "unavailable" ? {} : {
    clipboard: {
      async writeText(value) {
        nativeAttempts++;
        if (mode === "denied") throw new Error("Clipboard denied");
        copied = value;
      },
    },
  };
  runInNewContext(script, {
    document, navigator, window: { location }, URL,
    Element, HTMLElement, HTMLButtonElement,
  }, { filename: "thread-post-share.js" });
  assert.equal(typeof handler, "function");
  return {
    button, feedback,
    click: async () => handler({ target: button }),
    result: () => ({ copied, nativeAttempts, fallbackAttempts, focusRestored }),
  };
}

test("copies the real later-page post permalink through Clipboard API", async () => {
  const page = browser();
  await page.click();
  assert.equal(page.result().copied, "https://forum.example/threads/aabb?page=2#post-ccdd");
  assert.equal(page.result().nativeAttempts, 1);
  assert.equal(page.result().fallbackAttempts, 0);
  assert.equal(page.feedback.textContent, "Bağlantı kopyalandı.");
  assert.equal(page.button.disabled, false);
});

test("restores keyboard focus when Clipboard API is denied", async () => {
  const page = browser({ mode: "denied" });
  await page.click();
  assert.equal(page.result().nativeAttempts, 1);
  assert.equal(page.result().fallbackAttempts, 1);
  assert.equal(page.result().focusRestored, true);
  assert.equal(page.feedback.textContent, "Bağlantı kopyalandı.");
});

test("supports browsers without the modern Clipboard API", async () => {
  const page = browser({ mode: "unavailable" });
  await page.click();
  assert.equal(page.result().nativeAttempts, 0);
  assert.equal(page.result().fallbackAttempts, 1);
  assert.equal(page.result().copied, "https://forum.example/threads/aabb?page=2#post-ccdd");
});

test("rejects off-site copy destinations without invoking clipboard", async () => {
  const page = browser({ raw: "https://untrusted.example/redirect" });
  await page.click();
  assert.equal(page.result().copied, null);
  assert.equal(page.result().nativeAttempts, 0);
  assert.equal(page.feedback.textContent, "Geçersiz bağlantı.");
});
