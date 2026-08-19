const message = document.querySelector("#message");
const connectionMessage = document.querySelector("#connection-message");
const progress = document.querySelector("#progress");
const connectionRequired = document.querySelector("#connection-required");
const verifiedSummary = document.querySelector("#verified-summary");
const verifiedOrigin = document.querySelector("#verified-origin");
const verifiedUsername = document.querySelector("#verified-username");
const runSection = document.querySelector(".run");
const feedbackSection = document.querySelector(".feedback");
const capture = document.querySelector("#capture");
const recipes = document.querySelector("#recipes");
const reviews = document.querySelector("#reviews");
const retryRecipes = document.querySelector("#retry-recipes");
const refreshRecipes = document.querySelector("#refresh-recipes");
const retryReviews = document.querySelector("#retry-reviews");
const refreshReviews = document.querySelector("#refresh-reviews");
const recipeCount = document.querySelector("#recipe-count");
const reviewCount = document.querySelector("#review-count");
const captureCount = document.querySelector("#capture-count");
const pause = document.querySelector("#pause");
const resume = document.querySelector("#resume");
const manualReviewNote = document.querySelector("#manual-review-note");
const resumeCountdown = document.querySelector("#resume-countdown");
const pauseHistorySection = document.querySelector("#pause-history");
const pauseHistoryList = document.querySelector("#pause-history-list");
const failureLogSection = document.querySelector("#failure-log");
const failureLogList = document.querySelector("#failure-log-list");

const BLOCK_KIND_LABELS = {
  captcha: "CAPTCHA",
  login: "login wall",
  rate_limited: "rate limited",
  service: "connection issue",
  unknown: "blocked",
};

function blockKindLabel(kind) {
  return BLOCK_KIND_LABELS[kind] || kind;
}

let countdownTimer = null;

function clearCountdown() {
  if (countdownTimer) {
    clearInterval(countdownTimer);
    countdownTimer = null;
  }
}

