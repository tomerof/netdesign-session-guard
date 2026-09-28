# How it works

[← Back to README](../README.md)

## Building on WordPress sessions

Every WordPress login creates a **session token** (`WP_Session_Tokens`). The token sits in the user's `wordpress_logged_in_*` cookie, and its SHA-256 hash (the *verifier*) is stored in the `session_tokens` user meta. When the token is removed from that meta, the cookie stops working on the next request. WordPress itself uses this for "Log out everywhere else".

Session Guard keeps its own table, `ndsg_sessions`, with one row per token. Each row is keyed by the same SHA-256 hash and adds device, IP, country, activity and how the session ended.

## Request flow

```
Login ──► set_logged_in_cookie hook
            ├─ insert session row (device id, UA, IP, network, country, expiry)
            ├─ Enforcer: limit exceeded? → destroy oldest devices' tokens + mark rows "policy"
            └─ Detector: score this user → create or update flag → ndsg_flag_raised

Page view (logged in) ──► no DB work; prints a ~1 KB script with a ping key

Browser (tab visible) ──► every 30 s POST /wp-json/ndsg/v1/ping { key, url, post, course }
            Ping\Handler (DB only)
            ├─ SELECT row by ping_key (unique index)
            ├─ ended?  → {"s":"revoked","r":"policy"} → browser shows message, leaves page
            └─ active? → UPDATE last_seen, current_url → {"s":"ok"}

Logout ──► wp_logout hook → mark row "logout"

Cron hourly ──► close expired rows, run detection for users active since last run
Cron daily  ──► purge old history, refresh Cloudflare ranges, ndsg_daily
```

## Device identity

A random, long-lived, HttpOnly cookie, `ndsg_did`, is set at login. It identifies the browser across logins, so "3 devices" means 3 different browsers, not 3 logins. Tabs share the cookie and the session, so they count as one device.

If someone clears cookies or uses a private window, that browser counts as a new device. Detection also looks at networks and countries, so one private window doesn't make an account look suspicious on its own.

## The ping key

The heartbeat doesn't rely on the login cookie (it sends same-origin credentials only so it passes HTTP basic auth on staging sites). It identifies the session by a **ping key**: `sha256("ndsg|" + sha256(token))`.

- Only whoever holds the session cookie can compute it. The page receives it from the server.
- The server can't reverse it into a token, and knowing it doesn't let anyone sign in.
- The heartbeat's only answer is "ok" or "revoked", so a leaked key reveals nothing useful.

## Why it stays fast

| Work | Where it happens | Cost |
|---|---|---|
| Logged-out visitors | none | 0 |
| Logged-in page view | inline config only | 0 queries |
| Heartbeat | REST route; the handler itself runs 1 indexed SELECT + 1 UPDATE | about the cost of a light REST request |
| Login | 1 INSERT + a few indexed SELECTs | only at login |
| Detection | hourly cron, batches of 200 users | off the request path |

Session Guard Pro swaps the REST route for its own `ping.php`, which loads WordPress with `SHORTINIT` (database only, no plugins or theme). Measured on the ease-it local stack it takes about **3 ms**, compared with about **250 ms** for a logged-in page view. The logic is the same `Ping\Handler` class, and the URL is switched through the `ndsg_heartbeat_url` filter.

At 1,000 users online at once with a 30-second interval, that's about 33 tiny requests a second. Hidden tabs don't ping at all.

**When would you need a separate server?** Only if you need true instant push over websockets, or you have tens of thousands of users online at once. The heartbeat logic is isolated in `src/Ping/Handler.php`, which only needs a database connection, so it could run on another server later.

## What "immediately" means

- **The next request from the signed-out device** is already unauthenticated, because WordPress rejects the removed token. The page shows the kick message.
- **An open tab that isn't being clicked** finds out within one heartbeat interval (30 s by default), or instantly when the tab regains focus. It then shows a message and leaves the page.
