# Changelog

## [Unreleased] - 1.0.0

omnibase/video is a video platform on omnisense/omnibase: members send their films and the platform runs them, visitors
watch, rate, comment and keep lists; channels, subscriptions, views, suggestions and moderation. It names only `Base\`
classes: its repositories extend `Base\Database\Repository\ServiceEntityRepository` and speak the core's finder
language, its services write through `Base\Contract\UnitOfWork` and read other classes through
`Base\Database\Repository\Repositories`. Each query is written once in the finder language, the views and suggestions
included (`findValuesBy()` with aggregates, `updateBy()`, `deleteBy()`, `sql()` for the popular films), and compiled by
the engine of the persistence chosen (omnibridge/doctrine or omnibridge/eloquent).
