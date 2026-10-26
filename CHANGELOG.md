# Changelog

All notable changes to the backend, theme, plugin, and private Firefox extension
are documented here. These components share one version and must be used from
the same release tag.

## [0.2.0](https://github.com/drewnorman/mae-storm-eats/compare/v0.1.0...v0.2.0) (2026-10-26)


### Features

* **admin:** add browser import status and review filters ([c76bf12](https://github.com/drewnorman/mae-storm-eats/commit/c76bf1250d6aa5238964864003c0627810c7b68e))
* **admin:** add recipe review metadata UI ([3e241f3](https://github.com/drewnorman/mae-storm-eats/commit/3e241f3abe396c1d6038bf4bd47eb8a51ab757d6))
* **admin:** add structured recipe review editors ([22c1653](https://github.com/drewnorman/mae-storm-eats/commit/22c16533d50e6cbb9b1052839babf86081356d04))
* **auth:** add private recipe access gate ([11c6a9b](https://github.com/drewnorman/mae-storm-eats/commit/11c6a9b8db5dee5deb0fcf2bb4cc3ff34fc89577))
* **extension:** add resumable Firefox board collector ([14587ea](https://github.com/drewnorman/mae-storm-eats/commit/14587ea8583eadf437eaf9ebe8961a2096637ae9))
* **extension:** harden Pinterest import recovery ([fe6dd94](https://github.com/drewnorman/mae-storm-eats/commit/fe6dd944394a5160512b2cc5f83aaedb771e9590))
* **import:** add recipe candidate persistence ([dab4fb7](https://github.com/drewnorman/mae-storm-eats/commit/dab4fb74c6d3ee96f42f1d4c7fcb923daeedd78b))
* **import:** add restricted browser import service contract ([d166acd](https://github.com/drewnorman/mae-storm-eats/commit/d166acdaf3b34764edd7bf25e6507bfd86f4c93a))
* **import:** backfill recipe metadata and featured images ([c95bee3](https://github.com/drewnorman/mae-storm-eats/commit/c95bee34d51ec4f9efaa17114d095a9a17bc2ba5))
* **import:** connect Firefox collection to recipe review ([a4ba526](https://github.com/drewnorman/mae-storm-eats/commit/a4ba52676e80712edadd321dc3b09ae1aea5732e))
* **import:** extract schema.org recipes from protected sources ([183f93d](https://github.com/drewnorman/mae-storm-eats/commit/183f93d07734097dc9d81cf3d93ded9fa3c8d62c))
* **import:** reconcile owner reviews and local media ([a681047](https://github.com/drewnorman/mae-storm-eats/commit/a681047f7fc3c7704f51c7d997928229287d3b84))
* **ops:** add production launch automation ([bd6b6f7](https://github.com/drewnorman/mae-storm-eats/commit/bd6b6f755dea5567c1114e6f16e274c72f2ccb57))
* **plugin:** add recipes plugin data model ([99b5890](https://github.com/drewnorman/mae-storm-eats/commit/99b58905a5a5710975c8196e8cdddbcd0123debf))
* **release:** add production artifact handoff ([2f55719](https://github.com/drewnorman/mae-storm-eats/commit/2f55719162afee6d6ba39bd263bcd2121d0097e7))
* **theme:** add Timber recipe theme ([98552bc](https://github.com/drewnorman/mae-storm-eats/commit/98552bc5f5acae8a40cf6d14bfddcd4bb40e0dd2))
* **theme:** build private recipe browsing views ([1d4e206](https://github.com/drewnorman/mae-storm-eats/commit/1d4e20645a1fcc1d0cebd5457f281b621d342d66))
* **theme:** polish private recipe browsing experience ([380459a](https://github.com/drewnorman/mae-storm-eats/commit/380459a6fdcc04545478ba93c2deac73f26f18db))


### Bug Fixes

* **ci:** accept release-generated manifest formatting ([abb6603](https://github.com/drewnorman/mae-storm-eats/commit/abb66034d4fcfa7f47ddd36c4d1738d8feb5b7c8))
* **ci:** publish release assets from release workflow ([d62341f](https://github.com/drewnorman/mae-storm-eats/commit/d62341f5ae947968165d34139ce4b0bed77b1a7a))
* **ci:** validate release output and boundary checks ([ae844d1](https://github.com/drewnorman/mae-storm-eats/commit/ae844d1e4e8f3bd029b99c401dfe38a78fa65c8d))
* **extension:** preserve blocked import context ([f16e493](https://github.com/drewnorman/mae-storm-eats/commit/f16e493206796af19f3519154593decc5fe4caa7))
* **ops:** make first production launch recoverable ([bd96b30](https://github.com/drewnorman/mae-storm-eats/commit/bd96b305990a0633d7fda632e52630642d798fa3))
* **ops:** restrict production deployment through IAP ([661049a](https://github.com/drewnorman/mae-storm-eats/commit/661049a28805e015f354d06945d0c4eb9e1bd85a))

## 0.1.0 (2026-08-28)

- Initial production release contract for the WordPress application.
- Private temporary Firefox importer with stable extension ID.
