# Tests

This directory contains direct PHP and JavaScript tests. Executable integration
and smoke-test entrypoints live in `scripts/test`.

`just check` runs strict static and application checks. `just test-dev-boot`
bootstraps a clean Podman stack twice and verifies WordPress, the local
administrator, the plugin, the theme, Timber, assets, and HTTP rendering.
`just test-artifact` verifies complete, byte-reproducible release archives.
