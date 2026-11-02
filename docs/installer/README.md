# jumphp2installer

Native installers for your software, written as a short, readable YAML script.

```
My-App-setup-k2q9x7a1.exe      ← what your users download (Windows; .zip for macOS, a binary for Linux)
        │  asks the server (or the GitHub release) written into it
        ▼
install.yaml + its pictures    ← what you write: pages, newest version, steps
        │
        ▼
the newest version of My App, installed with shortcuts and an uninstaller
```

- **One installer for every package.** The installer is a JumPHP desktop app (PHP + an HTML wizard, Tauri), built
  once per system. A server writes its own address and the package's id into it as it hands it out (the id is in the
  file name too). Nothing to compile when you publish a new version: the installer always installs the newest one.
- **A script anyone can read**: [script.md](script.md).
- **Anywhere**: your own server ([server.md](server.md): Symfony + MariaDB in Docker, with the editor), jumphp.net
  (signed in › Developer › Installers), or **no server at all**: GitHub releases ([github.md](github.md)).
- **No administrator rights**: everything goes into the user's own folders; Windows "Apps" list, Linux menu, macOS.
- **Statistics**: downloads, runs, where people stop, which step fails and why, in a dashboard; nothing personal, the
  user can refuse ([stats.md](stats.md)).
- **For admins too**: `--silent`, `--folder=`, `--option=`, `--uninstall --silent` ([installer.md](installer.md)).

| Page | |
|---|---|
| [script.md](script.md) | the install script: app, version, pages, steps, variables |
| [installer.md](installer.md) | the executable: where it finds its package, command line, uninstall, systems, building it |
| [server.md](server.md) | the web server: install it (Docker), accounts, the editor, the API any server can implement |
| [github.md](github.md) | packages and installers in GitHub releases, no server |
| [stats.md](stats.md) | statistics: what installers tell (started, cancelled where, failed why, installed), the dashboard |
| [guide.fr.md](guide.fr.md) | le guide en français |
| [examples/](examples/) | scripts to start from |

## The parts (this repository)

| Folder | |
|---|---|
| `installer/` | the library (PHP, `JumPHP2Installer\`): `Script` (reads and checks), `Vars`, `Session` (fetches a package), `Runner` (the steps, a little each frame), `Uninstaller`, `Download` (non-blocking HTTP), `Binary` (writes the address into an installer), `Stats` / `StatsReport` (statistics). Shared by all the others |
| `games/installer/` | the installer: a JumPHP desktop app (`bin/jumphp app:export installer --linux --windows --macos`) |
| `server/` | the web server (Symfony 7.4, MariaDB, FrankenPHP; `docker compose -f server/compose.yaml up -d --build`) |
| `tools/package-release.php`, `tools/github-release.sh`, `.github/workflows/package.yml` | GitHub releases |
| `.github/workflows/release.yml` | builds the installers, tries them on Linux, Windows, two Macs, releases them |
| `.claude/skills/jumphp2installer/` | the skill for Claude Code |
