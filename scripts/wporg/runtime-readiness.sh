#!/usr/bin/env bash

wporg_snapshot_descendants() {
  local parent_pid="$1"
  local child_pid

  if command -v pgrep >/dev/null 2>&1; then
    while IFS= read -r child_pid; do
      if [ -n "$child_pid" ]; then
        wporg_snapshot_descendants "$child_pid"
        printf '%s\n' "$child_pid"
      fi
    done < <(pgrep -P "$parent_pid" 2>/dev/null || true)
  fi
}

wporg_terminate_process_tree() {
  local parent_pid="$1"
  local descendant_pids
  local descendant_pid

  descendant_pids="$(wporg_snapshot_descendants "$parent_pid")"

  while IFS= read -r descendant_pid; do
    if [ -n "$descendant_pid" ]; then
      kill -TERM "$descendant_pid" 2>/dev/null || true
    fi
  done <<<"$descendant_pids"
  kill -TERM "$parent_pid" 2>/dev/null || true

  while IFS= read -r descendant_pid; do
    if [ -n "$descendant_pid" ]; then
      kill -KILL "$descendant_pid" 2>/dev/null || true
    fi
  done <<<"$descendant_pids"
  kill -KILL "$parent_pid" 2>/dev/null || true
}

run_with_deadline() {
  local deadline_seconds="$1"
  shift

  local marker_dir
  marker_dir="$(mktemp -d "${TMPDIR:-/tmp}/wporg-readiness-deadline.XXXXXXXX")"
  local timeout_marker="$marker_dir/timed-out"
  local command_status=0
  local command_pid
  local watchdog_pid

  "$@" &
  command_pid=$!

  (
    sleep "$deadline_seconds"
    if kill -0 "$command_pid" 2>/dev/null; then
      : >"$timeout_marker"
      wporg_terminate_process_tree "$command_pid"
    fi
  ) &
  watchdog_pid=$!

  wait "$command_pid" || command_status=$?
  wporg_terminate_process_tree "$watchdog_pid"
  wait "$watchdog_pid" 2>/dev/null || true

  if [ -f "$timeout_marker" ]; then
    rm -f "$timeout_marker"
    rmdir "$marker_dir"
    return 124
  fi

  rmdir "$marker_dir"
  return "$command_status"
}

run_readiness_diagnostic() {
  local label="$1"
  local log_path="$2"
  local deadline_seconds="$3"
  shift 3

  local diagnostic_status
  if run_with_deadline "$deadline_seconds" "$@" >>"$log_path" 2>&1; then
    return 0
  else
    diagnostic_status=$?
  fi

  if [ "$diagnostic_status" -eq 124 ]; then
    printf '%s diagnostic exceeded its %s-second deadline.\n' \
      "$label" "$deadline_seconds" >>"$log_path"
  else
    printf '%s diagnostic failed with status %s.\n' \
      "$label" "$diagnostic_status" >>"$log_path"
  fi
  return "$diagnostic_status"
}

wait_for_wordpress_filesystem() {
  local log_path="$1"
  local max_attempts="${2:-20}"
  local retry_delay_seconds="${3:-1}"
  local attempt_deadline_seconds="${4:-5}"
  local attempt
  local attempt_status

  case "$max_attempts" in
    ''|*[!0-9]*|0)
      printf 'WordPress readiness attempts must be a positive integer.\n' >&2
      return 2
      ;;
  esac
  case "$retry_delay_seconds" in
    ''|*[!0-9]*)
      printf 'WordPress readiness retry delay must be a non-negative integer.\n' >&2
      return 2
      ;;
  esac
  case "$attempt_deadline_seconds" in
    ''|*[!0-9]*|0)
      printf 'WordPress readiness command deadline must be a positive integer.\n' >&2
      return 2
      ;;
  esac

  for ((attempt = 1; attempt <= max_attempts; attempt++)); do
    # shellcheck disable=SC2016
    if run_with_deadline "$attempt_deadline_seconds" dc exec -T wordpress sh -ec '
      test -f /var/www/html/wp-load.php
      test -f /var/www/html/wp-admin/includes/upgrade.php
      test -f /var/www/html/wp-includes/version.php
      case "$(tr "\000" " " </proc/1/cmdline)" in
        *apache2*) ;;
        *) exit 1 ;;
      esac
    ' >>"$log_path" 2>&1; then
      printf 'WordPress filesystem became ready after %s attempt(s).\n' "$attempt" \
        >>"$log_path"
      return 0
    else
      attempt_status=$?
    fi

    if [ "$attempt_status" -eq 124 ]; then
      printf 'WordPress readiness attempt %s exceeded its %s-second command deadline.\n' \
        "$attempt" "$attempt_deadline_seconds" >>"$log_path"
    fi

    if [ "$attempt" -lt "$max_attempts" ]; then
      sleep "$retry_delay_seconds"
    fi
  done

  printf 'Timed out waiting for WordPress filesystem readiness after %s attempts (%ss command deadline, %ss retry interval).\n' \
    "$max_attempts" "$attempt_deadline_seconds" "$retry_delay_seconds" \
    | tee -a "$log_path" >&2
  run_readiness_diagnostic \
    'Compose status' "$log_path" "$attempt_deadline_seconds" dc ps || true
  run_readiness_diagnostic \
    'Compose logs' "$log_path" "$attempt_deadline_seconds" \
    dc logs --no-color db wordpress || true
  return 1
}
