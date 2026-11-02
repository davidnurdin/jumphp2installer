# Statistics: what your installers tell you

An installer tells its package's statistics address what happens on the computers it runs on, and the package's
owner sees it in a dashboard: on the server (**Packages › the package › Statistics**), or on jumphp.net
(**Studio › Installers › the installer › Statistics**).

| The dashboard shows | |
|---|---|
| installers downloaded | per system: each download of `/download/{id}/{platform}` (an installer generated for it) |
| started | runs that read the package (the wizard opened, or `--silent`), how many silent |
| installed | runs that went to the end, the share of the started, the median install time |
| cancelled | where: the page of the wizard, or the step under way (the window closed, Cancel, Escape) |
| failed | which step, and the most frequent errors |
| launched at the end | the finish page's "Launch" box was ticked |
| uninstalled | the uninstaller of an install ran (the same run: its id is kept in `uninstall.json`) |
| how far people go | of the started runs, how many saw each page of the wizard (where they stop) |
| by system, by version, day by day | 7, 30, 90 or 365 days |

## What is sent

One JSON POST per event, without blocking (a little at each frame, like the downloads), never in the way: an
address that does not answer changes nothing, and at the end the installer waits 2 seconds at most for what is
still on the way.

```json
{"event": "fail", "package": "k2q9x7a1", "app": "my-app", "version": "1.4.0", "os": "windows", "arch": "x86_64",
 "installer": "0.2.0", "install": "3f0c…(32 hex)", "mode": "window", "step": "download",
 "error": "HTTP 404 for https://example.com/my-app-windows-x86_64.zip"}
```

| Field | |
|---|---|
| `event` | `start`, `page`, `cancel`, `fail`, `install`, `run`, `uninstall` |
| `package`, `app`, `version` | the package's id, the script's `app.id`, the version installed |
| `os`, `arch`, `installer` | the system, its architecture, the installer's own version |
| `install` | an anonymous id of this computer for this package (since 0.2.2): a hash of the system's machine id (Linux `/etc/machine-id`, Windows `MachineGuid`, macOS `IOPlatformUUID`) with the statistics address, so the system's id never leaves the computer and two packages can not be linked; a random id kept in the user's data folder when the system has none. The same for every run on this computer (two computers count two installs, installing again counts once, jumphp.net's installer and a GitHub release's setup of the same package count one computer once) and for its uninstall later. Never the IP address (computers at home share one) |
| `mode` | `window` (the wizard) or `silent` |
| `page` | `page` and `cancel`: the wizard's page (`welcome`, `license`, `folder`, `options`, `install`, `finish`) |
| `step` | `fail` and `cancel`: the step under way (`download`, `extract`…) |
| `error` | `fail`: the message, at most 300 characters, the user's folders replaced by `{folder}`, `{home}`, `{temp}` (and what remains of `C:\Users\<name>`, `/home/<name>`) |
| `seconds` | `install`, `uninstall`: how long the run took |

Nothing personal: no name, no address of the user's folders, no list of files; servers keep no IP address (they
use it only, hashed, to limit how much one computer sends).

## Where it goes

The first of:

1. **The user's no**: `--no-stats`, or the environment variable `JUMPHP2INSTALLER_STATS=off`: nothing is sent.
2. The script: `stats: false`: nothing is sent; `stats: https://example.com/stats`: there.
3. The installer's own setting: `--stats=URL` on its command line, `JUMPHP2INSTALLER_STATS=URL`, or the address written
   into it with `--make=OUT --server=… --stats=URL` (its php.ini line `jumphp2installer.stats`).
4. A package of a server: `{server}/api/packages/{id}/stats` (jumphp.net: `https://jumphp.net/installers/api/packages/{id}/stats`).
5. Otherwise nothing: a local script, a static package or a GitHub release without a `stats:` address.

```yaml
# install.yaml: your own address (a package in GitHub releases, or your own statistics)
stats: https://example.com/installer-stats
```

```bash
# an installer of a GitHub release that tells your server
jumphp2installer-linux-x86_64 --make=My-App-setup.exe --server=github:me/my-app \
    --from=jumphp2installer-windows-x86_64.exe --stats=https://example.com/installer-stats
```

## Receiving them on your own address

Any web server can: accept `POST` with a JSON body (≤ 4 KB), answer `204`, keep what you need. In PHP,
`JumPHP2Installer\StatsReport` does the work the jumphp2installer server and jumphp.net do:

```php
use JumPHP2Installer\StatsReport;

$row = StatsReport::accept(json_decode(file_get_contents('php://input'), true));   // null: not an installer event
if ($row !== null) {
    $row['day'] = date('Y-m-d');
    file_put_contents('/var/lib/installer-stats.jsonl', json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
}
http_response_code($row === null ? 400 : 204);

// the dashboard's numbers: totals, funnel, cancelled, failed, errors, systems, versions, daily
$rows = array_map(fn ($l) => json_decode($l, true), file('/var/lib/installer-stats.jsonl'));
$report = StatsReport::build($rows, StatsReport::pagesOf(file_get_contents('install.yaml')), 30);
```

Downloads are counted by whoever hands the installers out: add rows `{"event": "download", "os": "<platform>",
"install": "", "day": …}` when you serve one.
