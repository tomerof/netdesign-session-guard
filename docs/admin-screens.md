# Admin screens

[← Back to README](../README.md)

Everything is under the **Session Guard** menu (shield icon). By default only users with `manage_options` can see it; you can change that with the [`ndsg_capability`](developer.md#filters) filter. The menu shows a red badge with the number of open flags.

## Live

**Session Guard → Live**

- **Summary cards** (refreshed with the table): *Active now* (users active within the online window), *Weak today*, *Strong and above today* (users with an overlap at that level today, site timezone). With Pro also *Playing now* and *Medium today*. Each card has a one-line description.
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

- **How to read the risk levels?** A box with each level and what it means on this site (the video-based levels appear with Pro).
- **Tabs with counts:** *All open* (default: new and needs follow-up), *New*, *Needs follow-up*, *Caught and blocked*, *Checked, OK*, and *Excluded users*. A user search, and 30 per page. Sorted by risk level.
- **Columns:**
  - **Risk:** the highest level in the case, and "Flagged N times before" when the user had earlier flags.
  - **User:** name, email, phone (WooCommerce billing phone, if any), user ID, and the latest note.
  - **Summary:** number of events (overlaps), total device-overlap time, actual double viewing (with Pro), same / different network.
  - **Period:** first and last event.
  - **Handling:** the status dropdown (saves on change) and a quick note field.
- **Actions:** **Open case** (the user page with the full explanation, overlaps and history); for open flags also **Sign out everywhere**, and with Pro **Email user**.

The menu badge counts *New* flags.

**Excluded users** (last tab) lists every user excluded from monitoring (test accounts, staff), with their latest note and a **Monitor again** button. Users excluded by role aren't listed. (Called "whitelist" before 1.3.2.)

## User page

Open it from a user's name on Live or Flagged accounts, or from the **Sessions** link on **Users → All Users**.

- **Cards:** devices, networks, countries, logins and time online together in the look-back period.
- **Flag box:** the latest flag's risk level, date, handling status and the plain explanation.
- **Actions:** sign out everywhere, **Exclude from monitoring** / **Monitor again**.
- **Notes:** add a note; each shows its author and date and can be deleted.
- **Online at the same time:** the user's overlaps (a minute or longer): when, risk level (or *within the grace period*), how long, both devices with IP and country, and *Same network* / *Different networks*. Pro adds a *Viewing report* link.
- **Add-on panels:** Session Guard Pro adds *Email user*, a per-user device limit and, with LearnDash, the enrolled courses.
- **Session history:** the last 100 sessions, each with:
  - sign-in time and device (the full user agent shows on hover),
  - the first 8 characters of the device id, so you can tell browsers apart,
  - IP and country, last seen,
  - status: Active, Signed out, Device limit, Signed out by admin, Expired or Password reset.

## Settings

Three tabs, explained in [Configuration](configuration.md): **Device limit**, **Detection** and **Advanced**. Session Guard Pro adds **Notifications** and **License**.
