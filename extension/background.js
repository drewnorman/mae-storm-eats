const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let activeRun = false;
let activeVerification = null;
let activeController = null;
let activePinTabId = null;

const IMPORT_ALARM = "mae-storm-import-pump";
const RESUME_ALARM = "mae-storm-import-resume";
const CHECKPOINT_VERSION = 5;
const BATCH_SIZE = 10;
const PIN_DELAY_MIN_MS = 25000;
const PIN_DELAY_MAX_MS = 55000;
const LONG_PAUSE_EVERY_MIN = 4;
const LONG_PAUSE_EVERY_MAX = 6;
const LONG_PAUSE_MIN_MS = 120000;
const LONG_PAUSE_MAX_MS = 240000;
const JSON_TIMEOUT_MS = 20000;
const PHOTO_TIMEOUT_MS = 30000;
const MEDIA_TIMEOUT_MS = 60000;
const DAILY_PIN_CAP = 50;
const BACKOFF_TIERS_MS = [10 * 60 * 1000, 30 * 60 * 1000, 2 * 60 * 60 * 1000];
const DAY_MS = 24 * 60 * 60 * 1000;
const MAX_PIN_RETRIES = 3;
const PAUSE_HISTORY_LIMIT = 10;
const FAILURE_LOG_LIMIT = 25;

const permissionMessage =
  "Firefox permission is required to connect to this WordPress site. Choose Connect to try again.";

function randomBetween(min, max) {
  return Math.round(min + Math.random() * (max - min));
}

function nextPinDelay(run) {
  const sincePause = (run.pinsSinceLongPause || 0) + 1;
  const threshold = randomBetween(LONG_PAUSE_EVERY_MIN, LONG_PAUSE_EVERY_MAX);
  if (sincePause >= threshold)
    return {
      delayMs: randomBetween(LONG_PAUSE_MIN_MS, LONG_PAUSE_MAX_MS),
      pinsSinceLongPause: 0,
    };
  return {
    delayMs: randomBetween(PIN_DELAY_MIN_MS, PIN_DELAY_MAX_MS),
    pinsSinceLongPause: sincePause,
  };
}

function startOfNextDay() {
  const next = new Date();
  next.setHours(24, 0, 0, 0);
  return next;
}

function isSameDay(a, b) {
  return new Date(a).toDateString() === new Date(b).toDateString();
}

async function recordPinProcessed() {
  const { dailyUsage = {} } = await browser.storage.local.get("dailyUsage");
  const now = new Date().toISOString();
  const next =
    dailyUsage.windowStart && isSameDay(dailyUsage.windowStart, now)
      ? {
          windowStart: dailyUsage.windowStart,
          count: (dailyUsage.count || 0) + 1,
        }
      : { windowStart: now, count: 1 };
  await browser.storage.local.set({ dailyUsage: next });
  return next;
}

async function dailyCapReached() {
  const { dailyUsage = {} } = await browser.storage.local.get("dailyUsage");
  if (
    !dailyUsage.windowStart ||
    !isSameDay(dailyUsage.windowStart, new Date().toISOString())
  )
    return false;
  return (dailyUsage.count || 0) >= DAILY_PIN_CAP;
}

async function recordPause(reason, blockKind, blockEvidence = "") {
  const { pauseHistory = [] } = await browser.storage.local.get("pauseHistory");
  const entry = {
    at: new Date().toISOString(),
    reason,
    blockKind: blockKind || "",
    blockEvidence,
  };
  const next = [entry, ...pauseHistory].slice(0, PAUSE_HISTORY_LIMIT);
  await browser.storage.local.set({ pauseHistory: next });
  return next;
}

async function recordFailure(pinId, stage, reason) {
  const { failureLog = [] } = await browser.storage.local.get("failureLog");
  const entry = { pinId, stage, reason, at: new Date().toISOString() };
  const next = [
    entry,
    ...failureLog.filter(
      (item) => !(item.pinId === pinId && item.stage === stage),
    ),
  ].slice(0, FAILURE_LOG_LIMIT);
  await browser.storage.local.set({ failureLog: next });
  return next;
}

async function clearFailure(pinId, stage) {
  const { failureLog = [] } = await browser.storage.local.get("failureLog");
  const next = failureLog.filter(
    (item) => !(item.pinId === pinId && item.stage === stage),
  );
  if (next.length !== failureLog.length)
    await browser.storage.local.set({ failureLog: next });
}

