#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift shared Łapka audit failed: %s\n' "$1" >&2; exit 1; }

canonical_file() {
  local label="$1" path="$2" canonical
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -f "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute regular non-symlink file"
  canonical="$(cd "$(dirname "$path")" && pwd -P)/$(basename "$path")"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  printf '%s\n' "$canonical"
}

project=''
compose_file=''
override_file=''
command=()

while [ "$#" -gt 0 ]; do
  case "$1" in
    --project) project="${2:-}"; shift 2 ;;
    --compose-file) compose_file="${2:-}"; shift 2 ;;
    --override-file) override_file="${2:-}"; shift 2 ;;
    --) shift; command=("$@"); break ;;
    *) fail "unknown argument $1" ;;
  esac
done

[[ "$project" =~ ^[a-z0-9][a-z0-9_-]{2,63}$ ]] || fail 'Compose project is invalid'
compose_file="$(canonical_file 'base Compose file' "$compose_file")"
override_file="$(canonical_file 'audit override file' "$override_file")"
[ "${#command[@]}" -gt 0 ] && [ "${command[0]}" = wp ] || fail 'the one-off command must invoke wp'

# Keep this wrapper useful only for CartShift's non-mutating command family.
# The MU guard remains the second boundary, not the only boundary.
cli=()
for argument in "${command[@]:1}"; do
  case "$argument" in
    --allow-root|--quiet) ;;
    --skip-plugins=*)
      skip_plugins="${argument#--skip-plugins=}"
      [ -n "$skip_plugins" ] || fail 'only CartShift transfer inspection commands are allowed'
      IFS=',' read -r -a skipped <<< "$skip_plugins"
      for plugin in "${skipped[@]}"; do
        [[ "$plugin" =~ ^[a-z0-9][a-z0-9._-]{0,63}$ ]] \
          || fail 'only CartShift transfer inspection commands are allowed'
        case "$plugin" in cartshift|woocommerce|woocommerce-subscriptions|fluent-cart)
          fail 'only CartShift transfer inspection commands are allowed'
        esac
      done
      ;;
    *) cli+=("$argument") ;;
  esac
done
[ "${#cli[@]}" -ge 3 ] \
  && [ "${cli[0]}" = cartshift ] \
  && [ "${cli[1]}" = transfer ] \
  || fail 'only CartShift transfer inspection commands are allowed'
case "${cli[2]}" in
  audit|compatibility|inspect-target|source-instance|propose-decisions) ;;
  *) fail 'the requested CartShift command is not read-only audit tooling' ;;
esac

shared_id() {
  local ids
  ids="$(docker compose --project-name "$project" --file "$compose_file" ps -q app-web)"
  [ -n "$ids" ] && [ "$(printf '%s\n' "$ids" | wc -l | tr -d ' ')" = 1 ] \
    || return 1
  printf '%s\n' "$ids"
}

clean_shared_container() {
  local container_id="$1" state
  state="$(docker inspect "$container_id")" || return 1
  jq -e '
    .[0].State.Status == "running" and
    .[0].State.Health.Status == "healthy" and
    all(.[0].Config.Env[]?; startswith("CARTSHIFT_ZERO_WRITE_GUARD=") | not) and
    all(.[0].Mounts[]?;
      (.Destination != "/cartshift-evidence") and
      (.Destination != "/var/www/html/wp-content/plugins/cartshift") and
      ((.Source | contains("/artifacts/cartshift-evidence/")) | not)
    )
  ' <<< "$state" >/dev/null
}

before_id="$(shared_id)" || fail 'the shared app-web service is not exactly one running container'
clean_shared_container "$before_id" \
  || fail 'the shared app-web service is unhealthy or already contaminated by an audit override'

postflight() {
  local after_id
  after_id="$(shared_id)" || return 1
  [ "$after_id" = "$before_id" ] || return 1
  clean_shared_container "$after_id"
}

on_exit() {
  local result=$?
  trap - EXIT INT TERM
  if ! postflight; then
    printf 'CartShift shared Łapka audit failed: the shared app-web service changed during the one-off audit\n' >&2
    result=1
  fi
  exit "$result"
}
trap on_exit EXIT INT TERM

docker compose --project-name "$project" --file "$compose_file" --file "$override_file" \
  run --rm --no-deps -T app-web "${command[@]}"
