# Device limit (enforcement)

[← Back to README](../README.md)

## When it runs

Only at **login**, and only when all of these are true:

- **Settings → Device limit → Mode** is **Enforce**,
- the user is not whitelisted and doesn't have an exempt role.

## What it does

1. List the user's open sessions (not ended, not expired), newest first.
2. Group them by device (`ndsg_did` cookie).
3. Keep the newest **N** devices, where N is the user's [effective limit](#effective-limit).
4. For every session on the other devices:
   - remove its WordPress session token, so the cookie is dead,
   - mark the row `ended_at = now`, `end_reason = 'policy'`,
   - fire the `ndsg_session_kicked` action.

The newest login always wins. With a limit of 1, signing in on a phone signs out the laptop.

## What the signed-out device sees

- **With the tab open:** at the next heartbeat (or as soon as the tab gets focus), a full-screen message shows the **kick message**. It moves to the **redirect URL** (the login page by default, with `?ndsg_kicked=1`) when the user clicks OK, or after 15 seconds.
- **On its next page load or click:** WordPress no longer recognizes the cookie. The page shows a notice bar with the kick message, and the dead cookie is cleared.
- **On the login page:** a notice explains why they were signed out.

Sessions that ended for other reasons (normal logout, expiry, password reset) just reload quietly, with no message.

## Effective limit

The site default (**Settings → Device limit**), passed through the `ndsg_user_max_devices` filter ([Developer reference](developer.md)).

Session Guard Pro uses that filter for per-user overrides (highest priority) and per-LearnDash-group limits (the highest limit among the user's groups).

## Admin sign-outs

These work in any mode, including Monitor:

- **Live** screen → **Sign out** on a row: signs out that one device (`end_reason = 'admin'`).
- **Flagged accounts** or the user page → **Sign out everywhere**: signs out every device of that user.

## Edge cases

- **The same browser logs in twice** (e.g. after clearing only the WordPress cookie): the same device id, so both sessions count as one device and neither is signed out.
- **Sessions from before the plugin was activated** are imported with a separate device id each, because their browser is unknown. They count toward the limit until they expire or the user logs in again.
- **Application passwords and REST API tokens** don't create browser sessions and aren't affected.