async function incrementRetryCount(pinId) {
  const { retryCounts = {} } = await browser.storage.local.get("retryCounts");
  const next = { ...retryCounts, [pinId]: (retryCounts[pinId] || 0) + 1 };
  await browser.storage.local.set({ retryCounts: next });
  return next;
}

async function clearRetryCount(pinId) {
  const { retryCounts = {} } = await browser.storage.local.get("retryCounts");
  if (!(pinId in retryCounts)) return;
  const next = { ...retryCounts };
  delete next[pinId];
  await browser.storage.local.set({ retryCounts: next });
}

async function exhaustedPinIds() {
  const { retryCounts = {} } = await browser.storage.local.get("retryCounts");
  return new Set(
    Object.entries(retryCounts)
      .filter(([, count]) => count >= MAX_PIN_RETRIES)
      .map(([pinId]) => pinId),
  );
}

function notify(title, message) {
  browser.notifications
    .create({
      type: "basic",
      iconUrl: browser.runtime.getURL("icons/mae-storm-eats.svg"),
      title,
      message,
    })
    .catch(() => {});
}

function permissionOrigin(origin) {
  return `${origin}/*`;
}

async function setConnection(patch) {
  const { connection = {} } = await browser.storage.local.get("connection");
  const next = { ...connection, ...patch, updatedAt: new Date().toISOString() };
  await browser.storage.local.set({ connection: next });
  browser.runtime
    .sendMessage({ type: "connectionState", connection: next })
    .catch(() => {});
  return next;
}

async function performVerification(expected) {
  await setConnection({
    ...expected,
    state: "verifying",
    message: "Verifying connection…",
    verifiedAt: null,
  });
  try {
    const status = await api("/pinterest-imports/status");
    if (!status.authenticated)
      throw new Error("WordPress did not authenticate these credentials.");
    return setConnection({
      ...expected,
      state: "verified",
      message: "WordPress connection verified.",
      verifiedAt: new Date().toISOString(),
    });
  } catch (error) {
    return setConnection({
      ...expected,
      state: "failed",
      message: connectionVerificationMessage(error),
      verifiedAt: null,
    });
  }
}

function connectionVerificationMessage(error) {
  if (
    ["invalid_username", "invalid_email", "incorrect_password"].includes(
      error.code,
    )
  )
    return "WordPress did not accept this username and Application Password.";
  if (
    [
      "application_passwords_disabled",
      "application_passwords_disabled_for_user",
    ].includes(error.code)
  )
    return "Application Passwords are not available for this WordPress account. Check the site configuration and try again.";
  if (error.status === 401)
    return "WordPress received the request but did not authenticate it. Check that Application Password authentication is enabled for the import endpoint.";
  if (error.status === 403)
    return "This WordPress account does not have permission to import recipes. Assign it the Recipe Importer role and try again.";
  if (error.status === 404)
    return "The WordPress import endpoint was not found. Confirm the Mae Storm Recipes plugin is active and try again.";
  if (Number.isInteger(error.status))
    return `WordPress could not verify the connection (HTTP ${error.status}). Check the site and try again.`;
  return error.message || "Connection verification failed.";
}

function verifyConnection(expected) {
  const key = `${expected.origin}\n${expected.username}`;
  if (activeVerification?.key === key) return activeVerification.promise;
  if (activeVerification)
    return activeVerification.promise.then(() => verifyConnection(expected));
  const promise = performVerification(expected).finally(() => {
    if (activeVerification?.promise === promise) activeVerification = null;
  });
  activeVerification = { key, promise };
  return promise;
}

async function prepareVerification(settings) {
  await browser.storage.local.set({ settings });
  const expected = {
    origin: settings.wordpressHost,
    username: settings.username,
  };
  await setConnection({
    ...expected,
    state: "pending",
    message: "Waiting for Firefox site permission…",
    verifiedAt: null,
  });
  if (
    await browser.permissions.contains({
      origins: [permissionOrigin(expected.origin)],
    })
  )
    return verifyConnection(expected);
}

async function reconcileConnection() {
  const { connection = {} } = await browser.storage.local.get("connection");
  if (connection.state !== "pending" || !connection.origin) return connection;
  if (
    await browser.permissions.contains({
      origins: [permissionOrigin(connection.origin)],
    })
  )
    return verifyConnection({
      origin: connection.origin,
      username: connection.username,
    });
  return setConnection({
    state: "failed",
    message: permissionMessage,
    verifiedAt: null,
  });
}

