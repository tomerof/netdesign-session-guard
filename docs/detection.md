# Sharing detection

[← Back to README](../README.md)

Detection gives each account a score of 0–100 from simple, explainable rules. Accounts scoring at least the **flag threshold** (50 by default) appear under **Flagged accounts**. With Session Guard Pro you also get an email.

Detection never signs anyone out. It only informs you. Signing users out is the job of the [device limit](enforcement.md).

## When it runs

- **At login**, for the user who just signed in. This is where "devices online at the same time" is measured.
- **Every hour** (cron), for every user active since the previous run, in batches of 200.

It never runs during a normal page view.

## Rules

All counts cover the **look-back period** (30 days by default), except *concurrent*, which is measured at that moment.

| Rule | Measures | Points |
|---|---|---|
| `concurrent` | Different devices with a heartbeat in the last *online window* (5 min) | 40 |
| `devices` | Different device ids | 30, +5 for each device above the threshold, max 50 |
| `networks` | Different IP ranges (/24 for IPv4, /48 for IPv6) | 20 |
| `countries` | Different countries (from `CF-IPCountry` when Cloudflare is verified, or `GEOIP_COUNTRY_CODE`) | 30 |
| `kicks` | Sessions ended by the device limit | 30 |

The score is the sum, capped at 100. Thresholds are set in **Settings → Detection**.

### Why these rules

- **Networks instead of IPs:** home and mobile IPs change often, but usually within the same range. A different range usually means a different place.
- **Kicks:** in Enforce mode, sharing shows up as devices signing each other out over and over. Five kicks in 30 days is a strong signal.
- **Concurrent:** in Monitor mode, two devices active at the same moment is the clearest sign of two people.

### Example

A shared account used on 4 devices across Israel and Germany, with 6 limit sign-outs:
`devices 35 + countries 30 + kicks 30 = 95`, so it is flagged.

A student who uses a laptop and a phone at home:
`devices 2` (below 3), `networks 1`, so the score is 0 and nothing is flagged.

## Flags

- **One open flag per user.** New findings update it: reasons are merged, keeping the highest value for each rule, and the score is recalculated.
- **Dismiss:** removes the flag, and the user won't be flagged again for *dismiss days* (30).
- **Whitelist:** marks the user exempt (`ndsg_exempt` user meta). They are never limited or flagged.
- **Reopen:** from the *Dismissed* tab.

Flags are stored in `ndsg_flags`, with the reasons as JSON:

```json
{"devices":{"value":4,"threshold":3,"points":35},"countries":{"value":2,"threshold":2,"points":30}}
```

## Who is excluded

Users with an exempt role (Administrator by default) and whitelisted users.

## Tuning tips

- Start in **Monitor only** and watch the flags for a week before enforcing.
- Getting false flags from students who travel? Raise the `countries` threshold.
- Behind Cloudflare, check that *Client IP from* is **Automatic** and that the *Detected* line says Cloudflare. Otherwise every user shares a few Cloudflare networks and the network rule is useless.
