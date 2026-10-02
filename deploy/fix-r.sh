#!/bin/bash
# ==========================================================================
# fix-r.sh — закрыть r.*: CLI-guard в tests/*.php, deny /tests/ и /db/,
# удалить публичные копии БД из webroot.
# ==========================================================================
set -euo pipefail

W=/var/www/r.nayanovaacademy.ru/public
GUARD='<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>'

echo "==> 1. CLI-guard в public/tests/*.php"
for f in "$W"/tests/*.php; do
  [ -f "$f" ] || continue
  if head -1 "$f" | grep -q 'PHP_SAPI'; then
    echo "    already: $(basename "$f")"
    continue
  fi
  tmp="$(mktemp)"
  { printf '%s\n' "$GUARD"; cat "$f"; } > "$tmp"
  chown --reference="$f" "$tmp" 2>/dev/null || true
  chmod --reference="$f" "$tmp" 2>/dev/null || true
  mv "$tmp" "$f"
  php -l "$f" >/dev/null && echo "    guarded: $(basename "$f")"
done

echo "==> 2. Удалить публичные копии БД из webroot"
if [ -d "$W/db" ]; then
  ls -la "$W/db"
  rm -rf "$W/db"
  echo "    removed $W/db"
else
  echo "    $W/db отсутствует"
fi

echo "==> 3. nginx: deny /tests/, /db/, /downloads/"
CONF=/etc/nginx/sites-enabled/r.nayanovaacademy.ru
python3 - "$CONF" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
existing = s
block = '''    location ^~ /tests/ {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ^~ /db/ {
        deny all;
        access_log off;
        log_not_found off;
    }

'''
# вставить после location ^~ /cron_ { ... } (перед location ~ /\.)
if '/tests/' in s and 'location ^~ /tests/' in s:
    print('deny tests/db already present')
else:
    marker = '    location ~ /\\. {'
    idx = s.find(marker)
    if idx == -1:
        raise SystemExit('marker not found')
    s = s[:idx] + block + s[idx:]
    print('inserted deny tests/db')

open(p, 'w', encoding='utf-8').write(s)
PY

nginx -t
systemctl reload nginx
echo "==> done"
