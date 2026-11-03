#!/usr/bin/env bash
set -euo pipefail

# V3 experimental self-contained ZIP. Run in CI after PHP/Node setup.
# No dependency installation is required on the target hosting.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
VERSION="${VERSION:-v3.0.0-alpha.1}"
mkdir -p "$OUT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

bash "$ROOT/scripts/build-web-distribution.sh" "$WORK/build"
mkdir -p "$WORK/package/public"
cp "$ROOT/web-installer/install.php" "$WORK/package/public/install.php"
unzip -q "$WORK/build/flight-starter-runtime.zip" -d "$WORK/package"
# The existing web installer downloads the runtime from GitHub. It must be
# adapted to consume this local package before this artifact is installable.
cat > "$WORK/package/INSTALLATION-NOT-READY.txt" <<'EOF'
EXPERIMENTAL V3 PACKAGE: NOT YET INSTALLABLE.
The installer still requires GitHub download and must be refactored to
use the local installer/ and runtime/ directories.
Do not deploy this package on a client site.
EOF
(
  cd "$WORK/package"
  zip -q -r -X "$OUT/flight-starter-$VERSION.zip" .
)
(
  cd "$OUT"
  sha256sum "flight-starter-$VERSION.zip" > "flight-starter-$VERSION.zip.sha256"
)
echo "Experimental package: $OUT/flight-starter-$VERSION.zip"
du -h "$OUT/flight-starter-$VERSION.zip"
