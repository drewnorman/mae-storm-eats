import type {
  ConnectionState,
  ExtensionMessage,
  FailureEntry,
  Inventory,
  PauseEntry,
  QueueMode,
  RunState,
  Stage,
  StageCounts,
} from "./types";

function requiredElement<T extends Element>(selector: string): T {
  const element = document.querySelector<T>(selector);
  if (!element) throw new Error(`Missing popup element: ${selector}`);
  return element;
}

const message = requiredElement<HTMLElement>("#message");
const connectionMessage = requiredElement<HTMLElement>("#connection-message");
const progress = requiredElement<HTMLProgressElement>("#progress");
const connectionRequired = requiredElement<HTMLElement>("#connection-required");
const verifiedSummary = requiredElement<HTMLElement>("#verified-summary");
const verifiedOrigin = requiredElement<HTMLElement>("#verified-origin");
const verifiedUsername = requiredElement<HTMLElement>("#verified-username");
const runSection = requiredElement<HTMLElement>(".run");
const feedbackSection = requiredElement<HTMLElement>(".feedback");
const capture = requiredElement<HTMLButtonElement>("#capture");
const recipes = requiredElement<HTMLButtonElement>("#recipes");
const reviews = requiredElement<HTMLButtonElement>("#reviews");
const retryRecipes = requiredElement<HTMLButtonElement>("#retry-recipes");
const refreshRecipes = requiredElement<HTMLButtonElement>("#refresh-recipes");
const retryReviews = requiredElement<HTMLButtonElement>("#retry-reviews");
const refreshReviews = requiredElement<HTMLButtonElement>("#refresh-reviews");
const recipeCount = requiredElement<HTMLElement>("#recipe-count");
const reviewCount = requiredElement<HTMLElement>("#review-count");
const captureCount = requiredElement<HTMLElement>("#capture-count");
const pause = requiredElement<HTMLButtonElement>("#pause");
const resume = requiredElement<HTMLButtonElement>("#resume");
const manualReviewNote = requiredElement<HTMLElement>("#manual-review-note");
const resumeCountdown = requiredElement<HTMLElement>("#resume-countdown");
const pauseHistorySection = requiredElement<HTMLElement>("#pause-history");
const pauseHistoryList = requiredElement<HTMLElement>("#pause-history-list");
const failureLogSection = requiredElement<HTMLElement>("#failure-log");
const failureLogList = requiredElement<HTMLElement>("#failure-log-list");

const BLOCK_KIND_LABELS = {
  captcha: "CAPTCHA",
  login: "login wall",
  rate_limited: "rate limited",
  service: "connection issue",
  unknown: "blocked",
};

const BLOCK_EVIDENCE_LABELS = {
  visible_captcha_iframe: "visible CAPTCHA frame",
  visible_verification_prompt: "visible verification prompt",
};

function blockKindLabel(kind: string) {
  return BLOCK_KIND_LABELS[kind as keyof typeof BLOCK_KIND_LABELS] || kind;
}

let countdownTimer: ReturnType<typeof setInterval> | null = null;

function clearCountdown() {
  if (countdownTimer) {
    clearInterval(countdownTimer);
    countdownTimer = null;
  }
}

