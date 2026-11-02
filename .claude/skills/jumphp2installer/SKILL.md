---
name: jumphp2installer
description: Make native installers (Windows .exe, macOS .zip/.app, Linux binary) for software, JumPHP games and apps included, from a readable YAML install script (install.yaml), hosted in GitHub releases (no server), on a jumphp2installer server or on jumphp.net. Use when the user wants "an installer", "a setup.exe", installers in a GitHub release, an install wizard with pictures / license / folder / shortcuts / uninstaller, a silent install, or asks about jumphp2installer, install.yaml or its server.
---

# jumphp2installer

One installer executable per system, the same for every package; the package's address is written into a copy of it
(`My-App-setup.exe`). At run time it reads `install.yaml` (pages + steps), downloads the newest version and installs
it for the current user (no admin rights), with shortcuts and an uninstaller.

Repository: https://github.com/davidnurdin/jumphp2installer (local clone: `/home/david/Projets/jumphp2installer`).
Docs there: `docs/installer/` (README, script.md = the whole script reference, installer.md, server.md, github.md,
guide.fr.md, examples/). Read `docs/installer/script.md` before writing a script.

## Installers for a JumPHP app or game, in its GitHub release (the usual case)

1. Export the software, one file per system, and attach them to a release of its repository (tag `v1.2.0`):
   ```bash
   bin/jumphp app:export myapp --linux --windows --macos      # a desktop app (export:standalone for a game)
   gh release create v1.2.0 games/myapp/dist/standalone/myapp-linux-x86_64 \
       games/myapp/dist/standalone/myapp-windows-x86_64.exe games/myapp/dist/standalone/myapp-macos.zip -R owner/repo
   ```
   (`dist/standalone/myapp-macos-arm64` / `-x86_64` are intermediates: do not attach them.)
2. Write the package folder (e.g. `games/myapp/installer/`): `install.yaml` from
   `docs/installer/examples/jumphp-app.yaml` (version `{github: owner/repo}`, download URLs with `v{version}`, the
   macOS `.app` name = the app's `title` + `.app`), plus `icon.png`, `banner.png` (172 x 420 or 344 x 840; make one from
   the app's own graphics), optionally `LICENSE.txt`. File names: no spaces, all different (a release is flat).
3. Check it (no network to the package needed for problems):
   ```bash
   /home/david/Projets/jumphp2installer/games/installer/dist/standalone/installer-linux-x86_64 --check --script=games/myapp/installer/install.yaml
   ```
   (or the library: `cd /home/david/Projets/jumphp2installer && bin/jumphp php installer -r 'require "vendor/autoload.php"; ...Script::parse(...)'`).
4. Put the package and its installers into the release (needs `gh auth login`; run from the jumphp2installer clone):
   ```bash
   cd /home/david/Projets/jumphp2installer && tools/github-release.sh owner/repo /abs/path/games/myapp/installer v1.2.0
   ```
   It uses the base installers of `games/installer/dist/standalone/` when built there, else the newest
   jumphp2installer release, and uploads `install.yaml`, its files, `<Name>-setup.exe`, `<Name>-setup-linux-x86_64`,
   `<Name>-setup-macos.zip`. Or, in the software's repository, the reusable workflow
   `davidnurdin/jumphp2installer/.github/workflows/package.yml@main` on `release: published` (docs/installer/github.md).
5. Verify for real on Linux: download `<Name>-setup-linux-x86_64` from the release, then
   `HOME=$(mktemp -d) ./<Name>-setup-linux-x86_64 --silent` and `--uninstall --silent` with the `uninstall` it wrote
   (use a throwaway HOME; never install into the user's real HOME without asking). Windows / macOS: the repository's
   `tools/ci/try-installer.sh` runs the same check on GitHub runners.

Give the user the links `https://github.com/owner/repo/releases/latest/download/<Name>-setup.exe` (and the others).
Publishing a release is outward-facing: only when the user asked for it.

## Installer command line

`--silent [--folder=DIR] [--option=NAME] [--no-option=NAME]`, `--uninstall [--silent]`, `--check [--script=FILE]`,
`--make=OUT --server=SOURCE [--package=ID] [--from=BASE]` (SOURCE: `github:owner/repo`, `https://host/folder/`, or a
server + `--package`), `--version`, `--help`. Exit code 0 = done. `--make ... --stats=URL`: the installer tells URL
what happens; `--no-stats` / `JUMPHP2INSTALLER_STATS=off`: it tells nothing.

## Statistics

Installers tell started / pages seen / cancelled (where) / failed (step, error without the user's folders) /
installed / launched / uninstalled, with version, system and a random run id (docs/installer/stats.md). Where: the
script's `stats: https://…` (or `false`), else `--stats` / the executable's setting, else a server package's
`{server}/api/packages/{id}/stats`; a GitHub-release package without `stats:` tells nothing. The owner reads them in
the dashboard: server `/packages/{id}/stats`, jumphp.net Studio › Installers › Statistiques. To receive them on
one's own site: `JumPHP2Installer\StatsReport::accept()` / `build()`.

## A server instead

`docker compose -f server/compose.yaml up -d --build` in the clone (port 8095), `bin/console app:user:create`,
then the editor at `/packages` (docs/installer/server.md). On jumphp.net: Studio › Installers (signed in).

## Rebuilding the installer itself (only when its code changed)

```bash
cd /home/david/Projets/jumphp2installer
games/installer/tools/sync-lib.sh && bin/jumphp app:test installer
bin/jumphp app:export installer --linux --windows --macos
tools/ci/try-installer.sh games/installer/dist/standalone/installer-linux-x86_64
```
A tag `installer-v<version>` (not `v*`: those are JumPHP's) builds, tries (Linux, Windows, 2 Macs) and releases them (`.github/workflows/release.yml`).

## Limits (say so when relevant)

A repository releasing several products: `github:` follows its latest release, use the static mode (docs/installer/github.md).
Not signed (SmartScreen / Gatekeeper warn the first time; macOS: right click › Open); per-user installs only; no
Linux arm64, no phones; the wizard's own words in English and French. Windows: start it by its long path, never an
8.3 short one (`RUNNER~1`): micro then fails with "PharException: manifest cannot be larger than 100 MB".
