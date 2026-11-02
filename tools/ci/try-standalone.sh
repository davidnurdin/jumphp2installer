#!/usr/bin/env bash
# CI: a standalone installer (tools/ci/make-standalone.sh) installs with no server, then its uninstaller removes it.
#   tools/ci/try-standalone.sh <standalone installer>     (macOS: its .zip)
set -euo pipefail
setup="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
work="$(mktemp -d)"
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) os=windows; export MSYS2_ARG_CONV_EXCL="*"; work="$(cygpath -m -l "$work")"; setup="$(cygpath -m "$setup")";; Darwin) os=macos;; *) os=linux;; esac
if [ "$os" = macos ]; then
  ditto -x -k "$setup" "$work/app"
  setup="$(ls -d "$work"/app/*.app)/Contents/MacOS/jumphp2installer"
fi
[ "$os" = windows ] || chmod +x "$setup"
set -x
"$setup" --check
folder="$work/installed/CI Standalone"
"$setup" --silent --folder="$folder"
grep -q "hello from the standalone app" "$folder/bin/hello.txt"
grep -q "CI Standalone 3.0.0 on $os" "$folder/version.txt"
# the uninstaller is the installer without the app inside it (Binary::withoutPayload())
case "$os" in
  windows) u="$folder/uninstall.exe" ;;
  macos) u="$folder/Uninstall CI Standalone.app/Contents/MacOS/jumphp2installer" ;;
  linux) u="$folder/uninstall" ;;
esac
size() { cat "$(dirname "$1")"/* | wc -c; }     # macOS: every executable of the .app (one per processor)
[ "$os" = macos ] || size() { wc -c < "$1"; }
[ "$(size "$u")" -lt "$(size "$setup")" ] || { echo "the uninstaller still holds the package"; exit 1; }
case "$os" in
  windows) "$folder/uninstall.exe" --uninstall --silent; sleep 5 ;;
  macos) "$folder/Uninstall CI Standalone.app/Contents/MacOS/jumphp2installer" --silent ;;
  linux) "$folder/uninstall" --silent ;;
esac
if [ -e "$folder" ]; then echo "left behind:"; ls -laR "$folder"; exit 1; fi
echo "==> $os: the standalone installer installed with no server, then uninstalled, nothing left"
