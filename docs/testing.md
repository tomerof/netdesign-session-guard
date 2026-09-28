# Local development and testing

[← Back to README](../README.md)

## Where the code lives

- Free plugin: `/home/tomer/projects/wordpress/wp-content/plugins/netdesign-session-guard` (public repo `tomerof/netdesign-session-guard`).
- Pro add-on, next to it: `.../plugins/netdesign-session-guard-pro` (private repo `tomerof/netdesign-session-guard-pro`).
- Both are tested inside the **ease-it** Docker stack, which already has LearnDash, WooCommerce and real data.

## Running it in the ease-it stack

The ease-it compose file lives in the theme folder and must not be edited. Use an override file that moves the ports (other projects use 8000/8080/3306/3307) and mounts both plugins:

```yaml
# compose.override.yml (keep it outside the repo)
services:
  php-backend:
    ports: !override
      - "8010:80"
    volumes:
      - /home/tomer/projects/wordpress/wp-content/plugins/netdesign-session-guard:/var/www/html/wp-content/plugins/netdesign-session-guard
      - /home/tomer/projects/wordpress/wp-content/plugins/netdesign-session-guard-pro:/var/www/html/wp-content/plugins/netdesign-session-guard-pro
  mysql:
    ports: !override
      - "3316:3306"
```

```bash
cd /home/tomer/projects/ease-it/wp-content/themes/ease-it
docker compose -f docker-compose.yml -f /path/to/compose.override.yml up -d php-backend mysql
```

The site is at http://localhost:8010. WP-CLI isn't in the image, and it's lost whenever the container is recreated. Install it again:

```bash
docker exec php-backend sh -c 'curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
alias wpd='docker exec -i -u 1000 php-backend wp'
wpd plugin activate netdesign-session-guard
```

Test the free plugin **alone** as well as with Pro: `wpd plugin deactivate netdesign-session-guard-pro`.

### Login on the local site

- The theme can turn email/password login on or off: ACF options `auth_with_email_and_password` and `auth_with_google`. Turn on email/password locally with `wpd option update options_auth_with_email_and_password 1`.
- Loginizer shows a math captcha on wp-login.php, so scripted logins through the form don't work. Use the helpers below instead.

## Simulating devices

`device-login.php` creates a real WordPress session for a user, as if they logged in from a given device, IP and country. It prints the logged-in cookie so you can use it with curl:

```php
<?php
// wpd eval-file - <user_login> <device_id(32 hex)> <ip> <user agent> [country] < device-login.php
list( $login, $did, $ip, $ua ) = array_slice( $args, 0, 4 );
$_SERVER['REMOTE_ADDR']       = $ip;
$_SERVER['HTTP_USER_AGENT']   = $ua;
$_SERVER['HTTP_CF_IPCOUNTRY'] = $args[4] ?? '';
$_COOKIE['ndsg_did']          = $did;
$user = get_user_by( 'login', $login );
add_action( 'set_logged_in_cookie', function ( $cookie ) { echo LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie ); }, 99 );
wp_set_auth_cookie( $user->ID, false );
```

```bash
COOKIE=$(wpd eval-file - student1 $(openssl rand -hex 16) 5.29.10.1 "Mozilla/5.0 (Windows NT 10.0) Chrome/128" IL < device-login.php | tail -1)

# load a page as that device and read the heartbeat URL and key
curl -s -b "$COOKIE" http://localhost:8010/my-account/ | grep -o 'ndsgHeartbeat={[^;]*'

# send a heartbeat (REST; with Pro active the page points to Pro's ping.php instead)
curl -s -d "k=$KEY&u=/my-account/" http://localhost:8010/wp-json/ndsg/v1/ping
```

For wp-admin pages, `wp_set_auth_cookie()` also sets the auth cookie. Hook `set_auth_cookie` the same way and send both cookies.

## Test checklist

Checked on 2026-09-28 against ease-it (WP 7.1, LearnDash 5.0.5, PHP 8.1):

| # | Scenario | Expected | Result |
|---|---|---|---|
| 1 | Activate | Tables created, cron scheduled, import job queued | ✅ |
| 2 | Login | Session row with device label, type, IP, network and country | ✅ |
| 3 | Logged-in page | `window.ndsgHeartbeat` printed; URL is the REST route (Pro: `ping.php`) | ✅ |
| 4 | Heartbeat POST / bad key | `{"s":"ok"}` / `{"s":"invalid"}` | ✅ |
| 5 | Enforce, limit 1, second device logs in | The older device ends with `policy`; its heartbeat gets `{"s":"revoked","r":"policy"}` and its next page load is signed out | ✅ |
| 6 | All admin screens, free alone | Live, Flagged (open / dismissed / paged), User page, 3 settings tabs: no errors; Pro note shown | ✅ |
| 7 | All admin screens, free + Pro | Notifications and License tabs, Pro fields on Advanced | ✅ |
| 8 | Cloudflare ranges refresh | 22 ranges saved from `api.cloudflare.com/client/v4/ips` | ✅ |
| 9 | Privacy policy guide | Suggested text listed under Settings → Privacy | ✅ |
| 10 | Hebrew admin | Tabs and screens in Hebrew, RTL | ✅ |

## Plugin Check

wordpress.org reviews submissions with [Plugin Check](https://wordpress.org/plugins/plugin-check/). Run it before every release:

```bash
wpd plugin install plugin-check --activate
wpd plugin check netdesign-session-guard
```

Expected result: no errors, and two warnings that are fine: `hidden_files` (`.gitignore`, not in the zip) and `load_plugin_textdomainFound` (loads the bundled Hebrew until translate.wordpress.org has it). Delete or move `dist/` before running it, or it also scans the built zip and its staged copy.

Queries on the plugin's own tables carry a documented `phpcs:disable` at the top of each file; keep new queries prepared (`$wpdb->prepare()`) or integer-cast.

## Lint

```bash
docker exec php-backend sh -c 'for f in $(find /var/www/html/wp-content/plugins/netdesign-session-guard -name "*.php"); do php -l $f; done' | grep -v "No syntax errors"
```
