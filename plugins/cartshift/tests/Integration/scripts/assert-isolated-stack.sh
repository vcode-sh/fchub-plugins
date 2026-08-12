#!/usr/bin/env bash

set -euo pipefail

fail() {
  printf 'CartShift rehearsal isolation failed: %s\n' "$1" >&2
  exit 1
}

usage() {
  printf '%s\n' \
    'Usage: assert-isolated-stack.sh --project <generated-name> --compose-file <absolute-path>' \
    '       --fixture-root <private-generated-root> --evidence-dir <private-directory>' \
    '       --package-dir <sealed-package-directory> --candidate-dir <verified-cartshift-candidate> [--output <json>]'
}

absolute_directory() {
  local label="$1"
  local path="$2"
  [ -n "$path" ] && [ "${path#/}" != "$path" ] || fail "${label} must be an absolute directory"
  [ -d "$path" ] && [ ! -L "$path" ] || fail "${label} is missing or is a symlink"
  local canonical
  canonical="$(cd "$path" && pwd -P)"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  printf '%s\n' "$canonical"
}

private_directory() {
  local label="$1"
  local path="$2"
  local mode
  mode="$(stat -f '%Lp' "$path" 2>/dev/null || stat -c '%a' "$path")"
  case "$mode" in
    *00) ;;
    *) fail "${label} must not grant group or world permissions" ;;
  esac
}

digest_file() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}';
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

