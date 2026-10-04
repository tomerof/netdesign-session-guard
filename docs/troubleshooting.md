# Troubleshooting

[← Back to README](../README.md)

## The Live screen is empty although users are online

- Users who were signed in **before** the plugin was activated appear after the import job runs (about 10 seconds after activation, on the next WP-Cron run). They're marked online only after their first heartbeat.
- Check that the heartbeat works: open the site as a logged-in user, open the browser DevTools → Network tab, and look for `ping` (`/wp-json/ndsg/v1/ping`, or `ping.php` with Pro) every 30 seconds returning `{"s":"ok"}`.
- A 401/403 usually means a security plugin blocks the REST API. Allow the `ndsg/v1` namespace.
- With Session Guard Pro's fast endpoint (`ping.php`): some hosts (Cloudways, many nginx setups) return 403 for PHP files in `wp-content/plugins`. Since 1.3.5 the browser switches to the REST heartbeat by itself and remembers it; you can also set Settings → Advanced → Heartbeat endpoint → REST API.
- An HTML answer means a cache is serving it. Exclude the heartbeat URL from caching.

## Every user shows the same IP

Check **Settings → Advanced → Client IP from**: it should be **Automatic**, and the *Detected* line should show your real IP. If it still shows a proxy address, the proxy uses a public IP that isn't Cloudflare's. Choose the matching fixed header, but only if that proxy always sets it; otherwise visitors can fake their IP.

## Country is always empty

Country comes from `CF-IPCountry` (trusted only when the request verifiably came through Cloudflare) or `GEOIP_COUNTRY_CODE` (some hosts). Without either, the country rule never fires. Everything else still works.

## A signed-out device isn't signed out straight away

- A tab that's open but hidden doesn't send heartbeats (to save server load). It gets the message as soon as it becomes visible or gets focus, or on its next request.
- Lower **Heartbeat every (seconds)** for a faster reaction.
- Is the mode really **Enforce**? The Live screen shows the current mode.
- Is the user exempt? Check their role against *Roles without limits*, and whether they are whitelisted.

## Users are flagged too often

See [Detection → Tuning](detection.md#tuning-tips). The usual causes are a wrong *Client IP from* setting, or a `devices` threshold that's too low for students who switch between a phone and a laptop.

## Another plugin also limits logins

Deactivate it (for example *Loggedin – Limit Concurrent Sessions*). Two plugins removing sessions will sign users out in confusing ways.
