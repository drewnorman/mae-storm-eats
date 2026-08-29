const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function pinId(url) {
  return String(url || "").match(/\/pin\/(\d+)/)?.[1] || "";
}

function profileFromPage() {
  const links = [...document.querySelectorAll('a[href^="/"]')];
  const profile = links.find(
    (link) =>
      link.querySelector("img") &&
      /^\/[^/]+\/?$/.test(new URL(link.href, location.href).pathname),
  );
  const username = profile
    ? new URL(profile.href, location.href).pathname
        .split("/")
        .filter(Boolean)[0]
    : "";
  const accountId =
    document.querySelector("[data-test-id='header-profile']")?.dataset
      ?.userId ||
    document.querySelector("[data-user-id]")?.dataset?.userId ||
    "";
  return { id: accountId, username, profile_url: profile?.href || "" };
}

function authorFromNode(node) {
  const link = node.querySelector('a[href^="/"]');
  const profileUrl = link?.href || "";
  return {
    id: node.dataset.userId || link?.dataset.userId || "",
    username: profileUrl
      ? new URL(profileUrl).pathname.split("/").filter(Boolean)[0] || ""
      : "",
    profile_url: profileUrl,
  };
}

function ownerMatches(author, owner) {
  return ["id", "username", "profile_url"].some(
    (key) => author[key] && owner[key] && author[key] === owner[key],
  );
}

function isVisibleChallengeElement(node, minimumWidth = 1, minimumHeight = 1) {
  if (!node) return false;
  if (
    typeof node.checkVisibility === "function" &&
    !node.checkVisibility({
      checkOpacity: true,
      checkVisibilityCSS: true,
    })
  )
    return false;
  const style = getComputedStyle(node);
  if (
    style.display === "none" ||
    style.visibility === "hidden" ||
    Number(style.opacity) === 0
  )
    return false;
  const rect = node.getBoundingClientRect();
  return rect.width >= minimumWidth && rect.height >= minimumHeight;
}

function detectBlock() {
  const haystack = `${location.href} ${document.title}`.toLowerCase();
  const captchaFrame = [...document.querySelectorAll("iframe")].find(
    (frame) =>
      /recaptcha|hcaptcha|captcha/i.test(frame.src || "") &&
      isVisibleChallengeElement(frame, 100, 50),
  );
  if (captchaFrame)
    return {
      blocked: true,
      blockKind: "captcha",
      blockEvidence: "visible_captcha_iframe",
      reason: "Pinterest displayed a visible CAPTCHA frame.",
    };
  const promptCandidates = [
    ...document.querySelectorAll("[role='dialog'], form"),
  ];
  if (/captcha|challenge|checkpoint/.test(haystack)) {
    promptCandidates.push(...document.querySelectorAll("main"), document.body);
  }
  const verificationPrompt = promptCandidates.find(
    (node) =>
      isVisibleChallengeElement(node) &&
      /complete (?:this |the )?captcha|verify (?:that )?you(?:'|’)re (?:a )?human|verify your identity|i(?:'|’)m not a robot/i.test(
        node.innerText || "",
      ),
  );
  if (verificationPrompt)
    return {
      blocked: true,
      blockKind: "captcha",
      blockEvidence: "visible_verification_prompt",
      reason: "Pinterest displayed a visible human-verification prompt.",
    };
  const bodyText = (document.body?.innerText || "").toLowerCase();
  const rateLimitText = /too many requests|try again later|rate limit/.test(
    bodyText,
  );
  if (rateLimitText)
    return {
      blocked: true,
      blockKind: "rate_limited",
      reason: "Pinterest showed a rate-limit notice.",
    };
  const hasLoginForm = !!document.querySelector("input[type='password']");
  const loginText = /log in to pinterest|log in to continue|checkpoint/.test(
    bodyText,
  );
  if (/login|checkpoint/.test(haystack) || hasLoginForm || loginText)
    return {
      blocked: true,
      blockKind: "login",
      reason: "Pinterest login or checkpoint detected.",
    };
  return { blocked: false };
}

