# Sharing detection: risk levels

[← Back to README](../README.md)

Since 1.3.0 detection works with **risk levels** instead of points. The clearest sign of a shared account is two devices being used at the same moment, so everything starts from **overlaps**: periods when two devices of one account are active together.

Detection never signs anyone out. It only informs you. Signing users out is the job of the [device limit](enforcement.md).

## Overlaps

Every heartbeat checks whether another device of the same user is active right now: a different device id whose last heartbeat was within the *gap* (2.5 heartbeat intervals, at least 2 and at most 15 minutes). If so, it extends the overlap row for that pair of sessions, or starts a new one. That is one extra indexed query per heartbeat, and more only while another device is really active.

Each overlap stores both sessions, both networks (IP ranges), the start and end time, the length, and (once rated) its level and facts.

## Risk levels

| Level | Key | Free plugin alone | With Session Guard Pro (video detection) |
|---|---|---|---|
| All clear | `ok` | Only one device active | — |
| Weak | `weak` | Two devices active together longer than the grace period | …and neither is playing a video |
| Medium | `medium` | — | Only one of them is watching a video (≥ 1 min) |
| Strong | `strong` | — | Both are watching videos at the same moment (≥ 1 min) |
| Very strong | `very_strong` | Different countries, or weak overlaps repeated *N* times in 7 days | Different countries, or strong overlaps repeated *N* times in 7 days |

Whether the devices are on the same network is shown with every overlap and in the explanation, but doesn't change the level. The list shows a "How to read the risk levels?" box with the descriptions that apply to the site.

## How it runs

Every **5 minutes** (cron `ndsg_assess`, schedule `ndsg_five_minutes`), the detector (`Detection\Detector::run()`):

1. takes the overlaps that changed since the previous run and are longer than the **grace period**,
2. rates each one (`Risk::assess_overlap()`, then the `ndsg_overlap_risk` filter, where Pro adds the video facts) and stores `level` and `facts` on the overlap,
3. fires `ndsg_overlap_assessed` (Pro sends its alert email there),
4. for each affected user: the account's level is the highest level of its rated overlaps in the period, raised to *very strong* when overlaps at the repeat level happened *N* times in the last 7 days,
5. if that level is at least **Add accounts to the list from risk level**, it creates or updates the user's flag.

Nothing runs during page views. Exempt users (whitelist, exempt roles) are skipped.

## Flags

- **One open flag per user.** New findings update it; its level only goes up.
- **Explanation in plain words** (`Detector::explain()`), for example: *Two devices were active at the same time 3 times in the last 30 days, 18 min in total (longest 6 min). Videos played on both devices at the same time for 6 min. The devices were on the same internet connection.*
- **Handling status**, set from the list or the user page:

| Status | Open? | Meaning |
|---|---|---|
| New | yes | Just flagged, nobody looked at it yet. The menu badge counts these. |
| Needs follow-up | yes | Being checked. |
| Caught and blocked | no | Sharing confirmed and acted on (blocking the account is up to you). |
| Checked, OK | no | Handled / not sharing. |

Closed cases leave the *All open* tab and stay in their own tab.

- **Closing a case** (checked OK, or caught and blocked) stops new flags for *dismiss days* (30; 0 = none). Either way, only overlaps that started after the check count toward the next flag, so reviewed events never re-open a case on their own.
- **Notes:** free-text notes per user, with author and date.
- **Whitelist / Excluded users:** never limited or flagged.

Flags from before 1.3.0 (points model) keep their old reasons and get the level *weak*; "Dismissed" became "Checked, OK".

## Settings (Settings → Detection)

| Setting | Default | |
|---|---|---|
| Monitoring | On | Off: nothing new is collected or rated (the heartbeat only runs if the device limit is enforced). Nothing is deleted. |
| Grace period (seconds) | 120 | Overlaps shorter than this are ignored. |
| Add accounts to the list from risk level | Weak | |
| "Very strong" when it happens (times in 7 days) | 3 | |
| How many days back to check | 30 | |
| After "checked, OK", don't flag the account again for (days) | 30 | |

## Tuning tips

- Getting weak flags from students who move between the computer and the phone? Raise the grace period, or list accounts from *medium* (needs Pro).
- Behind Cloudflare, check that *Client IP from* is **Automatic**; otherwise every overlap looks like the same network.
