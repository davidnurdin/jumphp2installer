#!/usr/bin/env bash
# Tries an installer for real on this computer (CI: Linux, Windows, macOS): serves a small package on localhost,
# makes its installer from the base one (--make), installs it silently, checks the files / shortcut / uninstall entry,
# then uninstalls it with the uninstaller it wrote and checks that nothing is left.
#   tools/ci/try-installer.sh <base installer executable>
set -euo pipefail
base="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
work="$(mktemp -d)"
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) os=windows; exe=.exe; work="$(cygpath -m "$work")"; base="$(cygpath -m "$base")";; Darwin) os=macos; exe=;; *) os=linux; exe=;; esac
echo "==> $os, work in $work"
pkg="$work/pkg"; mkdir -p "$pkg/app/bin"
printf 'hello from the app\n' > "$pkg/app/bin/hello.txt"
(cd "$pkg" && python3 -c "import shutil; shutil.make_archive('app', 'zip', '.', 'app')")
rm -rf "$pkg/app"
cat > "$pkg/install.yaml" <<'YAML'
app: {name: CI App, publisher: CI, size: 1 KB}
version: 1.0.0
pages:
  - welcome
  - folder
  - options:
      desktop: {label: A shortcut, default: true}
  - install
  - finish
steps:
  - download: app.zip
  - extract: {strip: 1}
  - write: {file: "{folder}/version.txt", text: "{app} {version} on {os}"}
  - shortcut: {target: "{folder}/version.txt", name: "CI App", where: desktop}
    if: desktop
  - uninstaller
YAML
port=8769
(cd "$pkg" && python3 -m http.server "$port" --bind 127.0.0.1 >/dev/null 2>&1) &
server=$!
trap 'kill $server 2>/dev/null || true' EXIT
sleep 2

"$base" --version
"$base" --make="$work/CI-App-setup$exe" --server="http://127.0.0.1:$port/" --from="$base"
setup="$work/CI-App-setup$exe"
[ "$os" = windows ] || chmod +x "$setup"
"$setup" --check
folder="$work/installed/CI App"
"$setup" --silent --folder="$folder"
test -f "$folder/bin/hello.txt"
grep -q "CI App 1.0.0 on $os" "$folder/version.txt"
test -f "$folder/uninstall.json"
case "$os" in
  windows)
    test -f "$folder/uninstall.exe"
    reg query 'HKCU\Software\Microsoft\Windows\CurrentVersion\Uninstall\jumphp2installer-ci-app' /v DisplayName
    ls "$USERPROFILE/Desktop/CI App.lnk"
    "$folder/uninstall.exe" --uninstall --silent
    sleep 5
    if reg query 'HKCU\Software\Microsoft\Windows\CurrentVersion\Uninstall\jumphp2installer-ci-app' >/dev/null 2>&1; then echo "the uninstall entry is still there"; exit 1; fi
    ! ls "$USERPROFILE/Desktop/CI App.lnk" 2>/dev/null
    ;;
  macos)
    test -d "$folder/Uninstall CI App.app"
    test -L "$HOME/Desktop/CI App"
    "$folder/Uninstall CI App.app/Contents/MacOS/jumphp2installer" --silent
    ! test -e "$HOME/Desktop/CI App"
    ;;
  linux)
    test -x "$folder/uninstall"
    "$folder/uninstall" --silent
    ;;
esac
if [ -e "$folder" ]; then echo "left behind:"; ls -laR "$folder"; exit 1; fi
echo "==> $os: installed, then uninstalled, nothing left"
