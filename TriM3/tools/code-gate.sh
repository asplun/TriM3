#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED=0

step() { printf '\n[gate] %s\n' "$1"; }
ok() { printf '  ok: %s\n' "$1"; }
warn() { printf '  warn: %s\n' "$1"; }
fail() { printf '  fail: %s\n' "$1"; FAILED=1; }

step 'PHP syntax'
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null 2>&1 || fail "PHP syntax: $file"
  done < <(find "$ROOT" -type f -name '*.php' -print0)
  ok 'all PHP files passed php -l'
else
  warn 'php not found; syntax check skipped'
fi

step 'Forbidden working files'
bad_files=$(find "$ROOT" -type f \( -name '*.bak' -o -name '*.bak-*' -o -name '*.dbg' -o -name '*.old' -o -name '*.tmp' \) -print)
if [[ -n "$bad_files" ]]; then
  fail "backup/debug files found:\n$bad_files"
else
  ok 'no backup/debug files'
fi

step 'Dangerous PHP calls'
danger_hits=$(find "$ROOT" -type f -name '*.php' \
  ! -path "$ROOT/assets/*" \
  ! -path "$ROOT/lib/Meting.php" \
  -exec grep -En '(^|[^A-Za-z0-9_])(eval|system|shell_exec|passthru|proc_open|popen)\s*\(' {} + || true)
if [[ -n "$danger_hits" ]]; then
  fail "dangerous calls found:\n$danger_hits"
else
  ok 'no dangerous PHP calls'
fi

step 'Debug output'
debug_hits=$(find "$ROOT/assets/js" -type f -name '*.js' \
  ! -name '*.min.js' \
  -exec grep -En '(^|[^A-Za-z0-9_])(console\.log|debugger|var_dump|print_r)\s*\(' {} + || true)
if [[ -n "$debug_hits" ]]; then
  fail "debug output found:\n$debug_hits"
else
  ok 'no debug output in source JS'
fi

step 'Security patterns'
security_hits=$(grep -REn 'Access-Control-Allow-Origin:[[:space:]]*\*|FROM[[:space:]]+typecho_options' \
  "$ROOT/api" "$ROOT/lib" "$ROOT/functions.php" 2>/dev/null || true)
if [[ -n "$security_hits" ]]; then
  fail "unsafe pattern found:\n$security_hits"
else
  ok 'no wildcard CORS or hardcoded table name'
fi

step 'Legacy assets'
legacy_assets=(
  "$ROOT/assets/js/main.min.js"
  "$ROOT/assets/js/player-v1790265194.min.js"
  "$ROOT/assets/js/lazy.min.js"
  "$ROOT/assets/js/joe-content.min.js"
)
legacy_found=0
for file in "${legacy_assets[@]}"; do
  if [[ -e "$file" ]]; then
    fail "legacy asset still exists: $file"
    legacy_found=1
  fi
done
if [[ "$legacy_found" -eq 0 ]]; then
  ok 'no known legacy assets'
fi

step 'Required files'
for file in functions.php header.php footer.php index.php post.php page.php; do
  [[ -f "$ROOT/$file" ]] || fail "missing required file: $file"
done
ok 'required theme files exist'

if [[ "$FAILED" -ne 0 ]]; then
  printf '\n[gate] FAILED\n'
  exit 1
fi

printf '\n[gate] PASSED\n'
