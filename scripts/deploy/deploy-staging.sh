#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
STAGING_PROFILE="${STAGING_PROFILE:-strum-staging}"
export D14K_ENV_PROFILE="${STAGING_PROFILE}"
[[ -n "${STAGING_SSH_ALIAS:-}" ]] && export D14K_REMOTE_SSH_ALIAS="${STAGING_SSH_ALIAS}"
[[ -n "${STAGING_PLUGIN_PATH:-}" ]] && export D14K_REMOTE_PLUGIN_PATH="${STAGING_PLUGIN_PATH}"
[[ -n "${STAGING_WP_PATH:-}" ]] && export D14K_REMOTE_WP_PATH="${STAGING_WP_PATH}"
exec "${ROOT_DIR}/scripts/deploy/deploy-by-profile.sh"
