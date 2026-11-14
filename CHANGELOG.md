# Changelog

All notable changes to omnibase/video. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.2] — 2026-11-14

### Changed
- Accepts omnisense/omnibase 6.0 beside 5.1 (`^5.1 || ^6.0`) and omnibridge/symfony 2.0 beside 1.1 (`^1.1 || ^2.0`): the
  bundle names none of what 6.0 removes (the former names, BaseTrait's framework accessors, the dispatchers' former
  hooks, the union-typed arguments), so the same code runs on both.

## [1.0.1] — 2026-11-14

### Changed
- The bundle's classes extend omnibridge/symfony's `AbstractBaseBundle`, `AbstractBaseExtension` and
  `AbstractBaseConfiguration` by their names (`Omnibridge\Symfony\Omnibase\Bundle\*`), no longer by the former
  `Base\Bundle\*` ones, which omnisense/omnibase keeps only as aliases until 6.0. The same classes: nothing changes for
  an application.
- `WatchController` builds the comment form with `Omnibridge\Symfony\Omnibase\Form\Type\CommentType`, the
  former `Base\Form\Type\CommentType`.

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
