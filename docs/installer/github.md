# No server: GitHub releases

A package can live in the releases of your software's repository: `install.yaml`, its files, and its installers, all
as assets of each release. The installers read `https://github.com/<owner>/<repo>/releases/latest/download/install.yaml`,
so they always install the newest release.

1. In your repository, a folder (`installer/`) with `install.yaml` and its files (no spaces in names; a release has
   no folders, so `images/banner.png` is found as `banner.png`: names must differ).
2. Either a workflow that does it at each release:

   ```yaml
   # .github/workflows/installer.yml
   on:
     release:
       types: [published]
   jobs:
     installer:
       uses: davidnurdin/jumphp2installer/.github/workflows/package.yml@main
       with:
         folder: installer
       permissions:
         contents: write
   ```

   or by hand, from this repository (needs `gh auth login`):

   ```bash
   tools/github-release.sh owner/repo path/to/installer [tag]
   ```

3. The release then holds `My-App-setup.exe`, `My-App-setup-linux-x86_64`, `My-App-setup-macos.zip` (give these
   links: `https://github.com/owner/repo/releases/latest/download/My-App-setup.exe`), plus `install.yaml` and its files.

The script usually takes its version from the same releases:

```yaml
version: {github: owner/repo}
steps:
  - download: https://github.com/owner/repo/releases/download/v{version}/myapp-{platform}.zip
```

Any static host works the same way (GitHub Pages, a bucket, a folder of a web server): put `install.yaml` and its
files in a folder and make the installers with `php tools/package-release.php <folder> https://host/folder/ <bases> <out>`.
