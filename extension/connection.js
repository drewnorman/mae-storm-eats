const connectionMessage = document.querySelector("#connection-message");
const host = document.querySelector("#host");
const username = document.querySelector("#username");
const password = document.querySelector("#password");
const connect = document.querySelector("#connect");
const spinner = connect.querySelector(".spinner");
const connectLabel = connect.querySelector("[data-connect-label]");
const credentialForm = document.querySelector("#credential-form");
const verifiedSummary = document.querySelector("#verified-summary");
const verifiedOrigin = document.querySelector("#verified-origin");
const verifiedUsername = document.querySelector("#verified-username");
const editConnection = document.querySelector("#edit-connection");
const cancelEdit = document.querySelector("#cancel-edit");

let connection = {};
let editing = false;
let savedSettings = {};
let connectionAttempted = false;

function renderConnection(next = {}) {
  connection = next;
  const verified = next.state === "verified";
  const showSummary = verified && !editing;
  const busy =
    connectionAttempted &&
    ["pending", "verifying"].includes(next.state) &&
    !editing;

  credentialForm.hidden = showSummary;
  verifiedSummary.hidden = !showSummary;
  cancelEdit.hidden = !editing;
  connectionMessage.textContent =
    connectionAttempted || verified ? next.message || "" : "";
  connect.disabled = busy;
  connect.setAttribute("aria-busy", String(busy));
  spinner.hidden = !busy;
  connectLabel.textContent = busy ? "Connecting…" : "Connect";

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
      "Enter a complete WordPress site URL beginning with http:// or https://.",
    );
  }
  if (!["http:", "https:"].includes(url.protocol) || !url.hostname) {
    throw new Error(
      "WordPress site URL must begin with http:// or https:// and include a hostname.",
    );
  }
  return url.origin;
}

function formSettings() {
  return {
    wordpressHost: host.value,
    username: username.value,
    applicationPassword: password.value,
  };
}

function persistDraft() {
  browser.storage.local.set({ draftSettings: formSettings() }).catch(() => {});
}

browser.storage.local
  .get(["settings", "draftSettings", "connection"])
  .then(
    ({
      settings = {},
      draftSettings = null,
      connection: savedConnection = {},
    }) => {
      const form = draftSettings || settings;
      savedSettings = settings;
      host.value = form.wordpressHost || "";
      username.value = form.username || "";
      password.value = form.applicationPassword || "";
      renderConnection(savedConnection);
    },
  )
  .catch(() => {});

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
    connectionMessage.textContent = error.message;
    host.setCustomValidity(error.message);
    host.reportValidity();
    return;
  }

  const settings = {
    wordpressHost,
    username: username.value.trim(),
    applicationPassword: password.value.trim(),
  };
  const draftWrite = browser.storage.local.set({ draftSettings: settings });
  const preparation = browser.runtime.sendMessage({
    type: "prepareVerification",
    settings,
  });
  const permissionRequest = browser.permissions.request({
    origins: [`${wordpressHost}/*`],
  });

  savedSettings = settings;
  host.value = wordpressHost;
  editing = false;
  renderConnection({
    state: "pending",
    origin: wordpressHost,
    username: settings.username,
    message: "Waiting for Firefox site permission…",
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
    type: granted ? "connectionStatus" : "permissionDenied",
  });
  if (next) renderConnection(next);
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
  if (event.type === "connectionState") renderConnection(event.connection);
});
