# Mae Storm Recipes Plugin

First-party WordPress plugin for the private recipes.maestorm.com recipe app.

## Current Behavior

- Registers the `recipe` custom post type.
- Registers recipe taxonomies for cuisine, course, diet, and recipe tags.
- Registers typed recipe meta placeholders for:
  - source URLs and Pinterest URLs
  - image references
  - servings
  - prep, cook, and total time in minutes
  - difficulty
  - import status and import errors
  - structured recipe data for future ingredients, steps, notes, nutrition, and raw extracted artifacts
- Adds a REST authentication filter stub for recipe routes.
- Adds WP-CLI command stubs under `wp mae-storm recipes`.
- Performs activation checks for PHP 8.3+ and WordPress 6.5+.

The plugin loads through the repository Composer PSR-4 mapping:

```json
"MaeStorm\\Recipes\\": "plugins/mae-storm-recipes/src/"
```

A small fallback autoloader is present so the plugin can still load in an early
WordPress checkout before Composer dependencies have been installed.

## Not Implemented Yet

- Shared-password frontend access gate.
- REST route blocking for unauthenticated frontend sessions.
- Import candidate storage, extraction adapters, duplicate review, or publish pipeline.
- Admin metaboxes or Gutenberg sidebar controls for structured recipe editing.
- Real WP-CLI import, retry, reparse, validate, or duplicate-scan commands.
- Theme templates and Timber integration.

## Future Phases

- Phase 4: replace the REST protection stub with the shared-password access gate
  and protect feeds, searches, recipe media, and recipe REST routes.
- Phase 5: add focused admin editing UI around the structured recipe meta.
- Phase 6: add import candidates, Pinterest/archive/DOCX/image adapters,
  duplicate detection, and real WP-CLI commands.
- Phase 7: connect the private Timber browsing experience to the recipe model.
