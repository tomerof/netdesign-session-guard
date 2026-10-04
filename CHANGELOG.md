# Changelog

## 1.2.1
- Heartbeat sends `w`, a random id per page view, so add-ons (Pro's viewing trail) can tell open tabs of one session apart.

## 1.2.0
- **Whitelisted users** tab under Flagged accounts (users with `ndsg_exempt`), with "Remove from whitelist".
- `Ping\Handler::handle()` takes an optional `$after` callback; the REST heartbeat fires `ndsg_heartbeat` ($row, $now, $input). Pro records its viewing trail there.
- Heartbeat sends `v` (the playing video an add-on reports in `ndsgHeartbeat.video`) and keeps running in a hidden tab while a video plays. `ndsgHeartbeat.ping()` sends one right away.
- `ndsg_user_page_sections` action on the user page.

## 1.1.0
- Overlaps: the heartbeat records when two devices of a user are active at the same time (table `ndsg_overlaps`), shown on the user page with both devices and networks.
- New detection rule `overlap`: minutes online together in the period (default 10, +40 points).
- Handling status for flags (`new`, `follow_up`, `blocked`, `resolved`, `dismissed`) with filter tabs and counts; open flags migrate to `new`. The menu badge counts new flags.
- Notes on users (table `ndsg_notes`).
- Plain-language explanations of flag reasons (`Detector::explain()`).
- `ndsg_overlap_actions` hook; heartbeat sends its interval (`i`).

## 1.0.0
- First release on wordpress.org, as `netdesign-session-guard`.
- Split from the earlier private `nd-session-guard` plugin (0.1.0–0.3.0). Everything paid moved to the separate Session Guard Pro add-on; this plugin has no license checks or locked features.
- Included: session tracking, Live view, sharing detection with flags, manual sign-out, whitelist and exempt roles, and the site-wide device limit (kick the oldest device).
- Heartbeat over the REST API (`/wp-json/ndsg/v1/ping`). Pro can swap in a faster endpoint through `ndsg_heartbeat_url`.
- Cloudflare IP ranges are refreshed daily from `api.cloudflare.com/client/v4/ips`.
- Suggested privacy-policy text under Settings → Privacy.
- Extension hooks for add-ons: `ndsg_loaded`, settings tabs/fields/defaults/sanitize filters, `ndsg_flag_actions`, `ndsg_user_page_panel`, `ndsg_admin_action_{$do}`, `ndsg_settings_notices`.
- Hebrew translation.
