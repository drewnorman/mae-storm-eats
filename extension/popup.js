const message = document.querySelector("#message");
const progress = document.querySelector("#progress");
const username = document.querySelector("#username");
const password = document.querySelector("#password");

browser.storage.local.get(["settings", "run"]).then(({ settings = {}, run = {} }) => {
  username.value = settings.username || "";
  password.value = settings.applicationPassword || "";
  render(run);
});

function render(run) {
  message.textContent = run.status
    ? `Import ${run.status}: ${run.completed || 0} of ${run.queue?.length || 0}`
    : "Ready.";
  progress.max = Math.max(run.queue?.length || 1, 1);
  progress.value = run.completed || 0;
}

document.querySelector("#save").addEventListener("click", async () => {
  await browser.storage.local.set({
    settings: {
      username: username.value.trim(),
      applicationPassword: password.value.trim(),
    },
  });
  try {
    const status = await browser.runtime.sendMessage({ type: "status" });
    message.textContent = status.authenticated ? "WordPress connection verified." : "Connection failed.";
  } catch (error) {
    message.textContent = error.message;
  }
});

document.querySelector("#start").addEventListener("click", async () => {
  const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
  if (!tab?.url?.startsWith("https://www.pinterest.com/")) {
    message.textContent = "Open a Pinterest board first.";
    return;
  }
  await browser.runtime.sendMessage({ type: "start", tabId: tab.id });
});
document.querySelector("#cancel").addEventListener("click", () =>
  browser.runtime.sendMessage({ type: "cancel" }),
);
browser.runtime.onMessage.addListener((event) => {
  if (event.type === "progress") render(event.run);
});
