# Configuration

[← Back to README](../README.md)

All settings live in **Session Guard → Settings**, stored in a single option, `ndsg_settings`. The settings are split into tabs; saving one tab keeps the values on the other tabs.

## Device limit tab

| Setting | Default | Meaning |
|---|---|---|
| Mode | Monitor only | **Monitor only**: track and flag accounts, never sign anyone out. **Enforce**: apply the device limit. |
| Devices allowed at once | 1 | How many devices an account may be signed in on at the same time. Tabs in one browser count as one device. |
| Excluded users | — | Emails, usernames or user IDs. The same list as the whitelist: editing it adds or removes whitelisted users. |
| Roles without limits | Administrator | Users with these roles are never limited and never flagged. |
| Message shown to the signed-out device | "You have been signed out because…" | Shown on the device that was signed out. |
| Send signed-out device to | *(login page)* | Where that device goes after the message. |

Session Guard Pro adds per-user and per-LearnDash-group limits. Code can change the limit with the [`ndsg_user_max_devices`](developer.md#filters) filter.

## Detection tab

See [Sharing detection](detection.md) for how risk levels work.

| Setting | Default | Meaning |
|---|---|---|
| Monitoring | On | Collects overlaps (and viewing, with Pro) and rates them. Off stops collecting new data; nothing is deleted. |
| Grace period (seconds) | 120 | Two devices active together for less than this is ignored. |
| Add accounts to the list from risk level | Weak | Accounts at this level or higher appear under Flagged accounts. |
| "Very strong" when it happens (times in 7 days) | 3 | Repeats at the repeat level (weak; strong with Pro) that raise an account to *very strong*. |
| How many days back to check | 30 | The period the account level and the user pages count. |
| After "checked, OK", don't flag the account again for (days) | 30 | Quiet period after a case is closed. |

## Advanced tab

| Setting | Default | Notes |
|---|---|---|
| Heartbeat every (seconds) | 30 | How quickly a signed-out device notices (10–300). Only runs while the tab is visible. |
| Online if seen within (seconds) | 300 | A session counts as "online" if its last heartbeat was within this time. Must be at least twice the heartbeat interval. |
| Client IP from | Automatic | **Automatic** detects Cloudflare and local proxies (see below). The fixed options force one header; use them only if Automatic gets it wrong. |
| Store anonymized IPs | Off | Stores `1.2.3.0` instead of `1.2.3.4`. The network (/24) is still recorded for detection. |
| Keep session history for (days) | 90 | Ended sessions and overlaps older than this are deleted daily. |

### How "Automatic" finds the client IP

1. If `REMOTE_ADDR` is a private or loopback address (a local proxy such as nginx, Varnish or a load balancer, as on Cloudways), the client is the right-most public address in `X-Forwarded-For`, or else `X-Real-IP`.
2. If the address found so far is in **Cloudflare's IP ranges**, `CF-Connecting-IP` is used, and `CF-IPCountry` is trusted for the country.
3. If the web server already restored the visitor IP from Cloudflare (nginx `real_ip`, Apache `mod_remoteip`; dev.ease-it.co.il works like this), `CF-Connecting-IP` equals `REMOTE_ADDR`. That counts as Cloudflare, and the country header is used.
4. Otherwise `REMOTE_ADDR`.

Forwarding headers are only trusted when the request really comes from Cloudflare or a local proxy, so a visitor can't fake their IP or country by sending the headers themselves. Cloudflare's ranges are built in and refreshed daily from `api.cloudflare.com/client/v4/ips`. Below the setting, the screen shows what was detected on your own request.

With Session Guard Pro active, two more tabs appear (**Notifications** and **License**) and the Advanced tab gets a **Heartbeat endpoint** setting. They are documented in the Pro repo.
