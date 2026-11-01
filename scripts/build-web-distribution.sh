#!/usr/bin/env bash
set -euo pipefail

# Produce a prebuilt distribution with Composer vendor and three Vite themes.
# This runs only on GitHub Actions, never on the end user's PHP hosting.
ROOT="$(pwd)"
WORK="$(mktemp -d)"
OUTPUT="$ROOT/dist"
if [[ $# -gt 0 ]]; then
  OUTPUT="$1"
fi
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/package/installer" "$WORK/package/runtime" "$OUTPUT"
cp -R "$ROOT/installer/." "$WORK/package/installer/"
MASTER=0
for preset in saas outil_metier blog; do
  APP="$WORK/client-$preset"
  mkdir -p "$APP/public"
  FS_ROOT="$ROOT" FS_APP="$APP" FS_PRESET="$preset" php -r '
    declare(strict_types=1);
    require getenv("FS_ROOT") . "/installer/FlightStarterInstaller.php";
    $config = require getenv("FS_ROOT") . "/installer/config.php";
    $config["target_dir"] = getenv("FS_APP");
    $config["project_name"] = "Flight Distribution";
    $config["base_url"] = "https://example.test";
    $config["design"]["preset"] = getenv("FS_PRESET");
    $config["frontend"]["build_dir"] = "build";
    $config["backoffice"]["default_email"] = "distribution@example.test";
    $config["backoffice"]["default_password"] = "BUILD_ONLY_RANDOM_1234567890_TEST";
    $config["composer"]["auto_install"] = false;
    $config["npm"]["auto_build"] = false;
    $config["installer"]["cleanup_on_success"] = false;
    $config["mcp"]["enabled"] = true;
    $config["stripe"]["enabled"] = true;
    $config["mail"]["default"] = "smtp";
    $config["social_auth"]["enabled"] = true;
    $config["social_auth"]["google"]["enabled"] = true;
    $config["social_auth"]["github"]["enabled"] = true;
    $installer = new FlightStarterInstaller($config, ["install-flight-starter.php", "--no-composer", "--keep-installer"]);
    exit($installer->run());
  '
  if [[ "$MASTER" -eq 0 ]]; then
    (
      cd "$APP"
      composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
    )
    cp -R "$APP/vendor" "$WORK/package/runtime/vendor"
    MASTER=1
  fi
  (
    cd "$APP"
    npm install --no-audit --no-fund
    npm run build
    test -s public/build/manifest.json
  )
  mkdir -p "$WORK/package/runtime/build/$preset"
  cp -R "$APP/public/build/." "$WORK/package/runtime/build/$preset/"
done
cat > "$WORK/package/distribution.json" <<'JSON'
{"schema":1,"product":"Flight Starter Kit","runtime":"prebuilt composer + vite","themes":["saas","outil_metier","blog"],"php_min":"8.2","install":"web-installer/install.php"}
JSON
(
 cd "$WORK/package"
 zip -q -r -X "$OUTPUT/flight-starter-runtime.zip" installer runtime distribution.json
)
(cd "$OUTPUT" && sha256sum flight-starter-runtime.zip > flight-starter-runtime.zip.sha256)
echo "Distribution ready:"
ls -lh "$OUTPUT"/flight-starter-runtime.zip*
cat "$OUTPUT/flight-starter-runtime.zip.sha256"
