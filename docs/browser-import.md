# Firefox Pinterest browser import

The Firefox extension imports Pinterest recipes in three explicit stages. It
uses the Pinterest session already signed into Firefox, never asks for
Pinterest credentials, and never stores Pinterest cookies or tokens.

## Install

1. In WordPress, create the non-interactive `pinterest-importer` user with the
   **Recipe Importer** role and a revocable Application Password.
2. In Firefox, load `extension/manifest.json` from
   `about:debugging#/runtime/this-firefox`.
3. Open the extension, choose **Open connection setup**, and connect the
   restricted WordPress account. Use HTTPS outside trusted local development.

## Staged operation

### 1. Capture pins

Open the owner's Pinterest board and choose **Capture this board**. The
extension scrolls the board and sends only the discovered pin IDs, canonical
pin URLs, board URL, and runtime owner identity to WordPress. It does not open
individual pin pages or create recipe drafts. Repeating capture is idempotent
and preserves completed work.

### 2. Import recipes

Choose **Import next 10** under Import recipes from any browser tab. WordPress
returns up to ten pending saved pins. The extension opens each saved pin URL in
an inactive hidden tab, captures its outbound recipe URL, closes the tab, and
waits before visiting the next pin (see Recovery and throttling for the
pacing). WordPress fetches the outbound site, extracts Recipe JSON-LD, creates
the candidate, and imports its schema image without contacting Pinterest.

### 3. Import reviews and photos

Choose **Import next 10** under Import reviews & photos when convenient. Only
pins with a completed recipe stage are eligible. The extension revisits the pin
page, expands owner-authored comments and replies, uploads their photos, and
finalizes the review stage. Recipe pages are not fetched again.

Each stage displays ready, complete, and failed counts. Normal imports process
only ready records. **Retry failed** retries stage-specific failures, while
**Refresh completed** deliberately revisits the least-recently completed pins.

## Recovery and throttling

WordPress owns the durable pin inventory; Firefox stores only the active batch
checkpoint. Recipe and review batches resume from any tab and do not require
the original board. Capturing remains bound to the saved board URL because it
depends on that board document. Resume reuses the saved board tab when it is
still available. If it was closed or navigated away, reopen that exact board in
the active tab before choosing **Resume**; the extension will not silently
switch the capture to another board.

Pin-specific extraction and media failures are recorded and the batch
continues, up to 3 attempts per pin; a pin that keeps failing is excluded from
further automatic retries and flagged in the popup as needing manual review in
WordPress instead of being retried indefinitely.

The inter-pin wait is a randomized 25-55 seconds rather than a fixed interval,
and every 4-6 pins it takes a longer 2-4 minute break, so a batch's pacing
doesn't look like a fixed-interval script. A whole board's backlog is also
capped at 50 pins per day across the recipe and review stages combined; once
that cap is reached the batch pauses itself and resumes automatically the
next day.

Pinterest login/checkpoint pages, an owner-account mismatch, authentication
errors, or HTTP 429 throttling pause the whole batch. The extension now tells
these apart: only a visible CAPTCHA frame or explicit visible human-verification
prompt is treated as a CAPTCHA; hidden integration frames, incidental text, and
challenge-like URLs do not pause an import by themselves. A confirmed CAPTCHA
pauses indefinitely. Board capture directs you to its saved board tab. For a
recipe or review import, the extension reveals and focuses the exact pin tab
that was challenged, preserves it while paused, and reuses it for the same pin
after you solve the challenge and choose **Resume**. A login wall, rate limit,
or connection/server error schedules an automatic resume after an escalating
cooldown (10 minutes, then 30 minutes, then 2 hours, then a full day) that grows
the more times in a row the batch gets blocked. The
cooldown resets to the first tier once a batch completes cleanly. The popup
shows a live countdown and keeps the Resume button disabled until the
cooldown elapses, and records the last 10 pause events (with the detected
reason) so repeated patterns are visible instead of only the latest one.
Firefox restart also pauses active work, re-arming any pending auto-resume
cooldown on the next launch. Pause closes the temporary pin tab and keeps all
completed server-side work. Desktop notifications fire when a batch pauses,
auto-resumes, or completes, so a run doesn't need the popup kept open.

## Security

Installed host access is limited to `www.pinterest.com` and Pinterest's
`pinimg.com` image hosts. The extension asks for access only to the configured
WordPress origin. WordPress accepts imports only from users with
`mae_storm_eats_import_recipes`; revoking the Application Password disables the
extension. Destination fetching retains public-address validation, HTTP/HTTPS
enforcement, timeouts, and response-size limits. Uploaded media remains limited
to content-sniffed JPEG, PNG, WebP, or GIF files up to 10 MB.