async function credentials() {
  const { settings = {} } = await browser.storage.local.get("settings");
  if (!settings.wordpressHost)
    throw new Error("WordPress site URL is not configured.");
  if (!settings.username || !settings.applicationPassword)
    throw new Error("WordPress service credentials are not configured.");
  return {
    ...settings,
    authorization: `Basic ${btoa(`${settings.username}:${settings.applicationPassword}`)}`,
  };
}

async function timedFetch(url, options = {}, timeoutMs = JSON_TIMEOUT_MS) {
  const controller = new AbortController();
  activeController = controller;
  const timeout = setTimeout(() => controller.abort("timeout"), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } catch (error) {
    if (controller.signal.aborted) {
      const aborted = new Error(
        controller.signal.reason === "pause"
          ? "Import paused."
          : `Request timed out after ${Math.round(timeoutMs / 1000)} seconds.`,
      );
      aborted.failureKind =
        controller.signal.reason === "pause" ? "paused" : "timeout";
      throw aborted;
    }
    throw error;
  } finally {
    clearTimeout(timeout);
    if (activeController === controller) activeController = null;
  }
}

async function api(path, options = {}) {
  const auth = await credentials();
  let response;
  try {
    response = await timedFetch(
      `${auth.wordpressHost}/wp-json/mae-storm-recipes/v1${path}`,
      {
        ...options,
        credentials: "omit",
        headers: {
          Authorization: auth.authorization,
          ...(options.body instanceof FormData
            ? {}
            : { "Content-Type": "application/json" }),
          ...options.headers,
        },
      },
      options.body instanceof FormData ? MEDIA_TIMEOUT_MS : JSON_TIMEOUT_MS,
    );
  } catch (cause) {
    if (cause.failureKind) throw cause;
    const error = new Error(
      `Could not connect to ${auth.wordpressHost}. Check the URL and confirm WordPress is running.`,
    );
    error.failureKind = "connection";
    throw error;
  }
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(
      body.message || `WordPress returned ${response.status}`,
    );
    error.status = response.status;
    error.code = typeof body.code === "string" ? body.code : "";
    throw error;
  }
  return body;
}

async function setRun(patch) {
  const { run = {} } = await browser.storage.local.get("run");
  const next = { ...run, ...patch, updatedAt: new Date().toISOString() };
  await browser.storage.local.set({ run: next });
  browser.runtime.sendMessage({ type: "progress", run: next }).catch(() => {});
  return next;
}

async function refreshInventory() {
  const { run = {} } = await browser.storage.local.get("run");
  if (Array.isArray(run.legacyPins) && run.legacyPins.length) {
    await api("/pinterest-imports/pins", {
      method: "POST",
      body: JSON.stringify({ pins: run.legacyPins }),
    });
    await setRun({ legacyPins: [] });
  }
  const status = await api("/pinterest-imports/status");
  return setRun({ inventory: status.stages || {} });
}

function normalizeBoardUrl(value) {
  const url = new URL(value);
  url.hash = "";
  url.search = "";
  url.pathname = `${url.pathname.replace(/\/+$/, "")}/`;
  return url.href;
}

async function boardBinding(tabId) {
  const tab = await browser.tabs.get(tabId);
  if (!Number.isInteger(tab.id) || !Number.isInteger(tab.windowId))
    throw new Error("The Pinterest board tab is no longer available.");
  if (!isPinterestPage(tab.url) || /\/pin\/\d+/.test(new URL(tab.url).pathname))
    throw new Error("Open a Pinterest board before capturing pins.");
  return {
    boardUrl: normalizeBoardUrl(tab.url),
    boardTabId: tab.id,
    windowId: tab.windowId,
  };
}

function boardUrlMatches(tab, boardUrl) {
  if (!tab || !isPinterestPage(tab.url)) return false;
  try {
    return normalizeBoardUrl(tab.url) === boardUrl;
  } catch {
    return false;
  }
}

