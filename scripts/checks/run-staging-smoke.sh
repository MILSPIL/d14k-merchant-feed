#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STAGING_PROFILE="${STAGING_PROFILE:-strum-staging}"
export D14K_ENV_PROFILE="${STAGING_PROFILE}"
[[ -n "${STAGING_SSH_ALIAS:-}" ]] && export D14K_REMOTE_SSH_ALIAS="${STAGING_SSH_ALIAS}"
[[ -n "${STAGING_WP_PATH:-}" ]] && export D14K_REMOTE_WP_PATH="${STAGING_WP_PATH}"
[[ -n "${STAGING_FEED_URL:-}" ]] && export D14K_REMOTE_FEED_URL="${STAGING_FEED_URL}"
[[ -n "${STAGING_MAX_ITEMS:-}" ]] && export D14K_REMOTE_MAX_ITEMS="${STAGING_MAX_ITEMS}"
export SMOKE_TITLE="${SMOKE_TITLE:-Staging smoke}"
exec "${ROOT_DIR}/scripts/checks/run-smoke-by-profile.sh"
