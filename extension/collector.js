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
    ? new URL(profile.href, location.href).pathname.split("/").filter(Boolean)[0]
    : "";
  const accountId =
    document.querySelector("[data-test-id='header-profile']")?.dataset?.userId ||
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

async function expandThreads(timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let unchanged = 0;
  while (Date.now() < deadline && unchanged < 3) {
    const controls = [
      ...document.querySelectorAll(
        "button, [role='button']",
      ),
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
  if (message.type === "discover") {
    const pins = new Map();
    let stable = 0;
    while (stable < 15) {
      const state = await browser.storage.local.get("run");
      if (state.run?.cancelled) return { cancelled: true, pins: [...pins.values()] };
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
      window.scrollTo({ top: document.documentElement.scrollHeight, behavior: "smooth" });
      await wait(750);
    }
    return { pins: [...pins.values()], owner: profileFromPage() };
  }
  if (message.type === "resolve") {
    if (/login|checkpoint|challenge/i.test(location.href + document.title)) {
      return { blocked: true, reason: "Pinterest login or checkpoint detected" };
    }
    const complete = await expandThreads();
    const owner = profileFromPage();
    const outbound = [...document.querySelectorAll("a[href^='http']")].find(
      (link) =>
        !/pinterest\.com|pinimg\.com/.test(new URL(link.href).hostname),
    );
    return {
      owner,
      pin_url: location.href,
      board_url: message.pin.board_url,
      source_url: outbound?.href || "",
      comments: collectComments(owner),
      comments_complete: complete,
    };
  }
});