async function resumeBoardTab(run, activeTabId) {
  if (Number.isInteger(run.boardTabId)) {
    const saved = await browser.tabs.get(run.boardTabId).catch(() => null);
    if (boardUrlMatches(saved, run.boardUrl)) return saved;
  }
  const active = await browser.tabs.get(activeTabId).catch(() => null);
  if (boardUrlMatches(active, run.boardUrl)) return active;
  throw new Error(
    `Open the saved Pinterest board ${run.boardUrl || "from this capture"} in the active tab, then choose Resume.`,
  );
}

function isPinterestPage(url) {
  try {
    const page = new URL(url);
    return page.protocol === "https:" && page.hostname === "www.pinterest.com";
  } catch {
    return false;
  }
}

function collectorUnavailable(message) {
  const error = new Error(message);
  error.failureKind = "collector_unavailable";
  return error;
}

async function waitForTabReady(tab, timeoutMs = 30000) {
  if (tab.status === "complete" && isPinterestPage(tab.url)) return tab;
  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (callback) => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      browser.tabs.onUpdated.removeListener(listener);
      callback();
    };
    const check = (current) => {
      if (current.status === "complete" && isPinterestPage(current.url))
        finish(() => resolve(current));
    };
    const listener = (tabId, change, current) => {
      if (tabId === tab.id && change.status === "complete") check(current);
    };
    const timeout = setTimeout(
      () =>
        finish(() =>
          reject(
            collectorUnavailable(
              "Pinterest pin page did not finish loading on an allowed URL.",
            ),
          ),
        ),
      timeoutMs,
    );
    browser.tabs.onUpdated.addListener(listener);
    browser.tabs
      .get(tab.id)
      .then(check)
      .catch((error) => finish(() => reject(error)));
  });
}

async function waitForCollector(tabId, timeoutMs = 10000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  let currentUrl = "";
  while (Date.now() < deadline) {
    const current = await browser.tabs.get(tabId);
    currentUrl = current.url || "";
    if (!isPinterestPage(currentUrl))
      throw collectorUnavailable(
        `Pinterest redirected the pin tab to an unsupported URL: ${currentUrl || "unknown"}`,
      );
    try {
      const response = await browser.tabs.sendMessage(tabId, { type: "ping" });
      if (response?.ready) return response;
    } catch {}
    await delay(intervalMs);
  }
  throw collectorUnavailable(
    `Pinterest collector did not start for ${currentUrl || "the pin tab"}.`,
  );
}

async function uploadPhotos(pin, result) {
  for (const comment of result.comments || []) {
    for (const photo of comment.photos || []) {
      const response = await timedFetch(
        photo.url,
        { credentials: "include" },
        PHOTO_TIMEOUT_MS,
      );
      if (!response.ok) throw new Error(`Photo returned ${response.status}`);
      const form = new FormData();
      form.append("photo", await response.blob(), `${photo.id}.jpg`);
      form.append("photo_id", photo.id);
      await api(
        `/pinterest-imports/${pin.pin_id}/comments/${encodeURIComponent(comment.id)}/media`,
        { method: "POST", body: form },
      );
    }
  }
}

async function captureBoard(tabId) {
  if (activeRun) return;
  activeRun = true;
  try {
    const binding = await boardBinding(tabId);
    await setRun({
      version: CHECKPOINT_VERSION,
      kind: "capture",
      status: "capturing",
      phase: "discovering",
      ...binding,
      discovered: 0,
      completed: 0,
      failed: 0,
      error: "",
      pauseRequested: false,
    });
    const discovery = await browser.tabs.sendMessage(tabId, {
      type: "discover",
    });
    if (discovery.paused) return pauseRun("", { resetConsecutive: true });
    if (discovery.blocked) {
      const error = new Error(
        discovery.reason || "Pinterest login or checkpoint detected.",
      );
      error.blockKind = discovery.blockKind || "unknown";
      error.blockEvidence = discovery.blockEvidence || "";
      error.challengeContext = "capture";
      throw error;
    }
    if (!Array.isArray(discovery.pins) || discovery.pins.length === 0)
      throw new Error(
        "No Pinterest pins were discovered. Confirm this is a board page and its pins are visible.",
      );
    await api("/pinterest-imports/pins", {
      method: "POST",
      body: JSON.stringify({
        pins: discovery.pins,
        owner: discovery.owner || {},
      }),
    });
    await setRun({
      status: "complete",
      phase: "complete",
      completed: discovery.pins.length,
      discovered: discovery.pins.length,
      message: `${discovery.pins.length} pins captured.`,
    });
    notify(
      "Pinterest board captured",
      `${discovery.pins.length} pin${discovery.pins.length === 1 ? "" : "s"} captured.`,
    );
    await refreshInventory();
  } catch (error) {
    const blockKind = error.blockKind || null;
    await pauseRun(
      blockKind
        ? pauseMessageFor(error, blockKind)
        : error.message || "Pin capture paused unexpectedly.",
      blockKind ? { blockKind, blockEvidence: error.blockEvidence || "" } : {},
    );
  } finally {
    activeRun = false;
  }
}