function formatCountdown(ms: number) {
  const totalSeconds = Math.max(0, Math.round(ms / 1000));
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, "0")}`;
}

function showActions(run: RunState) {
  const busy = ["capturing", "running", "waiting", "pause_requested"].includes(
    run.status || "",
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

function renderResumeState(run: RunState) {
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

function renderManualReview(run: RunState) {
  const count = run.manualReviewExcluded || 0;
  manualReviewNote.hidden = !count;
  if (count)
    manualReviewNote.textContent = `${count} pin${count === 1 ? "" : "s"} failed repeatedly and need manual review in WordPress — excluded from auto-retry.`;
}

function renderPauseHistory(history: PauseEntry[] = []) {
  pauseHistorySection.hidden = history.length === 0;
  pauseHistoryList.innerHTML = "";
  history.forEach((entry) => {
    const li = document.createElement("li");
    const at = new Date(entry.at).toLocaleString([], {
      dateStyle: "short",
      timeStyle: "short",
    });
    const kind = entry.blockKind ? `${blockKindLabel(entry.blockKind)}: ` : "";
    const evidence = entry.blockEvidence
      ? ` [${BLOCK_EVIDENCE_LABELS[entry.blockEvidence as keyof typeof BLOCK_EVIDENCE_LABELS] || entry.blockEvidence}]`
      : "";
    li.textContent = `${at} — ${kind}${entry.reason || "Paused"}${evidence}`;
    pauseHistoryList.appendChild(li);
  });
}

function renderFailureLog(log: FailureEntry[] = []) {
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

function stageText(counts: StageCounts = {}) {
  return `${counts.pending || 0} ready · ${counts.complete || 0} complete · ${counts.failed || 0} failed`;
}

function renderInventory(inventory: Inventory = {}) {
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

function renderRun(run: RunState) {
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

function connectionStatusMessage(connection: ConnectionState) {
  if (["pending", "verifying"].includes(connection.state || "")) {
    return "Connection setup is in progress.";
  }
  return "";
}

function renderConnection(connection: ConnectionState = {}) {
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

browser.storage.local
  .get(["connection", "run", "pauseHistory", "failureLog"])
  .then((stored: Record<string, unknown>) => {
    const connection = isRecord(stored.connection)
      ? (stored.connection as ConnectionState)
      : {};
    renderRun(isRecord(stored.run) ? (stored.run as RunState) : {});
    renderConnection(connection);
    renderPauseHistory(
      Array.isArray(stored.pauseHistory) ? stored.pauseHistory : [],
    );
    renderFailureLog(Array.isArray(stored.failureLog) ? stored.failureLog : []);
    if (connection.state === "verified")
      browser.runtime.sendMessage({ type: "status" }).catch(() => {});
  })
  .catch(() => {});

function openConnectionSetup() {
  browser.runtime.openOptionsPage().catch((error: unknown) => {
    connectionMessage.textContent =
      error instanceof Error ? error.message : "Could not open setup.";
  });
}

requiredElement<HTMLButtonElement>("#open-setup").addEventListener(
  "click",
  openConnectionSetup,
);
requiredElement<HTMLButtonElement>("#edit-connection").addEventListener(
  "click",
  openConnectionSetup,
);

async function activeTab() {
  const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
  return tab;
}

async function capturePins() {
  const tab = await activeTab();
  if (
    typeof tab?.id !== "number" ||
    !tab.url?.startsWith("https://www.pinterest.com/") ||
    /\/pin\/\d+/.test(tab.url)
  ) {
    message.textContent = "Open a Pinterest board first.";
    return;
  }
  try {
    await browser.runtime.sendMessage({ type: "capture", tabId: tab.id });
  } catch (error) {
    message.textContent = `Capture failed: ${error instanceof Error ? error.message : "Could not capture this board."}`;
  }
}

async function startStage(stage: Stage, mode: QueueMode = "pending") {
  const tab = await activeTab();
  if (typeof tab?.id !== "number") {
    message.textContent = "Open a Pinterest tab first.";
    return;
  }
  try {
    await browser.runtime.sendMessage({
      type: "startStage",
      stage,
      mode,
      tabId: tab.id,
    });
  } catch (error) {
    message.textContent = `Import failed: ${error instanceof Error ? error.message : "Could not start this batch."}`;
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
  if (typeof tab?.id !== "number") return;
  browser.runtime.sendMessage({ type: "resume", tabId: tab.id });
});

browser.runtime.onMessage.addListener((event) => {
  if (!isRecord(event)) return;
  if (event.type === "progress" && isRecord(event.run))
    renderRun(event.run as RunState);
  if (event.type === "connectionState" && isRecord(event.connection))
    renderConnection(event.connection as ConnectionState);
});

browser.storage.onChanged.addListener((changes, area) => {
  if (area !== "local") return;
  if (isRecord(changes.run?.newValue))
    renderRun(changes.run.newValue as RunState);
  if (isRecord(changes.connection?.newValue))
    renderConnection(changes.connection.newValue as ConnectionState);
  if (changes.pauseHistory)
    renderPauseHistory(changes.pauseHistory.newValue || []);
  if (changes.failureLog) renderFailureLog(changes.failureLog.newValue || []);
});
