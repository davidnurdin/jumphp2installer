#!/usr/bin/env bash
# Copies the installer's page (games/installer/ui) to public/installer-ui: the editor's preview shows it.
set -euo pipefail
cd "$(dirname "$0")"
rm -rf public/installer-ui && mkdir -p public/installer-ui
cp ../games/installer/ui/index.html ../games/installer/ui/style.css ../games/installer/ui/app.js public/installer-ui/
