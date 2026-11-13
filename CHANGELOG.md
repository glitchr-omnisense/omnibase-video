# Changelog

All notable changes to omnibase/video. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — 2026-11-13

### Changed
- First stable release: installs with the default minimum-stability. Requires omnisense/omnibase `^5.1` (no longer
  the 3.0, 4.0 or 5.0 development branches), omnibridge/symfony `^1.1`, omnibase/admin `^1.0`, glitchr/typesense-bundle `^2.0`, without `@dev`; `minimum-stability: dev` removed;
  branch alias `dev-main` = `1.x-dev`.
- CI: glitchr-studio/ci's package workflow (validate, PHPUnit, PHPStan level 5 with a baseline, a consumer install)
  and release workflow on a `v*` tag.
- README: the install line, how to enable the bundle under Symfony, a first use.

### Fixed
- Requires doctrine/doctrine-bundle, whose classes `src/` uses: they came only with a host
  application (omnibridge/symfony suggests them), so the bundle installed alone failed to load its repositories.
- Neutral example data in docs and tests.
