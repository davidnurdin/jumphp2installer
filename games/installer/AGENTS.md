# installer — notes for agents

This is a JumPHP **desktop app** (not an SDL game). **Read `../../AGENTS.md` §16 and `../../docs/tauri.md` first.**

## Checklist

```bash
bin/jumphp app:test installer       # php -l + phpunit (AppTestCase, no window) + the app on Xvfb (must pass)
bin/jumphp app:shot installer       # -> games/installer/var/screenshots/app.png: open it and look
bin/jumphp app:export installer     # -> games/installer/dist/standalone/installer-linux-x86_64
```

## This app

- **What it is**: ...
- **PHP side**: `src/Main.php` (`AppMain::boot()`: commands, listeners, timers).
- **Pages**: `ui/` (`index.html`, `style.css`, `app.js`); `window.jumphp` is injected in every page.
- **Commands** the pages call: `greet`; plus the built-in `native.*` (`JumPHP\Tauri\NativeCommands`).
