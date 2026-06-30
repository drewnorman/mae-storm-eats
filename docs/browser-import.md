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
waits 30 seconds before visiting the next pin. WordPress fetches the outbound
site, extracts Recipe JSON-LD, creates the candidate, and imports its schema
image without contacting Pinterest.

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
the original board. Capturing can resume only from the saved board because it
depends on the board document.

Pin-specific extraction and media failures are recorded and the batch
continues. Pinterest login/checkpoint pages, an owner-account mismatch,
authentication errors, or HTTP 429 throttling pause the whole batch. Firefox
restart also pauses active work for explicit resume. Pause closes the temporary
pin tab and keeps all completed server-side work.

## Security

Installed host access is limited to `www.pinterest.com` and Pinterest's
`pinimg.com` image hosts. The extension asks for access only to the configured
WordPress origin. WordPress accepts imports only from users with
`mae_storm_import_recipes`; revoking the Application Password disables the
extension. Destination fetching retains public-address validation, HTTP/HTTPS
enforcement, timeouts, and response-size limits. Uploaded media remains limited
to content-sniffed JPEG, PNG, WebP, or GIF files up to 10 MB.
