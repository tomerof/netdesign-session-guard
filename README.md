# Netdesign Session Guard

A free WordPress plugin that shows who is signed in right now, detects accounts that are shared between several people, and can limit each account to a set number of devices at once. When the limit is reached, the **oldest device is signed out immediately**.

It is published on wordpress.org as [`netdesign-session-guard`](https://wordpress.org/plugins/netdesign-session-guard/). **Session Guard Pro** is a separate, paid add-on (private repo `tomerof/netdesign-session-guard-pro`) that plugs into this plugin's hooks.

## Features

- **Live view.** Everyone online now: device, browser, IP, country and current page. Sign out any device with one click.
- **Sharing detection.** Scores each account on devices, networks, countries and how often it switches devices. Suspicious accounts go to a *Flagged accounts* list.
- **Device limit.** Optional. Allow N devices per account. A new sign-in beyond the limit signs out the oldest device within seconds and shows it a message.
- **Whitelist and exempt roles.** Some users or roles are never limited or flagged.
- **Light on the server.** Normal page views add no database queries. Activity comes from a small heartbeat, and detection runs in cron.
- **Hebrew and English.** The admin screens follow each admin's own language setting, with full right-to-left support.

### What Session Guard Pro adds

Email alerts (instant or daily) and user warning emails, per-user and per-LearnDash-group device limits, LearnDash course tracking, and a faster heartbeat endpoint. Pro needs this plugin to be active. See the Pro repo's README.

## Documentation

| Topic | |
|---|---|
| [Installation](docs/installation.md) | Requirements, install, first-time setup |
| [Configuration](docs/configuration.md) | Every setting, explained |
| [How it works](docs/how-it-works.md) | Architecture, request flow, performance |
| [Device limit (enforcement)](docs/enforcement.md) | What happens when a device is signed out |
| [Sharing detection](docs/detection.md) | Rules, scoring, flags |
| [Admin screens](docs/admin-screens.md) | Live, flagged accounts, user page, settings |
| [Translations](docs/translations.md) | Hebrew / English, updating and adding languages |
| [Privacy](docs/privacy.md) | What is stored, retention, anonymization, external requests |
| [Developer reference](docs/developer.md) | Code structure, database, hooks (including the ones Pro uses) |
| [Local development and testing](docs/testing.md) | Docker setup, simulating devices, Plugin Check |
| [Releasing to wordpress.org](docs/releasing.md) | Versioning, building, SVN |
| [Troubleshooting](docs/troubleshooting.md) | Common problems |

The wordpress.org listing text lives in [`readme.txt`](readme.txt).

## Quick start

1. Install from **Plugins → Add New** (search "Netdesign Session Guard"), or upload the zip, and activate it.
2. Open **Session Guard → Live** to see who is online.
3. Leave it in **Monitor only** for a week, then check **Flagged accounts** to tune the thresholds.
4. When ready, open **Settings → Device limit** and switch to **Enforce**.

## Requirements

WordPress 6.0+, PHP 7.4+.

---

© Netdesign · [netdesign.media](https://netdesign.media) · GPL-2.0-or-later
