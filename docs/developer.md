# Developer reference

[← Back to README](../README.md)

## Code structure

```
netdesign-session-guard/
├── netdesign-session-guard.php  Plugin header, constants, PSR-4 autoloader, activation hooks
├── uninstall.php                Removes all data
├── readme.txt                   wordpress.org listing
├── src/                         Namespace NetDesign\SessionGuard\
│   ├── Plugin.php               Boots all modules on plugins_loaded, fires ndsg_loaded
│   ├── Install/Schema.php       Tables (dbDelta), DB versioning, activation
│   ├── Session/
│   │   ├── Tracker.php          Login/logout hooks, heartbeat script, "you were signed out" notice
│   │   ├── Repository.php       All SQL for the sessions table
│   │   ├── Kicker.php           Ends sessions (destroys WP token + marks row)
│   │   ├── Device.php           Device cookie, network, country, UA parsing
│   │   └── ClientIp.php         Client IP resolution (Automatic mode, Cloudflare ranges)
│   ├── Policy/
│   │   ├── Settings.php         Defaults, get/sanitize the ndsg_settings option
│   │   └── Enforcer.php         Device limit at login, exemptions, effective limit
│   ├── Detection/
│   │   ├── Risk.php             Risk levels, labels, rating one overlap
│   │   ├── Detector.php         5-minute rating of overlaps, account levels, explanations
│   │   ├── FlagRepository.php   All SQL for the flags table, handling statuses
│   │   ├── OverlapRepository.php Reads overlaps (written by Ping\Handler)
│   │   └── NoteRepository.php   Admin notes about users
│   ├── Cron/Jobs.php            Hourly, daily and import jobs
│   ├── Rest/Controller.php      /ndsg/v1 REST routes
│   ├── Ping/Handler.php         Heartbeat logic (DB only; Pro's ping.php reuses it)
│   └── Admin/                   Menu, Live, Flags, User and Settings pages
├── assets/js/heartbeat.js       Front-end heartbeat (~1 KB, no dependencies)
├── assets/js/admin-live.js      Live screen (uses wp.apiFetch)
├── assets/js/admin.js           Saves the status dropdown on change, confirms note deletion
├── assets/css/admin.css
├── languages/                   POT, Hebrew .po/.mo/.l10n.php
├── scripts/                     build.mjs, i18n.mjs (Node, no dependencies; not shipped)
└── docs/                        This documentation (not shipped)
```

There's no Composer or npm dependency at runtime. The autoloader lives in the main file.

## Database

Both tables use `$wpdb->base_prefix`, so on multisite they are shared across the network, just like users.

