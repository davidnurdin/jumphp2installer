# The server

`server/`: Symfony 7.4, MariaDB, FrankenPHP, in Docker. Accounts, packages (an install script + its files), the
editor (YAML with its problems as you type, the installer's real page as a live preview, Windows / macOS / Linux),
publishing, download pages and the installers.

```bash
docker compose -f server/compose.yaml up -d --build          # http://localhost:8095
docker compose -f server/compose.yaml exec app bin/console app:user:create you@example.com
docker compose -f server/compose.yaml exec app bin/console app:installers:fetch     # base installers (done at the first start)
```

| Setting (environment) | |
|---|---|
| `JUMPHP2INSTALLER_PUBLIC_URL` | the address written into the installers (`https://get.example.com`); empty: each request's own |
| `JUMPHP2INSTALLER_PORT` | 8095 |
| `APP_SECRET` | empty: made once and kept in the `packages` volume |
| `DB_PASSWORD` | MariaDB's |
| `JUMPHP2INSTALLER_FETCH` | 1: fetch the base installers of the newest jumphp2installer release at the first start |

Volumes: `database`, `packages` (the packages' files), `installers` (the base installers). Behind a proxy (Traefik,
Caddy, nginx) with https: forward to port 80 of `app` and set `JUMPHP2INSTALLER_PUBLIC_URL`.

## Using it

1. Sign in, **New package** (the software's name): a script to start from.
2. Write the script; drop the pictures and the license into **Files**; the preview shows the wizard as users will
   see it (Refresh, or it follows your typing). Ctrl+S saves.
3. Try it: the download links work for you before the package is published.
4. **Published**: the download page (`/p/<id>`) and the installers work for everyone. Downloads are counted.

## The API (what any server implements)

An installer only needs these, without an account (CORS open):

| | |
|---|---|
| `GET {server}/api/packages/{id}` | `{"id", "name", "script": "<the YAML>", "assets": "<address of its files>/"}`; 404: unknown or not published |
| `GET {assets}/{path}` | a file of the package |

and to hand installers out:

| | |
|---|---|
| `GET {server}/download/{id}/{platform}` | `windows-x86_64` / `macos` / `linux-x86_64`: the base installer with `{server}` and `{id}` written in, named `Name-setup-<id>.exe` / `.zip` / (nothing) |

Writing them in: `JumPHP2Installer\Binary::patchFile($base, $out, $server, $id)` (PHP, no dependency), or
`php tools/make-installer.php <base> <out> <server> <id>`, or `<installer> --make=<out> --server=<server> --package=<id>`.
`{server}` may have a path (`https://jumphp.net/installers`). A static host needs no code at all: [github.md](github.md).

## Development

```bash
cd server && ./sync-ui.sh                            # the installer's page for the preview (public/installer-ui)
docker compose -f server/compose.yaml up -d --build  # the simplest: the same as production
```

`server/lib/jumphp2installer` is a link to `installer/` (the image copies `installer/src` there).