function globalFailure(error) {
  return (
    [401, 403, 404, 429].includes(error.status) ||
    error.status >= 500 ||
    ["owner_mismatch"].includes(error.code) ||
    ["connection", "timeout"].includes(error.failureKind) ||
    error.failureKind === "pinterest_blocked"
  );
}

async function resolveStagePin(pin, run) {
  let tab;
  let preserveTab = false;
  try {
    if (Number.isInteger(run.challengeTabId))
      tab = await browser.tabs.get(run.challengeTabId).catch(() => null);
    if (tab && isPinterestPage(tab.url)) {
      const currentPinId = String(tab.url || "").match(/\/pin\/(\d+)/)?.[1];
      if (currentPinId !== String(pin.pin_id))
        tab = await browser.tabs.update(tab.id, { url: pin.pin_url });
    } else {
      tab = await browser.tabs.create({
        url: pin.pin_url,
        windowId: run.windowId,
        active: false,
      });
    }
    activePinTabId = tab.id;
    await setRun({
      phase: "loading_pin",
      tempTabId: tab.id,
      currentPin: pin.pin_id,
    });
    await browser.tabs.hide(tab.id).catch(() => {});
    await waitForTabReady(tab);
    await waitForCollector(tab.id);
    const result = await browser.tabs.sendMessage(tab.id, {
      type: run.stage === "recipe" ? "resolveRecipe" : "resolveReviews",
      pin,
    });
    if (result?.blocked) {
      const error = new Error(
        result.reason || "Pinterest login or checkpoint detected.",
      );
      error.failureKind = "pinterest_blocked";
      error.blockKind = result.blockKind || "unknown";
      error.blockEvidence = result.blockEvidence || "";
      if (error.blockKind === "captcha") {
        preserveTab = true;
        error.challengeContext = "stage";
        error.challengeTabId = tab.id;
        await setRun({
          challengeTabId: tab.id,
          tempTabId: tab.id,
          blockEvidence: error.blockEvidence,
        });
        await browser.tabs.show(tab.id).catch(() => {});
        await browser.tabs.update(tab.id, { active: true }).catch(() => {});
        await browser.windows
          ?.update(tab.windowId, { focused: true })
          .catch(() => {});
      }
      throw error;
    }
    if (run.stage === "recipe") {
      const response = await api(`/pinterest-imports/${pin.pin_id}/recipe`, {
        method: "POST",
        body: JSON.stringify(result),
      });
      return {
        status: response.status === "complete" ? "complete" : "failed",
        error: response.warnings?.join(" ") || "",
      };
    }
    await api(`/pinterest-imports/${pin.pin_id}/reviews`, {
      method: "POST",
      body: JSON.stringify(result),
    });
    await uploadPhotos(pin, result);
    if (!result.comments_complete)
      throw new Error("Pinterest comments did not finish expanding.");
    await api(`/pinterest-imports/${pin.pin_id}/finalize`, {
      method: "POST",
      body: JSON.stringify({ comments_complete: true }),
    });
    return { status: "complete", error: "" };
  } catch (error) {
    const latest = (await browser.storage.local.get("run")).run || {};
    if (latest.pauseRequested) {
      error.failureKind = "paused";
      throw error;
    }
    if (error.failureKind === "paused" || globalFailure(error)) throw error;
    await api(`/pinterest-imports/${pin.pin_id}/failure`, {
      method: "POST",
      body: JSON.stringify({
        stage: run.stage,
        error: error.message || "Pin processing failed.",
      }),
    }).catch(() => {});
    return {
      status: "failed",
      error: error.message || "Pin processing failed.",
    };
  } finally {
    if (tab?.id && !preserveTab)
      await browser.tabs.remove(tab.id).catch(() => {});
    if (activePinTabId === tab?.id) activePinTabId = null;
    if (!preserveTab)
      await setRun({
        tempTabId: null,
        challengeTabId: null,
        blockEvidence: null,
      });
  }
}

