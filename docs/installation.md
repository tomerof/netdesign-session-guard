# Installation

[← Back to README](../README.md)

## Requirements

- WordPress 6.0 or newer (tested with 7.1)
- PHP 7.4 or newer
- MySQL 5.7+ / MariaDB 10.3+

## Install

1. In WordPress go to **Plugins → Add New**, search for **Netdesign Session Guard**, then **Install** and **Activate**.
2. Or upload `netdesign-session-guard.zip` under **Plugins → Add New → Upload Plugin**.

On activation the plugin:

- creates two tables, `{prefix}ndsg_sessions` and `{prefix}ndsg_flags`,
- schedules its background jobs (hourly and daily),
- about 10 seconds later, imports the sessions that were already open, so users who were signed in before activation show up too.

Updates come from wordpress.org like any other plugin.

## First-time setup

1. **Session Guard → Settings → Device limit.** Leave the mode on **Monitor only** at first.
2. **Settings → Advanced → Client IP from.** Leave it on **Automatic**. Check the *Detected* line under it: behind Cloudflare it should say Cloudflare, and show your own IP.
3. After a few days, review **Flagged accounts**. Adjust the thresholds in **Settings → Detection** until the list only holds real cases.
4. Switch to **Enforce** when you are happy with it.

## Caching and security plugins

- The heartbeat is a `POST` to `/wp-json/ndsg/v1/ping`. It must **not** be cached. Page caches (Breeze, Varnish, Cloudflare) skip POST requests and logged-in users by default, so usually nothing needs changing.
- If a security plugin blocks the REST API for logged-in users, allow the `ndsg/v1` namespace.

## Other plugins that limit logins

Don't run two plugins that limit logins at the same time. For example, **Loggedin – Limit Concurrent Sessions** is installed on ease-it. Deactivate it before switching Session Guard to Enforce.

## Uninstall

Deleting the plugin from the Plugins screen removes all of its data: both tables, its options and the whitelist (`uninstall.php`). Deactivating keeps the data. Session Guard Pro removes its own data (license, per-user and group limits) when it is deleted.
