#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${ROOT_DIR}/scripts/deploy/common.sh"
source "${ROOT_DIR}/scripts/env/load-profile.sh"

ENV_PROFILE="${D14K_ENV_PROFILE:-${1:-}}"

if [[ -z "${ENV_PROFILE}" ]]; then
  echo "Usage: D14K_ENV_PROFILE=<profile> $0" >&2
  exit 4
fi

load_d14k_env_profile "${ROOT_DIR}" "${ENV_PROFILE}"

PROFILE_LABEL="${D14K_PROFILE_LABEL:-${ENV_PROFILE}}"
REMOTE_SSH_ALIAS="${D14K_REMOTE_SSH_ALIAS:?D14K_REMOTE_SSH_ALIAS is required}"
REMOTE_PLUGIN_PATH="${D14K_REMOTE_PLUGIN_PATH:?D14K_REMOTE_PLUGIN_PATH is required}"
REMOTE_WP_PATH="${D14K_REMOTE_WP_PATH:?D14K_REMOTE_WP_PATH is required}"
PLUGIN_SLUG="${D14K_PLUGIN_SLUG:-gmc-feed-for-woocommerce}"
WP_CLI_ARGS="${D14K_WP_CLI_ARGS:-}"
CACHE_FLUSH_COMMAND="${D14K_CACHE_FLUSH_COMMAND:-}"
POST_DEPLOY_COMMAND="${D14K_POST_DEPLOY_COMMAND:-}"
RUN_LOCAL_CHECKS="${RUN_LOCAL_CHECKS:-1}"
DRY_RUN="${DRY_RUN:-0}"
RUN_SMOKE="${RUN_SMOKE:-0}"
REQUIRE_CONFIRM="${D14K_DEPLOY_REQUIRE_CONFIRM:-0}"
CONFIRM_VAR="${D14K_DEPLOY_CONFIRM_VAR:-D14K_DEPLOY_CONFIRM}"
CONFIRM_VALUE="${D14K_DEPLOY_CONFIRM_VALUE:-DEPLOY}"
CONFIRM_ACTUAL="${!CONFIRM_VAR:-}"

deploy_print_header \
  "Deploy ${PROFILE_LABEL}" \
  "${REMOTE_SSH_ALIAS}" \
  "${REMOTE_PLUGIN_PATH}" \
  "${REMOTE_WP_PATH}" \
  "${RUN_LOCAL_CHECKS}" \
  "${DRY_RUN}" \
  "${RUN_SMOKE}"

deploy_require_confirmation \
  "${DRY_RUN}" \
  "${REQUIRE_CONFIRM}" \
  "${CONFIRM_ACTUAL}" \
  "${CONFIRM_VALUE}" \
  "${CONFIRM_VAR}"

deploy_run_local_checks "${ROOT_DIR}" "${RUN_LOCAL_CHECKS}"
deploy_rsync_plugin "${ROOT_DIR}" "${REMOTE_SSH_ALIAS}" "${REMOTE_PLUGIN_PATH}" "${DRY_RUN}"
deploy_post_checks \
  "${ROOT_DIR}" \
  "${ENV_PROFILE}" \
  "${REMOTE_SSH_ALIAS}" \
  "${REMOTE_WP_PATH}" \
  "${PLUGIN_SLUG}" \
  "${WP_CLI_ARGS}" \
  "${CACHE_FLUSH_COMMAND}" \
  "${POST_DEPLOY_COMMAND}" \
  "${DRY_RUN}" \
  "${RUN_SMOKE}" \
  "${PROFILE_LABEL} smoke"

echo
echo "Deploy completed."
