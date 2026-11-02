# The install script

An install script is a YAML file, `install.yaml`: what the installer **shows** (`pages`) and what it **does**
(`steps`). It is read by the installer on the user's computer, so the same script works on Windows, macOS and Linux:
variables (`{folder}`, `{exe}`, `{platform}`…) and `os:` conditions take care of the differences.

```yaml
installer: 1                       # the format of the script (optional)

app:
  name: My App                     # needed: the name shown everywhere
  publisher: Me
  website: https://example.com
  icon: icon.png                   # a file of the package (or an https:// address)
  banner: banner.png               # the picture on the left of the wizard: 172 x 420 (344 x 840 for sharp screens)
  accent: "#2f6fed"                # the colour of the buttons and the bar
  size: 40 MB                      # shown on the folder page (just text)

version:
  github: me/myapp                 # the newest GitHub release gives {version}

pages:
  - welcome
  - license: LICENSE.txt
  - folder: "{programs}/{app}"
  - options:
      desktop: A shortcut on the desktop
      menu: {label: A shortcut in the menu, default: false}
  - install
  - finish: {run: "{folder}/myapp{exe}"}

steps:
  - download: https://github.com/me/myapp/releases/download/v{version}/myapp-{platform}.zip
  - extract
  - executable: "{folder}/myapp"
    os: [linux, macos]
  - shortcut: {target: "{folder}/myapp{exe}", where: desktop}
    if: desktop
  - shortcut: {target: "{folder}/myapp{exe}", where: menu}
    if: menu
  - uninstaller

stats: https://example.com/stats   # optional: where the installer tells what happens (false: nowhere); stats.md
```

The editor of the server checks the script as you type: each problem with its line (`line 12: steps[2]: unknown
step: unzip`). Without a server: `jumphp2installer-linux-x86_64 --check --script=install.yaml`.

## `app`

| Key | | |
|---|---|---|
| `name` | needed | the software's name: window title, pages, shortcuts, uninstall entry |
| `id` | from the name | `my-app`: lower case letters, digits, `.` and `-`. Names the uninstall entry: keep it the same from one version to the next |
| `publisher`, `website` | | shown on the welcome page and in Windows' "Apps" list |
| `icon` | | shown at the top of the wizard and on the download page |
| `banner` | | the left side of the wizard (else a gradient of `accent` with the icon and the name) |
| `accent` | `#2f6fed` | `#rrggbb` |
| `size` | | text shown on the folder page ("Space needed: 40 MB") |

Files (`icon`, `banner`, the license, relative downloads) are files **of the package**: uploaded in the editor of the
server, or next to `install.yaml` in a GitHub release or a folder. An `https://` address works too.

## `version`

| Form | `{version}` |
|---|---|
| `version: 1.4.0` | fixed |
| `version: {github: owner/repo}` | the newest release of that repository (its tag, without the leading `v`) |
| `version: {latest: https://example.com/latest.json}` | what the address answers: JSON `{"version": "1.4.0"}` (or `tag_name`, `name`), or plain text `1.4.0` |
| `version: {latest: {url: https://..., field: data.current}}` | that field of the JSON |

Without `version`, `{version}` is empty. The installer asks for it each time it starts: **an installer given once
always installs the newest version.**

## `pages`

In the order of the wizard. Without `pages`: `welcome, folder, install, finish`.

| Page | Value | |
|---|---|---|
| `welcome` | text, or `{title, text}` | the text may have paragraphs (an empty line); default "Welcome to the installation of {app} {version}." |
| `license` | a file of the package, or `{text: ...}` | the user must tick "I accept" to go on |
| `folder` | the default folder, or `{default: ...}` | default `{programs}/{app}`; "Browse…" picks another; a folder that is not empty or already holds the app is said before going on |
| `options` | `name: label` or `name: {label, default}` | check boxes; `name` is used by the steps' `if:` (letters, digits, `_`); default checked |
| `install` | | needed: the steps run here, with a progress bar and the details |
| `finish` | text, or `{text, run, label}` | `run`: a "Start {app}" box (checked) starts that program when the user clicks Finish; one per system: `run: {windows: "{folder}/myapp.exe", macos: "{folder}/My App.app"}` |

