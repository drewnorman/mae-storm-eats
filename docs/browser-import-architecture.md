# Browser import architecture

Pinterest provenance is part of the recipe model, but Pinterest collection is
deliberately a browser responsibility. A Firefox WebExtension runs in the
owner's existing Pinterest session and submits discovered pin IDs, resolved
recipe destinations, owner-authored comments and replies, and comment-photo
manifests to a restricted WordPress REST service.

WordPress owns durable candidate, recipe, review, and media records. It fetches
recipe destination pages with public-address enforcement and extracts
schema.org Recipe JSON-LD. It never signs in to Pinterest, crawls boards,
schedules Pinterest work, or receives Pinterest session material.

The extension authenticates to WordPress as a non-interactive service identity
with only `read` and `mae_storm_import_recipes`. The owner's normal editorial
account is not stored in the extension. Imports are additive and resumable:
reviewed recipes are skipped, incomplete and failed pins are retried, and a
later scan never deletes recipes, reviews, or media.
