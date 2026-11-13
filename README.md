# Video

A video platform on [omnibase](https://github.com/glitchr-omnisense/omnibase):
members send their films and really run them, visitors watch, rate, comment
and keep lists. Hosted video only - this bundle is the hosting side; the
films of other platforms are referenced, never operated (see "Outside
sources" below).

- **A `Video` is an omnibase `Thread`** (a JOINED subclass): title, text,
  slug, owners, tags, likes, publication states, trash. On top: its
  `Channel`, its `Source`s, its length, poster and storyboard, its
  processing state, its views. Visibility is the thread's state: public,
  unlisted, private, scheduled.
- **Resumable uploads**: Uppy in the studio, [tusd](https://tus.io) behind
  `/files/`; two hooks ask the site (may it start? - it is whole).
- **HLS transcoding** by a Messenger handler with ffmpeg: fMP4 ladder
  1080/720/480/360 never above the source, poster, stills to choose from,
  storyboard sprite + WebVTT.
- **One player for the whole visit** (`media-chrome` + `hls-video-element`),
  outside `#content`: a transparent.js navigation never stops it; it shrinks
  to a corner and comes back to its page. Engines (`registerEngine`) and
  omnisense/omnibase's `media:play` (`MediaPlay`) let other sources and other
  players live with it.
- **Views** counted from the player's beacons (30 s or half the film, once a
  visitor every six hours), flushed by cron; **likes** on omnibase's `Like`;
  **comments** on omnibase's `Comment` + the moment of the film;
  **playlists**, **channels**, **subscriptions**, **reports** on omnibase's
  `Complaint`.
- **A studio** (`/studio`, pages of the site): send, describe, schedule,
  poster, statistics (views, average watched, retention, sources),
  comments, channel, trash.
- **Suggestions**: `Suggest\SuggestInterface`, computed here
  (`LocalSuggest`, a nightly `Related` table) or by Gorse (`GorseSuggest`,
  optional - down or absent, the local ones answer).
- **Search** through `glitchr/typesense-bundle`, the database when the
  server is down.
- **Back office** on omnibase/admin: films (take down with a reason),
  channels, comments, a dashboard tile.

```bash
composer require omnibase/video
```

Stable releases follow semantic versioning: this installs the latest 1.x, with omnisense/omnibase 5.1 or later
on Symfony (omnibridge/symfony) and Doctrine ORM (DoctrineBundle), omnibase/admin for the back office. No `minimum-stability` setting is needed.

Under Symfony, register the bundle (and glitchr/typesense-bundle, which it requires) and its pages, then create its
tables:

```php
// config/bundles.php
Typesense\Bundle\TypesenseBundle::class => ['all' => true],
Base\Video\VideoBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
video_controller:
    resource: "@VideoBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

```sh
bin/console make:migration && bin/console doctrine:migrations:migrate
```

First use: signed in, open `/lien` to add a film by its address, or `/envoyer` to send one (that one needs tusd and
an ffmpeg worker: [docs/installation.md](docs/installation.md), [docs/upload.md](docs/upload.md)).

Documentation: [docs/](docs/index.md). Tests: `vendor/bin/phpunit` (units:
no kernel, no database, no ffmpeg, no network).

Licence: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