## `steps`

Run in order on the `install` page. Any step may have:

- `if: desktop` (an option of an `options` page is checked) or `if: "!desktop"` (it is not);
- `os: windows` or `os: [linux, macos]`;
- `label: "Copying the samples"`: what the progress line says.

A failing step stops the installation and shows its error and the details.

| Step | Value | |
|---|---|---|
| `download` | an address, or `{url, sha256, name}` | downloads to a temporary file: `{download}`. The address may be a file of the package (`files/app.zip`). `sha256`: the install stops if the file differs. Redirects followed (GitHub release downloads work) |
| `extract` | the folder, or `{into, archive, strip}` | unpacks a `.zip`, `.tar`, `.tar.gz` (the last download by default) into `{folder}`. `strip: 1` drops the archive's top folder (`myapp-1.4.0/…`) |
| `copy` | `{from, to}` | a file of the package (or a file / folder on the computer) to a file or a folder (`to` ending with `/`) |
| `mkdir` | a folder | |
| `write` | `{file, text}` | writes a text file (variables expanded) |
| `executable` | a file | makes it executable (Linux, macOS; nothing on Windows) |
| `shortcut` | `{target, name, where, icon}` | `where`: `desktop`, `menu` or `both` (default). Windows: `.lnk`; Linux: `.desktop` files; macOS: a link on the desktop (no menu) |
| `run` | a command, or `{command, wait}` | runs it in `{folder}`; `wait: false` does not wait; a command that fails stops the installation |
| `delete` | a path | only inside the install folder, the home folder or the temporary folder |
| `uninstaller` | (nothing), or `{name}` | puts the uninstaller in the folder and registers it: Windows "Apps" list (no admin rights needed), Linux menu entry "Uninstall {app}" |

What the install created is written to `{folder}/uninstall.json`; the uninstaller removes exactly that (files,
folders left empty, shortcuts, the uninstall entry). A file the user added keeps its folder.

## `stats`

Where the installer tells what happens (started, the pages seen, cancelled, failed, installed, uninstalled): an
`https://` address, or `false` for nowhere. Without it, a package of a server tells that server (its dashboard), and
a package without a server tells nothing. [stats.md](stats.md).

## Variables

Written `{name}` anywhere in texts and paths. On Windows, `/` in paths becomes `\`.

| Variable | Windows | Linux | macOS |
|---|---|---|---|
| `{app}`, `{id}`, `{publisher}`, `{website}` | from `app` | | |
| `{version}` | from `version` | | |
| `{folder}` | the folder chosen on the folder page | | |
| `{programs}` | `%LOCALAPPDATA%\Programs` | `~/.local/opt` | `~/Applications` |
| `{desktop}` | the desktop | the desktop (`xdg-user-dir`) | `~/Desktop` |
| `{menu}` | the Start menu's Programs | `~/.local/share/applications` | (empty) |
| `{home}`, `{temp}` | the user's folder, the temporary folder | | |
| `{os}` | `windows` | `linux` | `macos` |
| `{arch}` | `x86_64`, `arm64`… | `x86_64`, `aarch64` | `arm64`, `x86_64` |
| `{platform}` | `windows-x86_64` | `linux-x86_64` | `macos-arm64` |
| `{exe}` | `.exe` | (empty) | (empty) |
| `{download}` | the file of the last `download` | | |

Everything installs **for the current user, without administrator rights**: `{programs}` is the user's own programs
folder on every system.

## Examples

- [`examples/github-app.yaml`](examples/github-app.yaml): software whose builds are in GitHub releases.
- [`examples/jumphp-app.yaml`](examples/jumphp-app.yaml): a JumPHP game or app exported with `bin/jumphp app:export`
  (one executable per system).
- [`examples/simple.yaml`](examples/simple.yaml): the smallest one.
