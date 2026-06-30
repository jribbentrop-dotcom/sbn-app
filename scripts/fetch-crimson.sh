#!/usr/bin/env bash
# Fetch the three Crimson Text roman weights (400/600/700) as woff2 and place
# them in public/fonts/crimson-text/ with the names the @font-face rules expect.
#
# Usage:  bash scripts/fetch-crimson.sh
#
# Google returns TWO @font-face blocks per weight: a "latin-ext" subset
# (unicode-range U+0100-...) and a "latin" subset (unicode-range U+0000-00FF).
# Chord symbols only need latin (A-G, digits, #, b, /, sharp/flat), so we pick
# the latin block per weight and ignore latin-ext.
#
# Hits fonts.googleapis.com + fonts.gstatic.com. No toolchain needed (curl only).
set -euo pipefail

DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/public/fonts/crimson-text"
mkdir -p "$DEST"

# A desktop Chrome UA makes Google return woff2 (a bare curl UA gets legacy ttf).
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

fetch_latin_url() {
  # $1 = weight. Prints the woff2 URL of the *latin* subset for that weight.
  local weight="$1"
  local css
  css="$(curl -fsSL -A "$UA" "https://fonts.googleapis.com/css2?family=Crimson+Text:wght@${weight}")"
  [ -n "$css" ] || { echo "ERROR: empty CSS for weight $weight" >&2; return 1; }

  # Walk @font-face blocks; remember the most recent woff2 url, and when we hit
  # the line carrying the basic-latin range (U+0000-00FF), emit that url.
  printf '%s\n' "$css" | awk '
    /\.woff2/      { if (match($0, /https:[^)]+\.woff2/)) url = substr($0, RSTART, RLENGTH) }
    /U\+0000-00FF/ { print url; exit }
  '
}

NAMES=(CrimsonText-Regular.woff2 CrimsonText-SemiBold.woff2 CrimsonText-Bold.woff2)
WEIGHTS=(400 600 700)

for i in 0 1 2; do
  w="${WEIGHTS[$i]}"
  echo "Resolving latin woff2 for weight $w..."
  url="$(fetch_latin_url "$w")"
  if [ -z "$url" ]; then
    echo "ERROR: could not find latin woff2 URL for weight $w" >&2
    exit 1
  fi
  echo "  -> ${NAMES[$i]}"
  curl -fsSL "$url" -o "$DEST/${NAMES[$i]}"
done

echo
echo "Done. Files in $DEST:"
ls -la "$DEST"
