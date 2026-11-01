#!/usr/bin/env bash
# Copies installer/ (the library) into games/installer/vendor again: Composer copies a path package (no symbolic
# link, so that the export holds it without its own vendor/), and does not copy it again while its version stays.
set -euo pipefail
cd "$(dirname "$0")/../../.."
rm -rf games/installer/vendor/jumphp2installer/installer
bin/jumphp composer installer install -q
rm -rf games/installer/vendor/jumphp2installer/installer/{vendor,tests,true,composer.lock,phpunit.xml,.phpunit.cache}