### `{prefix}ndsg_sessions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint | |
| `token_hash` | char(64) UNIQUE | `sha256(token)`, the same as the WordPress session verifier |
| `ping_key` | char(64) UNIQUE | `sha256("ndsg\|" . token_hash)` |
| `device_id` | varchar(64) | `ndsg_did` cookie; `legacy-…` for imported sessions |
| `device_label`, `device_type` | | e.g. `Chrome · Windows`, `desktop` / `mobile` / `tablet` |
| `user_agent` | varchar(255) | |
| `ip`, `ip_net`, `country` | | IP (optionally anonymized), /24 or /48 range, ISO country |
| `current_url`, `post_id`, `course_id` | | Written by the heartbeat (`course_id` is filled by Pro's LearnDash integration) |
| `created_at`, `last_seen`, `expires_at`, `ended_at` | datetime (UTC) | |
| `end_reason` | varchar(20) | `logout`, `policy`, `admin`, `expired`, `password` |

Indexes: `(user_id, ended_at)`, `last_seen`, `created_at`.

### `{prefix}ndsg_flags`

`id`, `user_id`, `level` (risk level 1–4), `score` (level × 25, kept for compatibility), `reasons` (JSON: `count`, `total`, `longest`, `top` {overlap_id, level, facts}, optional `repeat`), `status`, `created_at`, `updated_at`, `notified_at`.

`status` is the handling status: `new`, `follow_up` (open: `FlagRepository::OPEN_STATUSES`) or `blocked`, `resolved` ("Checked, OK") (closed). Before 1.3.4 `blocked` counted as open. DB v3 turned `open` into `new`; v4 turned `dismissed` into `resolved` and gave points-model flags level 1.

### `{prefix}ndsg_overlaps`

`id`, `user_id`, `session_a` (always the lower id), `session_b`, `net_a`, `net_b`, `started_at`, `ended_at`, `seconds`, `level` (0 until rated), `facts` (JSON: seconds, networks_differ, countries; Pro adds video_both, video_one, video_streak, same_video, video_checked). One row per pair of sessions per continuous period. Written by `Ping\Handler::record_overlaps()`. Indexes: `(user_id, started_at)`, `(session_a, session_b, ended_at)`, `ended_at`.

### `{prefix}ndsg_notes`

`id`, `user_id`, `author_id`, `note`, `created_at`.

### Options and meta

| Key | Where | |
|---|---|---|
| `ndsg_settings` | option | All settings |
| `ndsg_db_version` | option | Schema version |
| `ndsg_detector_last_run` | option | Timestamp |
| `ndsg_exempt` | user meta | Whitelisted user |
| `ndsg_cloudflare_ranges` | option | Cloudflare IP ranges (refreshed daily) |

## REST API

Namespace `ndsg/v1`. Admin routes need the `ndsg_capability` (default `manage_options`) and a REST nonce.

| Method | Route | |
|---|---|---|
| POST | `/ping` | Public heartbeat. Body: `k` (key), `u` (url), `p` (post), `c` (course), `i` (interval, for the overlap gap), `v` (playing video reported by an add-on), `vt` (title of the playing video), `w` (random page-view id), `m` (`0` when monitoring is off: only the sign-out check runs) |
| GET | `/live` | Online sessions and summary |
| POST | `/sessions/{id}/kick` | Sign out one session |
| POST | `/users/{id}/kick` | Sign out every session of a user |

Heartbeat answers: `{"s":"ok"}`, `{"s":"revoked","r":"<reason>"}`, `{"s":"unknown"}`, `{"s":"invalid"}`.

## Actions

| Hook | Args | When |
|---|---|---|
| `ndsg_loaded` | `$plugin` | The plugin finished booting. Add-ons start here. |
| `ndsg_session_kicked` | `$session_row, $reason` | A session was ended by policy or an admin |
| `ndsg_flag_raised` | `$user_id, $score, $reasons` | A new flag was created (`$score` = level × 25) |
| `ndsg_overlap_assessed` | `$overlap, $level, $facts` | An overlap was rated (every 5 minutes while it lasts) |
| `ndsg_daily` | | The daily cron ran |
| `ndsg_flag_actions` | `$flag` | Extra buttons in a Flagged accounts row |
| `ndsg_user_page_panel` | `$user, $flag` | Extra panels on the user page |
| `ndsg_settings_notices` | `$tab` | Above a settings tab |
| `ndsg_settings_tab_{$tab}` | | Renders a custom settings tab (instead of the fields form) |
| `ndsg_overlap_actions` | `$overlap` | Extra links in an overlap row on the user page (row includes both devices' details) |
| `ndsg_user_page_sections` | `$user` | Extra sections on the user page, above the session history |
| `ndsg_heartbeat` | `$row, $now, $input` | A valid heartbeat was stored over REST. `$row` has `id`, `user_id`, `device_id`, `ip_net` |

## Filters

| Filter | Args | Default |
|---|---|---|
| `ndsg_capability` | `$cap` | `manage_options` |
| `ndsg_user_max_devices` | `$max, $user_id` | The site setting |
| `ndsg_overlap_risk` | `$risk ['level','facts'], $overlap` | Level from countries (very strong) or weak |
| `ndsg_risk_levels` | `$levels` (level => [key, label, description]) | Descriptions shown in the legend; empty = not shown |
| `ndsg_risk_repeat_level` | `$level` | `Risk::WEAK` (Pro: `STRONG`) |
| `ndsg_live_cards` | `$cards` (key => [label, description, css class]) | Active now, Weak / Medium / Strong-and-above today |
| `ndsg_live_summary` | `$summary` (key => number) | Numbers for the cards, in the `/live` response |
| `ndsg_ping_context` | `$context ['post','course'], $post_id` | Current post, course 0 |
| `ndsg_heartbeat_url` | `$url` | `rest_url( 'ndsg/v1/ping' )` |
| `ndsg_user_extra_info` | `$lines[], $user_id` | Lines shown on the user page |
| `ndsg_settings_defaults` | `$defaults` | Setting defaults; add-ons add their keys |
| `ndsg_settings_translated` | `$keys` | Text settings stored empty and translated at display |
| `ndsg_settings_sanitize` | `$out, $in, $defaults` | Sanitized settings; add-ons sanitize their keys |
| `ndsg_settings_tabs` | `$tabs` | `general`, `detect`, `advanced` |
| `ndsg_settings_fields` | `$fields, $tab` | The fields of a tab |
| `ndsg_admin_action_{$do}` | `$notice, $user_id, $flag_id` | Handles a custom admin action (nonce already checked); return a notice key |

Built-in admin actions (`admin-post.php?action=ndsg_action&do=…`): `dismiss`, `reopen`, `set_status`, `add_note`, `delete_note`, `exempt`, `unexempt`, `kick_user`. `Admin::action_url()` builds GET links and `Admin::action_fields()` prints the hidden fields for POST forms.

Unknown keys in `ndsg_settings` are kept when the free plugin saves, so add-on settings survive.

## Heartbeat extension

- `Ping\Handler::handle( $wpdb, $input, $after = null )`: `$after( $wpdb, $row, $now, $input )` runs after a valid heartbeat. It must follow the SHORTINIT rule (only `$wpdb`), since Pro's `ping.php` passes its recorder this way.
- Over REST the free plugin passes a callback that fires `ndsg_heartbeat`.
- In the browser, `window.ndsgHeartbeat.video` (set by an add-on) is sent as `v`, and keeps the heartbeat running while the tab is hidden. `window.ndsgHeartbeat.ping()` sends a heartbeat right away (used when playback starts or stops).

## How Session Guard Pro plugs in

| Pro feature | Hooks |
|---|---|
| Email alerts, user warning email | `ndsg_flag_raised`, `ndsg_daily`, `ndsg_flag_actions`, `ndsg_user_page_panel`, `ndsg_admin_action_email_user`, settings filters |
| Per-user limit | `ndsg_user_max_devices` (priority 20), `ndsg_user_page_panel`, `ndsg_admin_action_max_devices` |
| LearnDash | `ndsg_ping_context`, `ndsg_user_max_devices`, `ndsg_user_extra_info` |
| Fast heartbeat | `ndsg_heartbeat_url` → Pro's `ping.php` |
| Viewing trail and report | `ndsg_heartbeat` / the `$after` callback, `ndsg_overlap_actions`, `ndsg_user_page_sections`, `ndsgHeartbeat.video` |
| License tab | `ndsg_settings_tabs`, `ndsg_settings_tab_license`, `ndsg_settings_notices` |

### Examples

Give every user with an active "Premium" WooCommerce subscription 2 devices:

```php
add_filter( 'ndsg_user_max_devices', function ( $max, $user_id ) {
	if ( function_exists( 'wcs_user_has_subscription' ) && wcs_user_has_subscription( $user_id, 1234, 'active' ) ) {
		return 2;
	}
	return $max;
}, 10, 2 );
```

Log every policy sign-out:

```php
add_action( 'ndsg_session_kicked', function ( $session, $reason ) {
	error_log( "ndsg: user {$session->user_id} device {$session->device_label} signed out ({$reason})" );
}, 10, 2 );
```

## Conventions

- All SQL lives in the `*Repository` classes (plus the schema and cron cleanup).
- Nothing runs database queries during a logged-in page view. Keep it that way: put new per-request work in the heartbeat or in cron.
- `src/Ping/Handler.php` may only use `$wpdb` and plain PHP, because Pro's `ping.php` runs it under `SHORTINIT`.
- Settings are read through `Settings::get()`, which caches for the whole request.
- Text domain `netdesign-session-guard`. Translations go in `languages/`.
- Code must pass Plugin Check (see [Testing](testing.md)); no license checks or locked features in this plugin (wordpress.org guideline 5).
