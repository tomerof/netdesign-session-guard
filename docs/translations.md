# Translations (Hebrew / English)

[← Back to README](../README.md)

The plugin is written in English and ships with a full **Hebrew (he_IL)** translation. Once the plugin is on wordpress.org, translations can also be contributed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/netdesign-session-guard/); language packs from there take priority over the bundled files.

Session Guard Pro has its own text domain (`netdesign-session-guard-pro`) and translation files, managed the same way in its repo.

## Which language is shown

| Where | Language |
|---|---|
| Admin screens (Live, Flagged, User, Settings) | The admin user's own language (**Users → Profile → Language**), falling back to the site language |
| Message on a signed-out device, front-end notice | Site language |

Right-to-left layout comes from WordPress (`is_rtl()`). The admin CSS uses logical properties (`margin-inline-start`, etc.), so it mirrors automatically.

### Editable texts

The *kick message* is editable in settings (Pro adds the user email subject and body, which work the same way). While left at their default they are stored **empty**, and the translated default is used at display time. So a Hebrew site shows the Hebrew default, and an English admin sees the English one. Once you edit a text, your version is used as written for everyone.

## Files

```
languages/
├── netdesign-session-guard.pot               template (all strings, generated)
├── netdesign-session-guard-he_IL.po          Hebrew, generated from the JSON below
├── netdesign-session-guard-he_IL.mo          compiled
└── netdesign-session-guard-he_IL.l10n.php    compiled, faster format (WordPress 6.5+)
scripts/translations/he_IL.json         ← edit translations here
```

The JSON maps the English source string to its translation. Plural strings map to `[singular, plural]`:

```json
{
	"Live sessions": "חיבורים פעילים",
	"Enforcing: %d device per user": ["אכיפה: מכשיר אחד למשתמש", "אכיפה: %d מכשירים למשתמש"]
}
```

## Updating translations

After adding or changing strings in the code:

```bash
npm run i18n
```

This command:

1. regenerates the POT with WP-CLI inside the ease-it `php-backend` container (see [Testing](testing.md)),
2. runs `scripts/i18n.mjs`, which builds the `.po` from the JSON and lists **missing** and **unused** strings,
3. compiles the `.mo` and `.l10n.php` files.

Add the missing strings to `he_IL.json` and run it again until it reports `0 missing`. Commit all the files in `languages/`.

## Adding a language

Create `scripts/translations/{locale}.json` (for example `ar.json`) and run `npm run i18n`. For a language with more than two plural forms, adjust the `Plural-Forms` header in `scripts/i18n.mjs`.

## Rules for new strings

- Always use the `netdesign-session-guard` text domain.
- Add a `/* translators: */` comment on the line before any string with placeholders.
- JavaScript strings are translated in PHP and passed to the script (`wp_localize_script` / inline config). Don't hard-code text in JS.