async function expandThreads(timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let unchanged = 0;
  while (Date.now() < deadline && unchanged < 3) {
    const state = await browser.storage.local.get("run");
    if (state.run?.pauseRequested) return false;
    const controls = [
      ...document.querySelectorAll("button, [role='button']"),
    ].filter((node) =>
      /more comments|view replies|more replies|load more|show more/i.test(
        node.textContent || node.getAttribute("aria-label") || "",
      ),
    );
    if (!controls.length) {
      unchanged += 1;
    } else {
      unchanged = 0;
      controls.forEach((control) => control.click());
    }
    await wait(500);
  }
  return unchanged >= 3;
}

function collectComments(owner) {
  const nodes = [
    ...document.querySelectorAll(
      "[data-comment-id], [data-test-id*='comment-item'], [data-test-id*='reply-item']",
    ),
  ];
  return nodes.flatMap((node) => {
    const author = authorFromNode(node);
    if (!ownerMatches(author, owner)) return [];
    const bodyNode = node.querySelector(
      "[data-test-id='comment-description'], [data-test-id='comment-text'], [dir='auto']",
    );
    const photos = [...node.querySelectorAll("img")]
      .map((image) => image.currentSrc || image.src)
      .filter((url) => /pinimg\.com/.test(url));
    const body = bodyNode?.textContent?.trim() || "";
    if (!body && !photos.length) return [];
    return [
      {
        id: node.dataset.commentId || node.id || "",
        parent_id:
          node.dataset.parentCommentId ||
          node.closest("[data-comment-id]")?.dataset.commentId ||
          "",
        body,
        timestamp:
          node.querySelector("time")?.dateTime ||
          node.querySelector("time")?.getAttribute("datetime") ||
          "",
        author,
        photos: photos.map((url, index) => ({
          id: `${node.dataset.commentId || "photo"}-${index}`,
          url,
        })),
      },
    ];
  });
}

browser.runtime.onMessage.addListener(async (message) => {
  if (message.type === "ping") {
    return { ready: true, url: location.href };
  }
  if (message.type === "discover") {
    const initialBlock = detectBlock();
    if (initialBlock.blocked) return initialBlock;
    const pins = new Map();
    let stable = 0;
    while (stable < 15) {
      const state = await browser.storage.local.get("run");
      if (state.run?.pauseRequested)
        return { paused: true, pins: [...pins.values()] };
      const block = detectBlock();
      if (block.blocked) return { ...block, pins: [...pins.values()] };
      const before = pins.size;
      document.querySelectorAll('a[href*="/pin/"]').forEach((link) => {
        const id = pinId(link.href);
        if (id)
          pins.set(id, {
            pin_id: id,
            pin_url: new URL(link.href, location.href).href,
            board_url: location.href,
          });
      });
      stable = pins.size === before ? stable + 1 : 0;
      browser.runtime
        .sendMessage({
          type: "discoveryProgress",
          discovered: pins.size,
          stable,
        })
        .catch(() => {});
      window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: "smooth",
      });
      await wait(750);
    }
    return { pins: [...pins.values()], owner: profileFromPage() };
  }
  if (["resolveRecipe", "resolveReviews"].includes(message.type)) {
    const block = detectBlock();
    if (block.blocked) return block;
    const owner = profileFromPage();
    const outbound = [...document.querySelectorAll("a[href^='http']")].find(
      (link) => !/pinterest\.com|pinimg\.com/.test(new URL(link.href).hostname),
    );
    if (message.type === "resolveRecipe") {
      return {
        owner,
        pin_url: location.href,
        board_url: message.pin.board_urls?.[0] || "",
        source_url: outbound?.href || "",
      };
    }
    const complete = await expandThreads();
    return {
      owner,
      pin_url: location.href,
      comments: collectComments(owner),
      comments_complete: complete,
    };
  }
});
