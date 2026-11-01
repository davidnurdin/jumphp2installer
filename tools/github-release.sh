#!/usr/bin/env bash
# Puts a package and its installers into a GitHub release: no web server needed. Its installers (Name-setup.exe,
# Name-setup-linux-x86_64, Name-setup-macos.zip) read the NEWEST release of the repository: publish the next version
# with this again (or the workflow .github/workflows/package.yml), installers already given install it.
#
#   tools/github-release.sh <owner/repo> <package folder> [tag]     (default tag: the newest release)
#
# Needs gh (logged in: gh auth login) and PHP 8.2+ with zip (else bin/jumphp php, in Docker).
# Base installers: games/installer/dist/standalone/ when built here, else jumphp2installer's newest release.
set -euo pipefail
cd "$(dirname "$0")/.."
repo="${1:?usage: tools/github-release.sh <owner/repo> <package folder> [tag]}"
folder="${2:?package folder (install.yaml and its files)}"
tag="${3:-$(gh release view -R "$repo" --json tagName -q .tagName)}"
[ -f "$folder/install.yaml" ] || { echo "no $folder/install.yaml" >&2; exit 1; }
work="$(mktemp -d .build-release.XXXXXX)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bases" "$work/out"

local_dist=games/installer/dist/standalone
if [ -f "$local_dist/installer-linux-x86_64" ]; then
  cp "$local_dist/installer-linux-x86_64" "$work/bases/jumphp2installer-linux-x86_64"
  [ -f "$local_dist/installer-windows-x86_64.exe" ] && cp "$local_dist/installer-windows-x86_64.exe" "$work/bases/jumphp2installer-windows-x86_64.exe"
  [ -f "$local_dist/installer-macos.zip" ] && cp "$local_dist/installer-macos.zip" "$work/bases/jumphp2installer-macos.zip"
else
  gh release download -R "${JUMPHP2INSTALLER_REPO:-davidnurdin/jumphp2installer}" -p 'jumphp2installer-*' -D "$work/bases"
fi

if command -v php >/dev/null && php -m | grep -qi '^zip$'; then
  php tools/package-release.php "$folder" "github:$repo" "$work/bases" "$work/out"
else
  bin/jumphp php . tools/package-release.php "$folder" "github:$repo" "$work/bases" "$work/out"
fi
gh release upload -R "$repo" "$tag" "$work"/out/* --clobber
echo "==> $repo $tag: the package and its installers are in the release"
echo "    https://github.com/$repo/releases/latest"
