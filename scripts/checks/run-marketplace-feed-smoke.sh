#!/usr/bin/env bash
set -euo pipefail

SMOKE_TITLE="${SMOKE_TITLE:-Marketplace feed smoke}"
REMOTE_SSH_ALIAS="${REMOTE_SSH_ALIAS:?REMOTE_SSH_ALIAS is required}"
REMOTE_WP_PATH="${REMOTE_WP_PATH:?REMOTE_WP_PATH is required}"
REMOTE_SITE_URL="${REMOTE_SITE_URL:-}"
REMOTE_PLUGIN_SLUG="${REMOTE_PLUGIN_SLUG:-d14k-merchant-feed}"
REMOTE_WP_CLI_ARGS="${REMOTE_WP_CLI_ARGS:-}"
MARKETPLACE_FEED_URL="${MARKETPLACE_FEED_URL:?MARKETPLACE_FEED_URL is required}"
MARKETPLACE_FEED_FORMAT="${MARKETPLACE_FEED_FORMAT:-yml}"
RUN_HTTP_CHECKS="${RUN_HTTP_CHECKS:-1}"

TMP_FILE="$(mktemp)"
trap 'rm -f "${TMP_FILE}"' EXIT

echo "== ${SMOKE_TITLE} =="
echo "SSH alias: ${REMOTE_SSH_ALIAS}"
echo "WP path:   ${REMOTE_WP_PATH}"
if [[ -n "${REMOTE_SITE_URL}" ]]; then
echo "Site URL:  ${REMOTE_SITE_URL}"
fi
echo "Feed URL:  ${MARKETPLACE_FEED_URL}"
echo "Feed format:${MARKETPLACE_FEED_FORMAT}"
echo "Plugin slug:${REMOTE_PLUGIN_SLUG}"
echo "WP-CLI args:${REMOTE_WP_CLI_ARGS}"

if [[ "${RUN_HTTP_CHECKS}" == "1" && -n "${REMOTE_SITE_URL}" ]]; then
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
echo "-- feed http --"
curl -fsSI "${MARKETPLACE_FEED_URL}" | sed -n '1,5p'

curl -fsSL "${MARKETPLACE_FEED_URL}" -o "${TMP_FILE}"

case "${MARKETPLACE_FEED_FORMAT}" in
  yml)
    if ! grep -q "<yml_catalog" "${TMP_FILE}"; then
      echo "Feed smoke failed: <yml_catalog> not found." >&2
      exit 5
    fi

    if ! grep -q "<offers>" "${TMP_FILE}"; then
      echo "Feed smoke failed: <offers> not found." >&2
      exit 6
    fi

    OFFER_COUNT="$(grep -o '<offer[ >]' "${TMP_FILE}" | wc -l | tr -d ' ')"

    if [[ "${OFFER_COUNT}" == "0" ]]; then
      echo "Feed smoke failed: offer count is 0." >&2
      exit 7
    fi
    ;;
  csv)
    if ! head -n 1 "${TMP_FILE}" | grep -q "Артикул"; then
      echo "Feed smoke failed: CSV header not found." >&2
      exit 8
    fi

    OFFER_COUNT="$(tail -n +2 "${TMP_FILE}" | wc -l | tr -d ' ')"

    if [[ "${OFFER_COUNT}" == "0" ]]; then
      echo "Feed smoke failed: CSV row count is 0." >&2
      exit 9
    fi
    ;;
  *)
    echo "Unknown marketplace feed format: ${MARKETPLACE_FEED_FORMAT}" >&2
    exit 10
    ;;
esac

echo
echo "-- feed sample --"
sed -n '1,5p' "${TMP_FILE}"

echo
echo "{\"offer_count\": ${OFFER_COUNT}}"
echo
echo "${SMOKE_TITLE} completed."
