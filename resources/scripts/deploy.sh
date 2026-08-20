#!/usr/bin/env bash
#
# deploy.sh
#
# Runs the full production build pipeline in order:
#   1. build-hashed.sh   -> hashes/minifies/obfuscates assets into pSRC-out/
#   2. npm install        (build/ tooling, only if not already installed)
#   3. build-bundle.js   -> reads pSRC-out/, writes assets-bundle.zip +
#                            bundle-manifest.json INTO pSRC-out/
#
# After this finishes, pSRC-out/ contains everything you need -- upload
# the whole directory's contents to your site root.
#
# Usage:
#   ./scripts/deploy.sh [dist_dir] [version_label]
#
# Defaults: dist_dir=pSRC-out (passed through to build-hashed.sh),
#           version_label=auto-generated timestamp (passed to build-bundle.js)
#
# Any env vars build-hashed.sh reads (OBFUSCATE, TURNSTILE_SITE_KEY) and
# build-bundle.js reads (BUNDLE_SOURCE_DIR) still work the same way here --
# just export them before calling this script. If you pass a custom
# dist_dir as $1, this script also sets BUNDLE_SOURCE_DIR for you so
# build-bundle.js reads from the same place build-hashed.sh just wrote to.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

DIST_DIR="${1:-pSRC-out}"
VERSION_LABEL="${2:-}"

echo "== Step 1/3: build-hashed.sh =="
"$SCRIPT_DIR/build-hashed.sh" "$PROJECT_DIR" "$DIST_DIR"
echo

echo "== Step 2/3: npm install (scripts/build) =="
if [ -d "$SCRIPT_DIR/build/node_modules" ]; then
  echo "node_modules already present -- skipping (delete scripts/build/node_modules to force a reinstall)."
else
  (cd "$SCRIPT_DIR/build" && npm install)
fi
echo

echo "== Step 3/3: build-bundle.js =="
# build-bundle.js resolves BUNDLE_SOURCE_DIR relative to the project root,
# so pass along whatever dist_dir was actually used above.
BUNDLE_SOURCE_DIR="$DIST_DIR" node "$SCRIPT_DIR/build/build-bundle.js" "$VERSION_LABEL"

echo
echo "Done. Upload the contents of $DIST_DIR/ to your site root."
