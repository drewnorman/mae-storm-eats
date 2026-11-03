"use strict";
function requiredElement(selector) {
  const element = document.querySelector(selector);
  if (!element) throw new Error(`Missing connection element: ${selector}`);
  return element;
}
function isRecord(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
const connectionMessage = requiredElement("#connection-message");
const host = requiredElement("#host");
const username = requiredElement("#username");
const password = requiredElement("#password");
const connect = requiredElement("#connect");
const spinner = requiredElement(".spinner");
const connectLabel = requiredElement("[data-connect-label]");
const credentialForm = requiredElement("#credential-form");
const verifiedSummary = requiredElement("#verified-summary");
const verifiedOrigin = requiredElement("#verified-origin");
const verifiedUsername = requiredElement("#verified-username");
const editConnection = requiredElement("#edit-connection");
const cancelEdit = requiredElement("#cancel-edit");
let connection = {};
let editing = false;
let savedSettings = {};
let connectionAttempted = false;
function renderConnection(next = {}) {
  connection = next;
  const verified = next.state === "verified";
  const showSummary = verified && !editing;
  const busy = connectionAttempted && ["pending", "verifying"].includes(next.state || "") && !editing;
  credentialForm.hidden = showSummary;
  verifiedSummary.hidden = !showSummary;
  cancelEdit.hidden = !editing;
  connectionMessage.textContent = connectionAttempted || verified ? next.message || "" : "";
  connect.disabled = busy;
  connect.setAttribute("aria-busy", String(busy));
  spinner.hidden = !busy;
  connectLabel.textContent = busy ? "Connecting\u2026" : "Connect";
  if (verified) {
    verifiedOrigin.textContent = next.origin || "";
    verifiedUsername.textContent = next.username || "";
  }
}
function normalizedHost(value) {
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(
      "Enter a complete WordPress site URL beginning with http:// or https://."
    );
  }
  if (!["http:", "https:"].includes(url.protocol) || !url.hostname) {
    throw new Error(
      "WordPress site URL must begin with http:// or https:// and include a hostname."
    );
  }
  const localHttp = url.protocol === "http:" && ["localhost", "127.0.0.1", "::1"].includes(url.hostname);
  if (url.protocol !== "https:" && !localHttp) {
    throw new Error(
      "Production WordPress connections require HTTPS; HTTP is allowed only for localhost development."
    );
  }
  return url.origin;
}
function formSettings() {
  return {
    wordpressHost: host.value,
    username: username.value,
    applicationPassword: password.value
  };
}
function persistDraft() {
  browser.storage.local.set({ draftSettings: formSettings() }).catch(() => {
  });
}
browser.storage.local.get(["settings", "draftSettings", "connection"]).then((stored) => {
  const settings = isRecord(stored.settings) ? stored.settings : {};
  const form = isRecord(stored.draftSettings) ? stored.draftSettings : settings;
  const savedConnection = isRecord(stored.connection) ? stored.connection : {};
  savedSettings = settings;
  host.value = form.wordpressHost || "";
  username.value = form.username || "";
  password.value = form.applicationPassword || "";
  renderConnection(savedConnection);
}).catch(() => {
});
[host, username, password].forEach((input) => {
  input.addEventListener("input", () => {
    input.setCustomValidity("");
    persistDraft();
  });
});
connect.addEventListener("click", async () => {
  connectionAttempted = true;
  for (const input of [host, username, password]) {
    input.setCustomValidity("");
    if (!input.value.trim()) input.setCustomValidity("This field is required.");
    if (!input.reportValidity()) return;
  }
  let wordpressHost;
  try {
    wordpressHost = normalizedHost(host.value.trim());
  } catch (error) {
    const message = error instanceof Error ? error.message : "Invalid site URL.";
    connectionMessage.textContent = message;
    host.setCustomValidity(message);
    host.reportValidity();
    return;
  }
  const settings = {
    wordpressHost,
    username: username.value.trim(),
    applicationPassword: password.value.trim()
  };
  const draftWrite = browser.storage.local.set({ draftSettings: settings });
  const preparation = browser.runtime.sendMessage({
    type: "prepareVerification",
    settings
  });
  const permissionRequest = browser.permissions.request({
    origins: [`${wordpressHost}/*`]
  });
  savedSettings = settings;
  host.value = wordpressHost;
  editing = false;
  renderConnection({
    state: "pending",
    origin: wordpressHost,
    username: settings.username,
    message: "Waiting for Firefox site permission\u2026"
  });
  await draftWrite;
  await preparation;
  let granted = false;
  try {
    granted = await permissionRequest;
  } catch {
    granted = false;
  }
  const next = await browser.runtime.sendMessage({
    type: granted ? "connectionStatus" : "permissionDenied"
  });
  if (isRecord(next)) renderConnection(next);
});
editConnection.addEventListener("click", () => {
  editing = true;
  connectionAttempted = false;
  renderConnection(connection);
  host.focus();
});
cancelEdit.addEventListener("click", () => {
  host.value = savedSettings.wordpressHost || "";
  username.value = savedSettings.username || "";
  password.value = savedSettings.applicationPassword || "";
  persistDraft();
  editing = false;
  renderConnection(connection);
});
browser.runtime.onMessage.addListener((event) => {
  if (isRecord(event) && event.type === "connectionState" && isRecord(event.connection))
    renderConnection(event.connection);
});