async function schedulePump(delayMs = 0) {
  await browser.alarms.create(IMPORT_ALARM, {
    when: Date.now() + Math.max(delayMs, 100),
  });
}

function pauseMessageFor(error, blockKind) {
  if (blockKind === "captcha") {
    if (error.challengeContext === "capture")
      return "Pinterest is asking for verification in the saved board tab. Complete it there, then choose Resume.";
    return "Pinterest is asking for verification in the pin tab opened by the importer. Complete it there, then choose Resume.";
  }
  if (blockKind === "rate_limited")
    return "Pinterest or WordPress throttled the import. The batch will resume automatically after a cooldown.";
  if (blockKind === "login")
    return error.message || "Pinterest showed a login or checkpoint page.";
  return error.message || "The stage paused unexpectedly.";
}

async function pauseRun(error = "", options = {}) {
  await browser.alarms.clear(IMPORT_ALARM);
  await browser.alarms.clear(RESUME_ALARM);
  const { run: currentRun = {} } = await browser.storage.local.get("run");
  const blockKind = options.blockKind || null;
  let consecutiveBlocks = currentRun.consecutiveBlocks || 0;
  let resumeNotBefore = options.resumeAt || null;
  if (options.resetConsecutive) {
    consecutiveBlocks = 0;
  } else if (blockKind) {
    consecutiveBlocks += 1;
    if (!resumeNotBefore && blockKind !== "captcha") {
      const tierMs = BACKOFF_TIERS_MS[consecutiveBlocks - 1] ?? DAY_MS;
      resumeNotBefore = new Date(Date.now() + tierMs).toISOString();
    }
  }
  const notable = Boolean(blockKind || options.resumeAt);
  const blockEvidence = options.blockEvidence || currentRun.blockEvidence || "";
  if (notable) await recordPause(error, blockKind, blockEvidence);
  const preserveChallenge =
    blockKind === "captcha" && Number.isInteger(currentRun.challengeTabId);
  await setRun({
    status: "paused",
    phase: "paused",
    pauseRequested: false,
    currentPin: null,
    tempTabId: preserveChallenge ? currentRun.challengeTabId : null,
    challengeTabId: preserveChallenge ? currentRun.challengeTabId : null,
    nextPinAt: null,
    error,
    blockKind,
    blockEvidence: blockKind ? blockEvidence : null,
    consecutiveBlocks,
    resumeNotBefore,
  });
  if (resumeNotBefore)
    await browser.alarms.create(RESUME_ALARM, {
      when: new Date(resumeNotBefore).getTime(),
    });
  if (notable) notify("Pinterest import paused", error || "The batch paused.");
}

async function processQueue() {
  const { run = {} } = await browser.storage.local.get("run");
  if (run.pauseRequested) return pauseRun("", { resetConsecutive: true });
  if (!Array.isArray(run.queue) || run.cursor >= run.queue.length) {
    await browser.alarms.clear(IMPORT_ALARM);
    await setRun({
      status: "complete",
      phase: "complete",
      currentPin: null,
      nextPinAt: null,
      error: "",
      consecutiveBlocks: 0,
      resumeNotBefore: null,
      blockKind: null,
      blockEvidence: null,
      challengeTabId: null,
    });
    notify(
      "Pinterest import complete",
      `${run.completed || 0} completed${run.failed ? `, ${run.failed} failed` : ""}.`,
    );
    return refreshInventory();
  }
  const pin = run.queue[run.cursor];
  try {
    await setRun({
      status: "running",
      phase: "loading_pin",
      currentPin: pin.pin_id,
      error: "",
    });
    const outcome = await resolveStagePin(pin, run);
    if (outcome.status === "complete") {
      await clearRetryCount(pin.pin_id);
      await clearFailure(pin.pin_id, run.stage);
    } else if (outcome.status === "failed") {
      await incrementRetryCount(pin.pin_id);
      await recordFailure(pin.pin_id, run.stage, outcome.error);
    }
    const completed =
      (run.completed || 0) + (outcome.status === "complete" ? 1 : 0);
    const failed = (run.failed || 0) + (outcome.status === "failed" ? 1 : 0);
    const cursor = run.cursor + 1;
    await recordPinProcessed();
    if (cursor >= run.queue.length) {
      await setRun({
        cursor,
        completed,
        failed,
        consecutiveBlocks: 0,
        resumeNotBefore: null,
        blockKind: null,
      });
      return schedulePump(100);
    }
    if (await dailyCapReached()) {
      await setRun({ cursor, completed, failed, currentPin: null });
      return pauseRun(
        `Daily import cap of ${DAILY_PIN_CAP} pins reached today. The batch will resume tomorrow.`,
        { resumeAt: startOfNextDay().toISOString() },
      );
    }
    const { delayMs, pinsSinceLongPause } = nextPinDelay(run);
    const nextPinAt = new Date(Date.now() + delayMs).toISOString();
    await setRun({
      status: "waiting",
      phase: "waiting",
      cursor,
      completed,
      failed,
      currentPin: null,
      nextPinAt,
      pinsSinceLongPause,
    });
    return schedulePump(delayMs);
  } catch (error) {
    if (error.failureKind === "paused")
      return pauseRun("", { resetConsecutive: true });
    const blockKind =
      error.blockKind || (error.status === 429 ? "rate_limited" : "service");
    return pauseRun(pauseMessageFor(error, blockKind), {
      blockKind,
      blockEvidence: error.blockEvidence || "",
    });
  }
}