function formatCountdown(ms) {
  const totalSeconds = Math.max(0, Math.round(ms / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, "0")}`;
}

function showActions(run) {
  const busy = ["capturing", "running", "waiting", "pause_requested"].includes(
    run.status,
  );
  const paused = run.status === "paused";
  [
    capture,
    recipes,
    reviews,
    retryRecipes,
    refreshRecipes,
    retryReviews,
    refreshReviews,
  ].forEach((button) => {
    button.disabled = busy || paused;
  });
  pause.hidden = !busy;
  resume.hidden = !paused;
}

function renderResumeState(run) {
  clearCountdown();
  const paused = run.status === "paused";
  const resumeNotBefore = run.resumeNotBefore
    ? new Date(run.resumeNotBefore).getTime()
    : null;
  const eligible = !resumeNotBefore || Date.now() >= resumeNotBefore;
  if (paused) resume.disabled = !eligible;
  resumeCountdown.hidden = !(paused && resumeNotBefore && !eligible);
  if (!(paused && resumeNotBefore && !eligible)) return;
  const label = run.blockKind ? ` (${blockKindLabel(run.blockKind)})` : "";
  const update = () => {
    const remaining = resumeNotBefore - Date.now();
    if (remaining <= 0) {
      resume.disabled = false;
      resumeCountdown.hidden = true;
      clearCountdown();
      return;
    }
    resumeCountdown.textContent = `Auto-resumes in ${formatCountdown(remaining)}${label}`;
  };
  update();
  countdownTimer = setInterval(update, 1000);
}

function renderManualReview(run) {
  const count = run.manualReviewExcluded || 0;
  manualReviewNote.hidden = !count;
  if (count)
    manualReviewNote.textContent = `${count} pin${count === 1 ? "" : "s"} failed repeatedly and need manual review in WordPress — excluded from auto-retry.`;
}

function renderPauseHistory(history = []) {
  pauseHistorySection.hidden = history.length === 0;
  pauseHistoryList.innerHTML = "";
  history.forEach((entry) => {
    const li = document.createElement("li");
    const at = new Date(entry.at).toLocaleString([], {
      dateStyle: "short",
      timeStyle: "short",
    });
    const kind = entry.blockKind ? `${blockKindLabel(entry.blockKind)}: ` : "";
    li.textContent = `${at} — ${kind}${entry.reason || "Paused"}`;
    pauseHistoryList.appendChild(li);
  });
}

function renderFailureLog(log = []) {
  failureLogSection.hidden = log.length === 0;
  failureLogList.innerHTML = "";
  log.forEach((entry) => {
    const li = document.createElement("li");
    const at = new Date(entry.at).toLocaleString([], {
      dateStyle: "short",
      timeStyle: "short",
    });
    li.textContent = `${at} — pin ${entry.pinId} (${entry.stage}): ${entry.reason || "Failed"}`;
    failureLogList.appendChild(li);
  });
}

function stageText(counts = {}) {
  return `${counts.pending || 0} ready · ${counts.complete || 0} complete · ${counts.failed || 0} failed`;
}

function renderInventory(inventory = {}) {
  recipeCount.textContent = stageText(inventory.recipe);
  reviewCount.textContent = stageText(inventory.reviews);
  const total = Object.values(inventory.recipe || {}).reduce(
    (sum, value) => sum + Number(value || 0),
    0,
  );
  captureCount.textContent = total
    ? `${total} pins saved`
    : "Open a Pinterest board";
  recipes.disabled = (inventory.recipe?.pending || 0) === 0;
  retryRecipes.disabled = (inventory.recipe?.failed || 0) === 0;
  refreshRecipes.disabled = (inventory.recipe?.complete || 0) === 0;
  reviews.disabled = (inventory.reviews?.pending || 0) === 0;
  retryReviews.disabled = (inventory.reviews?.failed || 0) === 0;
  refreshReviews.disabled = (inventory.reviews?.complete || 0) === 0;
}

function renderRun(run) {
  renderInventory(run.inventory);
  showActions(run);
  renderResumeState(run);
  renderManualReview(run);
  const discovered = run.discovered || run.pins?.length || 0;
  const queued = run.queue?.length || (run.kind === "capture" ? discovered : 0);
  const completed = run.completed || 0;
  const failed = run.failed || 0;
  const skipped = run.skipped || 0;

  if (run.status === "capturing") {
    message.textContent = `Capturing board — ${discovered} pin${discovered === 1 ? "" : "s"} found…`;
    progress.removeAttribute("value");
    progress.max = 1;
    return;
  }
  if (run.status === "running") {
    message.textContent = `${run.stage === "reviews" ? "Reviews" : "Recipes"}: ${run.cursor || 0} of ${queued}${run.currentPin ? ` — pin ${run.currentPin}` : ""}${failed ? ` (${failed} failed)` : ""}…`;
  } else if (run.status === "waiting") {
    const retryAt = run.nextPinAt
      ? new Date(run.nextPinAt).toLocaleTimeString([], {
          hour: "numeric",
          minute: "2-digit",
          second: "2-digit",
        })
      : "shortly";
    message.textContent = `${completed + failed} of ${queued} processed. Next pin at ${retryAt}.`;
  } else if (run.status === "pause_requested") {
    message.textContent =
      run.kind === "capture"
        ? "Pausing pin capture…"
        : "Pausing the current batch…";
  } else if (run.status === "paused") {
    message.textContent =
      run.error || `Paused at ${completed + failed} of ${queued}.`;
  } else if (run.status === "complete") {
    message.textContent =
      run.message ||
      run.error ||
      `Batch complete: ${completed} completed${failed ? `, ${failed} failed` : ""}.`;
  } else {
    message.textContent = "Choose an import stage.";
  }
  progress.max = Math.max(queued, 1);
  progress.value = completed + failed;
}

function connectionStatusMessage(connection) {
  if (["pending", "verifying"].includes(connection.state)) {
    return "Connection setup is in progress.";
  }
  return "";
}

function renderConnection(connection = {}) {
  const verified = connection.state === "verified";
  connectionRequired.hidden = verified;
  verifiedSummary.hidden = !verified;
  runSection.hidden = !verified;
  feedbackSection.hidden = !verified;
  connectionMessage.textContent = connectionStatusMessage(connection);

  if (verified) {
    verifiedOrigin.textContent = connection.origin || "";
    verifiedUsername.textContent = connection.username || "";
  }
}

browser.storage.local
  .get(["connection", "run", "pauseHistory", "failureLog"])
  .then(({ connection = {}, run = {}, pauseHistory = [], failureLog = [] }) => {
    renderRun(run);
    renderConnection(connection);
    renderPauseHistory(pauseHistory);
    renderFailureLog(failureLog);
    if (connection.state === "verified")
      browser.runtime.sendMessage({ type: "status" }).catch(() => {});
  })
  .catch(() => {});

function openConnectionSetup() {
  browser.runtime.openOptionsPage().catch((error) => {
    connectionMessage.textContent = error.message || "Could not open setup.";
  });
}

document
  .querySelector("#open-setup")
  .addEventListener("click", openConnectionSetup);
document
  .querySelector("#edit-connection")
  .addEventListener("click", openConnectionSetup);

async function activeTab() {
  const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
  return tab;
}

async function capturePins() {
  const tab = await activeTab();
  if (
    !tab?.url?.startsWith("https://www.pinterest.com/") ||
    /\/pin\/\d+/.test(tab.url)
  ) {
    message.textContent = "Open a Pinterest board first.";
    return;
  }
  try {
    await browser.runtime.sendMessage({ type: "capture", tabId: tab.id });
  } catch (error) {
    message.textContent = `Capture failed: ${error.message || "Could not capture this board."}`;
  }
}

async function startStage(stage, mode = "pending") {
  const tab = await activeTab();
  try {
    await browser.runtime.sendMessage({
      type: "startStage",
      stage,
      mode,
      tabId: tab.id,
    });
  } catch (error) {
    message.textContent = `Import failed: ${error.message || "Could not start this batch."}`;
  }
}

capture.addEventListener("click", capturePins);
recipes.addEventListener("click", () => startStage("recipe"));
reviews.addEventListener("click", () => startStage("reviews"));
retryRecipes.addEventListener("click", () => startStage("recipe", "retry"));
refreshRecipes.addEventListener("click", () => startStage("recipe", "refresh"));
retryReviews.addEventListener("click", () => startStage("reviews", "retry"));
refreshReviews.addEventListener("click", () =>
  startStage("reviews", "refresh"),
);
pause.addEventListener("click", () =>
  browser.runtime.sendMessage({ type: "pause" }),
);
resume.addEventListener("click", async () => {
  const tab = await activeTab();
  browser.runtime.sendMessage({ type: "resume", tabId: tab.id });
});

browser.runtime.onMessage.addListener((event) => {
  if (event.type === "progress") renderRun(event.run);
  if (event.type === "connectionState") renderConnection(event.connection);
});

browser.storage.onChanged.addListener((changes, area) => {
  if (area !== "local") return;
  if (changes.run?.newValue) renderRun(changes.run.newValue);
  if (changes.connection?.newValue)
    renderConnection(changes.connection.newValue);
  if (changes.pauseHistory)
    renderPauseHistory(changes.pauseHistory.newValue || []);
  if (changes.failureLog) renderFailureLog(changes.failureLog.newValue || []);
});
