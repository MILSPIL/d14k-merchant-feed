#!/usr/bin/env bash
set -euo pipefail

SMOKE_TITLE="${SMOKE_TITLE:-GMC + Prom smoke}"
REMOTE_SSH_ALIAS="${REMOTE_SSH_ALIAS:?REMOTE_SSH_ALIAS is required}"
REMOTE_WP_PATH="${REMOTE_WP_PATH:?REMOTE_WP_PATH is required}"
REMOTE_SITE_URL="${REMOTE_SITE_URL:?REMOTE_SITE_URL is required}"
REMOTE_PLUGIN_SLUG="${REMOTE_PLUGIN_SLUG:-d14k-merchant-feed}"
REMOTE_WP_CLI_ARGS="${REMOTE_WP_CLI_ARGS:-}"
RUN_HTTP_CHECKS="${RUN_HTTP_CHECKS:-1}"
GMC_LANGS="${GMC_LANGS:-uk ru}"
PROM_FEED_URL="${PROM_FEED_URL:-${REMOTE_SITE_URL%/}/marketplace-feed/prom/}"
PROM_CHECK_MODE="${PROM_CHECK_MODE:-optional}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

echo "== ${SMOKE_TITLE} =="
echo "SSH alias: ${REMOTE_SSH_ALIAS}"
echo "WP path:   ${REMOTE_WP_PATH}"
echo "Site URL:  ${REMOTE_SITE_URL}"
echo "Plugin slug:${REMOTE_PLUGIN_SLUG}"
echo "WP-CLI args:${REMOTE_WP_CLI_ARGS}"
echo "GMC langs: ${GMC_LANGS}"
echo "Prom URL:  ${PROM_FEED_URL}"
echo "Prom mode: ${PROM_CHECK_MODE}"

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

for lang in ${GMC_LANGS}; do
  gmc_url="${REMOTE_SITE_URL%/}/merchant-feed/${lang}/"
  gmc_file="${TMP_DIR}/merchant-feed-${lang}.xml"

  echo
  echo "-- gmc ${lang} http --"
  curl -fsSI "${gmc_url}" | sed -n '1,5p'

  curl -fsSL "${gmc_url}" -o "${gmc_file}"

  if ! grep -q "<rss" "${gmc_file}"; then
    echo "GMC smoke failed: <rss> not found for ${lang}." >&2
    exit 11
  fi

  if ! grep -q "<channel>" "${gmc_file}"; then
    echo "GMC smoke failed: <channel> not found for ${lang}." >&2
    exit 12
  fi

  item_count="$(grep -o "<item>" "${gmc_file}" | wc -l | tr -d ' ')"
  if [[ "${item_count}" == "0" ]]; then
    echo "GMC smoke failed: item count is 0 for ${lang}." >&2
    exit 13
  fi

  echo
  echo "-- gmc ${lang} sample --"
  sed -n '1,5p' "${gmc_file}"
  echo "{\"lang\":\"${lang}\",\"item_count\":${item_count}}"
done

case "${PROM_CHECK_MODE}" in
  skip)
    echo
    echo "-- prom feed --"
    echo "Skipped by profile."
    ;;
  required|optional)
    prom_file="${TMP_DIR}/prom-feed.xml"

    echo
    echo "-- prom feed http --"
    prom_http_code="$(curl -sS -L -o "${prom_file}" -w '%{http_code}' "${PROM_FEED_URL}" || true)"
    echo "HTTP ${prom_http_code}"

    if [[ "${prom_http_code}" == "200" ]]; then
      if ! grep -q "<yml_catalog" "${prom_file}"; then
        echo "Prom smoke failed: <yml_catalog> not found." >&2
        exit 21
      fi

      if ! grep -q "<offers>" "${prom_file}"; then
        echo "Prom smoke failed: <offers> not found." >&2
        exit 22
      fi

      offer_count="$(grep -o '<offer[ >]' "${prom_file}" | wc -l | tr -d ' ')"
      if [[ "${offer_count}" == "0" ]]; then
        echo "Prom smoke failed: offer count is 0." >&2
        exit 23
      fi

      echo
      echo "-- prom feed sample --"
      sed -n '1,5p' "${prom_file}"
      echo "{\"offer_count\":${offer_count}}"
    else
      echo
      echo "-- prom feed response --"
      sed -n '1,5p' "${prom_file}" || true

      if [[ "${PROM_CHECK_MODE}" == "required" ]]; then
        echo "Prom smoke failed: expected 200, got ${prom_http_code}." >&2
        exit 24
      fi

      echo "Prom feed is not ready yet. Continuing because Prom mode is optional."
    fi
    ;;
  *)
    echo "Unknown PROM_CHECK_MODE: ${PROM_CHECK_MODE}" >&2
    exit 25
    ;;
esac

echo
echo "${SMOKE_TITLE} completed."
