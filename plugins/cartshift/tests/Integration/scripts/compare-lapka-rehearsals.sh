#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift Łapka rehearsal comparison failed: %s\n' "$1" >&2; exit 1; }

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

verify_digest() {
  local label="$1" path="$2" expected="$3"
  [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || fail "${label} expected SHA-256 is invalid"
  [ "$(digest_file "$path")" = "$expected" ] || fail "${label} SHA-256 mismatch"
}

empty_report=''; empty_sha=''; populated_report=''; populated_sha=''; output=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --empty-report) empty_report="${2:-}"; shift 2 ;;
    --empty-sha256) empty_sha="${2:-}"; shift 2 ;;
    --populated-report) populated_report="${2:-}"; shift 2 ;;
    --populated-sha256) populated_sha="${2:-}"; shift 2 ;;
    --output) output="${2:-}"; shift 2 ;;
    *) fail "unknown argument $1" ;;
  esac
done

empty_report="$(canonical_private_file 'empty report' "$empty_report")"
populated_report="$(canonical_private_file 'populated report' "$populated_report")"
verify_digest 'empty report' "$empty_report" "$empty_sha"
verify_digest 'populated report' "$populated_report" "$populated_sha"
[ "$empty_report" != "$populated_report" ] || fail 'empty and populated reports must be distinct files'
[ -n "$output" ] && [ "${output#/}" != "$output" ] || fail 'output must be absolute'
[ ! -e "$output" ] && [ ! -L "$output" ] || fail 'output evidence already exists'
output_parent="$(cd "$(dirname "$output")" && pwd -P)"
[ "$output" = "$output_parent/$(basename "$output")" ] || fail 'output must be canonical'
output_mode="$(stat -f '%Lp' "$output_parent" 2>/dev/null || stat -c '%a' "$output_parent")"
case "$output_mode" in *00) ;; *) fail 'output directory must be private' ;; esac

