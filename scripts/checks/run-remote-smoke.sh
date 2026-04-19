#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SMOKE_TITLE="${SMOKE_TITLE:-Remote smoke}"
REMOTE_SSH_ALIAS="${REMOTE_SSH_ALIAS:?REMOTE_SSH_ALIAS is required}"
REMOTE_WP_PATH="${REMOTE_WP_PATH:?REMOTE_WP_PATH is required}"
REMOTE_FEED_URL="${REMOTE_FEED_URL:?REMOTE_FEED_URL is required}"
REMOTE_MAX_ITEMS="${REMOTE_MAX_ITEMS:-1}"
REMOTE_SITE_URL="${REMOTE_SITE_URL:-}"
REMOTE_PLUGIN_SLUG="${REMOTE_PLUGIN_SLUG:-gmc-feed-for-woocommerce}"
REMOTE_WP_CLI_ARGS="${REMOTE_WP_CLI_ARGS:-}"
RUN_HTTP_CHECKS="${RUN_HTTP_CHECKS:-0}"
RUN_BACKGROUND="${RUN_BACKGROUND:-0}"
FAIL_ON_BUSY_BACKGROUND="${FAIL_ON_BUSY_BACKGROUND:-0}"

echo "== ${SMOKE_TITLE} =="
echo "SSH alias: ${REMOTE_SSH_ALIAS}"
echo "WP path:   ${REMOTE_WP_PATH}"
if [[ -n "${REMOTE_SITE_URL}" ]]; then
  echo "Site URL:  ${REMOTE_SITE_URL}"
fi
echo "Feed URL:  ${REMOTE_FEED_URL}"
echo "Max items: ${REMOTE_MAX_ITEMS}"
echo "Background:${RUN_BACKGROUND}"
echo "Fail on busy:${FAIL_ON_BUSY_BACKGROUND}"
echo "Plugin slug:${REMOTE_PLUGIN_SLUG}"
echo "WP-CLI args:${REMOTE_WP_CLI_ARGS}"

scp "${ROOT_DIR}/scripts/smoke/import-readiness.php" "${REMOTE_SSH_ALIAS}:/tmp/import-readiness.php" >/dev/null
scp "${ROOT_DIR}/scripts/smoke/supplier-large-feed.php" "${REMOTE_SSH_ALIAS}:/tmp/supplier-large-feed.php" >/dev/null
scp "${ROOT_DIR}/scripts/tests/supplier-background-assertions.php" "${REMOTE_SSH_ALIAS}:/tmp/supplier-background-assertions.php" >/dev/null

if [[ "${RUN_HTTP_CHECKS}" == "1" ]]; then
  echo
  echo "-- public http --"
  curl -fsSI "${REMOTE_SITE_URL}" | sed -n '1,5p'

  echo
  echo "-- wp-json http --"
  curl -fsSI "${REMOTE_SITE_URL%/}/wp-json/" | sed -n '1,5p'
fi

echo
echo "-- plugin active --"
ssh "${REMOTE_SSH_ALIAS}" \
  "wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' plugin is-active '${REMOTE_PLUGIN_SLUG}'"

echo
echo "-- import-readiness --"
ssh "${REMOTE_SSH_ALIAS}" \
  "wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' eval-file /tmp/import-readiness.php"

echo
echo "-- supplier-background-assertions --"
ssh "${REMOTE_SSH_ALIAS}" \
  "wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' eval-file /tmp/supplier-background-assertions.php"

echo
echo "-- supplier-large-feed --"
ssh "${REMOTE_SSH_ALIAS}" \
  "D14K_SUPPLIER_FEED_URL='${REMOTE_FEED_URL}' D14K_SUPPLIER_MAX_ITEMS='${REMOTE_MAX_ITEMS}' wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' eval-file /tmp/supplier-large-feed.php"

if [[ "${RUN_BACKGROUND}" == "1" ]]; then
  CURRENT_STATE_JSON="$(ssh "${REMOTE_SSH_ALIAS}" "wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' option get d14k_supplier_background_import_state --format=json 2>/dev/null || true")"
  if echo "${CURRENT_STATE_JSON}" | grep -q '"status":"running"'; then
    echo
    echo "-- background-single-feed --"
    echo "Skipped: another background import is already running."
    echo "${CURRENT_STATE_JSON}"
    if [[ "${FAIL_ON_BUSY_BACKGROUND}" == "1" ]]; then
      exit 3
    fi
    echo
    echo "${SMOKE_TITLE} completed with background skip."
    exit 0
  fi

  scp "${ROOT_DIR}/scripts/smoke/background-single-feed.php" "${REMOTE_SSH_ALIAS}:/tmp/background-single-feed.php" >/dev/null
  echo
  echo "-- background-single-feed --"
  ssh "${REMOTE_SSH_ALIAS}" \
    "D14K_SUPPLIER_FEED_URL='${REMOTE_FEED_URL}' D14K_SUPPLIER_MAX_ITEMS='${REMOTE_MAX_ITEMS}' wp ${REMOTE_WP_CLI_ARGS} --path='${REMOTE_WP_PATH}' eval-file /tmp/background-single-feed.php"
fi

echo
echo "${SMOKE_TITLE} completed."
