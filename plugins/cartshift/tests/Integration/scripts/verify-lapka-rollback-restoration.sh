#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift Łapka rollback restoration failed: %s\n' "$1" >&2; exit 1; }

digest_file() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

canonical_private_file() {
  local label="$1" path="$2" canonical mode
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -f "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute regular non-symlink file"
  canonical="$(cd "$(dirname "$path")" && pwd -P)/$(basename "$path")"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  mode="$(stat -f '%Lp' "$path" 2>/dev/null || stat -c '%a' "$path")"
  case "$mode" in *00) ;; *) fail "${label} must be private" ;; esac
  printf '%s\n' "$canonical"
}

baseline=''; baseline_sha=''; descriptor=''; database=''; files=''; output=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --baseline-report) baseline="${2:-}"; shift 2 ;;
    --baseline-report-sha256) baseline_sha="${2:-}"; shift 2 ;;
    --descriptor) descriptor="${2:-}"; shift 2 ;;
    --database-projection) database="${2:-}"; shift 2 ;;
    --files-projection) files="${2:-}"; shift 2 ;;
    --output) output="${2:-}"; shift 2 ;;
    *) fail "unknown argument $1" ;;
  esac
done

[[ "$descriptor" =~ ^tr-[a-f0-9]{24}$ ]] || fail 'descriptor is invalid'
baseline="$(canonical_private_file 'baseline report' "$baseline")"
database="$(canonical_private_file 'database projection' "$database")"
files="$(canonical_private_file 'files projection' "$files")"
[[ "$baseline_sha" =~ ^[a-f0-9]{64}$ ]] || fail 'baseline report expected SHA-256 is invalid'
[ "$(digest_file "$baseline")" = "$baseline_sha" ] || fail 'baseline report SHA-256 mismatch'
[ -n "$output" ] && [ "${output#/}" != "$output" ] || fail 'output must be absolute'
[ ! -e "$output" ] && [ ! -L "$output" ] || fail 'output evidence already exists'
output_parent="$(cd "$(dirname "$output")" && pwd -P)"
[ "$output" = "$output_parent/$(basename "$output")" ] || fail 'output must be canonical'
output_mode="$(stat -f '%Lp' "$output_parent" 2>/dev/null || stat -c '%a' "$output_parent")"
case "$output_mode" in *00) ;; *) fail 'output directory must be private' ;; esac

jq -e --arg descriptor "$descriptor" '
  .status == "owner_review_required" and .mode == "rollback" and
  .descriptor == $descriptor and
  (.rollback_plan_sha256 | test("^[a-f0-9]{64}$")) and
  (.rollback_baseline | keys == ["target_database","target_files"]) and
  (.rollback_baseline.target_database | keys == ["schema_sha256","stable_option_hashes","stable_options_sha256","table_checksums","table_counts","volatile_option_value_names","volatile_options_shape_sha256"]) and
  (.rollback_baseline.target_database.schema_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.rollback_baseline.target_database.stable_option_hashes | type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$"))) and
  (.rollback_baseline.target_database.stable_options_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.rollback_baseline.target_database.volatile_option_value_names == ["_ff_fluentform_pro_license_status_checking","_transient_timeout_wcs_woocommerce_active_version","pmpro_library_conflicts"]) and
  (.rollback_baseline.target_database.volatile_options_shape_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.rollback_baseline.target_database.table_counts | type == "object" and all(.[]; type == "number" and . >= 0 and floor == .)) and
  (.rollback_baseline.target_database.table_checksums | type == "object" and all(.[]; type == "number" and . >= 0 and floor == .)) and
  (.rollback_baseline.target_files | type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$")))
' "$baseline" >/dev/null || fail 'baseline report contract is invalid'

jq -e '
  keys == ["schema_sha256","stable_option_hashes","stable_options_sha256","table_checksums","table_counts","volatile_option_value_names","volatile_options_shape_sha256"] and
  (.schema_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.stable_option_hashes | type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$"))) and
  (.stable_options_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.volatile_option_value_names == ["_ff_fluentform_pro_license_status_checking","_transient_timeout_wcs_woocommerce_active_version","pmpro_library_conflicts"]) and
  (.volatile_options_shape_sha256 | type == "string" and test("^[a-f0-9]{64}$")) and
  (.table_counts | type == "object" and all(.[]; type == "number" and . >= 0 and floor == .)) and
  (.table_checksums | type == "object" and all(.[]; type == "number" and . >= 0 and floor == .))
' "$database" >/dev/null || fail 'business database projection contract is invalid'
jq -e 'type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$"))' "$files" >/dev/null \
  || fail 'target files projection contract is invalid'

jq -e --slurpfile current "$database" '.rollback_baseline.target_database == $current[0]' "$baseline" >/dev/null \
  || fail 'business database differs from the pre-stage baseline'
jq -e --slurpfile current "$files" '.rollback_baseline.target_files == $current[0]' "$baseline" >/dev/null \
  || fail 'target files differ from the pre-stage baseline'

jq -S -n --arg status passed --arg descriptor "$descriptor" \
  --arg baseline_report_sha256 "$baseline_sha" --arg database_sha256 "$(digest_file "$database")" \
  --arg files_sha256 "$(digest_file "$files")" \
  '{status:$status,descriptor:$descriptor,baseline_report_sha256:$baseline_report_sha256,business_database_sha256:$database_sha256,target_files_sha256:$files_sha256}' \
  > "$output"
chmod 0600 "$output"
printf '%s\n' "$output"
