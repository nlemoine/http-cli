# Changelog

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
