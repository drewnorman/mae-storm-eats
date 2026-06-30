import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

const manifest = JSON.parse(readFileSync("extension/manifest.json", "utf8"));
assert.equal(manifest.manifest_version, 3);
assert.ok(manifest.browser_specific_settings.gecko.id);
assert.deepEqual(manifest.host_permissions, [
  "https://www.pinterest.com/*",
  "https://*.pinimg.com/*",
]);
assert.deepEqual(manifest.optional_host_permissions, [
  "http://*/*",
  "https://*/*",
]);
assert.equal(manifest.content_scripts[0].run_at, "document_start");
assert.ok(manifest.permissions.includes("tabHide"));
assert.ok(!JSON.stringify(manifest).includes("<all_urls>"));

const icon = readFileSync("extension/icons/mae-storm-eats.svg", "utf8");
assert.match(icon, /viewBox="0 0 128 128"/);
assert.ok(icon.length > 1000);

const popup = readFileSync("extension/popup.html", "utf8");
for (const id of [
  "open-setup",
  "verified-summary",
  "edit-connection",
  "capture",
  "recipes",
  "reviews",
  "retry-recipes",
  "refresh-recipes",
  "retry-reviews",
  "refresh-reviews",
])
  assert.match(popup, new RegExp(`id="${id}"`));
assert.match(popup, /Import next 10/);
assert.match(popup, /Import reviews &amp; photos/);
assert.doesNotMatch(popup, /id="host"|id="username"|id="password"/);

const popupScript = readFileSync("extension/popup.js", "utf8");
assert.match(popupScript, /browser\.runtime\.openOptionsPage/);
assert.match(popupScript, /Capturing board.*pin/);
assert.match(popupScript, /type: "startStage"/);
assert.match(popupScript, /stageText/);
assert.match(popupScript, /progress\.removeAttribute\("value"\)/);
assert.doesNotMatch(
  popupScript,
  /browser\.permissions\.request|applicationPassword/,
);

const connectionPage = readFileSync("extension/connection.html", "utf8");
assert.match(connectionPage, /id="host"[\s\S]*required/);
assert.match(connectionPage, /id="username"[\s\S]*required/);
assert.match(connectionPage, /id="password"[\s\S]*required/);
const connectionScript = readFileSync("extension/connection.js", "utf8");
assert.match(connectionScript, /browser\.permissions\.request/);
assert.match(connectionScript, /prepareVerification/);

const collector = readFileSync("extension/collector.js", "utf8");
assert.match(collector, /stable < 15/);
assert.match(collector, /resolveRecipe/);
assert.match(collector, /resolveReviews/);
assert.match(collector, /comments_complete/);
assert.match(collector, /ownerMatches/);

const background = readFileSync("extension/background.js", "utf8");
assert.match(background, /CHECKPOINT_VERSION = 4/);
assert.match(background, /BATCH_SIZE = 10/);
assert.match(background, /PIN_DELAY_MS = 30000/);
assert.match(background, /\/pinterest-imports\/pins/);
assert.match(background, /\/pinterest-imports\/queue\?stage=/);
assert.match(background, /resolveStagePin/);
assert.match(background, /browser\.tabs\.hide/);
assert.match(background, /browser\.alarms\.onAlarm/);
assert.match(background, /status === 429/);
assert.match(background, /owner_mismatch/);
assert.doesNotMatch(background, /https:\/\/recipes\.maestorm\.com/);
assert.doesNotMatch(background, /cookie|access.?token|oauth/i);

function backgroundHarness({ tabs = {}, fetchImpl } = {}) {
  const state = {
    settings: {
      wordpressHost: "http://wordpress.localhost",
      username: "importer",
      applicationPassword: "test-password",
    },
    run: { version: 4, windowId: 4 },
  };
  const context = {
    URL,
    Date,
    Error,
    FormData,
    AbortController,
    btoa: (value) => Buffer.from(value).toString("base64"),
    fetch:
      fetchImpl ||
      (async (url) => ({
        ok: true,
        json: async () =>
          url.includes("/recipe")
            ? { status: "complete" }
            : { authenticated: true, stages: {} },
      })),
    setTimeout,
    clearTimeout,
    browser: {
      storage: {
        local: {
          get: async (key) =>
            typeof key === "string" ? { [key]: state[key] } : { ...state },
          set: async (patch) => Object.assign(state, patch),
        },
        onChanged: { addListener: () => {} },
      },
      alarms: {
        create: async () => {},
        clear: async () => {},
        onAlarm: { addListener: () => {} },
      },
      runtime: {
        sendMessage: async () => {},
        onMessage: { addListener: () => {} },
        onStartup: { addListener: () => {} },
      },
      permissions: {
        contains: async () => true,
        onAdded: { addListener: () => {} },
        onRemoved: { addListener: () => {} },
      },
      tabs: {
        create: async (options) => ({
          id: 10,
          windowId: options.windowId,
          status: "complete",
          url: options.url,
        }),
        get: async (id) => ({
          id,
          windowId: 4,
          status: "complete",
          url: "https://www.pinterest.com/pin/789/",
        }),
        sendMessage: async (_id, message) =>
          message.type === "ping"
            ? { ready: true }
            : {
                pin_url: "https://www.pinterest.com/pin/789/",
                source_url: "https://example.com/recipe",
              },
        remove: async () => {},
        hide: async () => {},
        onUpdated: { addListener: () => {}, removeListener: () => {} },
        ...tabs,
      },
    },
  };
  vm.runInNewContext(
    `${background}\nglobalThis.hooks = { waitForTabReady, waitForCollector, resolveStagePin, migrateCheckpoint };`,
    context,
  );
  return { state, hooks: context.hooks };
}

