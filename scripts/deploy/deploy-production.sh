#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PRODUCTION_PROFILE="${PRODUCTION_PROFILE:-strum-production}"
export D14K_ENV_PROFILE="${PRODUCTION_PROFILE}"
[[ -n "${PRODUCTION_SSH_ALIAS:-}" ]] && export D14K_REMOTE_SSH_ALIAS="${PRODUCTION_SSH_ALIAS}"
[[ -n "${PRODUCTION_PLUGIN_PATH:-}" ]] && export D14K_REMOTE_PLUGIN_PATH="${PRODUCTION_PLUGIN_PATH}"
[[ -n "${PRODUCTION_WP_PATH:-}" ]] && export D14K_REMOTE_WP_PATH="${PRODUCTION_WP_PATH}"
exec "${ROOT_DIR}/scripts/deploy/deploy-by-profile.sh"