async function startStage(stage, mode, tabId) {
  if (activeRun) return;
  activeRun = true;
  try {
    const tab = await browser.tabs.get(tabId);
    const response = await api(
      `/pinterest-imports/queue?stage=${encodeURIComponent(stage)}&mode=${encodeURIComponent(mode)}`,
    );
    let queue = Array.isArray(response.pins)
      ? response.pins.slice(0, BATCH_SIZE)
      : [];
    let manualReviewExcluded = 0;
    if (mode === "retry") {
      const exhausted = await exhaustedPinIds();
      const before = queue.length;
      queue = queue.filter((pin) => !exhausted.has(String(pin.pin_id)));
      manualReviewExcluded = before - queue.length;
    }
    await setRun({
      version: CHECKPOINT_VERSION,
      kind: "stage",
      stage,
      mode,
      status: queue.length ? "running" : "complete",
      phase: queue.length ? "queued" : "complete",
      queue,
      cursor: 0,
      completed: 0,
      failed: 0,
      currentPin: null,
      tempTabId: null,
      challengeTabId: null,
      blockEvidence: null,
      windowId: tab.windowId,
      nextPinAt: null,
      pauseRequested: false,
      manualReviewExcluded,
      error: queue.length
        ? ""
        : manualReviewExcluded
          ? `No eligible pins in this queue. ${manualReviewExcluded} need manual review after repeated failures.`
          : "No eligible pins in this queue.",
    });
    if (queue.length) await processQueue();
    else await refreshInventory();
  } catch (error) {
    await pauseRun(error.message || "The stage could not start.");
  } finally {
    activeRun = false;
  }
}

async function resumeRun(tabId) {
  if (activeRun) return;
  const { run: pending = {} } = await browser.storage.local.get("run");
  if (
    pending.resumeNotBefore &&
    Date.now() < new Date(pending.resumeNotBefore).getTime()
  )
    return;
  activeRun = true;
  try {
    const { run = {} } = await browser.storage.local.get("run");
    if (run.kind === "capture") {
      const boardTab = await resumeBoardTab(run, tabId);
      activeRun = false;
      return captureBoard(boardTab.id);
    }
    let tab = null;
    if (Number.isInteger(run.challengeTabId))
      tab = await browser.tabs.get(run.challengeTabId).catch(() => null);
    if (!tab) tab = await browser.tabs.get(tabId);
    await setRun({
      windowId: tab.windowId,
      status: "running",
      phase: "queued",
      pauseRequested: false,
      error: "",
    });
    await processQueue();
  } catch (error) {
    await pauseRun(error.message || "The stage could not resume.");
  } finally {
    activeRun = false;
  }
}

async function autoResume() {
  if (activeRun) return;
  const { run = {} } = await browser.storage.local.get("run");
  if (run.status !== "paused" || run.kind !== "stage") return;
  if (run.blockKind === "captcha") return;
  if (
    run.resumeNotBefore &&
    Date.now() < new Date(run.resumeNotBefore).getTime()
  )
    return;
  activeRun = true;
  try {
    await setRun({
      status: "running",
      phase: "queued",
      pauseRequested: false,
      error: "",
    });
    notify(
      "Pinterest import resumed",
      "Resumed automatically after the cooldown elapsed.",
    );
    await processQueue();
  } catch (error) {
    await pauseRun(error.message || "Automatic resume failed.");
  } finally {
    activeRun = false;
  }
}

