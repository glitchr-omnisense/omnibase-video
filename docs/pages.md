---
title: "The pages"
order: 20
---

# The pages

Every route of the bundle's client controllers (`src/Controller/Client`).
The paths are French, as the platform's; the names are what a template's
`path()` uses.

## Watching

| Name | Path | Methods | |
|---|---|---|---|
| `video_watch` | `/v/{slug}` | GET | the watch page: the player, the film's text, the comments, "à suivre" ([The player](player.md)) |
| `video_manifest` | `/v/{slug}/master.m3u8` | GET | the HLS master of a film played from another host, read and kept ([The player](player.md#remote-hls-without-a-copy)) |
| `video_beacon` | `/v/{slug}/watch` | POST | the player's beacons: what was watched ([Views and suggestions](views-and-suggestions.md)) |
| `video_rate` | `/v/{slug}/rate` | POST | like or dislike, rate limited |
| `video_comment` | `/v/{slug}/comment` | POST | a comment, through omnisense/omnibase's guard ([Moderation and back office](moderation.md)) |
| `video_report` | `/v/{slug}/report` | POST | a report, rate limited |

The slug is `[A-Za-z0-9_-]+`.

## Channels and search

| Name | Path | Methods | |
|---|---|---|---|
| `video_channel` | `/chaine/{slug}` | GET | a channel: its films, its lists |
| `video_subscribe` | `/chaine/{slug}/abonnement` | POST | subscribe or unsubscribe (a toggle); a visitor is sent to sign in (401 in JSON), a bad CSRF token refused (403 in JSON) |
| `video_search` | `/recherche` | GET | `q` (120 characters at most), `categorie` (one of `video.categories`), `chaine` (a channel's slug), `duree` (`short`, `medium`, `long`), `date` (`day`, `week`, `month`, `year`) ([Search](search.md)) |

## A member's library

The member's pages answer a visitor with a 403; a list's own page is shown
to whoever the list is shown to.

| Name | Path | Methods | |
|---|---|---|---|
| `video_history` | `/historique` | GET | what the member watched |
| `video_history_clear` | `/historique/effacer` | POST | forget it |
| `video_later` | `/plus-tard` | GET | "plus tard": private, newest first |
| `video_subscriptions` | `/abonnements` | GET | the subscriptions' feed |
| `video_playlists` | `/listes` | GET, POST | the member's lists; POST makes one |
| `video_playlist` | `/liste/{slug}` | GET, POST | a list |
| `video_playlist_toggle` | `/listes/video` | POST | a film in or out of a list |
| `video_playlist_delete` | `/liste/{slug}/supprimer` | POST | delete a list |
| `video_playlist_order` | `/liste/{slug}/ordre` | POST | the list's new order |

## The studio

Under `/studio`, for a signed-in member (`ROLE_USER`); uploading needs
`video.upload.enabled` ([Uploads](upload.md)).

| Name | Path | Methods | |
|---|---|---|---|
| `video_studio` | `/studio` | GET | the member's films |
| `video_studio_upload` | `/studio/envoyer` | GET | the upload page |
| `video_studio_uploaded` | `/studio/envoi/{uploadId}` | GET | an upload finished: the film being made |
| `video_studio_link` | `/studio/lien` | GET, POST | a film from an address instead of a file (`LinkDetailsType`: the address, a name) |
| `video_studio_edit` | `/studio/video/{id}` | GET, POST | a film's details (`VideoDetailsType`): title, description, visibility, publication date, category, tags, language, licence, comments on or off, poster |
| `video_studio_status` | `/studio/video/{id}/etat` | GET | where its transcoding stands |
| `video_studio_stats` | `/studio/video/{id}/statistiques` | GET | its figures |
| `video_studio_comments` | `/studio/video/{id}/commentaires` | GET, POST | its comments, moderated by its owner |
| `video_studio_replace` | `/studio/video/{id}/remplacer` | GET | upload another file for it (it keeps playing the old one meanwhile) |
| `video_studio_delete` | `/studio/video/{id}/supprimer` | POST | to the trash |
| `video_studio_trash` | `/studio/corbeille` | GET, POST | the member's trash |
| `video_studio_channel` | `/studio/chaine` | GET, POST | the member's channel (`ChannelDetailsType`: name, slug, description, shelves, avatar, banner) |
| `video_studio_subscribers` | `/studio/abonnes` | GET | who subscribed |

## The upload hook

`video_upload_hook` (`POST /video/upload/hook`) is tusd's, not a page
([Uploads](upload.md#the-hooks-post-videouploadhook)).
