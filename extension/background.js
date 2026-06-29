const API = "https://recipes.maestorm.com/wp-json/mae-storm-recipes/v1";
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let activeRun = false;

async function credentials() {
  const { settings = {} } = await browser.storage.local.get("settings");
  if (!settings.username || !settings.applicationPassword) {
    throw new Error("WordPress service credentials are not configured.");
  }
  return {
    ...settings,
    authorization: `Basic ${btoa(`${settings.username}:${settings.applicationPassword}`)}`,
  };
}

async function api(path, options = {}) {
  const auth = await credentials();
  const response = await fetch(`${API}${path}`, {
    ...options,
    headers: {
      Authorization: auth.authorization,
      ...(options.body instanceof FormData
        ? {}
        : { "Content-Type": "application/json" }),
      ...options.headers,
    },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(body.message || `WordPress returned ${response.status}`);
  return body;
}

async function setRun(patch) {
  const { run = {} } = await browser.storage.local.get("run");
  const next = { ...run, ...patch, updatedAt: new Date().toISOString() };
  await browser.storage.local.set({ run: next });
  browser.runtime.sendMessage({ type: "progress", run: next }).catch(() => {});
  return next;
}

async function uploadPhotos(pin, result) {
  for (const comment of result.comments || []) {
    for (const photo of comment.photos || []) {
      const key = `${pin.pin_id}:${comment.id}:${photo.id}`;
      const { media = {} } = await browser.storage.local.get("media");
      if (media[key]?.complete) continue;
      try {
        const photoResponse = await fetch(photo.url, { credentials: "include" });
        if (!photoResponse.ok) throw new Error(`Photo returned ${photoResponse.status}`);
        const form = new FormData();
        form.append("photo", await photoResponse.blob(), `${photo.id}.jpg`);
        await api(`/pinterest-imports/${pin.pin_id}/comments/${encodeURIComponent(comment.id)}/media`, {
          method: "POST",
          body: form,
        });
        media[key] = { complete: true };
      } catch (error) {
        media[key] = { complete: false, error: error.message };
      }
      await browser.storage.local.set({ media });
    }
  }
}

async function resolvePin(pin) {
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    let tab;
    try {
      tab = await browser.tabs.create({ url: pin.pin_url, active: false });
      await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error("Pin load timed out")), 30000);
        const listener = (tabId, change) => {
          if (tabId === tab.id && change.status === "complete") {
            clearTimeout(timeout);
            browser.tabs.onUpdated.removeListener(listener);
            resolve();
          }
        };
        browser.tabs.onUpdated.addListener(listener);
      });
      const result = await browser.tabs.sendMessage(tab.id, { type: "resolve", pin });
      if (result.blocked) throw new Error(result.reason);
      await api(`/pinterest-imports/${pin.pin_id}/resolve`, {
        method: "POST",
        body: JSON.stringify(result),
      });
      await uploadPhotos(pin, result);
      return { status: result.comments_complete ? "complete" : "incomplete" };
    } catch (error) {
      if (attempt === 3 || /login|checkpoint/i.test(error.message)) {
        return { status: "failed", error: error.message };
      }
      await delay(2000 * attempt);
    } finally {
      if (tab?.id) await browser.tabs.remove(tab.id).catch(() => {});
    }
  }
}

async function runImport(tabId) {
  if (activeRun) return;
  activeRun = true;
  try {
    await setRun({ cancelled: false, status: "discovering" });
    const discovery = await browser.tabs.sendMessage(tabId, { type: "discover" });
    if (discovery.cancelled) return setRun({ status: "cancelled" });
    const response = await api("/pinterest-imports", {
      method: "POST",
      body: JSON.stringify({ pins: discovery.pins }),
    });
    const actions = new Map(response.instructions.map((item) => [item.pin_id, item]));
    const queue = discovery.pins.filter((pin) => actions.get(pin.pin_id)?.action === "resolve");
    await setRun({ status: "resolving", pins: discovery.pins, queue, completed: 0 });
    for (let index = 0; index < queue.length; index += 1) {
      const { run } = await browser.storage.local.get("run");
      if (run.cancelled) return setRun({ status: "cancelled" });
      if (index > 0) await delay(2000);
      const outcome = await resolvePin(queue[index]);
      const results = { ...(run.results || {}), [queue[index].pin_id]: outcome };
      await setRun({ completed: index + 1, results });
    }
    await setRun({ status: "complete" });
  } finally {
    activeRun = false;
  }
}

browser.runtime.onMessage.addListener(async (message) => {
  if (message.type === "status") return api("/pinterest-imports/status");
  if (message.type === "start") return runImport(message.tabId);
  if (message.type === "cancel") return setRun({ cancelled: true });
});
