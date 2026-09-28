# Changelog

## 1.0.0
- First release on wordpress.org, as `netdesign-session-guard`.
- Split from the earlier private `nd-session-guard` plugin (0.1.0–0.3.0). Everything paid moved to the separate Session Guard Pro add-on; this plugin has no license checks or locked features.
- Included: session tracking, Live view, sharing detection with flags, manual sign-out, whitelist and exempt roles, and the site-wide device limit (kick the oldest device).
- Heartbeat over the REST API (`/wp-json/ndsg/v1/ping`). Pro can swap in a faster endpoint through `ndsg_heartbeat_url`.
- Cloudflare IP ranges are refreshed daily from `api.cloudflare.com/client/v4/ips`.
- Suggested privacy-policy text under Settings → Privacy.
- Extension hooks for add-ons: `ndsg_loaded`, settings tabs/fields/defaults/sanitize filters, `ndsg_flag_actions`, `ndsg_user_page_panel`, `ndsg_admin_action_{$do}`, `ndsg_settings_notices`.
- Hebrew translation.