validate_report() {
  local label="$1" mode="$2" path="$3"
  jq -e --arg mode "$mode" '
    . as $report |
    ($report.status == "passed" and $report.mode == $mode and
    ($report.package_manifest_sha256 | test("^[a-f0-9]{64}$")) and
    ($report.decision_set_sha256 | test("^[a-f0-9]{64}$")) and
    ($report.candidate_zip_sha256 | test("^[a-f0-9]{64}$")) and
    ($report.candidate_tree_sha256 | test("^[a-f0-9]{64}$")) and
    ($report.selection_fingerprint | test("^[a-f0-9]{64}$")) and
    ($report.approval_reference | test("^[a-f0-9]{64}$")) and
    ($report.expectations_sha256 | test("^[a-f0-9]{64}$")) and
    ($report.record_counts | type == "object" and all(.[]; type == "number" and . >= 0 and floor == .)) and
    ($report.final_status.state == "completed") and
    ($report.final_status.receipt_counts | type == "object") and
    ($report.outcomes | type == "object") and $report.outcomes.blocked == 0 and
    ($report.outcomes.selected == ([$report.record_counts[]] | add // 0)) and
    ($report.receipt_action_counts | type == "object") and
    (all($report.receipt_action_counts | to_entries[];
      (.key | test("^(product|customer|order|subscription):(created|reused)$")) and
      (.value | type == "number" and . >= 0 and floor == .))) and
    (($report.receipt_action_counts | [.[]] | add // 0)
      == ($report.outcomes.created + $report.outcomes.reused)) and
    ($report.semantic_receipts | type == "object") and
    (($report.semantic_receipts | length) == ($report.outcomes.created + $report.outcomes.reused)) and
    (all($report.semantic_receipts | to_entries[];
      (.key | test("^[a-z0-9][a-z0-9-]{2,63}:(product|customer|order|subscription):.+$")) and
      (.value | keys == ["record_kind","source_fingerprint"]) and
      (.value.record_kind | test("^(product|customer|order|subscription)$")) and
      (.value.source_fingerprint | test("^[a-f0-9]{64}$")))) and
    ($report.map_counts | type == "object") and ($report.money | type == "object") and
    ($report.spies == {lifecycle_event:0,mail_attempt:0,outbound_http_attempt:0}) and
    ($report.outbox_rows | type == "number") and $report.outbox_rows >= 0 and
    $report.blocking_findings == 0 and $report.dangling_maps == 0 and
    ($report.target_table_deltas | type == "object") and
    ($report.target_files | keys == ["added","changed","removed"]) and
    all($report.record_counts | to_entries[] | select(.key | test("^(product|customer|order|subscription)$"));
      ((($report.receipt_action_counts[.key + ":created"] // 0)
        + ($report.receipt_action_counts[.key + ":reused"] // 0)) == .value)))
  ' "$path" >/dev/null || fail "${label} report contract is invalid"
}

validate_report empty empty "$empty_report"
validate_report populated populated "$populated_report"

jq -e --slurpfile populated "$populated_report" '.semantic_receipts == $populated[0].semantic_receipts' \
  "$empty_report" >/dev/null || fail 'semantic receipt coverage differs'

jq -e --slurpfile populated "$populated_report" '
  {
    package_manifest_sha256,
    decision_set_sha256,
    candidate_zip_sha256,
    candidate_tree_sha256,
    selection_fingerprint,
    approval_reference,
    record_counts,
    receipt_counts:.final_status.receipt_counts,
    selected:.outcomes.selected,
    blocked:.outcomes.blocked,
    map_counts,
    money,
    spies,
    outbox_rows,
    blocking_findings,
    dangling_maps
  } == ($populated[0] | {
    package_manifest_sha256,
    decision_set_sha256,
    candidate_zip_sha256,
    candidate_tree_sha256,
    selection_fingerprint,
    approval_reference,
    record_counts,
    receipt_counts:.final_status.receipt_counts,
    selected:.outcomes.selected,
    blocked:.outcomes.blocked,
    map_counts,
    money,
    spies,
    outbox_rows,
    blocking_findings,
    dangling_maps
  })
' "$empty_report" >/dev/null || fail 'an unexplained semantic difference was found'

jq -S -n --slurpfile empty "$empty_report" --slurpfile populated "$populated_report" \
  --arg empty_sha256 "$empty_sha" --arg populated_sha256 "$populated_sha" '
  def action($report; $kind; $action): ($report.receipt_action_counts[$kind + ":" + $action] // 0);
  ((($empty[0].receipt_action_counts | keys) + ($populated[0].receipt_action_counts | keys))
    | map(split(":")[0]) | unique) as $kinds |
  (reduce $kinds[] as $kind ({};
    .[$kind] = {
      empty:{created:action($empty[0];$kind;"created"),reused:action($empty[0];$kind;"reused")},
      populated:{created:action($populated[0];$kind;"created"),reused:action($populated[0];$kind;"reused")}
    })) as $actions |
  {
    status:"passed",
    empty_report_sha256:$empty_sha256,
    populated_report_sha256:$populated_sha256,
    package_manifest_sha256:$empty[0].package_manifest_sha256,
    decision_set_sha256:$empty[0].decision_set_sha256,
    candidate_zip_sha256:$empty[0].candidate_zip_sha256,
    candidate_tree_sha256:$empty[0].candidate_tree_sha256,
    selection_fingerprint:$empty[0].selection_fingerprint,
    semantic_receipts:$empty[0].semantic_receipts,
    explained_action_differences:$actions,
    baseline_bound_differences:{
      empty:{expectations_sha256:$empty[0].expectations_sha256,target_table_deltas:$empty[0].target_table_deltas,target_files:$empty[0].target_files},
      populated:{expectations_sha256:$populated[0].expectations_sha256,target_table_deltas:$populated[0].target_table_deltas,target_files:$populated[0].target_files}
    }
  }
' > "$output"
chmod 0600 "$output"
printf '%s\n' "$output"
