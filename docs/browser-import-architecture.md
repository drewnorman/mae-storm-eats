# Browser import architecture

Pinterest provenance is part of the recipe model, but Pinterest collection is
deliberately a browser responsibility. A Firefox WebExtension runs in the
owner's existing Pinterest session and performs three independent operations:
board pin capture, destination discovery, and review/photo collection.

WordPress owns the durable pin inventory and its independent recipe/review stage
statuses in addition to candidate, recipe, review, and media records. It fetches
recipe destination pages with public-address enforcement and extracts
schema.org Recipe JSON-LD. It never signs in to Pinterest, crawls boards,
schedules Pinterest work, or receives Pinterest session material.

Board capture never opens individual pins. Destination and review stages obtain
server-backed batches of at most 25 saved pin URLs and space direct pin visits
with a randomized, non-fixed delay and an escalating cooldown if Pinterest
pushes back (see `docs/browser-import.md`). Once a
destination is captured, recipe extraction bypasses Pinterest entirely. Review
collection is deferred to its own explicit stage.

The extension authenticates to WordPress as a non-interactive service identity
with only `read` and `mae_storm_eats_import_recipes`. The owner's normal editorial
account is not stored in the extension. Imports are additive and resumable:
normal queues select only pending work, failures and completed records require
explicit retry or refresh actions, and a later capture never deletes recipes,
reviews, or media.
