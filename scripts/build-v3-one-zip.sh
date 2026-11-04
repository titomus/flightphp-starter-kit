#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
VERSION="${VERSION:-v3.0.0-beta.2}"
[[ "$VERSION" =~ ^v3\.[0-9]+\.[0-9]+-(alpha|beta)\.[0-9]+$ ]] || { echo "Invalid prerelease version" >&2; exit 1; }
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

bash "$ROOT/scripts/build-web-distribution.sh" "$WORK/build"
mkdir -p "$WORK/package/public" "$WORK/package/.flight-package"
cp "$ROOT/web-installer/install.php" "$WORK/package/public/install.php"
unzip -q "$WORK/build/flight-starter-runtime.zip" -d "$WORK/package/.flight-package"
test -s "$WORK/package/.flight-package/runtime/vendor/autoload.php"
test -s "$WORK/package/.flight-package/installer/WebInstallEngine.php"
test -s "$WORK/package/.flight-package/distribution.json"
(
  cd "$WORK/package"
  zip -q -r -X "$OUT/flight-starter-$VERSION.zip" .
)
(
  cd "$OUT"
  sha256sum "flight-starter-$VERSION.zip" > "flight-starter-$VERSION.zip.sha256"
)
unzip -t "$OUT/flight-starter-$VERSION.zip" >/dev/null
echo "V3 package: $OUT/flight-starter-$VERSION.zip"
du -h "$OUT/flight-starter-$VERSION.zip"
