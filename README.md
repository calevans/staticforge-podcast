# StaticForge Podcast

**Turn your StaticForge site into a fully-featured podcast host.**

The **Podcast** feature enables you to host, manage, and distribute a podcast directly from your static site. It handles everything from generating iTunes-compliant RSS feeds to automatically updating the ID3 tags on your media files.

## What You'll Need

*   A working [StaticForge](https://calevans.com/staticforge) project.
*   Audio or video files for your episodes.
*   A desire to be heard!

## Installation

First, pull in the package via Composer:

```bash
composer require calevans/staticforge-podcast
```

Next, register the feature with StaticForge:

```bash
php vendor/bin/staticforge feature:setup Podcast
```

Finally, run the podcast-specific setup to initialize caching and install helper templates:

```bash
php vendor/bin/staticforge podcast:setup
```

## Configuration

### 1. Site Configuration

Your podcast needs some global metadata for the RSS feed (iTunes requires this). Open your `siteconfig.yaml` and add your podcast details.

You also need to define where your podcast is distributed if you want to use the "Listen On" badges.

```yaml
# Global Site Settings (used for ID3 tags and Feed info)
site:
  name: "My Awesome Podcast" # Used as the 'Album' name in ID3 tags

# Podcast Feed Metadata (Injected into your RSS feed)
itunes_owner_name: "Jane Doe"
itunes_owner_email: "jane@example.com"
itunes_author: "Jane Doe"
itunes_category:
  - "Technology > Software"
  - "Education"
itunes_image: "/assets/images/cover_art.jpg" # 3000x3000px recommmended
itunes_type: "episodic" # or 'serial'
itunes_summary: "A show about static sites and PHP mastery."

# Podcast Distribution Platforms (for the badge widget)
podcast_platforms:
  apple: "https://podcasts.apple.com/us/podcast/your-show/id123456"
  spotify: "https://open.spotify.com/show/your-show-id"
  amazon: "https://music.amazon.com/podcasts/your-show-id"
  iheart: "https://www.iheart.com/podcast/your-show-id"
  pocketcasts: "https://pca.st/your-show-id"
  youtube: "https://www.youtube.com/@YourChannel"
  rss: "/rss.xml" # Your generated feed
```

### 2. The Badge Template

The `podcast:setup` command installs a badge template at `templates/YOUR_THEME/_podcast_badges.html.twig`. You can include this in your site's header, footer, or sidebar to give visitors quick links to subscribe.

```twig
{# In your base.html.twig or footer.html.twig #}
{% include '_podcast_badges.html.twig' %}
```

## Creating an Episode

Creating a podcast episode is as simple as creating a new content file (Markdown). The magic happens in the Frontmatter.

Create a file like `content/episodes/001-hello-world.md`:

```markdown
---
title: "Episode 1: Hello World"
date: 2023-10-27
description: "In our first episode, we explore the origins of the universe."
itunes_author: "Jane Doe"
itunes_episode: 1
itunes_duration: "30:00" # Optional, auto-detected if file is local
audio_file: "/audio/ep001.mp3"
tags: ["intro", "php"]
---

# Welcome to the show!

Here are the show notes for our very first episode.

## Links discussed
- link 1
- link 2
```

### How Media Handling Works

When you build your site (`site:render`), the Podcast feature kicks in:

1.  **Media Detection**: It sees `audio_file` (or `video_file`) in your frontmatter.
2.  **Processing**:
    *   **Local Files**: If the file is in your `content/` folder (e.g., `content/audio/ep001.mp3`), it is copied to your public build directory.
    *   **Remote Files**: If you host media elsewhere (Libsyn, S3, etc.), just use the full URL. We'll skip the copy step but still generate the feed tags.
3.  **Automatic ID3 Tagging**:
    *   *This is cooler than it sounds.* The feature scans your **local source file** and updates its ID3 tags based on your frontmatter.
    *   It sets the **Title**, **Artist**, **Album** (Site Name), **Year**, **Track Number** (`itunes_episode`), and even embeds the **Cover Art** (`itunes_image`).
    *   *Note: This modifies the source file in your `content/` directory so your master copy is always compliant.*
4.  **Feed Generation**: It adds the `<enclosure>` tag and all relevant iTunes namespaces to your RSS feed automatically.

## Commands

### `media:inspect`

Want to double-check what StaticForge sees in a specific file?

```bash
php vendor/bin/staticforge media:inspect content/episodes/001-hello-world.md
```

This will parse the frontmatter, locate the media file, and report on its size, duration, and ID3 tags.

### `podcast:setup`

Runs the initial setup.

*   Creates `cache/` directory if missing.
*   Initializes the media state cache (preventing re-processing of unchanged files).
*   Copies the badge templates to your active theme.

## Deep Dive

### Caching

Processing media files (calculating duration, writing ID3 tags) can be slow. We cache the state of processed files in `cache/podcast_media_state.json`.

If you change a file's metadata and it doesn't seem to update in the build, you can safely delete this JSON file to force a fresh re-scan of all media.

### Video Podcasts

We support video too! Just use `video_file` instead of `audio_file`. The templates will inject a `{{ video_url }}` variable instead of `{{ audio_url }}`.

## Troubleshooting

**"My RSS feed doesn't validate!"**
*   Check your `itunes_category` in `siteconfig.yaml`. Apple is picky about exact category names.
*   Ensure your `itunes_image` is a square JPG/PNG, ideally 1400x1400 to 3000x3000px.

**"The ID3 tags aren't updating."**
*   Check permissions on your `content/` directory. StaticForge needs write access to the *source* files to embed tags.
*   Try deleting `cache/podcast_media_state.json` and running `site:render` again.

---

*Part of the [StaticForge](https://calevans.com/staticforge) ecosystem.*

