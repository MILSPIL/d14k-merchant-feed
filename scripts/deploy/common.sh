#!/usr/bin/env bash

deploy_print_header() {
  local deploy_title="$1"
  local remote_ssh_alias="$2"
  local remote_plugin_path="$3"
  local remote_wp_path="$4"
  local run_local_checks="$5"
  local dry_run="$6"
  local run_smoke="$7"

  echo "== ${deploy_title} =="
  echo "SSH alias:   ${remote_ssh_alias}"
  echo "Plugin path: ${remote_plugin_path}"
  echo "WP path:     ${remote_wp_path}"
  echo "Local checks:${run_local_checks}"
  echo "Dry run:     ${dry_run}"
  echo "Run smoke:   ${run_smoke}"
}

deploy_require_confirmation() {
  local dry_run="$1"
  local require_confirm="$2"
  local confirm_value="$3"
  local expected_value="$4"
  local confirm_var_name="$5"

  if [[ "${dry_run}" == "1" || "${require_confirm}" != "1" ]]; then
    return 0
  fi

  if [[ "${confirm_value}" != "${expected_value}" ]]; then
    echo "Refusing deploy without ${confirm_var_name}=${expected_value}" >&2
    exit 2
  fi
}

deploy_run_local_checks() {
  local root_dir="$1"
  local run_local_checks="$2"

  if [[ "${run_local_checks}" != "1" ]]; then
    return 0
  fi

  echo
  echo "-- local checks --"
  "${root_dir}/scripts/checks/run-local-checks.sh"
}

deploy_rsync_plugin() {
  local root_dir="$1"
  local remote_ssh_alias="$2"
  local remote_plugin_path="$3"
  local dry_run="$4"
  local -a rsync_flags

  rsync_flags=(-avz --delete)

  if [[ "${dry_run}" == "1" ]]; then
    rsync_flags+=(--dry-run --itemize-changes)
  fi

  echo
  echo "-- rsync --"
  rsync "${rsync_flags[@]}" \
    --exclude='.git' \
    --exclude='.DS_Store' \
    --exclude='releases' \
    --exclude='.agents' \
    --exclude='*.xlsx' \
    --exclude='*.csv' \
    --exclude='*.zip' \
    "${root_dir}/" \
    "${remote_ssh_alias}:${remote_plugin_path}"
}

deploy_post_checks() {
  local root_dir="$1"
  local env_profile="$2"
  local remote_ssh_alias="$3"
  local remote_wp_path="$4"
  local plugin_slug="$5"
  local wp_cli_args="$6"
  local cache_flush_command="$7"
  local post_deploy_command="$8"
  local dry_run="$9"
  local run_smoke="${10}"
  local smoke_title="${11}"

  if [[ "${dry_run}" == "1" ]]; then
    return 0
  fi

  if [[ -z "${cache_flush_command}" ]]; then
    cache_flush_command="wp ${wp_cli_args} --path='${remote_wp_path}' cache flush"
  fi

  echo
  echo "-- cache flush --"
  ssh "${remote_ssh_alias}" "${cache_flush_command}" || true

  echo
  echo "-- plugin status --"
  ssh "${remote_ssh_alias}" "wp ${wp_cli_args} --path='${remote_wp_path}' plugin is-active '${plugin_slug}'"

  if [[ -n "${post_deploy_command}" ]]; then
    echo
    echo "-- post-deploy command --"
    bash -lc "${post_deploy_command}"
  fi

  if [[ "${run_smoke}" == "1" ]]; then
    echo
    echo "-- ${smoke_title} --"
    D14K_ENV_PROFILE="${env_profile}" "${root_dir}/scripts/checks/run-smoke-by-profile.sh"
  fi
}
