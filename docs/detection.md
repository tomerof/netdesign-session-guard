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
| `overlap` | Minutes two devices were active at the same time ([overlaps](#overlaps)), overlaps under a minute ignored | 40 |
| `devices` | Different device ids | 30, +5 for each device above the threshold, max 50 |
| `networks` | Different IP ranges (/24 for IPv4, /48 for IPv6) | 20 |
| `countries` | Different countries (from `CF-IPCountry` when Cloudflare is verified, or `GEOIP_COUNTRY_CODE`) | 30 |
| `kicks` | Sessions ended by the device limit | 30 |

The score is the sum, capped at 100. Thresholds are set in **Settings → Detection**.

### Why these rules

- **Networks instead of IPs:** home and mobile IPs change often, but usually within the same range. A different range usually means a different place.
- **Kicks:** in Enforce mode, sharing shows up as devices signing each other out over and over. Five kicks in 30 days is a strong signal.
- **Concurrent and overlap:** in Monitor mode, two devices active at the same moment is the clearest sign of two people. *Concurrent* is a snapshot at login; *overlap* adds up how long it really lasted.

### Example

A shared account used on 4 devices across Israel and Germany, with 6 limit sign-outs:
`devices 35 + countries 30 + kicks 30 = 95`, so it is flagged.

A student who uses a laptop and a phone at home:
`devices 2` (below 3), `networks 1`, so the score is 0 and nothing is flagged.

## Overlaps

Every heartbeat checks whether another device of the same user is active right now: a different device id whose last heartbeat was within the *gap* (2.5 heartbeat intervals, at least 2 and at most 15 minutes). If so, it extends the overlap row for that pair of sessions, or starts a new one. That is one extra indexed query per heartbeat, and more only while another device is really active.

Each overlap stores both sessions, both networks (IP ranges), the start and end time and the length in seconds. The user page lists overlaps of a minute or more, with both devices, IPs and whether they share a network.

## Flags

- **One open flag per user.** New findings update it: reasons are merged, keeping the highest value for each rule, and the score is recalculated.
- **Handling status:** every flag has one, set from the list or the user page.

| Status | Open? | Meaning |
|---|---|---|
| New | yes | Just flagged, nobody looked at it yet. The menu badge counts these. |
| Needs follow-up | yes | Being checked. |
| Caught and blocked | yes | Sharing confirmed and acted on (blocking the account is up to you, e.g. a password reset or a user-blocking plugin). |
| Checked and resolved | no | Handled. |
| Dismissed (not sharing) | no | False alarm. |

- **Closed flags** (resolved or dismissed) stop new flags for *dismiss days* (30); after that a new flag can open.
- **Notes:** free-text notes per user, with author and date, shown on the user page and as a preview in the list.
- **Whitelist:** marks the user exempt (`ndsg_exempt` user meta). They are never limited or flagged. Whitelisting dismisses the open flag.

Flags are stored in `ndsg_flags`, with the reasons as JSON. Each flag on the screen is explained in plain words (`Detector::explain()`), for example *Signed in from 4 different devices in the last 30 days (flagged from 3).*

```json
{"devices":{"value":4,"threshold":3,"points":35},"countries":{"value":2,"threshold":2,"points":30}}
```

## Who is excluded

Users with an exempt role (Administrator by default) and whitelisted users.

## Tuning tips

- Start in **Monitor only** and watch the flags for a week before enforcing.
- Getting false flags from students who travel? Raise the `countries` threshold.
- Behind Cloudflare, check that *Client IP from* is **Automatic** and that the *Detected* line says Cloudflare. Otherwise every user shares a few Cloudflare networks and the network rule is useless.