is_within() {
  local candidate="$1"
  local root="$2"
  [ "$candidate" = "$root" ] || [[ "$candidate" == "$root"/* ]]
}

project=''
compose_file=''
fixture_root=''
evidence_dir=''
package_dir=''
candidate_dir=''
output=''

while [ "$#" -gt 0 ]; do
  case "$1" in
    --project) project="${2:-}"; shift 2 ;;
    --compose-file) compose_file="${2:-}"; shift 2 ;;
    --fixture-root) fixture_root="${2:-}"; shift 2 ;;
    --evidence-dir) evidence_dir="${2:-}"; shift 2 ;;
    --package-dir) package_dir="${2:-}"; shift 2 ;;
    --candidate-dir) candidate_dir="${2:-}"; shift 2 ;;
    --output) output="${2:-}"; shift 2 ;;
    --help) usage; exit 0 ;;
    *) fail "unknown argument $1" ;;
  esac
done

[[ "$project" =~ ^cartshift-lapka-(empty|populated|repeat|rollback)-[a-z0-9][a-z0-9-]{5,47}$ ]] \
  || fail 'Compose project name is not a generated CartShift rehearsal identity'
[ -n "$compose_file" ] && [ "${compose_file#/}" != "$compose_file" ] \
  || fail 'compose file must be absolute'
[ -f "$compose_file" ] && [ ! -L "$compose_file" ] || fail 'compose file is missing or is a symlink'
compose_file="$(cd "$(dirname "$compose_file")" && pwd -P)/$(basename "$compose_file")"

fixture_root="$(absolute_directory 'fixture root' "$fixture_root")"
evidence_dir="$(absolute_directory 'evidence directory' "$evidence_dir")"
package_dir="$(absolute_directory 'package directory' "$package_dir")"
candidate_dir="$(absolute_directory 'candidate directory' "$candidate_dir")"
private_directory 'fixture root' "$fixture_root"
private_directory 'evidence directory' "$evidence_dir"
private_directory 'package directory' "$package_dir"

temp_base="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
[[ "$fixture_root" == "$temp_base"/cartshift-lapka-fixture.* ]] \
  || fail 'fixture root is not a fresh generated temporary root'
[ -f "$fixture_root/.cartshift-lapka-fixture" ] || fail 'fixture marker is missing'
[ "$(cat "$fixture_root/.cartshift-lapka-fixture")" = "$project" ] \
  || fail 'fixture marker does not match the Compose project'
is_within "$compose_file" "$fixture_root" || fail 'compose file must live inside the generated fixture root'
is_within "$candidate_dir" "$fixture_root" || fail 'candidate directory must live inside the generated fixture root'
[ -f "$candidate_dir/cartshift.php" ] && [ ! -L "$candidate_dir/cartshift.php" ] \
  || fail 'candidate directory is not an installable CartShift plugin tree'

for shared_root in \
  '/Users/tomrobak/_projects_/fchub-playground' \
  '/Users/tomrobak/wp/wesolalapka.com' \
  '/var/www/html' \
  '/var/www/web' \
  '/var/www/klub'; do
  if is_within "$fixture_root" "$shared_root" || is_within "$shared_root" "$fixture_root"; then
    fail "fixture root intersects known shared root ${shared_root}"
  fi
done

command -v docker >/dev/null 2>&1 || fail 'docker is unavailable'
command -v jq >/dev/null 2>&1 || fail 'jq is unavailable'
config_file="$(mktemp "${TMPDIR:-/tmp}/cartshift-isolation-config.XXXXXX")"
bind_file="$(mktemp "${TMPDIR:-/tmp}/cartshift-isolation-binds.XXXXXX")"
cleanup() { rm -f -- "$config_file" "$bind_file"; }
trap cleanup EXIT INT TERM
chmod 0600 "$config_file" "$bind_file"

docker compose --project-name "$project" --file "$compose_file" config --format json > "$config_file" \
  || fail 'Compose configuration cannot be rendered'
jq -e --arg project "$project" '.name == $project and (.services | type == "object") and (.services | length > 0)' "$config_file" >/dev/null \
  || fail 'rendered Compose project identity changed'
jq -e '[.services[] | select((.container_name // "") != "" or (.network_mode // "") == "host" or (.pid // "") == "host" or (.privileged // false) == true)] | length == 0' "$config_file" >/dev/null \
  || fail 'host namespaces, explicit container names, or privileged services are forbidden'
jq -e '[.networks[] | select((.internal // false) != true or (.external // false) == true)] | length == 0' "$config_file" >/dev/null \
  || fail 'every rehearsal network must be project-owned and internal'
jq -e --arg prefix "${project}_" '[.networks[], .volumes[] | select((.external // false) == true or ((.name // "") | startswith($prefix) | not))] | length == 0' "$config_file" >/dev/null \
  || fail 'network or volume names escape the generated Compose project'
jq -e '[.services[] | (.ports // [])[] | (.published | tonumber) as $port | select((.host_ip // "") != "127.0.0.1" or ($port != 0 and ($port < 20000 or $port > 60999)))] | length == 0' "$config_file" >/dev/null \
  || fail 'published ports must be random high ports bound only to 127.0.0.1'
jq -e '[.services[] | (.environment // {}) | to_entries[] | select((.key | test("(^|_)(HOME|SITEURL|URL)$")) and ((.value | tostring) | test("^https?://(127\\.0\\.0\\.1:[2-6][0-9]{4}|[a-z0-9.-]+\\.invalid)(/|$)") | not))] | length == 0' "$config_file" >/dev/null \
  || fail 'a WordPress or integration URL is routable outside the rehearsal boundary'
jq -e --argjson services "$(jq '.services | keys' "$config_file")" '[.services[] | (.environment // {}) | to_entries[] | select((.key | test("_DB_HOST$")) and ((.value | tostring | split(":")[0]) as $host | ($services | index($host)) == null))] | length == 0' "$config_file" >/dev/null \
  || fail 'a database endpoint is not another service in the isolated project'
jq -e --arg candidate "$candidate_dir" '
  . as $config |
  ["source-cli","target-cli","source-wordpress","target-wordpress"] |
  all(.[]; . as $service | any($config.services[$service].volumes[]?;
    .type == "bind" and .source == $candidate and .target == "/var/www/html/wp-content/plugins/cartshift" and .read_only == true))
' "$config_file" >/dev/null || fail 'every WordPress rehearsal service must mount the exact verified candidate read-only'
jq -e --arg candidate "$candidate_dir" '[.services[] | (.volumes // [])[] | select(.target == "/var/www/html/wp-content/plugins/cartshift" and (.type != "bind" or .source != $candidate or .read_only != true))] | length == 0' "$config_file" >/dev/null \
  || fail 'a WordPress rehearsal service substitutes another CartShift tree for the verified candidate'

jq -r '.services | to_entries[] as $service | ($service.value.volumes // [])[] | select(.type == "bind") | [$service.key, .source, .target, (.read_only // false)] | @tsv' "$config_file" > "$bind_file"
while IFS=$'\t' read -r service source _target read_only; do
  [ -n "$source" ] || fail "service ${service} has an empty bind source"
  [ -e "$source" ] && [ ! -L "$source" ] || fail "service ${service} bind source is missing or a symlink"
  canonical_source="$(cd "$(dirname "$source")" && pwd -P)/$(basename "$source")"
  [ "$canonical_source" = "$source" ] || fail "service ${service} bind source is not canonical"
  allowed=false
  for root in "$fixture_root" "$evidence_dir" "$package_dir" "$candidate_dir"; do
    if is_within "$source" "$root"; then allowed=true; break; fi
  done
  [ "$allowed" = true ] || fail "service ${service} bind source escapes approved roots"
  if (is_within "$source" "$package_dir" || is_within "$source" "$candidate_dir") && [ "$read_only" != true ]; then
    fail "service ${service} may bind package and verified candidate only read-only"
  fi
  for shared_root in '/Users/tomrobak/_projects_/fchub-playground' '/Users/tomrobak/wp/wesolalapka.com'; do
    is_within "$source" "$shared_root" && fail "service ${service} binds known shared root ${shared_root}"
  done
done < "$bind_file"

report="$(jq -S -n \
  --arg status isolated \
  --arg project "$project" \
  --arg compose_sha256 "$(digest_file "$compose_file")" \
  --arg config_sha256 "$(digest_file "$config_file")" \
  --arg fixture_root_hash "$(printf '%s' "$fixture_root" | shasum -a 256 | awk '{print $1}')" \
  --arg evidence_dir_hash "$(printf '%s' "$evidence_dir" | shasum -a 256 | awk '{print $1}')" \
  --arg package_dir_hash "$(printf '%s' "$package_dir" | shasum -a 256 | awk '{print $1}')" \
  --arg candidate_dir_hash "$(printf '%s' "$candidate_dir" | shasum -a 256 | awk '{print $1}')" \
  '{status:$status,project:$project,compose_sha256:$compose_sha256,config_sha256:$config_sha256,fixture_root_hash:$fixture_root_hash,evidence_dir_hash:$evidence_dir_hash,package_dir_hash:$package_dir_hash,candidate_dir_hash:$candidate_dir_hash,outbound_networks:0,shared_resources:0}')"

if [ -n "$output" ]; then
  [ "${output#/}" != "$output" ] || fail 'output path must be absolute'
  is_within "$output" "$evidence_dir" || fail 'output must stay inside the private evidence directory'
  [ ! -e "$output" ] && [ ! -L "$output" ] || fail 'output evidence already exists'
  umask 077
  printf '%s\n' "$report" > "$output"
else
  printf '%s\n' "$report"
fi
