#!/usr/bin/env bash
# CI: standalone installers of a small package (tools/make-installer.php --standalone), one per system, from the base
# installers in <folder>; tools/ci/try-standalone.sh then installs them with no server.
#   tools/ci/make-standalone.sh <folder of the base installers> <out folder>
set -euo pipefail
dir="$1"                                  # relative to the repository (bin/jumphp php runs in Docker there)
pkg=var/ci-standalone/pkg; rm -rf "$pkg"; mkdir -p "$pkg/app/bin"
printf 'hello from the standalone app\n' > "$pkg/app/bin/hello.txt"
(cd "$pkg" && python3 -c "import shutil; shutil.make_archive('app', 'zip', '.', 'app')")
rm -rf "$pkg/app"
cat > "$pkg/install.yaml" <<'YAML'
app: {name: CI Standalone, publisher: CI}
version: 3.0.0
pages: [welcome, folder, install, finish]
steps:
  - download: app.zip
  - extract: {strip: 1}
  - write: {file: "{folder}/version.txt", text: "{app} {version} on {os}"}
  - uninstaller
YAML
out="$2"
mkdir -p "$out"
for base in jumphp2installer-linux-x86_64 jumphp2installer-windows-x86_64.exe jumphp2installer-macos.zip; do
  bin/jumphp php . -d phar.readonly=0 tools/make-installer.php "$dir/$base" "$out/${base/jumphp2installer/ci-standalone}" --standalone "$pkg"
done
ls -la "$out"
