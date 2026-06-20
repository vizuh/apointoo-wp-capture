#!/usr/bin/env bash
#
# Build a wp.org-ready distribution zip.
#
# The zip's SINGLE top-level folder is the slug (apointoo-capture/), so the
# installed plugin directory — and therefore the slug Plugin Check derives the
# text domain from — is exactly "apointoo-capture", regardless of the zip
# filename. Dev cruft is stripped via .distignore (which rsync reads directly:
# it treats #/; lines as comments).
#
# Usage: bin/build.sh
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="apointoo-capture"
MAIN="apointoo-wp-capture.php"
VERSION="$(grep -oiP '^\s*\*\s*Version:\s*\K[0-9.]+' "$MAIN")"
[ -n "$VERSION" ] || { echo "could not parse Version from $MAIN" >&2; exit 1; }

STAGE="build/$SLUG"
rm -rf build
mkdir -p "$STAGE" dist
rm -f "dist/$SLUG-$VERSION.zip"

rsync -a --exclude-from=.distignore --exclude='build' ./ "$STAGE/"

( cd build && zip -rqX "../dist/$SLUG-$VERSION.zip" "$SLUG" )
rm -rf build

echo "Built dist/$SLUG-$VERSION.zip"
unzip -l "dist/$SLUG-$VERSION.zip"