async function migrateCheckpoint() {
  const { run = {} } = await browser.storage.local.get("run");
  if (run.version === CHECKPOINT_VERSION) return run;
  if (run.version === 3 && Array.isArray(run.pins) && run.pins.length) {
    try {
      await api("/pinterest-imports/pins", {
        method: "POST",
        body: JSON.stringify({ pins: run.pins }),
      });
    } catch {
      const migrated = {
        version: CHECKPOINT_VERSION,
        kind: "idle",
        status: "paused",
        phase: "paused",
        legacyPins: run.pins,
        inventory: {},
        error:
          "Connect to WordPress to finish moving the previous pin list into the staged importer.",
      };
      await browser.storage.local.set({ run: migrated });
      return migrated;
    }
  }
  const migrated = {
    version: CHECKPOINT_VERSION,
    kind: "idle",
    status: "ready",
    phase: "ready",
    inventory: {},
    message: run.version
      ? "Previous captured pins were moved to the staged importer."
      : "",
  };
  await browser.storage.local.set({ run: migrated });
  return migrated;
}

async function wakeWorker() {
  if (activeRun) return;
  const run = await migrateCheckpoint();
  if (!["running", "waiting"].includes(run.status) || run.kind !== "stage")
    return;
  activeRun = true;
  try {
    await processQueue();
  } finally {
    activeRun = false;
  }
}

browser.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === IMPORT_ALARM) return wakeWorker();
  if (alarm.name === RESUME_ALARM) return autoResume();
});
browser.runtime.onStartup.addListener(async () => {
  const run = await migrateCheckpoint();
  if (["running", "waiting", "capturing"].includes(run.status)) {
    await pauseRun("Firefox restarted. Resume the staged import when ready.");
    return;
  }
  if (
    run.status === "paused" &&
    run.kind === "stage" &&
    run.blockKind !== "captcha" &&
    run.resumeNotBefore
  )
    await browser.alarms.create(RESUME_ALARM, {
      when: new Date(run.resumeNotBefore).getTime(),
    });
});

browser.runtime.onMessage.addListener(async (message) => {
  if (message.type === "prepareVerification")
    return prepareVerification(message.settings);
  if (message.type === "connectionStatus") return reconcileConnection();
  if (message.type === "permissionDenied")
    return setConnection({
      state: "failed",
      message: permissionMessage,
      verifiedAt: null,
    });
  if (message.type === "status") return refreshInventory();
  if (message.type === "discoveryProgress") {
    const { run = {} } = await browser.storage.local.get("run");
    if (run.status === "capturing")
      return setRun({
        discovered: message.discovered,
        discoveryStable: message.stable,
      });
  }
  if (message.type === "capture") return captureBoard(message.tabId);
  if (message.type === "startStage")
    return startStage(message.stage, message.mode || "pending", message.tabId);
  if (message.type === "resume") return resumeRun(message.tabId);
  if (message.type === "pause") {
    const { run = {} } = await browser.storage.local.get("run");
    await setRun({ pauseRequested: true, status: "pause_requested" });
    activeController?.abort("pause");
    if (activePinTabId) {
      await browser.tabs.remove(activePinTabId).catch(() => {});
      activePinTabId = null;
    }
    if (["waiting", "retry_wait"].includes(run.status))
      return pauseRun("", { resetConsecutive: true });
  }
});

browser.permissions.onAdded.addListener(async (permissions) => {
  const { connection = {} } = await browser.storage.local.get("connection");
  if (
    connection.origin &&
    permissions.origins?.includes(permissionOrigin(connection.origin))
  )
    await verifyConnection({
      origin: connection.origin,
      username: connection.username,
    });
});
browser.permissions.onRemoved.addListener(async (permissions) => {
  const { connection = {} } = await browser.storage.local.get("connection");
  if (
    connection.state === "verified" &&
    connection.origin &&
    permissions.origins?.includes(permissionOrigin(connection.origin))
  )
    await setConnection({
      state: "failed",
      message:
        "Firefox site permission was removed. Edit the connection to grant it again.",
      verifiedAt: null,
    });
});
