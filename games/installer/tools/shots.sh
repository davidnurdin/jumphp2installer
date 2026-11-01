#!/usr/bin/env bash
# Screenshots of each page of the wizard (the demo package): var/screenshots/page-<n>.png
# tools/shots.sh [pages...]  (from the repository's root: games/installer/tools/shots.sh 0 1 2 3 5)
set -euo pipefail
cd "$(dirname "$0")/../../.."
yaml=games/installer/config/game.yaml
cp "$yaml" "$yaml.orig"
trap 'mv "$yaml.orig" "$yaml"' EXIT
for n in "${@:-0 1 2 3 5}"; do
  for p in $n; do
    sed "s|url: index.html.*|url: \"index.html#page=$p\"|" "$yaml.orig" > "$yaml"
    bin/jumphp app:shot installer 240 "var/screenshots/page-$p.png" >/dev/null
    echo "games/installer/var/screenshots/page-$p.png"
  done
done
