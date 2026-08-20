#!/usr/bin/env node
// scripts/build/reformat-views.js
//
// assets/js/views/*.js each define one global const holding a huge
// chunk of HTML markup for the dashboard's page shell / a view (see
// dashboard.html's <script src="assets/js/views/..."> tags and its
// VIEW_HTML_MAP / injectRemainingViews() wiring -- that runtime
// mechanism is untouched by this script). They used to be written as
// `const NAME = "<escaped, single physical line>";` -- unreadable and
// undiffable. This script rewrites each one in place as
// `const NAME = \`<real multi-line markup>\`;` -- a template literal,
// so the actual HTML with its real indentation/newlines/quotes is
// what's sitting in the .js file, no separate source files and no
// extra build step needed to read or edit it.
//
// Safe to re-run: it reads whichever form (old double-quoted escaped
// string OR already-converted template literal) is currently in the
// file, decodes it with the real JS engine (so it's byte-exact either
// way), and only escapes the 3 characters that are actually special
// inside a template literal: backslash, backtick, and the `${` that
// starts an interpolation. Verified against the current codebase:
// none of these files contain a literal backtick or `${` today, but
// the script escapes them anyway in case a future edit introduces one
// (e.g. a `${'` inside embedded JS in an onclick attribute).
//
// Usage: node scripts/build/reformat-views.js [--check]
//   --check   don't write anything; exit 1 if any file isn't already
//             in the reformatted template-literal form (for CI).

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const DIR = path.join(ROOT, 'assets', 'js', 'views');
const FILES = require('./views-manifest.js');
const CHECK = process.argv.includes('--check');

function extractCurrentValue(text, constName) {
    const marker = `const ${constName} =`;
    const idx = text.indexOf(marker);
    if (idx === -1) throw new Error(`couldn't find "${marker}"`);
    let literal = text.slice(idx + marker.length).trim();
    if (!literal.endsWith(';')) throw new Error('expected a trailing ";"');
    literal = literal.slice(0, -1);
    // Let V8 decode it -- works whether `literal` is currently a
    // "double-quoted escaped string" or a `template literal`, so this
    // script can run on either the old or the already-converted form.
    return new Function(`"use strict"; return (${literal});`)();
}

function toTemplateLiteral(value) {
    // Only these three sequences are special inside `...`:
    //   \        -> escape sequence introducer
    //   `        -> ends the literal
    //   ${       -> starts an interpolation
    // Everything else (including literal newlines, single/double
    // quotes) is passed through as-is, which is the whole point --
    // that's what makes the output actually readable.
    const escaped = value
        .replace(/\\/g, '\\\\')
        .replace(/`/g, '\\`')
        .replace(/\$\{/g, '\\${');
    return '`' + escaped + '`';
}

function reformatOne({ jsFile, constName }) {
    const filePath = path.join(DIR, jsFile);
    const text = fs.readFileSync(filePath, 'utf8');
    const value = extractCurrentValue(text, constName);

    // Keep the leading `//` comment block exactly as-is.
    const lines = text.split('\n');
    const headerLines = [];
    let i = 0;
    while (i < lines.length && lines[i].startsWith('//')) {
        headerLines.push(lines[i]);
        i++;
    }

    const out = (headerLines.length ? headerLines.join('\n') + '\n' : '')
        + `const ${constName} = ${toTemplateLiteral(value)};\n`;

    if (CHECK) {
        const same = out === text;
        if (!same) console.error(`STALE: assets/js/views/${jsFile} is not in reformatted form -- run node scripts/build/reformat-views.js`);
        return same;
    }
    if (out === text) {
        console.log(`Unchanged: ${jsFile} (already reformatted)`);
        return true;
    }
    fs.writeFileSync(filePath, out, 'utf8');
    console.log(`Reformatted: ${jsFile} (${value.length} chars of markup, now multi-line)`);
    return true;
}

function main() {
    let ok = true;
    for (const entry of FILES) {
        ok = reformatOne(entry) && ok;
    }
    if (CHECK) {
        if (!ok) process.exit(1);
        console.log(`All ${FILES.length} view files already in reformatted form.`);
    } else {
        console.log(`\nDone -- ${FILES.length} files checked.`);
    }
}

main();
