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
│   │   ├── Detector.php         Rules and scoring (login + hourly)
│   │   └── FlagRepository.php   All SQL for the flags table
│   ├── Cron/Jobs.php            Hourly, daily and import jobs
│   ├── Rest/Controller.php      /ndsg/v1 REST routes
│   ├── Ping/Handler.php         Heartbeat logic (DB only; Pro's ping.php reuses it)
│   └── Admin/                   Menu, Live, Flags, User and Settings pages
├── assets/js/heartbeat.js       Front-end heartbeat (~1 KB, no dependencies)
├── assets/js/admin-live.js      Live screen (uses wp.apiFetch)
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

`id`, `user_id`, `score`, `reasons` (JSON), `status` (`open`/`dismissed`), `created_at`, `updated_at`, `notified_at`.

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
| POST | `/ping` | Public heartbeat fallback. Body: `k`, `u`, `p`, `c` |
| GET | `/live` | Online sessions and summary |
| POST | `/sessions/{id}/kick` | Sign out one session |
| POST | `/users/{id}/kick` | Sign out every session of a user |

Heartbeat answers: `{"s":"ok"}`, `{"s":"revoked","r":"<reason>"}`, `{"s":"unknown"}`, `{"s":"invalid"}`.

## Actions

| Hook | Args | When |
|---|---|---|
| `ndsg_loaded` | `$plugin` | The plugin finished booting. Add-ons start here. |
| `ndsg_session_kicked` | `$session_row, $reason` | A session was ended by policy or an admin |
| `ndsg_flag_raised` | `$user_id, $score, $reasons` | A new flag was created |
| `ndsg_daily` | | The daily cron ran |
| `ndsg_flag_actions` | `$flag` | Extra buttons in a Flagged accounts row |
| `ndsg_user_page_panel` | `$user, $flag` | Extra panels on the user page |
| `ndsg_settings_notices` | `$tab` | Above a settings tab |
| `ndsg_settings_tab_{$tab}` | | Renders a custom settings tab (instead of the fields form) |

## Filters

| Filter | Args | Default |
|---|---|---|
| `ndsg_capability` | `$cap` | `manage_options` |
| `ndsg_user_max_devices` | `$max, $user_id` | The site setting |
| `ndsg_ping_context` | `$context ['post','course'], $post_id` | Current post, course 0 |
| `ndsg_heartbeat_url` | `$url` | `rest_url( 'ndsg/v1/ping' )` |
| `ndsg_user_extra_info` | `$lines[], $user_id` | Lines shown on the user page |
| `ndsg_settings_defaults` | `$defaults` | Setting defaults; add-ons add their keys |
| `ndsg_settings_translated` | `$keys` | Text settings stored empty and translated at display |
| `ndsg_settings_sanitize` | `$out, $in, $defaults` | Sanitized settings; add-ons sanitize their keys |
| `ndsg_settings_tabs` | `$tabs` | `general`, `detect`, `advanced` |
| `ndsg_settings_fields` | `$fields, $tab` | The fields of a tab |
| `ndsg_admin_action_{$do}` | `$notice, $user_id, $flag_id` | Handles a custom admin action (nonce already checked); return a notice key |

Unknown keys in `ndsg_settings` are kept when the free plugin saves, so add-on settings survive.

## How Session Guard Pro plugs in

| Pro feature | Hooks |
|---|---|
| Email alerts, user warning email | `ndsg_flag_raised`, `ndsg_daily`, `ndsg_flag_actions`, `ndsg_user_page_panel`, `ndsg_admin_action_email_user`, settings filters |
| Per-user limit | `ndsg_user_max_devices` (priority 20), `ndsg_user_page_panel`, `ndsg_admin_action_max_devices` |
| LearnDash | `ndsg_ping_context`, `ndsg_user_max_devices`, `ndsg_user_extra_info` |
| Fast heartbeat | `ndsg_heartbeat_url` → Pro's `ping.php` |
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
