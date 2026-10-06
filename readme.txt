=== Netdesign Session Guard ===
Contributors: netdesign
Tags: sessions, login, account sharing, concurrent logins, security
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See who is signed in right now, spot accounts shared between several people, and limit how many devices an account can use at once.

== Description ==

Selling courses, memberships or subscriptions? Session Guard shows you when one account is used by several people, and can stop it.

= Live view =

Everyone signed in right now: user, device and browser, IP and country, the page they're on, and when they were last seen. Users on more than one device at the same time stand out. Sign out any device with one click.

= Account-sharing detection with risk levels =

The clearest sign of a shared account is two devices used at the same moment. Session Guard records every **overlap** (two devices of one account active together), and rates it:

* **Weak:** two devices active together longer than the grace period.
* **Very strong:** the devices were in different countries, or it keeps happening (several times in a week).
* With the Pro add-on, which detects video playback: **medium** (only one device watching a video) and **strong** (both watching at the same time).

Accounts from the level you choose are listed under **Flagged accounts**, with a plain explanation, for example "Two devices were active at the same time 3 times in the last 30 days, 18 min in total. The devices were on the same internet connection." A "How to read the risk levels?" box explains each level.

= Handle each case =

* **Handling status** for every flagged account: New, Needs follow-up, Caught and blocked, or Checked, OK. Filter the list by status.
* **Notes** on any user ("called the student, the second phone is his wife's"), with author and date.
* **Online at the same time:** each user's page lists the periods when two of their devices were active together, how long, from which devices, and whether from the same network.
* Sign the user out everywhere, or exclude them from monitoring (test accounts, staff). The **Excluded users** tab lists them, and the setting of the same name lets you paste a list of emails or user IDs.
* **Monitoring switch:** turn collection off without deleting anything.

= Device limit =

Allow N devices per account, one by default. When someone signs in on another device, the **oldest device is signed out immediately**. An open tab gets the message within seconds, and any other device is signed out on its next click. Tabs in the same browser count as one device. Administrators (or any roles you choose) and whitelisted users are never limited.

= Built to stay light =

Normal page views add no database queries. Activity comes from a small heartbeat that runs only while the tab is visible, detection runs every 5 minutes in the background, and old history is cleaned up automatically.

= More =

* Automatic client-IP detection behind Cloudflare and local proxies, which visitors can't fake.
* Right-to-left support; Hebrew translation included.
* Imports sessions that were already open when you activate it.

= Session Guard Pro =

