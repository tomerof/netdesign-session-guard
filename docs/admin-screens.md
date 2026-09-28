# Admin screens

[← Back to README](../README.md)

Everything is under the **Session Guard** menu (shield icon). By default only users with `manage_options` can see it; you can change that with the [`ndsg_capability`](developer.md#filters) filter. The menu shows a red badge with the number of open flags.

## Live

**Session Guard → Live**

- **Summary cards:** users online, active sessions, users on 2+ devices now, and open flags (links to the list).
- **Mode badge:** *Monitor only*, or *Enforcing: N devices per user*.
- **Table:** one row per online session.
  - Columns: user, device and browser (with a desktop, phone or tablet icon), IP and country, what they're viewing (the page title or URL; with Pro + LearnDash, the course in bold plus the lesson), and last seen.
  - Users on more than one device get a yellow side marker and an "N devices" label.
  - **Sign out** ends that one device straight away.
- **Filter box:** by name, email, IP, page or course. **Only users on 2+ devices** narrows the list further.
- **Refresh:** every 15 seconds, and only while the admin tab is visible.

Data comes from `GET /wp-json/ndsg/v1/live` (up to 500 sessions).

## Flagged accounts

**Session Guard → Flagged accounts**

- **Open** and **Dismissed** tabs, a user search, and 30 per page.
- **Columns:**
  - **Score:** grey below 50, yellow 50–79, red 80 and above.
  - **Why:** every rule that fired, with its value and threshold.
  - **Updated:** when the flag last changed.
- **Actions:**
  - **Dismiss**
  - **Sign out everywhere**
  - **Whitelist:** exempts the user and dismisses the flag
  - **Reopen:** on dismissed flags

## User page

Open it from a user's name on Live or Flagged accounts, or from the **Sessions** link on **Users → All Users**.

- **Cards:** devices, networks, countries and logins in the look-back period, plus the open flag's score and reasons.
- **Actions:** sign out everywhere, dismiss the flag, whitelist or remove from the whitelist.
- **Add-on panels:** Session Guard Pro adds *Email user*, a per-user device limit and, with LearnDash, the enrolled courses.
- **Session history:** the last 100 sessions, each with:
  - sign-in time and device (the full user agent shows on hover),
  - the first 8 characters of the device id, so you can tell browsers apart,
  - IP and country, last seen,
  - status: Active, Signed out, Device limit, Signed out by admin, Expired or Password reset.

## Settings

Three tabs, explained in [Configuration](configuration.md): **Device limit**, **Detection** and **Advanced**. Session Guard Pro adds **Notifications** and **License**.
