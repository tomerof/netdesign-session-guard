#!/usr/bin/env node
/**
 * Builds languages/netdesign-session-guard-{locale}.po from the POT file and
 * scripts/translations/{locale}.json (source string => translation, or
 * [singular, plural] for plural strings).
 *
 *   npm run i18n       # regenerate POT (needs WP-CLI), .po, .mo and .l10n.php
 *
 * Reports strings that are missing a translation, and translations whose
 * source string no longer exists.
 */
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const pot = readFileSync(join(root, 'languages/netdesign-session-guard.pot'), 'utf8');

const unescape = (s) => s.replace(/\\(["\\nt])/g, (_, c) => ({ n: '\n', t: '\t', '"': '"', '\\': '\\' })[c]);
const escape = (s) => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t');
const joinLines = (block, key) => {
	const m = block.match(new RegExp(`^${key} ((?:".*"\\n?)+)`, 'm'));
	return m ? unescape(m[1].trim().split('\n').map((l) => l.slice(1, -1)).join('')) : null;
};

const entries = pot.split(/\n\n/).slice(1); // first block is the header

for (const file of readdirSync(join(root, 'scripts/translations'))) {
	const locale = file.replace('.json', '');
	const dict = JSON.parse(readFileSync(join(root, 'scripts/translations', file), 'utf8'));
	const used = new Set();
	const missing = [];

	let out = `msgid ""
msgstr ""
"Project-Id-Version: Netdesign Session Guard\\n"
"Language: ${locale}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: netdesign-session-guard\\n"
`;

	for (const block of entries) {
		const id = joinLines(block, 'msgid');
		if (!id) continue;
		const plural = joinLines(block, 'msgid_plural');
		const refs = block.split('\n').filter((l) => l.startsWith('#')).join('\n');
		const t = dict[id];
		if (t === undefined) { if (!/^https?:\/\//.test(id)) missing.push(id); }
		else used.add(id);

		out += `\n${refs ? refs + '\n' : ''}msgid "${escape(id)}"\n`;
		if (plural) {
			const [one, many] = Array.isArray(t) ? t : ['', ''];
			out += `msgid_plural "${escape(plural)}"\nmsgstr[0] "${escape(one)}"\nmsgstr[1] "${escape(many)}"\n`;
		} else {
			out += `msgstr "${escape(typeof t === 'string' ? t : '')}"\n`;
		}
	}

	writeFileSync(join(root, `languages/netdesign-session-guard-${locale}.po`), out);
	const unused = Object.keys(dict).filter((k) => !used.has(k));
	console.log(`${locale}: ${used.size} translated, ${missing.length} missing, ${unused.length} unused`);
	missing.forEach((m) => console.log(`  missing: ${JSON.stringify(m)}`));
	unused.forEach((m) => console.log(`  unused:  ${JSON.stringify(m)}`));
}
