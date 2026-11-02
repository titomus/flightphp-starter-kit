#!/usr/bin/env bash
set -euo pipefail
ROOT="$(mktemp -d)"
PORT=8793
mkdir -p "$ROOT/public"
cp web-installer/install.php "$ROOT/public/install.php"
php -S "127.0.0.1:$PORT" -t "$ROOT/public" > "$ROOT/http.log" 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null || true; rm -rf "$ROOT"; }
trap cleanup EXIT
sleep 1

BASE="http://127.0.0.1:$PORT/install.php"
HTTP=$(curl -sS -o "$ROOT/initial.html" -w '%{http_code}' -c "$ROOT/cookies" "$BASE")
test "$HTTP" = 200
grep -q 'Déverrouiller' "$ROOT/initial.html"
test -s "$ROOT/.flight-install-access"
KEY="$(cat "$ROOT/.flight-install-access")"
test "$(printf '%s' "$KEY" | wc -c)" -eq 48
CSRF="$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$ROOT/initial.html" | head -1 | cut -d '"' -f4)"
test "$(printf '%s' "$CSRF" | wc -c)" -eq 48
HTTP=$(curl -sS -o "$ROOT/rejected.html" -w '%{http_code}' -b "$ROOT/cookies" -c "$ROOT/cookies" \
  -d "_csrf=$CSRF" -d "_unlock=1" -d "access_key=incorrect" "$BASE")
test "$HTTP" = 403
test ! -e "$ROOT/app/bootstrap.php"
HTTP=$(curl -sS -o /dev/null -w '%{http_code}' -b "$ROOT/cookies" -c "$ROOT/cookies" \
  -d "_csrf=$CSRF" -d "_unlock=1" --data-urlencode "access_key=$KEY" "$BASE")
test "$HTTP" = 303
HTTP=$(curl -sS -o "$ROOT/unlocked.html" -w '%{http_code}' -b "$ROOT/cookies" -c "$ROOT/cookies" "$BASE")
test "$HTTP" = 200
grep -q 'name="github_token"' "$ROOT/unlocked.html"
grep -q 'name="project_name"' "$ROOT/unlocked.html"
if grep -q "$KEY" "$ROOT/unlocked.html"; then
  echo "Access key leaked into response"
  exit 1
fi
HTTP=$(curl -sS -o "$ROOT/csrf.html" -w '%{http_code}' -b "$ROOT/cookies" \
  -d "_csrf=bad" -d "_install=1" "$BASE")
test "$HTTP" = 419
mkdir -p "$ROOT/app"
touch "$ROOT/app/bootstrap.php"
HTTP=$(curl -sS -o "$ROOT/blocked.html" -w '%{http_code}' -b "$ROOT/cookies" "$BASE")
test "$HTTP" = 403
grep -q 'Déjà installé' "$ROOT/blocked.html"
echo "Web installer HTTP session, unlock, CSRF and overwrite protection: PASS"
