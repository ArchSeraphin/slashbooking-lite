#!/usr/bin/env bash
# Build a distribution ZIP for slashbooking.
# Usage: bin/build-release.sh [version]
#        version defaults to the value read from src/Plugin.php
#
# This build has NO third-party runtime dependencies, so there is nothing to
# scope: vendor/ holds only Composer's PSR-4 autoloader for Slash\Booking.

set -euo pipefail

PLUGIN_SLUG="slashbooking"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^[[:space:]]*public const VERSION" "${ROOT_DIR}/src/Plugin.php" \
        | sed -E "s/.*'([^']+)'.*/\1/")
fi
ZIP_PATH="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "→ Building ${PLUGIN_SLUG} v${VERSION}"

# 1. Clean previous build
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING_DIR}"

# 2. Composer autoloader only (no runtime deps, no dev)
echo "→ composer install --no-dev (autoloader only)"
(cd "${ROOT_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)

# 3. Build the admin SPA assets (production webpack)
if [ "${SKIP_NPM_BUILD:-0}" = "1" ]; then
    [ -f "${ROOT_DIR}/assets/dist/index.jsx.js" ] \
        || { echo "✗ SKIP_NPM_BUILD=1 but assets/dist/index.jsx.js is missing." >&2; exit 1; }
    echo "→ SKIP_NPM_BUILD=1: reusing existing assets/dist bundle"
else
    echo "→ npm run build (SPA assets)"
    (cd "${ROOT_DIR}" && npm ci --silent && npm run build --silent)
fi

# 4. Stage the plugin tree
echo "→ staging files into ${STAGING_DIR}"
cp "${ROOT_DIR}/slashbooking.php" "${STAGING_DIR}/slashbooking.php"
cp "${ROOT_DIR}/readme.txt" "${STAGING_DIR}/readme.txt"
# composer.json must ship alongside vendor/ — wp.org's Plugin Check warns when a
# vendor/ directory is present without its companion composer.json.
cp "${ROOT_DIR}/composer.json" "${STAGING_DIR}/composer.json"
# package.json documents the build tool (@wordpress/scripts) and the `npm run
# build` step that regenerates assets/dist from src/Admin/react-app/src — so the
# compiled admin bundle has a public, reproducible source (wp.org guideline 4).
cp "${ROOT_DIR}/package.json" "${STAGING_DIR}/package.json"
[ -f "${ROOT_DIR}/uninstall.php" ] && cp "${ROOT_DIR}/uninstall.php" "${STAGING_DIR}/uninstall.php"
[ -f "${ROOT_DIR}/CHANGELOG.md" ] && cp "${ROOT_DIR}/CHANGELOG.md" "${STAGING_DIR}/CHANGELOG.md"
cp -R "${ROOT_DIR}/src" "${STAGING_DIR}/src"
cp -R "${ROOT_DIR}/vendor" "${STAGING_DIR}/vendor"
cp -R "${ROOT_DIR}/assets" "${STAGING_DIR}/assets"
[ -d "${ROOT_DIR}/languages" ] && cp -R "${ROOT_DIR}/languages" "${STAGING_DIR}/languages"

# Keep src/Admin/react-app (the JSX/SCSS sources of the admin bundle) in the ZIP
# so the human-readable, modifiable source of the compiled assets/dist files
# ships with the plugin — wp.org guideline 4 (no compiled-only / obfuscated code).

# Strip hidden files (.gitkeep, .DS_Store, …) — wp.org's Plugin Check rejects
# any hidden file in the ZIP.
find "${STAGING_DIR}" -name '.gitkeep' -delete
find "${STAGING_DIR}" -name '.DS_Store' -delete

# 5. ZIP
echo "→ packaging ZIP ${ZIP_PATH}"
(cd "${BUILD_DIR}" && zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}")

# 6. Checksum
CHECKSUM=$(shasum -a 256 "${ZIP_PATH}" | awk '{print $1}')
echo "${CHECKSUM}  $(basename "${ZIP_PATH}")" > "${ZIP_PATH}.sha256"

# 7. Restore the dev vendor so phpunit/phpstan/phpcs keep working locally.
echo "→ composer install (restore dev vendor)"
(cd "${ROOT_DIR}" && composer install --no-interaction --quiet)

SIZE=$(du -h "${ZIP_PATH}" | awk '{print $1}')
echo ""
echo "✓ Release built:"
echo "  File:     ${ZIP_PATH}"
echo "  Size:     ${SIZE}"
echo "  SHA-256:  ${CHECKSUM}"
