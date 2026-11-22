> **⚠️ This repository has moved to [https://git.jumphp.net/jumphp/jumphp2installer](https://git.jumphp.net/jumphp/jumphp2installer)** — it is archived here (read-only) and no longer updated.
> **⚠️ Ce dépôt a déménagé sur [https://git.jumphp.net/jumphp/jumphp2installer](https://git.jumphp.net/jumphp/jumphp2installer)** — il est archivé ici (lecture seule) et n'est plus mis à jour.

# jumphp2installer

**Native installers from a readable script.** Write what to install in a few lines of YAML (where the newest version
is, the pictures and the license to show, the folder, the options, the shortcuts); your users get one executable for
Windows, macOS or Linux that installs the newest version, with an uninstaller. Hosted on your own server (Symfony +
MariaDB in Docker, with an editor and a live preview), on [jumphp.net](https://jumphp.net), or in GitHub releases with
no server at all.

```yaml
app: {name: My App, publisher: Me, banner: banner.png}
version: {github: me/myapp}
pages: [welcome, folder, install, finish]
steps:
  - download: https://github.com/me/myapp/releases/download/v{version}/myapp-{platform}.zip
  - extract
  - shortcut: {target: "{folder}/myapp{exe}"}
  - uninstaller
```

- Documentation: [docs/installer/](docs/installer/README.md) · the script: [docs/installer/script.md](docs/installer/script.md)
  · en français : [docs/installer/guide.fr.md](docs/installer/guide.fr.md)
- The installers: [Releases](https://github.com/davidnurdin/jumphp2installer/releases/latest)
  (`jumphp2installer-windows-x86_64.exe`, `jumphp2installer-macos.zip`, `jumphp2installer-linux-x86_64`)
- Your own server: `docker compose -f server/compose.yaml up -d --build` ([docs/installer/server.md](docs/installer/server.md))
- No server: [docs/installer/github.md](docs/installer/github.md)

It is a [JumPHP](https://github.com/davidnurdin/jumPhp) project: the installer is a JumPHP desktop app (PHP + an HTML
wizard), so everything here (`bin/jumphp`, `engine/`, `docs/*.md`) is JumPHP's; jumphp2installer is `installer/`,
`games/installer/`, `server/`, `tools/` (package-release, github-release, make-installer) and `docs/installer/`.
`bin/jumphp update` brings a newer JumPHP in.

License: MIT.