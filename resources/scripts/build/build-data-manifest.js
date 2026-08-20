#!/usr/bin/env node
// scripts/build/build-data-manifest.js
//
// Generates a per-file checksum manifest for a data directory -- the
// counterpart on the server side to assets/js/pyq-data-cache.js on the
// client. Unlike build-bundle.js (which swaps the WHOLE app shell
// atomically), this is for a dataset that only ever grows -- new PYQ
// papers get added over time, so the client should keep everything it
// already has and just fetch what's new or changed, file by file.
//
// Run this any time you add/update files under a data directory (e.g.
// after dropping in a new paper's JSON + updating its year-N.json entry),
// then upload the directory (including the manifest.json this writes)
// to the server.
//
// Usage (can be run from anywhere -- paths are resolved relative to the
// project root regardless of your current directory):
//   node scripts/build/build-data-manifest.js assets/data/beu-pyq
//   node scripts/build/build-data-manifest.js assets/data/gate-pyq
//
// No dependencies beyond Node's own fs/crypto -- doesn't need the
// fflate install that build-bundle.js does.

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const target = process.argv[2];

if (!target) {
    console.error('Usage: node scripts/build/build-data-manifest.js <data-dir-relative-to-project-root>');
    process.exit(1);
}

const dataDir = path.resolve(ROOT, target);
if (!fs.existsSync(dataDir) || !fs.statSync(dataDir).isDirectory()) {
    console.error(`Not a directory: ${dataDir}`);
    process.exit(1);
}

function walk(dir, base) {
    let out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        const rel = path.relative(base, full).split(path.sep).join('/');
        if (entry.isDirectory()) out = out.concat(walk(full, base));
        else if (entry.name !== 'manifest.json') out.push(rel); // the manifest doesn't checksum itself
    }
    return out;
}

function main() {
    const files = walk(dataDir, dataDir).sort();
    const manifest = { generatedAt: new Date().toISOString(), files: {} };

    for (const rel of files) {
        const bytes = fs.readFileSync(path.join(dataDir, rel));
        manifest.files[rel] = {
            checksum: crypto.createHash('sha256').update(bytes).digest('hex'),
            size: bytes.length,
        };
    }

    fs.writeFileSync(path.join(dataDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
    console.log(`Wrote manifest for ${files.length} files -> ${path.join(target, 'manifest.json')}`);
}

main();
