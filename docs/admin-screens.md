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

- **Tabs with counts:** *All open* (default), *New*, *Needs follow-up*, *Caught and blocked*, *Checked and resolved*, *Dismissed*. A user search, and 30 per page.
- **How are accounts flagged?** A short explanation of the scoring, with a link to the rules.
- **Columns:**
  - **User**, with a preview of the latest note.
  - **Score:** grey below 50, yellow 50–79, red 80 and above.
  - **Why:** each rule that fired as a plain sentence, with its points, and when the account was flagged.
  - **Handling:** the status dropdown. It saves as soon as you change it.
- **Actions** (open flags): **Sign out everywhere**, **Whitelist** (exempts the user and dismisses the flag), **Add note**. Pro adds **Email user**.

The menu badge counts *New* flags.

**Whitelisted users** (last tab) lists every whitelisted user with their latest note and a **Remove from whitelist** button. Users who are exempt by role aren't listed.

## User page

Open it from a user's name on Live or Flagged accounts, or from the **Sessions** link on **Users → All Users**.

- **Cards:** devices, networks, countries, logins and time online together in the look-back period.
- **Flag box:** the latest flag's score, date, handling status and a plain explanation of each reason.
- **Actions:** sign out everywhere, whitelist or remove from the whitelist.
- **Notes:** add a note; each shows its author and date and can be deleted.
- **Online at the same time:** the user's overlaps (a minute or longer): when, how long, both devices with IP and country, and *Same network* / *Different networks*.
- **Add-on panels:** Session Guard Pro adds *Email user*, a per-user device limit and, with LearnDash, the enrolled courses.
- **Session history:** the last 100 sessions, each with:
  - sign-in time and device (the full user agent shows on hover),
  - the first 8 characters of the device id, so you can tell browsers apart,
  - IP and country, last seen,
  - status: Active, Signed out, Device limit, Signed out by admin, Expired or Password reset.

## Settings

Three tabs, explained in [Configuration](configuration.md): **Device limit**, **Detection** and **Advanced**. Session Guard Pro adds **Notifications** and **License**.
