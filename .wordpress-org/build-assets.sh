#!/usr/bin/env bash
# Regenerate the WordPress.org plugin-directory assets from the brand SVG sources.
#   - icon-256x256.png / icon-128x128.png : the SlashBooking calendar + check mark
#   - banner-772x250.png / banner-1544x500.png : directory banner (1x + retina)
#
# Requires librsvg (rsvg-convert): brew install librsvg
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RSVG="$(command -v rsvg-convert || echo /opt/homebrew/bin/rsvg-convert)"

# Icons (square, transparent background)
"$RSVG" -w 256 -h 256 "$DIR/icon.svg" -o "$DIR/icon-256x256.png"
"$RSVG" -w 128 -h 128 "$DIR/icon.svg" -o "$DIR/icon-128x128.png"

# Banner — 1x and 2x retina
"$RSVG" -w 1544 -h 500 "$DIR/banner.svg" -o "$DIR/banner-1544x500.png"
"$RSVG" -w  772 -h 250 "$DIR/banner.svg" -o "$DIR/banner-772x250.png"

echo "✓ WordPress.org assets regenerated in $DIR"
