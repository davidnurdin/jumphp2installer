# The installer

One executable per system, the same for every package:

| System | File | Needs |
|---|---|---|
| Windows 10 / 11 (64-bit) | `jumphp2installer-windows-x86_64.exe` | WebView2 (in Windows) for the window |
| macOS 12+ (Apple silicon, Intel) | `jumphp2installer-macos.zip` (`Setup.app`) | not signed: right click › Open the first time |
| Linux x86_64 | `jumphp2installer-linux-x86_64` | WebKitGTK 4.1 for the window (it offers to install it); `--silent` needs nothing |

Linux arm64 and phones: not yet (no JumPHP runtime for them).

Users never get these files as they are: a server (or `tools/package-release.php`) writes a package's address into
a copy: `My-App-setup-k2q9x7a1.exe`. A server may also name each download by an id of its own
(`My-App-k3x9p2qa7m4z.exe`, 12 letters and digits, since 0.2.5): the installer sends it back when it fetches its
package (`?download=<id>`) and in its statistics, so the server knows which downloads were run and installed.

## Where it finds its package

The first of:

1. `--script=install.yaml` (or env `JUMPHP2INSTALLER_SCRIPT`): a local script, its files next to it (authors);
2. `--uninstall`, or a program named `uninstall…` (or inside `Uninstall … .app`): the uninstaller of the install whose
   folder holds it (`uninstall.json`);
3. the server: `--server=`, env `JUMPHP2INSTALLER_SERVER`, the address written into the executable
   (`jumphp2installer.server` in its php.ini section), else `https://jumphp.net/installers`;
   the package: `--package=`, env `JUMPHP2INSTALLER_PACKAGE`, the id written into it, else the id in its file name
   (`…-setup-<id>`, even renamed `… (1).exe`); the download: `--download=`, env `JUMPHP2INSTALLER_DOWNLOAD`, else the id at
   the end of its file name (`My-App-k3x9p2qa7m4z (1).exe`; macOS: of its `.app`). A file renamed without it still
   installs (the package id written into it), only uncounted as that download;
4. a server `github:owner/repo`, `github:owner/repo@tag` or `https://host/folder/` (package `static`): `install.yaml`
   and its files at that address (GitHub: the newest release).

## Command line

```
setup                                  the wizard
setup --silent [--folder=DIR] [--option=NAME] [--no-option=NAME]
                                       installs with the defaults, prints the steps; exit 0 = installed
setup --check [--script=install.yaml]  reads the package, says what is wrong
uninstall --uninstall --silent         removes the app (Windows: QuietUninstallString does this)
setup --make=OUT --server=SOURCE [--package=ID] [--from=BASE] [--stats=URL]
                                       writes an installer of SOURCE (a copy of this one, or of BASE),
                                       that tells URL what happens (stats.md)
setup --no-stats ...                   tells nothing (or JUMPHP2INSTALLER_STATS=off)
setup --version | --help
```

## What it does

- Downloads without blocking (the window stays alive), straight to the disk, with redirects, HTTPS (the system's
  certificates, else its own), `sha256` checks.
- Extracts zip / tar / tar.gz, keeping Unix modes; refuses paths that go up (`../`).
- Writes `{folder}/uninstall.json` (what it created) and, with the `uninstaller` step, puts itself there as the
  uninstaller: `uninstall.exe` + an entry of Windows' "Apps" list (HKCU, no admin rights), `uninstall` + a menu
  entry on Linux, `Uninstall My App.app` on macOS.
- The uninstaller removes what the install made, folders left empty, shortcuts, the entry; files the user added
  stay. On Windows the `.exe` deletes itself a moment after it quits.
- Tells its statistics address what happens (started, pages, cancelled, failed, installed, uninstalled), without
  blocking and without anything personal: [stats.md](stats.md).
- The first launch unpacks the installer's own PHP code into the user's cache (`~/.cache/jumphp`,
  `%LOCALAPPDATA%\JumPHP`, `~/Library/Caches/JumPHP`).

## Building it

```bash
bin/jumphp install installer
games/installer/tools/sync-lib.sh                 # installer/ (the library) into its vendor/
bin/jumphp app:test installer                     # PHPUnit (AppTestCase) + the window on Xvfb
bin/jumphp app:shot installer                     # the demo package (games/installer/demo): var/screenshots/app.png
games/installer/tools/shots.sh 0 1 2 3 5          # each page
bin/jumphp app:export installer --linux --windows --macos
tools/ci/try-installer.sh games/installer/dist/standalone/installer-linux-x86_64   # install + uninstall for real
```

A tag `installer-v<version>` (game.yaml version; `v*` tags are JumPHP's own) runs `.github/workflows/release.yml`: the same on GitHub, tried on Linux, Windows and two Macs, then the
release. The library's own tests: `bin/jumphp php installer vendor/bin/phpunit` (after
`bin/jumphp php installer /usr/bin/composer install`).

## Not done yet

- Signing (Windows Authenticode, Apple notarization): the installers are not signed, so Windows SmartScreen and macOS
  Gatekeeper warn the first time.
- Installs for all users (Program Files, admin rights), file associations, services.
- Updating in place: installing again over an install works (files replaced), but nothing yet tells an installed app
  that a newer version exists (JumPHP apps have their own updater: `bin/jumphp app:release`).
- The wizard's own words exist in English and French (the script's texts are its author's).
- Windows: started by a short 8.3 path (`C:\Users\RUNNER~1\...`, as some scripts and CI runners do), the
  executable does not start ("PharException: manifest cannot be larger than 100 MB": StaticPHP's micro does not
  know itself by that name). Explorer and a normal command line use the long path; in scripts, start it by its long
  path (Git Bash: `cygpath -m -l`).
