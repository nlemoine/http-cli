# Changelog

## [1.2.0](https://github.com/nlemoine/http-cli/compare/1.1.2...1.2.0) (2026-11-26)


### Features

* support Guzzle 8 ([#10](https://github.com/nlemoine/http-cli/issues/10)) ([a59256a](https://github.com/nlemoine/http-cli/commit/a59256a3dd18426518a4a26fd22334ba53e89925))


### Bug Fixes

* accept a float delay in the Guzzle handler ([4bbd65c](https://github.com/nlemoine/http-cli/commit/4bbd65cc2bdda19300fd38d27d4aa5c775d7b047))
* accept the options a Guzzle client passes to the handler ([5dbf01c](https://github.com/nlemoine/http-cli/commit/5dbf01c43dea3f2a8e601bbabe9562e39fb5a537))
* align the Guzzle handler with Guzzle 8 on three edge cases ([0ddadb6](https://github.com/nlemoine/http-cli/commit/0ddadb6e01acd751fc4c3ad28d9016dbd13a8a83))
* build $_REQUEST in the script's PHP process, as PHP does ([45c7441](https://github.com/nlemoine/http-cli/commit/45c74419ba3fe9423c5d595facadf6fcd2f3c369))
* follow PHP when request_order is set but empty ([e5982db](https://github.com/nlemoine/http-cli/commit/e5982dbf1673e1f76a3326ac5aaa54fb8252ccea))
* implement the session ID interfaces for PHP 8.6 ([35a99a8](https://github.com/nlemoine/http-cli/commit/35a99a896af118d8a65568c06772be3af8c350cf))
* keep the whole decoded body when the response is gzipped ([6055480](https://github.com/nlemoine/http-cli/commit/6055480e3354a3c4dcc96ab764298a564f6c19c9))
* make multipart file uploads work ([2cde307](https://github.com/nlemoine/http-cli/commit/2cde3078f9c7e0a74b3713781f24351972823cee))
* reject with a Guzzle transfer failure as is ([38c1dca](https://github.com/nlemoine/http-cli/commit/38c1dcad0c50b561d370ce3f5e60a2bfe8223f47))
* set the superglobals by name so $_ENV reaches the script ([fbabd0f](https://github.com/nlemoine/http-cli/commit/fbabd0ff1c4ce7881f2de9fe9254482358bcb638))


### Miscellaneous Chores

* add GitHub Sponsors to composer funding ([efc49af](https://github.com/nlemoine/http-cli/commit/efc49af317476e9a15f9300e6a724c3659252ba0))
* Add README badges ([05bc01b](https://github.com/nlemoine/http-cli/commit/05bc01b978418b7aba526424b6b283fba80b16ef))
* update PHP QA workflow reference to latest commit ([f32388c](https://github.com/nlemoine/http-cli/commit/f32388c189075170edd1424f51910031903e4633))
* update PHP QA workflow reference to latest version ([9766f77](https://github.com/nlemoine/http-cli/commit/9766f77f4349e0f807746bc7e8468d2cdb5fb832))
* validate phpunit.xml.dist against the installed PHPUnit schema ([7907d3a](https://github.com/nlemoine/http-cli/commit/7907d3a202af6f66683e08c035b5e2f51063d34a))

## [1.1.2](https://github.com/nlemoine/http-cli/compare/1.1.1...1.1.2) (2026-11-06)


### Bug Fixes

* confine the executed script to the document root ([4eb3d75](https://github.com/nlemoine/http-cli/commit/4eb3d756ea1c7435abf1757154d6637342bc7b7e))
* read the response metadata from a file instead of the output ([0635f51](https://github.com/nlemoine/http-cli/commit/0635f514caf4c0f81e7e612d90d57829388e9594))
* remove upload temporary files when the request is over ([a499fe7](https://github.com/nlemoine/http-cli/commit/a499fe7a13aace69fe5556894602816fb4546aaf))
* return a 500 when the script doesn't hand back its response metadata ([05ab319](https://github.com/nlemoine/http-cli/commit/05ab319d189f4f829f12e877508fee6691f6ef61))
* stop InheritEnvGlobalsHandler from passing request variables to the script ([9bc786b](https://github.com/nlemoine/http-cli/commit/9bc786bf73c4096f1487cb10deae950a1a5715df))
* stop passing the calling process's request body to the script ([40f7838](https://github.com/nlemoine/http-cli/commit/40f78384ce7e065610fd456bc225f45c15e29671))


### Miscellaneous Chores

* add support links to composer.json ([5b88d44](https://github.com/nlemoine/http-cli/commit/5b88d44b4a2d44fab4b94d087bd9f43c417612f7))
* apply the Rector and ECS fixes ([e10cbe1](https://github.com/nlemoine/http-cli/commit/e10cbe197c46c865162ee9c6d3f6fc78160ab155))
* drop the v from GitHub release names ([5b6b08d](https://github.com/nlemoine/http-cli/commit/5b6b08d2e0695b44ddb1120699e582bfc25f34f7))
* fix export-ignore rules in .gitattributes ([b7e101e](https://github.com/nlemoine/http-cli/commit/b7e101e53471783d42f5b6b7922fdcfe36dcc671))

## [1.1.1](https://github.com/nlemoine/http-cli/compare/1.1.0...1.1.1) (2026-06-16)


### Bug Fixes

* ignore Symfony HTTP Client 8.1 max_connect_duration option ([23bdd22](https://github.com/nlemoine/http-cli/commit/23bdd22a2efb0e82d2e44fccf6d692405215fc54))
* throw a clear error when no executable file can be resolved ([80fc09a](https://github.com/nlemoine/http-cli/commit/80fc09ae06927ce7d140634a25031ac10af65795))

## [1.1.0](https://github.com/nlemoine/http-cli/compare/1.0.0...1.1.0) (2026-04-02)


### Features

* implement GlobalsHandler interface and InheritEnvGlobalsHandler for environment variable management ([af57507](https://github.com/nlemoine/http-cli/commit/af57507c48cdc6d63b5dd469ea28ae98fbb1c12f))

## 1.0.0 (2026-01-02)


### Features

* Release `n5s/http-cli` ([f2a1014](https://github.com/nlemoine/http-cli/commit/f2a1014d3931b552397505673e96cd7a300024cf))


### Miscellaneous Chores

* Initial commit ([fbebfe2](https://github.com/nlemoine/http-cli/commit/fbebfe2097dab84de262aa0e8c0c78d43704228a))
