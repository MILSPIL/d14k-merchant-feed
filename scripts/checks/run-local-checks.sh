#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "== Local checks =="
echo "ROOT=${ROOT_DIR}"

echo
echo "-- PHP lint --"
find "${ROOT_DIR}" \
  -path "${ROOT_DIR}/.git" -prune -o \
  -path "${ROOT_DIR}/releases" -prune -o \
  -type f -name '*.php' -print | sort | while read -r file; do
    php -l "${file}" >/dev/null
    echo "OK  ${file#${ROOT_DIR}/}"
done

echo
echo "-- Shell syntax --"
find "${ROOT_DIR}/scripts" -type f -name '*.sh' -print | sort | while read -r file; do
  bash -n "${file}"
  echo "OK  ${file#${ROOT_DIR}/}"
done

if command -v node >/dev/null 2>&1; then
  echo
  echo "-- JS syntax --"
  node -c "${ROOT_DIR}/assets/admin.js"
  echo "OK  assets/admin.js"
fi

echo
echo "-- Markdown secret scan --"
"${ROOT_DIR}/scripts/checks/scan-markdown-secrets.py"

echo
echo "All local checks passed."
