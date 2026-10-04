# Releasing to wordpress.org

[← Back to README](../README.md)

The free plugin is distributed by wordpress.org. The GitHub repo is the source of truth; wordpress.org's SVN repository is the delivery channel. (Session Guard Pro is released separately, through GitHub releases and the Netdesign Dashboard.)

## First submission

1. `node scripts/build.mjs` builds `dist/netdesign-session-guard-<version>.zip`. It checks that the header version, the `VERSION` constant and the readme `Stable tag` agree.
2. Upload the zip at https://wordpress.org/plugins/developers/add/ while signed in as **netdesign**.
3. The review team emails tomer@netdesign.media. Fix what they ask for, rebuild, and upload the new zip on the same page (or reply to the email).
4. On approval you get SVN access: `https://plugins.svn.wordpress.org/netdesign-session-guard/`.

Submitted: 2026-09-28.

## Releasing a new version

1. Update the version in three places: the `Version:` header, `const VERSION` in `netdesign-session-guard.php`, and `Stable tag:` in `readme.txt`. Update `package.json` too.
2. Add a `= x.y.z =` entry under `== Changelog ==` in `readme.txt`, and a section in `CHANGELOG.md`.
3. Bump `Schema::DB_VERSION` if a table changed. `Schema::maybe_upgrade()` runs `dbDelta` on the first request after the update.
4. Run `npm run i18n` if strings changed, then Plugin Check (see [Testing](testing.md)).
5. Commit and tag on GitHub: `git tag vX.Y.Z && git push origin main vX.Y.Z`.
   Then publish the GitHub release with the versioned zip: `gh release create vX.Y.Z dist/netdesign-session-guard-X.Y.Z.zip --title vX.Y.Z --notes-file <notes>` (notes from `CHANGELOG.md`).
6. Build and publish to SVN:

```bash
node scripts/build.mjs          # dist/netdesign-session-guard/ is the staged trunk

svn co https://plugins.svn.wordpress.org/netdesign-session-guard ~/svn/netdesign-session-guard
cd ~/svn/netdesign-session-guard
rsync -a --delete /home/tomer/projects/wordpress/wp-content/plugins/netdesign-session-guard/dist/netdesign-session-guard/ trunk/
svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm
svn cp trunk tags/X.Y.Z
svn ci -m "Release X.Y.Z" --username netdesign
```

Sites get the update from wordpress.org within hours.

## Listing assets

Banners, the icon and screenshots go in the SVN `assets/` folder (not in trunk): `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png`, `screenshot-1.png` … matching the `== Screenshots ==` list in `readme.txt`.

## What goes in the zip

Set in `scripts/build.mjs` (`include`): the main file, `uninstall.php`, `readme.txt`, `src/`, `assets/` and `languages/`. `docs/`, `scripts/`, `README.md` and `CHANGELOG.md` stay in the GitHub repo only.

## Versioning

Semantic versioning. Keep the free plugin backward compatible with Pro: Pro only uses the hooks listed in [Developer reference](developer.md#how-session-guard-pro-plugs-in). If one has to change, release a Pro version that handles both first.
