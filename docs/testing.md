---
title: "Testing"
order: 60
---

# Testing

## Running the suite

The suite is units: no kernel, no database, no ffmpeg, no network.

```bash
vendor/bin/phpunit
```

Inside a host application:

```bash
php vendor/bin/phpunit -c vendor/omnibase/video/phpunit.xml.dist
```

omnisense/omnibase-harness does not install omnibase/video, its
glitchr/typesense-bundle nor symfony/rate-limiter. Run there with the
checkout and glitchr/typesense-bundle on the autoloader, the suite gives:

```text
Tests: 60, Assertions: 253, Errors: 4, Skipped: 1.
```

The four errors are `UploadsTest`'s, for want of symfony/rate-limiter (a
requirement of the bundle, so present in a host); the skipped test is
`PrefetchTest`'s read itself.

## What the tests check

| Test | Checks |
|---|---|
| `Controller\CrudRequiredFieldsTest` | omnibase/admin's `CrudFieldsTestCase` on the screens |
| `Css\WatchLayoutTest` | the stages row as tall as the player; a phone has one column and its own rows |
| `Demo\VideoDemoAccountsTest` | one account for each of the platform's people; nobody reaches the super-admin; where the editors are above it, the moderator is left out; each account's label and sentence in the catalogues |
| `Entity\PlaylistTest` | a list keeps its order and plays on; a film no longer reachable leaves it; "plus tard" is private and newest first; a sitting's seconds never run ahead of the clock |
| `Entity\VideoTest` | the visibility is the thread's state; a film taken down is out of sight with its reason; the player starts from the master; an outside source keeps its reference only; times and views; the moments typed in a comment |
| `Player\MediaPlayTest`, `Player\PrefetchTest` | the player takes part through `media:play`; the dock; what was read ahead is read first; its words in each language |
| `Repository\FiguresTest` | the studio's figures from raw sittings; no sitting, no division; the search filters |
| `Service\RemoteManifestTest` | the URIs made absolute against the origin; what is not a playlist refused; the length summed; only the allowed hosts read; the master read once, then kept; a failing origin is a remote exception |
| `Service\TranscoderTest` | the ladder never above the source; a portrait film laddered on its short side; one HLS variant a rung in fMP4; a silent film maps no sound; the storyboard and its VTT; ffprobe's JSON; frame times and bitrates |
| `Service\UploadsTest` | a token says who, until when and which film; pre-create refuses without a valid token, checks the member, the size, the quota and the limiter; post-finish takes only a file of the uploads directory; an address part is lowercase hex |
| `Suggest\SuggestTest` | a pair's score; only pairs that share something; the cap per channel; Gorse read and its failures fall back; a view needs thirty seconds or half the film |
| `Twig\VideoExtensionTest` | timecodes become links and the text is escaped; compact counts; an excerpt cut at a word |
