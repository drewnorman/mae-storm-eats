# Mae Storm Eats Plugin

First-party WordPress plugin for the private eats.maestorm.com recipe app.

## Current Behavior

- Registers the `recipe` custom post type.
- Registers recipe taxonomies for cuisine, course, diet, and recipe tags.
- Registers typed recipe meta placeholders for:
  - source URLs and Pinterest URLs
  - canonical Pinterest pin IDs
  - image references
  - servings
  - prep, cook, and total time in minutes
  - difficulty
  - import status and import errors
  - structured recipe data for future ingredients, steps, notes, nutrition, and raw extracted artifacts
- Adds a REST authentication filter stub for recipe routes.
- Adds WP-CLI commands under `wp mae-storm eats`.
- Performs activation checks for PHP 8.3+ and WordPress 6.5+.
- Provides recipe view helpers consumed by the first-party Timber theme.
- Protects frontend access through a shared-password gate.
- Imports Pinterest boards, owner reviews, replies, and photos through a
  restricted Firefox WebExtension and capability-protected REST routes.

The plugin loads through the repository Composer PSR-4 mapping:

```json
"MaeStorm\\Eats\\": "plugins/mae-storm-eats/src/"
```

A small fallback autoloader is present so the plugin can still load in an early
WordPress checkout before Composer dependencies have been installed.

## Theme Contract

Timber is a required app dependency. The plugin renders the private frontend
login screen with the `mae_storm_eats_private_login_template` filter,
defaulting to `private-login.twig`. The active Mae Storm Eats theme provides
that template through its registered Timber locations.

## Browser Import

The plugin creates the restricted `recipe_importer` role and non-interactive
`pinterest-importer` user during its versioned upgrade. An administrator creates
a revocable Application Password for that user and supplies it to the Firefox
extension. See `docs/browser-import.md`.
