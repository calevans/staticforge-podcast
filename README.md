# StaticForge Podcast

Publish a podcast RSS feed — with iTunes tags, ID3-tagged audio, and "listen on" badges — from a [StaticForge](https://calevans.com/staticforge) site.

This package is a StaticForge feature. It doesn't change how you write episodes (they're still Markdown files with frontmatter); it watches for `audio_file`/`video_file` in that frontmatter and, at build time, adds the `<enclosure>` tag, the iTunes namespace, and (for MP3s) proper ID3 tags.

## Requirements

* A StaticForge site (`eicc/staticforge` ^3.0 or later).
* `site.name` set in `siteconfig.yaml` and `SITE_BASE_URL` set in `.env`. Run `php vendor/bin/staticforge audit:config` to check for these and other missing settings.
* Audio or video files for your episodes.

## Install

```bash
composer require calevans/staticforge-podcast
```

That's it for registration — `composer.json`'s `extra.staticforge.feature` key tells StaticForge to load the feature automatically. You don't run a separate "register" command.

Next, ask core to install the example config and badge template:

```bash
php vendor/bin/staticforge feature:setup calevans/staticforge-podcast
```

This copies two files:

* `siteconfig.yaml.example.staticforge-podcast` in your project root — merge its `podcast:` block into your own `siteconfig.yaml`.
* `_podcast_badges.html.twig.example` into your active template directory.

**About that second file:** `feature:setup` decides where to put it using the `TEMPLATE_DIR` and `TEMPLATE` environment variables from `.env`, not your `siteconfig.yaml`'s `site.template` key. If `site.template` is what actually controls your active theme (the normal case) and you don't also set `TEMPLATE` in `.env`, the file lands in `templates/_podcast_badges.html.twig.example` instead of inside your theme's folder. Check where it landed, then move and rename it yourself, for example:

```bash
mv templates/_podcast_badges.html.twig.example templates/sample/_podcast_badges.html.twig
```