An optional paid add-on, sold separately by [Netdesign](https://dashboard.netdesign.media/buy/netdesign-session-guard-pro), adds:

* video detection (Vimeo, YouTube, HTML5) for the medium and strong levels, and a viewing report for every overlap,
* email alerts about flagged accounts and active suspects, with an email history, and a warning email to the user,
* per-user device limits,
* LearnDash: device limits per group, and the course each user is in,
* a faster heartbeat endpoint.

This plugin works fully on its own.

== Installation ==

1. Install from **Plugins → Add New**, or upload the zip, then activate.
2. Open **Session Guard → Live** to see who is online.
3. Leave **Settings → Device limit → Mode** on *Monitor only* for a few days, and review **Flagged accounts**.
4. When you're ready, switch the mode to **Enforce**.

Behind Cloudflare or a proxy? Leave **Settings → Advanced → Client IP from** on *Automatic*, and check the *Detected* line under it.

== Frequently Asked Questions ==

= Does it slow my site down? =

No. Page views do no extra database work. The heartbeat runs once every 30 seconds (configurable), and only while the tab is visible. Detection runs every 5 minutes in the background.

= What does a signed-out device see? =

A message that the account was signed in on another device (you can change the text), then the login page or a URL you choose.

= Will a user with two tabs open be signed out? =

No. Tabs in the same browser share one session and count as one device.

= Does it work with page caching? =

Yes. Logged-in pages are normally not cached, and the heartbeat is a REST request that caches skip.

= I use another plugin that limits logins. =

Use only one of them, or users will be signed out in confusing ways.

== Privacy ==

For every login session, Session Guard stores in your own database (`{prefix}ndsg_sessions`):

* a hash of the WordPress session token,
* a random device ID (the `ndsg_did` cookie, HttpOnly, valid 2 years),
* the browser user agent,
* the IP address (optionally anonymized) and its IP range,
* the country code, when Cloudflare or the server provides one,
* the last page visited, and timestamps.

Flags store the user ID, the rule values and the handling status. Overlaps store the two sessions, their networks and the times. Notes store the text, its author and the date.

Ended sessions and overlaps are deleted after 90 days (configurable). Notes are kept until you delete them or the user. Deleting a user deletes their data. Uninstalling the plugin removes all of its data.

**External request:** once a day the plugin downloads Cloudflare's public list of IP ranges from `https://api.cloudflare.com/client/v4/ips`. It uses them only to recognize requests coming through Cloudflare, and a built-in copy is used if the download fails. No data about your site or users is sent anywhere. This service is provided by Cloudflare, Inc.: [Terms of Use](https://www.cloudflare.com/website-terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

Mention this processing in your site's privacy policy. A suggested text: "To protect accounts from unauthorized sharing, we record the devices, approximate location (IP address and country) and activity times of signed-in sessions, for up to 90 days."

== Screenshots ==

1. Live view: who is online, their device, location and current page.
2. Flagged accounts, with scores, plain reasons and handling status.
3. A user's page: why they were flagged, notes, overlaps and session history.
4. Settings: device limit.

== Changelog ==

= 1.3.6 =
* The heartbeat can carry the title of the playing video (used by the Pro add-on).

= 1.3.5 =
* The heartbeat switches to the REST endpoint by itself when a faster endpoint is blocked by the host, and always uses https on https pages.

= 1.3.4 =
* "Caught and blocked" closes the case: it leaves the "All open" tab, like "Checked, OK".

= 1.3.3 =
* "Don't flag again for (days)" accepts 0. After "Checked, OK" only new overlaps count.

= 1.3.2 =
* Flagged accounts list: summary (events, device-overlap time, double viewing), period (first and last event), phone and user ID, quick note, "flagged before", and an "Open case" button.
* "Whitelist" is now "Exclude from monitoring" / "Excluded users".

= 1.3.1 =
* Live screen: cards for users active now and users at each risk level today.

= 1.3.0 =
* Risk levels (weak / medium / strong / very strong) replace the points score. Accounts are flagged from a level you choose, with a plain explanation and a "How to read the risk levels?" box.
* Detection runs every 5 minutes.
* Simpler detection settings: monitoring on/off, grace period in seconds, level to list from, repeats for "very strong", days to check.
* Excluded users box (emails, usernames or IDs), synced with the whitelist.
* Status "Checked, OK" replaces "Checked and resolved" and "Dismissed".

= 1.2.1 =
* The heartbeat identifies each page view, so add-ons can tell open tabs apart.

= 1.2.0 =
* Whitelisted users tab under Flagged accounts.
* Extension points for add-ons: heartbeat callback and `ndsg_heartbeat` action, `ndsg_user_page_sections`, and the heartbeat can report a playing video (keeps it running in a background tab).

= 1.1.0 =
* Overlaps: the heartbeat records when two devices of a user are active at the same time, and for how long. Listed on the user page.
* New detection rule: minutes online together (default: flag from 10 minutes, +40 points).
* Handling status for flags: New, Needs follow-up, Caught and blocked, Checked and resolved, Dismissed. Filter tabs with counts.
* Notes on users.
* Plain-language explanation of why an account was flagged.
* The menu badge counts new flags only.

= 1.0.0 =
* First release on wordpress.org: live sessions, account-sharing detection and flags, device limit (the oldest device is signed out), manual sign-out, whitelist, automatic client-IP detection, Hebrew translation.

== Upgrade Notice ==

= 1.3.0 =
Risk levels replace the points score. Existing flags keep their reasons; "Dismissed" flags become "Checked, OK".

= 1.2.0 =
Adds a list of whitelisted users.

= 1.1.0 =
Adds overlap tracking, handling statuses and notes. Existing open flags become "New".

= 1.0.0 =
First release.
