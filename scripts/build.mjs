#!/usr/bin/env node
/**
 * Builds dist/netdesign-session-guard-<version>.zip: the package submitted to
 * wordpress.org (first review) and the content committed to the SVN trunk.
 * Needs `zip`.
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const slug = 'netdesign-session-guard';
const dist = join(root, 'dist');
const stage = join(dist, slug);

// Only what WordPress needs. No dev files, no docs, no build scripts.
const include = [`${slug}.php`, 'uninstall.php', 'readme.txt', 'src', 'assets', 'languages'];

const header = readFileSync(join(root, `${slug}.php`), 'utf8');
const version = (header.match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1];
const constVersion = (header.match(/const VERSION\s*=\s*'([^']+)'/) || [])[1];
const stable = (readFileSync(join(root, 'readme.txt'), 'utf8').match(/^Stable tag:\s*(\S+)/m) || [])[1];
if (!version || version !== constVersion || version !== stable) {
	throw new Error(`Version mismatch: header ${version}, VERSION ${constVersion}, readme Stable tag ${stable}`);
}

rmSync(dist, { recursive: true, force: true });
mkdirSync(stage, { recursive: true });
for (const item of include) {
	if (existsSync(join(root, item))) cpSync(join(root, item), join(stage, item), { recursive: true });
}
execFileSync('zip', ['-rq9', `${slug}-${version}.zip`, slug], { cwd: dist });
console.log(`Built dist/${slug}-${version}.zip; staged copy in dist/${slug}/ for SVN trunk.`);
