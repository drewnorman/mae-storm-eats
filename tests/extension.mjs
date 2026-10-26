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
assert.match(background, /CHECKPOINT_VERSION = 5/);
assert.match(background, /BATCH_SIZE = 10/);
assert.match(background, /PIN_DELAY_MIN_MS = 25000/);
assert.match(background, /PIN_DELAY_MAX_MS = 55000/);
assert.match(background, /DAILY_PIN_CAP = 50/);
assert.match(background, /MAX_PIN_RETRIES = 3/);
assert.match(background, /\/pinterest-imports\/pins/);
assert.match(background, /\/pinterest-imports\/queue\?stage=/);
assert.match(background, /resolveStagePin/);
assert.match(background, /browser\.tabs\.hide/);
assert.match(background, /browser\.alarms\.onAlarm/);
assert.match(background, /status === 429/);
assert.match(background, /owner_mismatch/);
assert.doesNotMatch(background, /https:\/\/recipes\.maestorm\.com/);
assert.doesNotMatch(background, /cookie|access.?token|oauth/i);

function backgroundHarness({ tabs = {}, fetchImpl, alarms = {} } = {}) {
  const state = {
    settings: {
      wordpressHost: "http://wordpress.localhost",
      username: "importer",
      applicationPassword: "test-password",
    },
    run: { version: 5, windowId: 4 },
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
        ...alarms,
      },
      notifications: {
        create: async () => {},
      },
      runtime: {
        sendMessage: async () => {},
        getURL: (path) => path,
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
        show: async () => {},
        update: async (id, options) => ({
          id,
          windowId: 4,
          status: options.url ? "loading" : "complete",
          url: options.url || "https://www.pinterest.com/pin/789/",
        }),
        onUpdated: { addListener: () => {}, removeListener: () => {} },
        ...tabs,
      },
      windows: { update: async () => {} },
    },
  };
  vm.runInNewContext(
    `${background}\nglobalThis.hooks = {
      waitForTabReady,
      waitForCollector,
      resolveStagePin,
      migrateCheckpoint,
      pauseRun,
      nextPinDelay,
      dailyCapReached,
      recordPinProcessed,
      exhaustedPinIds,
      incrementRetryCount,
      clearRetryCount,
      resumeBoardTab,
    };`,
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

const boardTabs = new Map([
  [20, { id: 20, windowId: 4, url: "https://www.pinterest.com/mae/recipes/" }],
  [21, { id: 21, windowId: 4, url: "https://www.pinterest.com/mae/desserts/" }],
]);
const boardHarness = backgroundHarness({
  tabs: {
    get: async (id) => {
      if (!boardTabs.has(id)) throw new Error("Missing tab");
      return boardTabs.get(id);
    },
  },
});
const savedBoardRun = {
  boardTabId: 20,
  boardUrl: "https://www.pinterest.com/mae/recipes/",
};
assert.equal(
  (await boardHarness.hooks.resumeBoardTab(savedBoardRun, 21)).id,
  20,
);
boardTabs.delete(20);
boardTabs.set(22, {
  id: 22,
  windowId: 4,
  url: "https://www.pinterest.com/mae/recipes/?utm_source=test#pins",
});
assert.equal(
  (await boardHarness.hooks.resumeBoardTab(savedBoardRun, 22)).id,
  22,
);
await assert.rejects(
  boardHarness.hooks.resumeBoardTab(savedBoardRun, 21),
  /Open the saved Pinterest board/,
);

let challengeBlocked = true;
let challengeShown = false;
let challengeActivated = false;
let challengeRemoved = false;
const challengeTab = {
  id: 30,
  windowId: 4,
  status: "complete",
  url: "https://www.pinterest.com/pin/789/",
};
const challengeHarness = backgroundHarness({
  tabs: {
    create: async () => challengeTab,
    get: async (id) => {
      if (id !== challengeTab.id || challengeRemoved)
        throw new Error("Missing tab");
      return challengeTab;
    },
    sendMessage: async (_id, message) => {
      if (message.type === "ping") return { ready: true };
      if (challengeBlocked)
        return {
          blocked: true,
          blockKind: "captcha",
          blockEvidence: "visible_captcha_iframe",
          reason: "Pinterest displayed a visible CAPTCHA frame.",
        };
      return {
        pin_url: challengeTab.url,
        source_url: "https://example.com/recipe",
      };
    },
    show: async () => {
      challengeShown = true;
    },
    update: async (_id, options) => {
      if (options.active) challengeActivated = true;
      if (options.url) challengeTab.url = options.url;
      return challengeTab;
    },
    remove: async () => {
      challengeRemoved = true;
    },
  },
});
await assert.rejects(
  challengeHarness.hooks.resolveStagePin(
    { pin_id: "789", pin_url: challengeTab.url },
    { stage: "recipe", windowId: 4 },
  ),
  /visible CAPTCHA frame/,
);
assert.equal(challengeShown, true);
assert.equal(challengeActivated, true);
assert.equal(challengeRemoved, false);
assert.equal(challengeHarness.state.run.challengeTabId, 30);
assert.equal(
  challengeHarness.state.run.blockEvidence,
  "visible_captcha_iframe",
);

challengeBlocked = false;
const resumedChallenge = await challengeHarness.hooks.resolveStagePin(
  { pin_id: "789", pin_url: challengeTab.url },
  { stage: "recipe", windowId: 4, challengeTabId: 30 },
);
assert.equal(resumedChallenge.status, "complete");
assert.equal(challengeRemoved, true);
assert.equal(challengeHarness.state.run.challengeTabId, null);

let replacementCreated = false;
const closedChallengeHarness = backgroundHarness({
  tabs: {
    get: async (id) => {
      if (id === 31)
        return {
          id: 31,
          windowId: 4,
          status: "complete",
          url: "https://www.pinterest.com/pin/789/",
        };
      throw new Error("Missing tab");
    },
    create: async (options) => {
      replacementCreated = true;
      return {
        id: 31,
        windowId: options.windowId,
        status: "complete",
        url: options.url,
      };
    },
  },
});
const replacementResult = await closedChallengeHarness.hooks.resolveStagePin(
  { pin_id: "789", pin_url: "https://www.pinterest.com/pin/789/" },
  { stage: "recipe", windowId: 4, challengeTabId: 30 },
);
assert.equal(replacementCreated, true);
assert.equal(replacementResult.status, "complete");

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
assert.equal(migrated.version, 5);
assert.equal(migrated.status, "ready");
assert.equal(migratedCapture, true);

let resumeAlarm = null;
const pauseHarness = backgroundHarness({
  alarms: {
    create: async (name, info) => {
      resumeAlarm = { name, when: info.when };
    },
    clear: async () => {
      resumeAlarm = null;
    },
  },
});
pauseHarness.state.run = { version: 5, kind: "stage", windowId: 4 };
await pauseHarness.hooks.pauseRun("Pinterest showed a rate-limit notice.", {
  blockKind: "rate_limited",
});
assert.equal(pauseHarness.state.run.status, "paused");
assert.equal(pauseHarness.state.run.consecutiveBlocks, 1);
assert.ok(pauseHarness.state.run.resumeNotBefore);
assert.equal(resumeAlarm.name, "mae-storm-eats-import-resume");
const firstTierMs =
  new Date(pauseHarness.state.run.resumeNotBefore).getTime() - Date.now();
assert.ok(firstTierMs > 9 * 60 * 1000 && firstTierMs <= 10 * 60 * 1000 + 2000);

await pauseHarness.hooks.pauseRun("Pinterest showed a rate-limit notice.", {
  blockKind: "rate_limited",
});
assert.equal(pauseHarness.state.run.consecutiveBlocks, 2);
const secondTierMs =
  new Date(pauseHarness.state.run.resumeNotBefore).getTime() - Date.now();
assert.ok(
  secondTierMs > 29 * 60 * 1000 && secondTierMs <= 30 * 60 * 1000 + 2000,
);

await pauseHarness.hooks.pauseRun("Solve the CAPTCHA manually.", {
  blockKind: "captcha",
});
assert.equal(pauseHarness.state.run.consecutiveBlocks, 3);
assert.equal(pauseHarness.state.run.resumeNotBefore, null);
assert.equal(resumeAlarm, null);

await pauseHarness.hooks.pauseRun("", { resetConsecutive: true });
assert.equal(pauseHarness.state.run.consecutiveBlocks, 0);
assert.equal(pauseHarness.state.run.resumeNotBefore, null);

const pacingHarness = backgroundHarness();
const normalDelay = pacingHarness.hooks.nextPinDelay({
  pinsSinceLongPause: 0,
});
assert.ok(normalDelay.delayMs >= 25000 && normalDelay.delayMs <= 55000);
const longDelay = pacingHarness.hooks.nextPinDelay({ pinsSinceLongPause: 5 });
assert.ok(longDelay.delayMs >= 120000 && longDelay.delayMs <= 240000);
assert.equal(longDelay.pinsSinceLongPause, 0);

const dailyHarness = backgroundHarness();
assert.equal(await dailyHarness.hooks.dailyCapReached(), false);
for (let i = 0; i < 50; i += 1) await dailyHarness.hooks.recordPinProcessed();
assert.equal(dailyHarness.state.dailyUsage.count, 50);
assert.equal(await dailyHarness.hooks.dailyCapReached(), true);

const retryHarness = backgroundHarness();
for (let i = 0; i < 3; i += 1)
  await retryHarness.hooks.incrementRetryCount("789");
assert.ok((await retryHarness.hooks.exhaustedPinIds()).has("789"));
await retryHarness.hooks.clearRetryCount("789");
assert.ok(!(await retryHarness.hooks.exhaustedPinIds()).has("789"));

const collectorListeners = [];
let scrolls = 0;
const collectorContext = {
  URL,
  location: { href: "https://www.pinterest.com/mae/recipes/" },
  setTimeout: (callback) => {
    callback();
    return 1;
  },
  getComputedStyle: (node) =>
    node.style || { display: "block", visibility: "visible", opacity: "1" },
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
vm.runInNewContext(
  `${collector}\nglobalThis.testDetectBlock = detectBlock;`,
  collectorContext,
);
const discovery = await collectorListeners[0]({ type: "discover" });
assert.deepEqual(
  Array.from(discovery.pins, (pin) => pin.pin_id),
  ["123", "456"],
);
assert.ok(scrolls >= 15);

collectorContext.location = { href: "https://www.pinterest.com/pin/1/" };
const visibleNode = (innerText = "", width = 800, height = 600) => ({
  innerText,
  style: { display: "block", visibility: "visible", opacity: "1" },
  getBoundingClientRect: () => ({ width, height }),
});
const hiddenNode = (innerText = "") => ({
  ...visibleNode(innerText),
  style: { display: "none", visibility: "visible", opacity: "1" },
});
collectorContext.document = {
  title: "",
  body: visibleNode("please complete this captcha to continue"),
  querySelector: () => null,
  querySelectorAll: (selector) =>
    selector === "[role='dialog'], form"
      ? [visibleNode("please complete this captcha to continue")]
      : [],
};
assert.equal(collectorContext.testDetectBlock().blockKind, "captcha");
assert.equal(
  collectorContext.testDetectBlock().blockEvidence,
  "visible_verification_prompt",
);

collectorContext.location = {
  href: "https://www.pinterest.com/challenge/background-check/",
};
collectorContext.document = {
  title: "Challenge recipes",
  body: visibleNode("A recipe article that mentions captcha technology"),
  querySelector: () => null,
  querySelectorAll: (selector) =>
    selector === "iframe"
      ? [
          {
            src: "https://captcha.example.test/widget",
            ...hiddenNode(),
          },
        ]
      : [],
};
assert.equal(collectorContext.testDetectBlock().blocked, false);

collectorContext.location = { href: "https://www.pinterest.com/pin/1/" };
collectorContext.document = {
  title: "",
  body: visibleNode(""),
  querySelector: () => null,
  querySelectorAll: (selector) =>
    selector === "iframe"
      ? [
          {
            src: "https://captcha.example.test/widget",
            ...visibleNode("", 304, 78),
          },
        ]
      : [],
};
assert.equal(collectorContext.testDetectBlock().blockKind, "captcha");
assert.equal(
  collectorContext.testDetectBlock().blockEvidence,
  "visible_captcha_iframe",
);

collectorContext.document = {
  title: "",
  body: visibleNode(""),
  querySelector: (selector) =>
    selector === "input[type='password']" ? {} : null,
  querySelectorAll: () => [],
};
assert.equal(collectorContext.testDetectBlock().blockKind, "login");

collectorContext.document = {
  title: "",
  body: visibleNode("too many requests, please try again later"),
  querySelector: () => null,
  querySelectorAll: () => [],
};
assert.equal(collectorContext.testDetectBlock().blockKind, "rate_limited");

collectorContext.document = {
  title: "",
  body: visibleNode("a delicious pasta recipe"),
  querySelector: () => null,
  querySelectorAll: () => [],
};
assert.equal(collectorContext.testDetectBlock().blocked, false);

const popupElements = new Map();
const popupElement = (selector) => {
  if (!popupElements.has(selector))
    popupElements.set(selector, {
      hidden: false,
      disabled: false,
      textContent: "",
      innerHTML: "",
      children: [],
      max: 0,
      value: 0,
      addEventListener: () => {},
      appendChild(child) {
        this.children.push(child);
      },
      removeAttribute(name) {
        if (name === "value") this.value = undefined;
      },
    });
  return popupElements.get(selector);
};
const popupContext = {
  Object,
  Date,
  setInterval: () => 1,
  clearInterval: () => {},
  document: {
    querySelector: popupElement,
    createElement: () => ({ textContent: "" }),
  },
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
  `${popupScript}\nglobalThis.testRenderRun = renderRun;\nglobalThis.testRenderPauseHistory = renderPauseHistory;`,
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

popupContext.testRenderRun({
  status: "paused",
  error: "Pinterest showed a login or checkpoint page.",
  blockKind: "login",
  resumeNotBefore: new Date(Date.now() + 5 * 60 * 1000).toISOString(),
  manualReviewExcluded: 2,
  inventory: {},
});
assert.equal(popupElement("#resume").disabled, true);
assert.equal(popupElement("#resume-countdown").hidden, false);
assert.match(popupElement("#resume-countdown").textContent, /Auto-resumes in/);
assert.equal(popupElement("#manual-review-note").hidden, false);
assert.match(
  popupElement("#manual-review-note").textContent,
  /2 pins failed repeatedly/,
);

popupContext.testRenderRun({
  status: "paused",
  error: "Pinterest showed a CAPTCHA.",
  blockKind: "captcha",
  resumeNotBefore: null,
  inventory: {},
});
assert.equal(popupElement("#resume").disabled, false);
assert.equal(popupElement("#resume-countdown").hidden, true);

popupContext.testRenderPauseHistory([
  {
    at: new Date().toISOString(),
    reason: "Pinterest or WordPress throttled the import.",
    blockKind: "rate_limited",
  },
]);
assert.equal(popupElement("#pause-history").hidden, false);
assert.equal(popupElement("#pause-history-list").children.length, 1);
assert.match(
  popupElement("#pause-history-list").children[0].textContent,
  /rate limited/,
);

console.log("Firefox extension tests passed.");