let createdOptions;
let hidden;
const directHarness = backgroundHarness({
  tabs: {
    create: async (options) => {
      createdOptions = options;
      return { id: 10, windowId: 4, status: "complete", url: options.url };
    },
    hide: async (id) => {
      hidden = id;
    },
  },
});
const directResult = await directHarness.hooks.resolveStagePin(
  { pin_id: "789", pin_url: "https://www.pinterest.com/pin/789/" },
  { stage: "recipe", windowId: 4 },
);
assert.equal(directResult.status, "complete");
assert.equal(createdOptions.active, false);
assert.equal(createdOptions.windowId, 4);
assert.equal(hidden, 10);

let migratedCapture = false;
const migrationHarness = backgroundHarness({
  fetchImpl: async (url) => {
    if (url.includes("/pins")) migratedCapture = true;
    return { ok: true, json: async () => ({}) };
  },
});
migrationHarness.state.run = {
  version: 3,
  pins: [
    {
      pin_id: "9",
      pin_url: "https://www.pinterest.com/pin/9/",
      board_url: "https://www.pinterest.com/mae/recipes/",
    },
  ],
};
const migrated = await migrationHarness.hooks.migrateCheckpoint();
assert.equal(migrated.version, 4);
assert.equal(migrated.status, "ready");
assert.equal(migratedCapture, true);

const collectorListeners = [];
let scrolls = 0;
const collectorContext = {
  URL,
  location: { href: "https://www.pinterest.com/mae/recipes/" },
  setTimeout: (callback) => {
    callback();
    return 1;
  },
  document: {
    documentElement: { scrollHeight: 1000 },
    querySelector: () => null,
    querySelectorAll: (selector) =>
      selector === 'a[href*="/pin/"]'
        ? [
            { href: "https://www.pinterest.com/pin/123/" },
            { href: "https://www.pinterest.com/pin/456/" },
          ]
        : [],
  },
  window: {
    scrollTo: () => {
      scrolls += 1;
    },
  },
  browser: {
    storage: { local: { get: async () => ({ run: {} }) } },
    runtime: {
      onMessage: {
        addListener: (listener) => collectorListeners.push(listener),
      },
      sendMessage: async () => {},
    },
  },
};
vm.runInNewContext(collector, collectorContext);
const discovery = await collectorListeners[0]({ type: "discover" });
assert.deepEqual(
  Array.from(discovery.pins, (pin) => pin.pin_id),
  ["123", "456"],
);
assert.ok(scrolls >= 15);

const popupElements = new Map();
const popupElement = (selector) => {
  if (!popupElements.has(selector))
    popupElements.set(selector, {
      hidden: false,
      disabled: false,
      textContent: "",
      max: 0,
      value: 0,
      addEventListener: () => {},
      removeAttribute(name) {
        if (name === "value") this.value = undefined;
      },
    });
  return popupElements.get(selector);
};
const popupContext = {
  Object,
  document: { querySelector: popupElement },
  browser: {
    storage: {
      local: { get: async () => ({ connection: {}, run: {} }) },
      onChanged: { addListener: () => {} },
    },
    runtime: {
      openOptionsPage: async () => {},
      sendMessage: async () => {},
      onMessage: { addListener: () => {} },
    },
    tabs: { query: async () => [] },
  },
};
vm.runInNewContext(
  `${popupScript}\nglobalThis.testRenderRun = renderRun;`,
  popupContext,
);
popupContext.testRenderRun({
  status: "capturing",
  discovered: 7,
  inventory: {},
});
assert.equal(
  popupElement("#message").textContent,
  "Capturing board — 7 pins found…",
);
assert.equal(popupElement("#progress").value, undefined);

console.log("Firefox extension tests passed.");
