#!/usr/bin/env bash

load_d14k_env_profile() {
  local root_dir="$1"
  local profile_name="$2"
  local profile_path="${root_dir}/scripts/env/profiles/${profile_name}.sh"

  if [[ ! -f "${profile_path}" ]]; then
    echo "Unknown env profile: ${profile_name}" >&2
    echo "Expected profile file: ${profile_path}" >&2
    exit 4
  fi

  # shellcheck source=/dev/null
  source "${profile_path}"
}
