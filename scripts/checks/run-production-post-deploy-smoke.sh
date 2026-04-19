#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PRODUCTION_PROFILE="${PRODUCTION_PROFILE:-strum-production}"
export D14K_ENV_PROFILE="${PRODUCTION_PROFILE}"
[[ -n "${PRODUCTION_SSH_ALIAS:-}" ]] && export D14K_REMOTE_SSH_ALIAS="${PRODUCTION_SSH_ALIAS}"
[[ -n "${PRODUCTION_WP_PATH:-}" ]] && export D14K_REMOTE_WP_PATH="${PRODUCTION_WP_PATH}"
[[ -n "${PRODUCTION_SITE_URL:-}" ]] && export D14K_REMOTE_SITE_URL="${PRODUCTION_SITE_URL}"
[[ -n "${PRODUCTION_FEED_URL:-}" ]] && export D14K_REMOTE_FEED_URL="${PRODUCTION_FEED_URL}"
[[ -n "${PRODUCTION_MAX_ITEMS:-}" ]] && export D14K_REMOTE_MAX_ITEMS="${PRODUCTION_MAX_ITEMS}"
export RUN_HTTP_CHECKS="${RUN_HTTP_CHECKS:-1}"
export SMOKE_TITLE="${SMOKE_TITLE:-Production post-deploy smoke}"
exec "${ROOT_DIR}/scripts/checks/run-smoke-by-profile.sh"