(Replace `sample` with your actual template name — the one in `siteconfig.yaml`'s `site.template`.)

## Configuration

Podcast metadata lives in two places, and they layer:

1. **`siteconfig.yaml`** — a `podcast:` block with your channel-wide defaults (owner, category, cover art, and so on) plus your platform badge links. See `siteconfig.yaml.example` in this repo for the full block.
2. **The category definition file's frontmatter** — a content file with `type: category` in its frontmatter. Any key it sets overrides the matching key from `siteconfig.yaml`, one key at a time. This is also the *only* place that turns the iTunes namespace on for a feed: it must set `podcast: true`.

Root-level `itunes_*` or `podcast_platforms:` keys directly in `siteconfig.yaml` are **not** read. These two have different histories:

* Root-level `itunes_*` never worked, in any released version. Channel metadata has always come from the category definition file, despite what the 3.0 README said. 3.1.0 makes `siteconfig.yaml` a real source for it, via the `podcast:` block.
* `podcast_platforms:` *did* work in 3.0 — the badge template read it directly. In 3.1.0 it moves to `podcast.platforms`. This is the one config key that genuinely breaks on upgrade.

Either way, if those root-level keys are still present the build logs a startup warning telling you where to move them.

### `siteconfig.yaml`

```yaml
site:
  name: "My Awesome Podcast"   # used as the ID3 'Album' tag and in feed defaults

podcast:
  itunes_author: "Jane Doe"
  itunes_owner_name: "Jane Doe"
  itunes_owner_email: "jane@example.com"
  itunes_category:
    - "Technology > Software"
    - "Education"
  itunes_image: "/assets/images/cover_art.jpg"   # 3000x3000px recommended
  itunes_type: "episodic"   # or 'serial'
  itunes_summary: "A show about static sites and PHP mastery."
  itunes_explicit: false

  # "Listen On" badges. Site-wide only — platforms are never overridden per
  # category. Every URL must start with https:// or the badge template skips it.
  platforms:
    apple: "https://podcasts.apple.com/us/podcast/your-show/id123456"
    spotify: "https://open.spotify.com/show/your-show-id"
    amazon: "https://music.amazon.com/podcasts/your-show-id"
    iheart: "https://www.iheart.com/podcast/your-show-id"
    pocketcasts: "https://pca.st/your-show-id"
    youtube: "https://www.youtube.com/@YourChannel"
    rss: "https://example.com/podcast/rss.xml"
```

The keys a category definition file can override are: `itunes_owner_name`, `itunes_owner_email`, `itunes_author`, `itunes_category`, `itunes_image`, `itunes_type`, `itunes_summary`, `itunes_explicit`. `platforms` isn't one of them — it's read straight from `siteconfig.yaml` by the badge template.

### The category definition file

RSS feeds in StaticForge are built per category, and StaticForge only picks up files that have a `category:` key in their frontmatter. So every episode needs one:

```markdown
---
category: episodes
---
```

The feed itself, and any iTunes-specific overrides, come from a separate content file whose frontmatter has `type: category`. Its filename (without extension) is the category slug, so a category value of `episodes` needs a definition file that saves down to slug `episodes` — for example `content/episodes.md`:

```markdown
---
type: category
title: "Episodes"
podcast: true
itunes_category:
  - "Technology > Software"
---

Show notes and episode archive for the main feed.
```

Without `podcast: true` here, the feed builds fine as plain RSS but carries no iTunes tags — even if `siteconfig.yaml` has a full `podcast:` block. Without the file at all, episodes still publish, but there's no channel-level metadata to attach the iTunes namespace to, and the build logs a warning if it looks like you meant to run a podcast.

### The badge template

Include the badge partial wherever you want "listen on" links:

```twig
{% include '_podcast_badges.html.twig' %}
```

It reads `site_config.podcast.platforms.*` directly, so it only needs the `siteconfig.yaml` side of the config — no category file required.

## Writing an episode

Create a Markdown file with `audio_file` (or `video_file`) and a `category` pointing at a category definition file:

```markdown
---
title: "Episode 1: Hello World"
date: 2023-10-27
category: episodes
description: "In our first episode, we explore the origins of the universe."
itunes_author: "Jane Doe"
itunes_episode: 1
audio_file: "/audio/ep001.mp3"
tags: ["intro", "php"]
---

# Welcome to the show!

Here are the show notes for our very first episode.

## Links discussed
- link 1
- link 2
```

For local files, `itunes_duration` and the enclosure's size and MIME type are filled in automatically from the audio file during the build — you don't need to set them by hand. For **remote** files (a full `http(s)://` URL in `audio_file`/`video_file`), the build never downloads the file, so nothing is auto-detected: set `itunes_duration` yourself, and set `audio_size`/`audio_type` (or `video_size`/`video_type`) if you want an accurate enclosure length and MIME type in the feed.

Other frontmatter keys the feed reads per episode, all optional: `itunes_title`, `itunes_episode_type`, `itunes_subtitle`, `itunes_summary` (falls back to `description`), `itunes_season`, `itunes_explicit`, `itunes_image` (falls back to the channel's cover art).

### How media is handled during a build

Your files under `content/` are never modified. When you run `site:render`:

1. The feature finds `audio_file`/`video_file` in an episode's frontmatter.
2. **Local files** (a path under `content/`) are staged into `cache/podcast/media/`, a working copy the build uses instead of touching your source.
3. **MP3s** get their ID3 tags (title, artist, album, year, track number, and cover art) written to that staged copy. Other formats — m4a, mp4, ogg, video — are published as-is, untagged. That's expected: getID3's tag writer only understands MPEG audio.
4. **Remote files** (a full `http(s)://` URL) are left alone entirely; the feed links straight to them and no staging or tagging happens.
5. At the end of the build, the staged (or remote) copy is published to your output directory, and the feed's `<enclosure>` and iTunes tags are built from it.

### Video podcasts

Use `video_file` instead of `audio_file`. The feature exposes `{{ video_url }}` in place of `{{ audio_url }}`; everything else works the same, except video is never ID3-tagged.

## Commands

### `media:inspect`

Check what StaticForge sees in a specific episode's media file, without changing anything:

```bash
php vendor/bin/staticforge media:inspect content/episodes/001-hello-world.md
```

This reads the file's frontmatter, locates `audio_file`/`video_file` (downloading it first if it's remote), and prints its size, MIME type, and duration. It's read-only by default.

Pass `--write` to persist those values into the file's frontmatter as `audio_size`, `audio_type`, and `itunes_duration`:

```bash
php vendor/bin/staticforge media:inspect content/episodes/001-hello-world.md --write
```

`--write` edits only those three lines in place — it preserves the rest of your frontmatter's formatting and comments, and does not create a backup file.

## Caching and disk use

Staged, tagged media lives in `cache/podcast/media/`. For MPEG audio, that means roughly double the disk space of your source files while both copies exist — the untagged master under `content/` and the tagged copy in cache. Non-MPEG media isn't staged at all, so it doesn't get this second copy.

Build state (what's already been staged/tagged and doesn't need redoing) is tracked in `cache/podcast/state.json`.

Keep `cache/` out of version control. Besides being large, `state.json` is build state the feature trusts to skip work — committing it means anyone who can open a pull request can edit it. (The build cross-checks the recorded file size against the file on disk and only accepts a known set of media types, so a tampered entry can't misreport an episode's length, but there's no reason to hand it the opportunity.)

The trade-off: if `cache/` is gitignored — the usual setup — a CI build starts empty and re-tags and re-analyzes every episode from scratch. If that makes CI slow, cache the `cache/podcast/` directory between runs rather than committing it.

If a change to an episode's metadata doesn't seem to show up after a build, delete `cache/podcast/state.json` and rebuild — that forces every episode to be re-staged and re-tagged.

### `--incremental` drops show notes from the feed

Don't publish a feed built with `site:render --incremental`.

Show notes are captured during Markdown conversion, and core skips that step entirely for any file it can reuse from cache. On an incremental build the episode still gets its `<enclosure>`, duration, and iTunes tags — but no `<content:encoded>`; subscribers see only the `description`. The same applies to episodes written as `.html` rather than Markdown, which never go through Markdown conversion at all.

This is deliberate. The alternative was falling back to the fully rendered page, which would ship your site's `<head>`, navigation, and footer to every subscriber. A short description beats that. Use a plain `site:render` for anything you actually publish; `--incremental` is a local preview aid (core marks it experimental).

## Upgrading from 3.0

Despite being a minor release, 3.1.0 changes the config shape and how media is handled. Two of these changes alter a published feed without erroring — read this before you upgrade.

* **Config moved.** Root-level `itunes_*` and `podcast_platforms:` keys in `siteconfig.yaml` are no longer read. Move them under a single `podcast:` root key; `podcast_platforms:` becomes `podcast.platforms:`. The build logs a startup warning if it finds the old keys still sitting at the root.
* **`podcast:setup` is gone.** Use core's `feature:setup calevans/staticforge-podcast` instead, and see the caveat above about where it drops the badge template.
* **Source files are no longer tagged.** In 3.0, the build wrote ID3 tags directly into your files under `content/`. In 3.1.0, tagging happens on a staged copy in `cache/podcast/media/`; your source files are read-only during a build.
* **iTunes tags now require an explicit opt-in.** This is the one that fails silently: if you have episodes with a `category:` but no category definition file (a `type: category` content file) for that category, or that file exists but doesn't have `podcast: true`, your feed still builds — but with no iTunes tags at all. If your podcast feed loses its Apple Podcasts / Spotify metadata after upgrading, this is almost certainly why. Create the category definition file and add `podcast: true` to it.

## Troubleshooting

**My feed has no iTunes tags (no `itunes:*` elements, no badges show up in podcast apps).**
The category definition file for that feed either doesn't exist or is missing `podcast: true` in its frontmatter. Add a content file with `type: category` and `podcast: true` — see "The category definition file" above. A `podcast:` block in `siteconfig.yaml` alone is not enough.

**My episode never shows up in any feed.**
It has no `category:` key in its frontmatter. RSS feeds only collect files that declare a category, and the category slug (the file's frontmatter value, sanitized to lowercase with hyphens) has to match the filename of a `type: category` content file.

**The feed doesn't validate, or Apple rejects the category.**
Check `itunes_category` in your `podcast:` block or category definition file — Apple only accepts exact category and subcategory names, case-sensitive, in `"Category > Subcategory"` form.

**The badges don't show up on the page.**
Each platform URL in `podcast.platforms` must start with `https://`; anything else is silently skipped. Also confirm the badge template actually got included in your theme — see the `feature:setup` caveat above about where it's copied.

**An episode's audio isn't ID3-tagged.**
Only MPEG audio (`.mp3`) gets ID3 tags — that's the only format getID3's tag writer supports. m4a, mp4, ogg, and video files publish untagged; that's expected, not a bug.

**A metadata change doesn't show up after rebuilding.**
Delete `cache/podcast/state.json` and rebuild.

---

Part of the [StaticForge](https://calevans.com/staticforge) ecosystem.
