import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const manifest = JSON.parse(readFileSync("extension/manifest.json", "utf8"));
assert.equal(manifest.manifest_version, 3);
assert.ok(manifest.browser_specific_settings.gecko.id);
assert.deepEqual(manifest.host_permissions, [
  "https://www.pinterest.com/*",
  "https://*.pinimg.com/*",
  "https://recipes.maestorm.com/*",
]);
assert.ok(!JSON.stringify(manifest).includes("<all_urls>"));

const collector = readFileSync("extension/collector.js", "utf8");
assert.match(collector, /stable < 15/);
assert.match(collector, /ownerMatches/);
assert.match(collector, /photo/);
assert.match(collector, /comments_complete/);
assert.match(collector, /cancelled/);

const background = readFileSync("extension/background.js", "utf8");
assert.match(background, /active: false/);
assert.match(background, /await delay\(2000\)/);
assert.match(background, /attempt <= 3/);
assert.match(background, /browser\.storage\.local/);
assert.match(background, /FormData/);
assert.doesNotMatch(background, /cookie|access.?token|oauth/i);

console.log("Firefox extension tests passed.");
