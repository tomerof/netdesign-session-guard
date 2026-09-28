# Privacy

[← Back to README](../README.md)

## What is stored

For each login session (`{prefix}ndsg_sessions`):

| Data | Why |
|---|---|
| User id | Whose session it is |
| SHA-256 hash of the session token, and the ping key | Matches the WordPress session. Neither can be used to sign in. |
| Random device id (`ndsg_did` cookie) | Counts distinct browsers |
| User agent and a short device label | To show "Chrome · Windows" |
| IP address (optionally anonymized), IP range, country code | Detection and display |
| Last URL and post id (and course id with Session Guard Pro + LearnDash) | "Viewing" column on the Live screen |
| Created, last seen, expiry, end time and reason | History |

Flags (`{prefix}ndsg_flags`) store the user id, the score and the rule values.

## Cookies

`ndsg_did`: random, HttpOnly, SameSite=Lax, valid for 2 years, set at login. It's a security and fraud-prevention cookie, needed for the account-protection service the site provides. List it in the site's cookie or privacy policy.

## Retention

Ended sessions older than **Keep session history for** (90 days by default) are deleted every day. Flags are kept until the user is deleted or the plugin is uninstalled.

## Anonymization

With **Store anonymized IPs** on, IPs are stored through `wp_privacy_anonymize_ip()` (last octet zeroed). The IP range used for detection is stored either way, since it can't identify a single address.

## Deleting a user

Deleting a WordPress user deletes all of their sessions and flags.

## Suggested privacy-policy text

The plugin adds this text to **Settings → Privacy → Policy guide**, so you can copy it into your policy:

> To protect accounts from unauthorized sharing, we record the devices, approximate location (IP address and country) and activity times of signed-in sessions. This data is kept for up to 90 days and is used only for account security.

## External requests

Once a day the plugin fetches Cloudflare's public list of IP ranges from `https://api.cloudflare.com/client/v4/ips` ([Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/)). Nothing about the site or its users is sent. If the request fails, the built-in list is used.
