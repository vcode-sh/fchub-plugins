#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift Łapka rehearsal failed: %s\n' "$1" >&2; exit 1; }

digest_file() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

canonical_file() {
  local label="$1" path="$2" canonical mode
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -f "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute regular non-symlink file"
  canonical="$(cd "$(dirname "$path")" && pwd -P)/$(basename "$path")"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  mode="$(stat -f '%Lp' "$path" 2>/dev/null || stat -c '%a' "$path")"
  case "$mode" in *00) ;; *) fail "${label} must be private" ;; esac
  printf '%s\n' "$canonical"
}

canonical_directory() {
  local label="$1" path="$2" canonical
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -d "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute non-symlink directory"
  canonical="$(cd "$path" && pwd -P)"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  printf '%s\n' "$canonical"
}

verify_digest() {
  local label="$1" path="$2" expected="$3"
  [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || fail "${label} expected SHA-256 is invalid"
  [ "$(digest_file "$path")" = "$expected" ] || fail "${label} SHA-256 mismatch"
}

candidate_tree_digest() {
  local root="$1" inventory relative
  inventory="$(mktemp "${TMPDIR:-/tmp}/cartshift-candidate-tree.XXXXXX")"
  while IFS= read -r relative; do
    printf '%s\t%s\n' "$relative" "$(digest_file "$root/$relative")" >> "$inventory"
  done < <(cd "$root" && find . -type f -print | sed 's#^\./##' | LC_ALL=C sort)
  [ -s "$inventory" ] || { rm -f -- "$inventory"; fail 'candidate tree is empty'; }
  digest_file "$inventory"
  rm -f -- "$inventory"
}

within() { [ "$1" = "$2" ] || [[ "$1" == "$2"/* ]]; }

mode=''; state_file=''; package_dir=''; manifest_sha=''; decision_set=''; decision_sha=''
expectations=''; expectations_sha=''; output=''; operator=''; source_key=''; selection=''
schema_from=''; approval_reference=''; resume_descriptor=''; rollback_plan=''; rollback_plan_sha=''
rollback_action=''
rollback_baseline=''; rollback_baseline_sha=''

while [ "$#" -gt 0 ]; do
  case "$1" in
    --mode) mode="${2:-}"; shift 2 ;;
    --state-file) state_file="${2:-}"; shift 2 ;;
    --package-dir) package_dir="${2:-}"; shift 2 ;;
    --manifest-sha256) manifest_sha="${2:-}"; shift 2 ;;
    --decision-set) decision_set="${2:-}"; shift 2 ;;
    --decision-set-sha256) decision_sha="${2:-}"; shift 2 ;;
    --expectations) expectations="${2:-}"; shift 2 ;;
    --expectations-sha256) expectations_sha="${2:-}"; shift 2 ;;
    --output) output="${2:-}"; shift 2 ;;
    --operator) operator="${2:-}"; shift 2 ;;
    --source-key) source_key="${2:-}"; shift 2 ;;
    --selection-fingerprint) selection="${2:-}"; shift 2 ;;
    --schema-from) schema_from="${2:-}"; shift 2 ;;
    --approval-reference) approval_reference="${2:-}"; shift 2 ;;
    --resume-descriptor) resume_descriptor="${2:-}"; shift 2 ;;
    --rollback-plan) rollback_plan="${2:-}"; shift 2 ;;
    --rollback-plan-sha256) rollback_plan_sha="${2:-}"; shift 2 ;;
    --rollback-action) rollback_action="${2:-}"; shift 2 ;;
    --rollback-baseline) rollback_baseline="${2:-}"; shift 2 ;;
    --rollback-baseline-sha256) rollback_baseline_sha="${2:-}"; shift 2 ;;
    *) fail "unknown argument $1" ;;
  esac
done

case "$mode" in empty|populated|repeat|rollback) ;; *) fail 'mode must be empty, populated, repeat, or rollback' ;; esac
if [ "$mode" = rollback ]; then
  case "$rollback_action" in plan|apply) ;; *) fail 'rollback mode requires --rollback-action=plan or apply' ;; esac
  if [ "$rollback_action" = plan ]; then
    [ -z "$rollback_baseline" ] && [ -z "$rollback_baseline_sha" ] \
      || fail 'rollback plan mode cannot accept a prior rollback baseline'
  else
    [ -n "$rollback_baseline" ] && [ -n "$rollback_baseline_sha" ] \
      || fail 'rollback apply mode requires the sealed rollback baseline report and SHA-256'
  fi
elif [ "$mode" = repeat ]; then
  [ -n "$resume_descriptor" ] || fail 'repeat mode requires --resume-descriptor from the completed rehearsal'
  [[ "$resume_descriptor" =~ ^tr-[a-f0-9]{24}$ ]] || fail 'repeat descriptor is invalid'
  [ -z "$rollback_action" ] && [ -z "$rollback_plan" ] && [ -z "$rollback_plan_sha" ] \
    && [ -z "$rollback_baseline" ] && [ -z "$rollback_baseline_sha" ] \
    || fail 'rollback-only arguments are forbidden in repeat mode'
elif [ -n "$rollback_action" ] || [ -n "$resume_descriptor" ] || [ -n "$rollback_plan" ] || [ -n "$rollback_plan_sha" ] \
  || [ -n "$rollback_baseline" ] || [ -n "$rollback_baseline_sha" ]; then
  fail 'rollback-only arguments are forbidden outside rollback mode'
fi
case "$schema_from" in 7|8) ;; *) fail 'schema-from must be 7 or 8' ;; esac
[[ "$operator" =~ ^[A-Za-z0-9._:-]{1,64}$ ]] || fail 'operator identity is invalid'
[[ "$source_key" =~ ^[a-z0-9][a-z0-9-]{2,63}$ ]] || fail 'source key is invalid'
[[ "$selection" =~ ^[a-f0-9]{64}$ ]] || fail 'selection fingerprint is invalid'
[[ "$approval_reference" =~ ^[a-f0-9]{64}$ ]] || fail 'approval reference is invalid'

state_file="$(canonical_file 'restore state' "$state_file")"
decision_set="$(canonical_file 'decision set' "$decision_set")"
expectations="$(canonical_file 'expectations' "$expectations")"
verify_digest 'decision set' "$decision_set" "$decision_sha"
verify_digest 'expectations' "$expectations" "$expectations_sha"

jq -e --arg mode "$mode" --arg source_key "$source_key" --arg selection "$selection" '
  .version == 1 and
  (.mode == $mode or ($mode == "repeat" and (.mode == "empty" or .mode == "populated"))) and
  (.project | type == "string") and
  (.compose_file | type == "string") and (.compose_sha256 | test("^[a-f0-9]{64}$")) and
  (.fixture_root | type == "string") and (.evidence_dir | type == "string") and
  (.package_dir | type == "string") and (.manifest_sha256 | test("^[a-f0-9]{64}$")) and
  (.candidate_dir | type == "string") and (.candidate_zip_sha256 | test("^[a-f0-9]{64}$")) and
  (.candidate_tree_sha256 | test("^[a-f0-9]{64}$")) and
  (.mariadb_image | test("^([a-z0-9./_-]+@sha256:[a-f0-9]{64}|sha256:[a-f0-9]{64})$")) and
  (.wpcli_image | test("^([a-z0-9./_-]+@sha256:[a-f0-9]{64}|sha256:[a-f0-9]{64})$")) and
  (.wordpress_image | test("^([a-z0-9./_-]+@sha256:[a-f0-9]{64}|sha256:[a-f0-9]{64})$")) and
  (.source_prefix | test("^[A-Za-z0-9_]{1,48}$")) and (.target_prefix | test("^[A-Za-z0-9_]{1,48}$")) and
  (.restore_report | type == "string") and (.restore_report_sha256 | test("^[a-f0-9]{64}$")) and
  (.isolation_report | type == "string") and (.isolation_report_sha256 | test("^[a-f0-9]{64}$"))
' "$state_file" >/dev/null || fail 'restore state contract or mode is invalid'

project="$(jq -r '.project' "$state_file")"
compose_file="$(jq -r '.compose_file' "$state_file")"
fixture_root="$(jq -r '.fixture_root' "$state_file")"
evidence_dir="$(jq -r '.evidence_dir' "$state_file")"
state_package_dir="$(jq -r '.package_dir' "$state_file")"
candidate_dir="$(jq -r '.candidate_dir' "$state_file")"
candidate_zip_sha="$(jq -r '.candidate_zip_sha256' "$state_file")"
candidate_tree_sha="$(jq -r '.candidate_tree_sha256' "$state_file")"
mariadb_image="$(jq -r '.mariadb_image' "$state_file")"
wpcli_image="$(jq -r '.wpcli_image' "$state_file")"
wordpress_image="$(jq -r '.wordpress_image' "$state_file")"
source_prefix="$(jq -r '.source_prefix' "$state_file")"
target_prefix="$(jq -r '.target_prefix' "$state_file")"
restore_report="$(jq -r '.restore_report' "$state_file")"
isolation_report="$(jq -r '.isolation_report' "$state_file")"

[ "$package_dir" = "$state_package_dir" ] || fail 'package directory differs from restored fixture state'
[ "$(jq -r '.manifest_sha256' "$state_file")" = "$manifest_sha" ] || fail 'manifest differs from restored fixture state'
verify_digest 'Compose file' "$compose_file" "$(jq -r '.compose_sha256' "$state_file")"
verify_digest 'restore report' "$restore_report" "$(jq -r '.restore_report_sha256' "$state_file")"
verify_digest 'isolation report' "$isolation_report" "$(jq -r '.isolation_report_sha256' "$state_file")"
candidate_dir="$(canonical_directory 'candidate directory' "$candidate_dir")"
within "$candidate_dir" "$fixture_root" || fail 'candidate directory escaped the restored fixture root'
[ -f "$candidate_dir/cartshift.php" ] && [ ! -L "$candidate_dir/cartshift.php" ] \
  || fail 'candidate directory is not an installable CartShift plugin tree'
[ "$(candidate_tree_digest "$candidate_dir")" = "$candidate_tree_sha" ] || fail 'verified CartShift candidate tree changed after restore'
[ -f "$package_dir/manifest.json" ] && verify_digest 'package manifest' "$package_dir/manifest.json" "$manifest_sha"
within "$decision_set" "$evidence_dir" || fail 'decision set must be copied into the private rehearsal evidence directory'
within "$expectations" "$evidence_dir" || fail 'expectations must be copied into the private rehearsal evidence directory'
if [ -z "$output" ] || [ "${output#/}" = "$output" ] || ! within "$output" "$evidence_dir"; then
  fail 'output must be an absolute path inside the private evidence directory'
fi
[ ! -e "$output" ] && [ ! -L "$output" ] || fail 'output evidence already exists'

jq -e --arg source_key "$source_key" --arg selection "$selection" '
  .version == 1 and .source_key == $source_key and .selection_fingerprint == $selection and
  (.record_counts | type == "object") and (.receipt_counts | type == "object") and
  (.receipt_action_counts | type == "object") and (.map_counts | type == "object") and
  (.target_table_deltas | type == "object") and (.money | type == "object") and
  (.target_files | type == "object") and
  (.target_files | keys == ["added","changed","removed"]) and
  (all(.target_files[]; type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$")))) and
  (.outcomes | type == "object") and (.outcomes.blocked == 0) and
  (.outbox_rows | type == "number") and (.outbox_rows >= 0) and
  (.spies == {lifecycle_event:0,mail_attempt:0,outbound_http_attempt:0}) and
  (.dangling_maps == 0) and (.blocking_findings == 0)
' "$expectations" >/dev/null || fail 'expectations contract is invalid or contains accepted blockers/side effects'

manifest="$package_dir/manifest.json"
jq -e --arg source_key "$source_key" --arg selection "$selection" --slurpfile expected "$expectations" '
  .format == "cartshift-transfer" and .format_version == 2 and .source_key == $source_key and
  .selection_fingerprint == $selection and .record_counts == $expected[0].record_counts
' "$manifest" >/dev/null || fail 'manifest identity or record counts differ from sealed expectations'

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
export CARTSHIFT_FIXTURE_ARTIFACTS="$fixture_root/artifacts" CARTSHIFT_CANDIDATE_DIR="$candidate_dir"
export CARTSHIFT_PACKAGE_DIR="$package_dir" CARTSHIFT_EVIDENCE_DIR="$evidence_dir"
export CARTSHIFT_SOURCE_PREFIX="$source_prefix" CARTSHIFT_TARGET_PREFIX="$target_prefix"
export CARTSHIFT_REHEARSAL_MARIADB_IMAGE="$mariadb_image" CARTSHIFT_REHEARSAL_WPCLI_IMAGE="$wpcli_image" CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE="$wordpress_image"

run_phase='full'
if [ "$mode" = rollback ]; then run_phase="$rollback_action"
elif [ "$mode" = repeat ]; then run_phase='repeat'
fi
fresh_isolation="$evidence_dir/${project}-isolation-${run_phase}.json"
[ ! -e "$fresh_isolation" ] || fail 'isolation rerun evidence already exists'
"$script_dir/assert-isolated-stack.sh" --project "$project" --compose-file "$compose_file" \
  --fixture-root "$fixture_root" --evidence-dir "$evidence_dir" --package-dir "$package_dir" \
  --candidate-dir "$candidate_dir" --output "$fresh_isolation"
jq -e --slurpfile prior "$isolation_report" '. == $prior[0]' "$fresh_isolation" >/dev/null \
  || fail 'isolated Compose boundary changed since restore'

run_dir="$evidence_dir/${project}-run-${run_phase}"
[ ! -e "$run_dir" ] || fail 'rehearsal command evidence directory already exists'
mkdir -m 0700 "$run_dir"
durations_file="$run_dir/durations.json"
jq -S -n '{}' > "$durations_file"
decision_container="/cartshift-evidence/${decision_set#"$evidence_dir"/}"
target_backup_sha="$(jq -r '.target_backup_sha256' "$restore_report")"
spy_source="$evidence_dir/rehearsal-spy-source.jsonl"
spy_target="$evidence_dir/rehearsal-spy-target.jsonl"
spy_source_lines=0; spy_source_sha=''
spy_target_lines=0; spy_target_sha=''
if [ -e "$spy_source" ]; then spy_source_lines="$(wc -l < "$spy_source" | tr -d ' ')"; spy_source_sha="$(digest_file "$spy_source")"; fi
if [ -e "$spy_target" ]; then spy_target_lines="$(wc -l < "$spy_target" | tr -d ' ')"; spy_target_sha="$(digest_file "$spy_target")"; fi
jq -S -n --argjson source_lines "$spy_source_lines" --arg source_sha256 "$spy_source_sha" \
  --argjson target_lines "$spy_target_lines" --arg target_sha256 "$spy_target_sha" \
  '{source:{lines:$source_lines,sha256:$source_sha256},target:{lines:$target_lines,sha256:$target_sha256}}' \
  > "$run_dir/spy-baseline.json"
chmod 0600 "$run_dir/spy-baseline.json"

rehearsal_skip_plugins='sfwd-lms,learndash-achievements,learndash-certificate-builder,learndash-integrity,learndash-notifications,learndash-woocommerce'
if [ "$mode" = repeat ]; then
  active_plugins="$(docker compose --project-name "$project" --file "$compose_file" exec -T target-cli \
    wp --allow-root option get active_plugins --format=json --skip-plugins --skip-themes \
    | jq -r '.[] | split("/")[0]' | LC_ALL=C sort -u)"
  for required_plugin in cartshift woocommerce woocommerce-subscriptions fluent-cart; do
    printf '%s\n' "$active_plugins" | grep -Fx "$required_plugin" >/dev/null \
      || fail "required rehearsal plugin ${required_plugin} is not active"
  done
  rehearsal_skip_plugins=''
  while IFS= read -r active_plugin; do
    [[ "$active_plugin" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'active plugin list contains an unsafe slug'
    case "$active_plugin" in
      cartshift|woocommerce|woocommerce-subscriptions|fluent-cart|fluent-cart-pro|\
      fchub|fchub-fakturownia|fchub-memberships) continue ;;
    esac
    if [ -n "$rehearsal_skip_plugins" ]; then rehearsal_skip_plugins+=','; fi
    rehearsal_skip_plugins+="$active_plugin"
  done <<< "$active_plugins"
fi

db_query() {
  docker compose --project-name "$project" --file "$compose_file" exec -T target-db \
    mariadb -B -N -urehearsal -prehearsal target -e "$1" < /dev/null | tr -d '\r'
}

preexisting_target_projection() {
  local destination="$1" boundaries="${2:-}" rows_file entries_file table key maximum count rows_sha
  local -a specifications=(
    "cartshift_id_map:id"
    "cartshift_migration_log:id"
    "posts:ID"
    "postmeta:meta_id"
    "fct_product_details:id"
    "fct_product_variations:id"
    "fct_product_downloads:id"
    "fct_customers:id"
    "fct_customer_addresses:id"
    "fct_orders:id"
    "fct_order_items:id"
    "fct_order_transactions:id"
    "fct_order_tax_rate:id"
    "fct_order_addresses:id"
    "fct_applied_coupons:id"
    "fct_order_meta:id"
    "fct_subscriptions:id"
    "fct_subscription_meta:id"
  )

  if [ -n "$boundaries" ]; then
    [ -f "$boundaries" ] && [ ! -L "$boundaries" ] \
      || fail 'pre-existing target boundary report is missing or unsafe'
    jq -e '.status == "captured" and (.tables | type == "object")' "$boundaries" >/dev/null \
      || fail 'pre-existing target boundary report is malformed'
  fi

  rows_file="$run_dir/.preexisting-target-rows-$(basename "$destination")"
  entries_file="$run_dir/.preexisting-target-entries-$(basename "$destination")"
  : > "$entries_file"

  for specification in "${specifications[@]}"; do
    table="${target_prefix}${specification%%:*}"
    key="${specification#*:}"
    if [ "$(db_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='target' AND TABLE_NAME='${table}';")" -ne 1 ]; then
      continue
    fi
    if [ "$(db_query "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='target' AND TABLE_NAME='${table}' AND COLUMN_NAME='${key}';")" -ne 1 ]; then
      fail "pre-existing target projection key is missing for ${table}"
    fi

    if [ -n "$boundaries" ]; then
      maximum="$(jq -r --arg table "$table" '.tables[$table].maximum_id // empty' "$boundaries")"
      [[ "$maximum" =~ ^[0-9]+$ ]] || fail "pre-existing target boundary is missing for ${table}"
    else
      maximum="$(db_query "SELECT COALESCE(MAX(\`${key}\`),0) FROM \`${table}\`;")"
      [[ "$maximum" =~ ^[0-9]+$ ]] || fail "pre-existing target maximum ID is unreadable for ${table}"
    fi

    db_query "SELECT * FROM \`${table}\` WHERE \`${key}\` <= ${maximum} ORDER BY \`${key}\`;" > "$rows_file"
    count="$(db_query "SELECT COUNT(*) FROM \`${table}\` WHERE \`${key}\` <= ${maximum};")"
    [[ "$count" =~ ^[0-9]+$ ]] || fail "pre-existing target row count is unreadable for ${table}"
    rows_sha="$(digest_file "$rows_file")"
    jq -S -n --arg table "$table" --arg key "$key" --argjson maximum_id "$maximum" \
      --argjson row_count "$count" --arg rows_sha256 "$rows_sha" \
      '{key:$table,value:{key:$key,maximum_id:$maximum_id,row_count:$row_count,rows_sha256:$rows_sha256}}' \
      >> "$entries_file"
  done

  jq -S -s '{status:"captured",tables:(from_entries)}' "$entries_file" > "$destination"
  chmod 0600 "$destination"
  rm -f -- "$rows_file" "$entries_file"
}

database_projection() {
  local destination="$1" scope="${2:-all}" tables_file projected_tables_file rows_file schema_file
  local counts_file checksums_file table count_sql checksum_tables metadata_table_filter
  local stable_options_file stable_option_hashes_file volatile_options_file
  local stable_options_sha volatile_options_shape_sha
  case "$scope" in all|business) ;; *) fail 'database projection scope is invalid' ;; esac
  tables_file="$run_dir/.database-tables-$(basename "$destination")"
  projected_tables_file="$run_dir/.database-projected-tables-$(basename "$destination")"
  rows_file="$run_dir/.database-rows-$(basename "$destination")"
  schema_file="$run_dir/.database-schema-$(basename "$destination")"
  counts_file="$run_dir/.database-counts-$(basename "$destination")"
  checksums_file="$run_dir/.database-checksums-$(basename "$destination")"
  stable_options_file="$run_dir/.database-stable-options-$(basename "$destination")"
  stable_option_hashes_file="$run_dir/.database-stable-option-hashes-$(basename "$destination")"
  volatile_options_file="$run_dir/.database-volatile-options-$(basename "$destination")"
  db_query "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='target' AND LEFT(TABLE_NAME, ${#target_prefix})='${target_prefix}' ORDER BY TABLE_NAME;" > "$tables_file"
  [ -s "$tables_file" ] || fail 'target database projection found no prefixed tables'
  : > "$projected_tables_file"
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail 'target database projection found an unsafe table name'
    if [ "$scope" = business ]; then
      case "$table" in
        "${target_prefix}cartshift_id_map"|"${target_prefix}cartshift_target_claims"|\
        "${target_prefix}cartshift_shared_links"|"${target_prefix}cartshift_transfer_runs"|\
        "${target_prefix}cartshift_transfer_records"|"${target_prefix}cartshift_transfer_outbox") continue ;;
      esac
    fi
    printf '%s\n' "$table" >> "$projected_tables_file"
  done < "$tables_file"
  [ -s "$projected_tables_file" ] || fail 'target database projection found no in-scope tables'

  count_sql=''; checksum_tables=''; metadata_table_filter=''
  while IFS= read -r table; do
    if [ -n "$count_sql" ]; then count_sql+=' UNION ALL '; checksum_tables+=','; metadata_table_filter+=','; fi
    count_sql+="SELECT '${table}',COUNT(*) FROM \`${table}\`"
    checksum_tables+="\`${table}\`"
    metadata_table_filter+="'${table}'"
  done < "$projected_tables_file"
  db_query "${count_sql};" > "$counts_file"
  db_query "CHECKSUM TABLE ${checksum_tables};" > "$checksums_file"
  awk -F '\t' -v options_table="${target_prefix}options" '
    NR == FNR { counts[$1] = $2; next }
    {
      table = $1; sub(/^target[.]/, "", table); checksum = $2;
      if (table == options_table) checksum = 0;
      print table "\t" counts[table] "\t" checksum;
    }
  ' "$counts_file" "$checksums_file" > "$rows_file"
  if [ "$(wc -l < "$rows_file" | tr -d ' ')" -ne "$(wc -l < "$projected_tables_file" | tr -d ' ')" ] \
    || ! awk -F '\t' 'NF != 3 || $2 !~ /^[0-9]+$/ || $3 !~ /^[0-9]+$/ { exit 1 }' "$rows_file"; then
    fail 'target database projection did not return an exact count and checksum for every table'
  fi

  {
    db_query "SELECT CONCAT_WS(CHAR(9),'TABLE',TABLE_NAME,COALESCE(ENGINE,''),COALESCE(ROW_FORMAT,''),COALESCE(TABLE_COLLATION,''),COALESCE(CREATE_OPTIONS,''),TO_BASE64(COALESCE(TABLE_COMMENT,''))) FROM information_schema.TABLES WHERE TABLE_SCHEMA='target' AND TABLE_NAME IN (${metadata_table_filter}) ORDER BY TABLE_NAME;"
    db_query "SELECT CONCAT_WS(CHAR(9),'COLUMN',TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COALESCE(TO_BASE64(COLUMN_DEFAULT),'NULL'),EXTRA,COALESCE(CHARACTER_SET_NAME,''),COALESCE(COLLATION_NAME,''),TO_BASE64(COALESCE(COLUMN_COMMENT,'')),TO_BASE64(COALESCE(GENERATION_EXPRESSION,''))) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='target' AND TABLE_NAME IN (${metadata_table_filter}) ORDER BY TABLE_NAME,ORDINAL_POSITION;"
    db_query "SELECT CONCAT_WS(CHAR(9),'INDEX',TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,COALESCE(SUB_PART,0),COALESCE(COLLATION,''),COALESCE(INDEX_TYPE,''),TO_BASE64(COALESCE(INDEX_COMMENT,''))) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='target' AND TABLE_NAME IN (${metadata_table_filter}) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;"
    db_query "SELECT CONCAT_WS(CHAR(9),'CONSTRAINT',TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='target' AND TABLE_NAME IN (${metadata_table_filter}) ORDER BY TABLE_NAME,CONSTRAINT_NAME;"
    db_query "SELECT CONCAT_WS(CHAR(9),'KEY',TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION,COLUMN_NAME,COALESCE(REFERENCED_TABLE_SCHEMA,''),COALESCE(REFERENCED_TABLE_NAME,''),COALESCE(REFERENCED_COLUMN_NAME,'')) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='target' AND TABLE_NAME IN (${metadata_table_filter}) ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION;"
    db_query "SELECT CONCAT_WS(CHAR(9),'CHECK',tc.TABLE_NAME,cc.CONSTRAINT_NAME,TO_BASE64(cc.CHECK_CLAUSE)) FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA='target' AND tc.TABLE_NAME IN (${metadata_table_filter}) ORDER BY tc.TABLE_NAME,cc.CONSTRAINT_NAME;"
    db_query "SELECT CONCAT_WS(CHAR(9),'TRIGGER',EVENT_OBJECT_TABLE,TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,TO_BASE64(ACTION_STATEMENT)) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA='target' AND EVENT_OBJECT_TABLE IN (${metadata_table_filter}) ORDER BY EVENT_OBJECT_TABLE,TRIGGER_NAME;"
  } > "$schema_file"

  db_query "SELECT CONCAT_WS(CHAR(9),option_id,TO_BASE64(option_name),TO_BASE64(option_value),TO_BASE64(autoload)) FROM \`${target_prefix}options\` WHERE option_name NOT IN ('_ff_fluentform_pro_license_status_checking','_transient_timeout_wcs_woocommerce_active_version','pmpro_library_conflicts') ORDER BY option_id;" > "$stable_options_file"
  db_query "SELECT JSON_OBJECT('name',option_name,'sha256',SHA2(CONCAT_WS(CHAR(31),option_id,TO_BASE64(option_name),TO_BASE64(option_value),TO_BASE64(autoload)),256)) FROM \`${target_prefix}options\` WHERE option_name NOT IN ('_ff_fluentform_pro_license_status_checking','_transient_timeout_wcs_woocommerce_active_version','pmpro_library_conflicts') ORDER BY option_id;" > "$stable_option_hashes_file"
  db_query "SELECT CONCAT_WS(CHAR(9),option_id,TO_BASE64(option_name),TO_BASE64(autoload)) FROM \`${target_prefix}options\` WHERE option_name IN ('_ff_fluentform_pro_license_status_checking','_transient_timeout_wcs_woocommerce_active_version','pmpro_library_conflicts') ORDER BY option_id;" > "$volatile_options_file"
  stable_options_sha="$(digest_file "$stable_options_file")"
  volatile_options_shape_sha="$(digest_file "$volatile_options_file")"
  jq -R -s -S --arg schema_sha256 "$(digest_file "$schema_file")" \
    --arg stable_options_sha256 "$stable_options_sha" \
    --arg volatile_options_shape_sha256 "$volatile_options_shape_sha" \
    --slurpfile stable_option_hashes "$stable_option_hashes_file" '
    split("\n") | map(select(length > 0) | split("\t")) |
    {
      schema_sha256:$schema_sha256,
      stable_option_hashes:($stable_option_hashes | map({key:.name,value:.sha256}) | from_entries),
      stable_options_sha256:$stable_options_sha256,
      volatile_option_value_names:[
        "_ff_fluentform_pro_license_status_checking",
        "_transient_timeout_wcs_woocommerce_active_version",
        "pmpro_library_conflicts"
      ],
      volatile_options_shape_sha256:$volatile_options_shape_sha256,
      table_counts:(map({key:.[0],value:(.[1]|tonumber)})|from_entries),
      table_checksums:(map({key:.[0],value:(.[2]|tonumber)})|from_entries)
    }
  ' "$rows_file" > "$destination"
  chmod 0600 "$destination"
  rm -f -- "$tables_file" "$projected_tables_file" "$rows_file" "$schema_file" "$counts_file" \
    "$checksums_file" "$stable_options_file" "$stable_option_hashes_file" "$volatile_options_file"
}

filesystem_projection() {
  local destination="$1" raw
  raw="$run_dir/.filesystem-$(basename "$destination")"
  docker compose --project-name "$project" --file "$compose_file" exec -T target-cli php -r '
$root = "/var/www/html/wp-content";
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);
foreach ($iterator as $entry) {
    $path = $entry->getPathname();
    $relative = substr($path, strlen($root) + 1);
    if ($relative === "plugins/cartshift" || str_starts_with($relative, "plugins/cartshift/")) {
        continue;
    }
    $stat = lstat($path);
    if (!is_array($stat) || !isset($stat["mode"])) {
        fwrite(STDERR, "Unreadable filesystem metadata in target wp-content.\n");
        exit(2);
    }
    $mode = sprintf("%04o", ((int) $stat["mode"]) & 07777);
    if ($entry->isLink()) {
        $target = readlink($path);
        if (!is_string($target)) {
            fwrite(STDERR, "Unreadable symlink in target wp-content.\n");
            exit(3);
        }
        $files[$relative] = hash("sha256", "link\0" . $mode . "\0" . $target);
    } elseif ($entry->isFile()) {
        $hash = hash_file("sha256", $path);
        if (!is_string($hash)) {
            fwrite(STDERR, "Unreadable file in target wp-content.\n");
            exit(4);
        }
        $files[$relative] = hash("sha256", "file\0" . $mode . "\0" . $hash);
    } elseif ($entry->isDir()) {
        $files[$relative] = hash("sha256", "directory\0" . $mode);
    } else {
        fwrite(STDERR, "Unsupported filesystem entry in target wp-content.\n");
        exit(5);
    }
}
ksort($files, SORT_STRING);
echo json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
' > "$raw"
  jq -e 'type == "object" and all(.[]; type == "string" and test("^[a-f0-9]{64}$"))' "$raw" >/dev/null \
    || fail 'target filesystem projection is malformed'
  jq -S . "$raw" > "$destination"
  chmod 0600 "$destination"
  rm -f -- "$raw"
}

filesystem_projection "$run_dir/target-files-before.json"
database_projection "$run_dir/target-database-before.json"

command_json() {
  local service="$1" destination="$2"; shift 2
  local raw errors started finished duration duration_key durations_next
  raw="$run_dir/.raw-$(basename "$destination")"
  errors="$run_dir/.stderr-$(basename "$destination")"
  started="$(date +%s)"
  if [ "$mode" = repeat ]; then
    docker compose --project-name "$project" --file "$compose_file" exec -T \
      -e CARTSHIFT_TRANSFER_PRIVATE_DIR=/cartshift-evidence \
      -e CARTSHIFT_TRANSFER_OPERATOR_ID="$operator" \
      "$service" wp --allow-root "$@" --quiet --skip-plugins="$rehearsal_skip_plugins" --skip-themes \
      > "$raw" 2> "$errors" || { chmod 0600 "$raw" "$errors"; fail "$(basename "$destination" .json) command returned non-zero; private stderr evidence was retained"; }
  elif ! docker compose --project-name "$project" --file "$compose_file" exec -T \
    -e CARTSHIFT_TRANSFER_PRIVATE_DIR=/cartshift-evidence \
    -e CARTSHIFT_TRANSFER_OPERATOR_ID="$operator" \
    "$service" wp --allow-root "$@" --quiet --skip-plugins="$rehearsal_skip_plugins" > "$raw" 2> "$errors"; then
    chmod 0600 "$raw" "$errors"
    fail "$(basename "$destination" .json) command returned non-zero; private stderr evidence was retained"
  fi
  [ ! -s "$errors" ] || { chmod 0600 "$raw" "$errors"; fail "$(basename "$destination" .json) wrote unexpected stderr"; }
  jq -e 'type == "object"' "$raw" >/dev/null || fail "$(basename "$destination" .json) did not emit one JSON object"
  jq -S . "$raw" > "$destination"
  chmod 0600 "$destination"
  rm -f -- "$raw" "$errors"
  finished="$(date +%s)"
  duration="$((finished - started))"
  duration_key="$(basename "$destination" .json)"
  durations_next="$run_dir/.durations-next.json"
  jq -S --arg key "$duration_key" --argjson seconds "$duration" '. + {($key):$seconds}' "$durations_file" > "$durations_next"
  mv "$durations_next" "$durations_file"
}

command_json target-cli "$run_dir/01-validate-package.json" cartshift transfer validate-package \
  --role=target --package=/sealed-package --format=json
jq -e --arg selection "$selection" '.status == "validated" and .selection_fingerprint == $selection' "$run_dir/01-validate-package.json" >/dev/null \
  || fail 'validate-package did not confirm the sealed selection'

if [ "$mode" = repeat ]; then
  jq -S -n '{status:"not_applicable",reason:"completed_descriptor_uses_final_state_reconciliation"}' > "$run_dir/02-inspect-target.json"
  chmod 0600 "$run_dir/02-inspect-target.json"
else
  command_json target-cli "$run_dir/02-inspect-target.json" cartshift transfer inspect-target \
    --role=target --source-key="$source_key" --format=json
fi

if [ "$schema_from" = 7 ]; then
  command_json target-cli "$run_dir/03-upgrade-schema.json" \
    --exec="define('CARTSHIFT_TRANSFER_MAINTENANCE', true);" cartshift transfer upgrade-schema \
    --role=target --from=7 --to=8 --confirm-backup="$target_backup_sha" --execution-context=rehearsal --format=json
  jq -e '.status == "upgraded" and .from == "7" and .to == "8"' "$run_dir/03-upgrade-schema.json" >/dev/null \
    || fail 'schema upgrade postcondition was not reported'
else
  jq -S -n '{status:"not_required",from:"8",to:"8"}' > "$run_dir/03-upgrade-schema.json"
fi

command_json target-cli "$run_dir/04-compatibility.json" cartshift transfer compatibility --role=target --format=json
jq -e '.ready == true and (.errors | length) == 0' "$run_dir/04-compatibility.json" >/dev/null \
  || fail 'target compatibility is not ready after the explicit schema gate'

if [ -n "$resume_descriptor" ]; then
  [[ "$resume_descriptor" =~ ^[a-z0-9][a-z0-9-]{2,35}$ ]] || fail 'resume descriptor is invalid'
  descriptor="$resume_descriptor"
  jq -S -n --arg descriptor "$descriptor" --arg selection_fingerprint "$selection" \
    '{state:"resumed",descriptor:$descriptor,selection_fingerprint:$selection_fingerprint}' > "$run_dir/05-prepare.json"
else
  command_json target-cli "$run_dir/05-prepare.json" cartshift transfer prepare --role=target \
    --package=/sealed-package --decision-set="$decision_container" --private-dir=/cartshift-evidence \
    --execution-context=rehearsal --format=json
  descriptor="$(jq -r '.descriptor' "$run_dir/05-prepare.json")"
  [[ "$descriptor" =~ ^tr-[a-f0-9]{24}$ ]] || fail 'prepare returned an invalid descriptor'
  jq -e --arg selection "$selection" '.state == "prepared" and .selection_fingerprint == $selection and (.blocking_findings | length) == 0' "$run_dir/05-prepare.json" >/dev/null \
    || fail 'prepared descriptor is blocked or changed selection'
fi

preexisting_target_projection "$run_dir/preexisting-target-before.json"

if [ "$mode" = rollback ] && [ "$rollback_action" = plan ]; then
  [ -z "$resume_descriptor" ] && [ -z "$rollback_plan" ] && [ -z "$rollback_plan_sha" ] \
    || fail 'rollback plan mode requires a fresh descriptor and accepts no prior plan'
  database_projection "$run_dir/rollback-business-database-baseline.json" business
  filesystem_projection "$run_dir/rollback-target-files-baseline.json"
  command_json target-cli "$run_dir/06-stage-full.json" cartshift transfer stage --role=target --package=/sealed-package \
    --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal --format=json
  expected_stage_receipt_total="$(jq '[.receipt_action_counts | to_entries[] | select(.key | endswith(":created")) | .value] | add // 0' "$expectations")"
  jq -e --argjson expected "$expected_stage_receipt_total" \
    '.state == "staged" and ([.receipt_counts[]] | add // 0) == $expected' "$run_dir/06-stage-full.json" >/dev/null \
    || fail 'rollback rehearsal did not stage the complete sealed graph'
  fault_file="$evidence_dir/rehearsal-rollback-plan-${descriptor}.php"
  [ ! -e "$fault_file" ] || fail 'rollback planning helper already exists'
  cat > "$fault_file" <<'PHP'
<?php
defined('ABSPATH') || exit;
if (!defined('CARTSHIFT_REHEARSAL_ISOLATED') || CARTSHIFT_REHEARSAL_ISOLATED !== true) {
    throw new RuntimeException('rollback_rehearsal_requires_isolated_runtime');
}
$descriptor = (string) getenv('CARTSHIFT_REHEARSAL_DESCRIPTOR');
$private = \CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence::privateDirectory();
$preparedRepository = new \CartShift\Domain\Transfer\Execution\PreparedTransferRepository($private);
$prepared = $preparedRepository->get($descriptor);
$journal = new \CartShift\Domain\Transfer\Execution\TransferJournalRepository($preparedRepository);
if ($journal->state($descriptor) !== \CartShift\Domain\Transfer\Execution\TransferRunState::Staged) {
    throw new RuntimeException('rollback_rehearsal_fault_state_changed');
}
$journal->transition(
    $descriptor,
    \CartShift\Domain\Transfer\Execution\TransferRunState::Staged,
    \CartShift\Domain\Transfer\Execution\TransferRunState::Reconciling,
);
$journal->transition(
    $descriptor,
    \CartShift\Domain\Transfer\Execution\TransferRunState::Reconciling,
    \CartShift\Domain\Transfer\Execution\TransferRunState::Failed,
);
$gateway = new \CartShift\Domain\Transfer\Execution\LoadedRollbackTargetGateway(
    $prepared->sourceKey,
    new \CartShift\Domain\Transfer\Execution\FilesystemSagaRepository($private),
);
$plan = (new \CartShift\Domain\Transfer\Execution\RollbackPlanner())->plan(
    $descriptor,
    $prepared->generation,
    $journal->receipts($descriptor),
    $gateway,
);
$path = (new \CartShift\Domain\Transfer\Execution\RollbackPlanRepository($private))->save($plan);
echo wp_json_encode([
    'conflict_count' => count($plan->conflicts),
    'deletion_count' => count($plan->deletions),
    'plan' => $path,
    'plan_fingerprint' => $plan->fingerprint(),
    'safe' => $plan->safe,
    'state' => $journal->state($descriptor)->value,
]);
PHP
  chmod 0600 "$fault_file"
  raw="$run_dir/.raw-rollback-plan.json"; errors="$run_dir/.stderr-rollback-plan.json"
  if ! docker compose --project-name "$project" --file "$compose_file" exec -T \
    -e CARTSHIFT_TRANSFER_PRIVATE_DIR=/cartshift-evidence -e CARTSHIFT_TRANSFER_OPERATOR_ID="$operator" \
    -e CARTSHIFT_REHEARSAL_DESCRIPTOR="$descriptor" target-cli wp --allow-root --quiet \
    --skip-plugins="$rehearsal_skip_plugins" eval-file \
    "/cartshift-evidence/$(basename "$fault_file")" > "$raw" 2> "$errors"; then
    chmod 0600 "$raw" "$errors"; fail 'controlled rollback planning fault returned non-zero'
  fi
  [ ! -s "$errors" ] || fail 'controlled rollback planning fault wrote unexpected stderr'
  jq -S . "$raw" > "$run_dir/07-rollback-plan.json"
  rm -f -- "$raw" "$errors"
  expected_created="$(jq '[.receipt_action_counts | to_entries[] | select(.key | endswith(":created")) | .value] | add // 0' "$expectations")"
  jq -e --argjson expected_created "$expected_created" \
    '.state == "failed" and .safe == true and .conflict_count == 0 and .deletion_count == $expected_created and (.plan_fingerprint | test("^[a-f0-9]{64}$"))' \
    "$run_dir/07-rollback-plan.json" >/dev/null || fail 'controlled rollback plan is not safe and exact'
  plan_container="$(jq -r '.plan' "$run_dir/07-rollback-plan.json")"
  [[ "$plan_container" == /cartshift-evidence/rollback-*.json ]] || fail 'rollback plan escaped private evidence'
  plan_host="$evidence_dir/$(basename "$plan_container")"
  [ -f "$plan_host" ] || fail 'rollback plan was not exported to host evidence'
  jq -S -n --arg status owner_review_required --arg mode "$mode" --arg project "$project" \
    --arg descriptor "$descriptor" --arg rollback_plan "$(basename "$plan_host")" \
    --arg rollback_plan_sha256 "$(digest_file "$plan_host")" --arg approval_reference "$approval_reference" \
    --slurpfile plan "$run_dir/07-rollback-plan.json" \
    --slurpfile database "$run_dir/rollback-business-database-baseline.json" \
    --slurpfile files "$run_dir/rollback-target-files-baseline.json" \
    '{status:$status,mode:$mode,project:$project,descriptor:$descriptor,rollback_plan:$rollback_plan,rollback_plan_sha256:$rollback_plan_sha256,approval_reference:$approval_reference,plan:$plan[0],rollback_baseline:{target_database:$database[0],target_files:$files[0]}}' > "$output"
  chmod 0600 "$output"
  printf '%s\n' "$output"
  exit 0
elif [ "$mode" = rollback ]; then
  [ "$rollback_action" = apply ] && [ -n "$resume_descriptor" ] || fail 'rollback apply mode requires --resume-descriptor'
  rollback_plan="$(canonical_file 'rollback plan' "$rollback_plan")"
  verify_digest 'rollback plan' "$rollback_plan" "$rollback_plan_sha"
  within "$rollback_plan" "$evidence_dir" || fail 'rollback plan must belong to private rehearsal evidence'
  rollback_baseline="$(canonical_file 'rollback baseline report' "$rollback_baseline")"
  verify_digest 'rollback baseline report' "$rollback_baseline" "$rollback_baseline_sha"
  within "$rollback_baseline" "$evidence_dir" || fail 'rollback baseline report must belong to private rehearsal evidence'
  jq -e --arg descriptor "$descriptor" --arg plan_sha "$rollback_plan_sha" '
    .status == "owner_review_required" and .mode == "rollback" and
    .descriptor == $descriptor and .rollback_plan_sha256 == $plan_sha
  ' "$rollback_baseline" >/dev/null || fail 'rollback baseline report is not bound to the descriptor and approved plan'
  rollback_container="/cartshift-evidence/${rollback_plan#"$evidence_dir"/}"
  command_json target-cli "$run_dir/06-rollback-status-before.json" cartshift transfer status --role=target --descriptor="$descriptor" --format=json
  jq -e '.state == "failed" or .state == "rolling_back"' "$run_dir/06-rollback-status-before.json" >/dev/null \
    || fail 'rollback descriptor is not in a rollback-eligible state'
  command_json target-cli "$run_dir/07-rollback.json" cartshift transfer rollback --role=target \
    --package=/sealed-package --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
    --rollback-plan="$rollback_container" --lease-recovery="$rollback_plan_sha" --format=json
  command_json target-cli "$run_dir/08-status.json" cartshift transfer status --role=target --descriptor="$descriptor" --format=json
  jq -e '.state == "rolled_back"' "$run_dir/08-status.json" >/dev/null || fail 'rollback did not reach rolled_back'
elif [ "$mode" = repeat ]; then
  command_json target-cli "$run_dir/06-repeat-status-before.json" cartshift transfer status \
    --role=target --descriptor="$descriptor" --format=json
  jq -e '.state == "completed" and (.next_legal_actions | length) == 0' "$run_dir/06-repeat-status-before.json" >/dev/null \
    || fail 'repeat verification requires the exact completed descriptor'
  command_json target-cli "$run_dir/07-repeat-reconcile.json" cartshift transfer reconcile --role=target \
    --package=/sealed-package --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
    --lease-recovery="$approval_reference" --format=json
  jq -e '.state == "completed" and .command == "reconcile"' "$run_dir/07-repeat-reconcile.json" >/dev/null \
    || fail 'repeat verification did not reconcile the completed receipts read-only'
  command_json target-cli "$run_dir/08-status.json" cartshift transfer status --role=target --descriptor="$descriptor" --format=json
  jq -e '.state == "completed" and (.next_legal_actions | length) == 0' "$run_dir/08-status.json" >/dev/null \
    || fail 'repeat verification changed the completed descriptor state'
else
  command_json target-cli "$run_dir/06-stage.json" cartshift transfer stage --role=target --package=/sealed-package \
    --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal --format=json
  jq -e '.state == "staged"' "$run_dir/06-stage.json" >/dev/null || fail 'stage did not reach staged'
  command_json target-cli "$run_dir/07-reconcile.json" cartshift transfer reconcile --role=target --package=/sealed-package \
    --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
    --lease-recovery="$approval_reference" --format=json
  jq -e '.state == "reconciled"' "$run_dir/07-reconcile.json" >/dev/null || fail 'reconcile did not reach reconciled'
  command_json target-cli "$run_dir/08-promote.json" cartshift transfer promote --role=target --package=/sealed-package \
    --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
    --lease-recovery="$approval_reference" --format=json
  jq -e '.state == "promoted"' "$run_dir/08-promote.json" >/dev/null || fail 'promote did not reach promoted'

  subscriptions="$(jq -r '.record_counts.subscription // 0' "$manifest")"
  if [ "$subscriptions" -gt 0 ]; then
    command_json target-cli "$run_dir/09-prepare-subscription-cutover.json" cartshift transfer prepare-subscription-cutover \
      --role=target --package=/sealed-package --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
      --lease-recovery="$approval_reference" --format=json
    command_json source-cli "$run_dir/10a-isolated-source-instance.json" eval \
      'echo wp_json_encode(["fingerprint" => (new \CartShift\Domain\Transfer\Package\LoadedSourceInstanceFingerprint())->fingerprint()]);'
    isolated_source_fingerprint="$(jq -r '.fingerprint' "$run_dir/10a-isolated-source-instance.json")"
    [[ "$isolated_source_fingerprint" =~ ^[a-f0-9]{64}$ ]] || fail 'isolated source instance fingerprint is invalid'
    proof_host="$evidence_dir/rehearsal-source-proof-${descriptor}.json"
    [ ! -e "$proof_host" ] || fail 'rehearsal source proof already exists'
    jq -cS -n --arg descriptor "$descriptor" --arg isolated "$isolated_source_fingerprint" \
      --arg production "$(jq -r '.source_instance_fingerprint' "$manifest")" --arg project "$project" \
      --arg restore_report "$(basename "$restore_report")" --arg restore_report_sha256 "$(digest_file "$restore_report")" \
      --arg source_backup_sha256 "$(jq -r '.source_backup_sha256' "$restore_report")" \
      '{descriptor:$descriptor,isolated_source_instance_fingerprint:$isolated,production_source_instance_fingerprint:$production,project:$project,restore_report:$restore_report,restore_report_sha256:$restore_report_sha256,source_backup_sha256:$source_backup_sha256,version:1}' \
      > "$proof_host"
    chmod 0600 "$proof_host"
    proof_container="/cartshift-evidence/$(basename "$proof_host")"
    command_json source-cli "$run_dir/10-release-subscription-source.json" cartshift transfer release-subscription-source \
      --role=source --private-dir=/cartshift-evidence --descriptor="$descriptor" --execution-context=rehearsal \
      --renewals-paused --rehearsal-source-proof="$proof_container" --format=json
    jq -e '.state == "source_released"' "$run_dir/10-release-subscription-source.json" >/dev/null \
      || fail 'source subscription ownership was not released'
    command_json target-cli "$run_dir/11-activate-subscriptions.json" cartshift transfer activate-subscriptions \
      --role=target --package=/sealed-package --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
      --lease-recovery="$approval_reference" --format=json
    jq -e '.subscription_cutover_state == "reconciled"' "$run_dir/11-activate-subscriptions.json" >/dev/null \
      || fail 'target subscription ownership was not activated and independently reconciled'
    finalisation_status="$run_dir/11-activate-subscriptions.json"
  else
    finalisation_status="$run_dir/08-promote.json"
  fi
  finalisation_action="$(jq -r '
    if .next_legal_actions == ["activate-catalogue"] then "activate-catalogue"
    elif .next_legal_actions == ["complete"] then "complete"
    else "blocked"
    end
  ' "$finalisation_status")"
  if [ "$finalisation_action" = activate-catalogue ]; then
    command_json target-cli "$run_dir/12-activate-catalogue.json" cartshift transfer activate-catalogue --role=target \
      --package=/sealed-package --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
      --lease-recovery="$approval_reference" --format=json
    jq -e '.state == "catalogue_activating" and .next_legal_actions == ["complete"]' \
      "$run_dir/12-activate-catalogue.json" >/dev/null || fail 'catalogue activation did not reach its checked completion gate'
  elif [ "$finalisation_action" = complete ]; then
    jq -S -n '{status:"not_required",reason:"owner_accepted_leave_draft"}' > "$run_dir/12-activate-catalogue.json"
    chmod 0600 "$run_dir/12-activate-catalogue.json"
  else
    fail 'sealed transfer has no single legal catalogue finalisation action'
  fi
  command_json target-cli "$run_dir/13-complete.json" cartshift transfer complete --role=target --package=/sealed-package \
    --descriptor="$descriptor" --confirm="$selection" --execution-context=rehearsal \
    --lease-recovery="$approval_reference" --format=json
  command_json target-cli "$run_dir/14-status.json" cartshift transfer status --role=target --descriptor="$descriptor" --format=json
  jq -e '.state == "completed" and (.next_legal_actions | length) == 0' "$run_dir/14-status.json" >/dev/null \
    || fail 'run did not reach an unambiguous completed state'
fi

filesystem_projection "$run_dir/target-files-after.json"
database_projection "$run_dir/target-database-after.json"
preexisting_target_projection "$run_dir/preexisting-target-after.json" "$run_dir/preexisting-target-before.json"
jq -e --slurpfile before "$run_dir/preexisting-target-before.json" '. == $before[0]' \
  "$run_dir/preexisting-target-after.json" >/dev/null \
  || fail 'a pre-existing target commerce row changed during rehearsal'
jq -S -n --arg before_sha256 "$(digest_file "$run_dir/preexisting-target-before.json")" \
  --arg after_sha256 "$(digest_file "$run_dir/preexisting-target-after.json")" \
  --argjson table_count "$(jq '.tables | length' "$run_dir/preexisting-target-before.json")" \
  '{status:"unchanged",table_count:$table_count,before_sha256:$before_sha256,after_sha256:$after_sha256}' \
  > "$run_dir/preexisting-target-preservation.json"
chmod 0600 "$run_dir/preexisting-target-preservation.json"
if [ "$mode" = repeat ]; then
  jq -e --slurpfile before "$run_dir/target-database-before.json" '. == $before[0]' \
    "$run_dir/target-database-after.json" >/dev/null \
    || fail 'repeat verification changed target database rows'
elif [ "$mode" = rollback ] && [ "$rollback_action" = apply ]; then
  database_projection "$run_dir/rollback-business-database-after.json" business
  "$script_dir/verify-lapka-rollback-restoration.sh" \
    --baseline-report "$rollback_baseline" --baseline-report-sha256 "$rollback_baseline_sha" \
    --descriptor "$descriptor" --database-projection "$run_dir/rollback-business-database-after.json" \
    --files-projection "$run_dir/target-files-after.json" --output "$run_dir/rollback-restoration.json" >/dev/null
fi
jq -S -n --slurpfile before "$run_dir/target-files-before.json" --slurpfile after "$run_dir/target-files-after.json" '
  {
    added: reduce ($after[0] | keys[]) as $path ({};
      if ($before[0] | has($path)) then . else .[$path] = $after[0][$path] end),
    changed: reduce ($after[0] | keys[]) as $path ({};
      if (($before[0] | has($path)) and $before[0][$path] != $after[0][$path]) then .[$path] = $after[0][$path] else . end),
    removed: reduce ($before[0] | keys[]) as $path ({};
      if ($after[0] | has($path)) then . else .[$path] = $before[0][$path] end)
  }
' > "$run_dir/target-files-delta.json"
if [ "$mode" = rollback ] && [ "$rollback_action" = apply ]; then
  rollback_expected_files="$run_dir/rollback-expected-target-files-delta.json"
  jq -S -n --slurpfile before "$run_dir/target-files-before.json" --slurpfile baseline "$rollback_baseline" '
    ($baseline[0].rollback_baseline.target_files) as $after |
    {
      added: reduce ($after | keys[]) as $path ({};
        if ($before[0] | has($path)) then . else .[$path] = $after[$path] end),
      changed: reduce ($after | keys[]) as $path ({};
        if (($before[0] | has($path)) and $before[0][$path] != $after[$path]) then .[$path] = $after[$path] else . end),
      removed: reduce ($before[0] | keys[]) as $path ({};
        if ($after | has($path)) then . else .[$path] = $before[0][$path] end)
    }
  ' > "$rollback_expected_files"
  jq -e --slurpfile expected "$rollback_expected_files" '. == $expected[0]' "$run_dir/target-files-delta.json" >/dev/null \
    || fail 'target filesystem delta differs from sealed pre-stage baseline'
else
  jq -e --slurpfile expected "$expectations" '. == $expected[0].target_files' "$run_dir/target-files-delta.json" >/dev/null \
    || fail 'target filesystem content, type, or mode delta differs from sealed expectations'
fi

status_file="$(find "$run_dir" -maxdepth 1 -type f -name '*status.json' | sort | tail -1)"
receipt_counts="$(jq -S '.receipt_counts' "$status_file")"
expected_receipts="$(jq -S '.receipt_counts' "$expectations")"
[ "$receipt_counts" = "$expected_receipts" ] || fail 'receipt counts differ from sealed expectations'

action_rows="$run_dir/.receipt-actions.tsv"
db_query "SELECT CONCAT(record_kind, ':', action), COUNT(*) FROM \`${target_prefix}cartshift_transfer_records\` WHERE run_id='${descriptor}' AND record_kind IN ('product','customer','order','subscription','taxonomy_term','media_asset') GROUP BY record_kind, action ORDER BY record_kind, action;" > "$action_rows"
jq -R -s -S 'split("\n") | map(select(length > 0) | split("\t")) | map({key:.[0],value:(.[1]|tonumber)}) | from_entries' \
  "$action_rows" > "$run_dir/receipt-action-counts.json"
jq -e --slurpfile expected "$expectations" '. == $expected[0].receipt_action_counts' "$run_dir/receipt-action-counts.json" >/dev/null \
  || fail 'created/reused receipt actions differ from sealed expectations'

semantic_rows="$run_dir/.semantic-receipts.tsv"
db_query "SELECT source_identity, record_kind, source_fingerprint FROM \`${target_prefix}cartshift_transfer_records\` WHERE run_id='${descriptor}' AND record_kind IN ('product','customer','order','subscription','taxonomy_term','media_asset') AND state IN ('successful','rolled_back') ORDER BY source_identity;" > "$semantic_rows"
jq -R -s -S '
  split("\n") | map(select(length > 0) | split("\t")) |
  map(select(length == 3) | {key:.[0],value:{record_kind:.[1],source_fingerprint:.[2]}}) | from_entries
' "$semantic_rows" > "$run_dir/semantic-receipts.json"
jq -e --argjson expected "$(jq '[.record_counts.product, .record_counts.customer, .record_counts.order, .record_counts.subscription] | add // 0' "$manifest")" '
  type == "object" and length == $expected and
  all(to_entries[];
    (.key | test("^[a-z0-9][a-z0-9-]{2,63}:(product|customer|order|subscription|taxonomy_term|media_asset):.+$")) and
    (.value.record_kind | test("^(product|customer|order|subscription|taxonomy_term|media_asset)$")) and
    (.value.source_fingerprint | test("^[a-f0-9]{64}$")))
' "$run_dir/semantic-receipts.json" >/dev/null || fail 'semantic receipt coverage is incomplete or malformed'

created="$(jq '[to_entries[] | select(.key | endswith(":created")) | .value] | add // 0' "$run_dir/receipt-action-counts.json")"
reused="$(jq '[to_entries[] | select(.key | endswith(":reused")) | .value] | add // 0' "$run_dir/receipt-action-counts.json")"
selected="$(jq '[.record_counts[]] | add // 0' "$manifest")"
jq -e --argjson created "$created" --argjson reused "$reused" --argjson selected "$selected" \
  '.outcomes.created == $created and .outcomes.reused == $reused and .outcomes.selected == $selected' "$expectations" >/dev/null \
  || fail 'selected/created/reused outcome totals differ from receipts or package'

map_rows="$run_dir/.map-counts.tsv"
db_query "SELECT r.record_kind, COUNT(*) FROM \`${target_prefix}cartshift_transfer_records\` r JOIN \`${target_prefix}cartshift_id_map\` m ON m.source_key='${source_key}' AND CONCAT(m.source_key, ':', m.entity_type, ':', m.wc_id)=r.source_identity AND m.fc_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.target_ids, '\$.primary')) AS UNSIGNED) WHERE r.run_id='${descriptor}' AND r.record_kind IN ('product','customer','order','subscription') AND m.record_state='reconciled' GROUP BY r.record_kind ORDER BY r.record_kind;" > "$map_rows"
jq -R -s -S 'split("\n") | map(select(length > 0) | split("\t")) | map({key:.[0],value:(.[1]|tonumber)}) | from_entries' \
  "$map_rows" > "$run_dir/map-counts.json"
jq -e --slurpfile expected "$expectations" '. == $expected[0].map_counts' "$run_dir/map-counts.json" >/dev/null \
  || fail 'reconciled root mapping counts differ from sealed expectations'

dangling="$(db_query "SELECT COUNT(*) FROM \`${target_prefix}cartshift_transfer_records\` r JOIN \`${target_prefix}cartshift_id_map\` m ON m.source_key='${source_key}' AND CONCAT(m.source_key, ':', m.entity_type, ':', m.wc_id)=r.source_identity LEFT JOIN \`${target_prefix}posts\` p ON r.record_kind='product' AND p.ID=m.fc_id LEFT JOIN \`${target_prefix}fct_customers\` c ON r.record_kind='customer' AND c.id=m.fc_id LEFT JOIN \`${target_prefix}fct_orders\` o ON r.record_kind='order' AND o.id=m.fc_id LEFT JOIN \`${target_prefix}fct_subscriptions\` s ON r.record_kind='subscription' AND s.id=m.fc_id WHERE r.run_id='${descriptor}' AND r.record_kind IN ('product','customer','order','subscription') AND m.record_state='reconciled' AND ((r.record_kind='product' AND p.ID IS NULL) OR (r.record_kind='customer' AND c.id IS NULL) OR (r.record_kind='order' AND o.id IS NULL) OR (r.record_kind='subscription' AND s.id IS NULL));")"
[[ "$dangling" =~ ^[0-9]+$ ]] || fail 'dangling-map projection is unreadable'
[ "$dangling" -eq "$(jq -r '.dangling_maps' "$expectations")" ] || fail 'dangling root mappings differ from sealed expectations'

outbox_rows="$(db_query "SELECT COUNT(*) FROM \`${target_prefix}cartshift_transfer_outbox\` WHERE run_id='${descriptor}';")"
[[ "$outbox_rows" =~ ^[0-9]+$ ]] || fail 'outbox projection is unreadable'
[ "$outbox_rows" -eq "$(jq -r '.outbox_rows' "$expectations")" ] || fail 'immutable outbox row count differs from sealed expectations'

money_row="$(db_query "SELECT COUNT(*),COALESCE(SUM(subtotal),0),COALESCE(SUM(discount_tax),0),COALESCE(SUM(coupon_discount_total),0),COALESCE(SUM(manual_discount_total),0),COALESCE(SUM(shipping_total),0),COALESCE(SUM(fee_total),0),COALESCE(SUM(tax_total),0),COALESCE(SUM(shipping_tax),0),COALESCE(SUM(total_amount),0),COALESCE(SUM(total_paid),0),COALESCE(SUM(total_refund),0) FROM \`${target_prefix}fct_orders\` WHERE id IN (SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(target_ids, '\$.primary')) AS UNSIGNED) FROM \`${target_prefix}cartshift_transfer_records\` WHERE run_id='${descriptor}' AND record_kind='order');")"
IFS=$'\t' read -r order_count subtotal discount_tax coupon_discount_total manual_discount_total shipping_total fee_total tax_total shipping_tax total_amount total_paid total_refund <<< "$money_row"
for value in "$order_count" "$subtotal" "$discount_tax" "$coupon_discount_total" "$manual_discount_total" "$shipping_total" "$fee_total" "$tax_total" "$shipping_tax" "$total_amount" "$total_paid" "$total_refund"; do
  [[ "$value" =~ ^-?[0-9]+$ ]] || fail 'money projection contains a non-integer value'
done
jq -S -n --argjson order_count "$order_count" --argjson subtotal "$subtotal" --argjson discount_tax "$discount_tax" \
  --argjson coupon_discount_total "$coupon_discount_total" --argjson manual_discount_total "$manual_discount_total" \
  --argjson shipping_total "$shipping_total" --argjson fee_total "$fee_total" --argjson tax_total "$tax_total" \
  --argjson shipping_tax "$shipping_tax" --argjson total_amount "$total_amount" --argjson total_paid "$total_paid" \
  --argjson total_refund "$total_refund" \
  '{order_count:$order_count,subtotal:$subtotal,discount_tax:$discount_tax,coupon_discount_total:$coupon_discount_total,manual_discount_total:$manual_discount_total,shipping_total:$shipping_total,fee_total:$fee_total,tax_total:$tax_total,shipping_tax:$shipping_tax,total_amount:$total_amount,total_paid:$total_paid,total_refund:$total_refund}' \
  > "$run_dir/money.json"
jq -e --slurpfile expected "$expectations" '. == $expected[0].money' "$run_dir/money.json" >/dev/null \
  || fail 'target order money aggregates differ from sealed expectations'

tables_file="$run_dir/.target-tables.txt"
counts_rows="$run_dir/.target-counts.tsv"
db_query "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='target' AND LEFT(TABLE_NAME, ${#target_prefix})='${target_prefix}' ORDER BY TABLE_NAME;" > "$tables_file"
: > "$counts_rows"
while IFS= read -r table; do
  [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail 'target table name is unsafe'
  count="$(db_query "SELECT COUNT(*) FROM \`${table}\`;")"
  [[ "$count" =~ ^[0-9]+$ ]] || fail 'target table count is unreadable'
  printf '%s\t%s\n' "$table" "$count" >> "$counts_rows"
done < "$tables_file"
jq -R -s -S 'split("\n") | map(select(length > 0) | split("\t")) | map({key:.[0],value:(.[1]|tonumber)}) | from_entries' \
  "$counts_rows" > "$run_dir/target-table-counts.json"
jq -S -n --slurpfile restore "$restore_report" --slurpfile current "$run_dir/target-table-counts.json" '
  (($restore[0].target.table_counts | keys) + ($current[0] | keys) | unique) as $keys |
  reduce $keys[] as $key ({}; .[$key] = (($current[0][$key] // 0) - ($restore[0].target.table_counts[$key] // 0)))
' > "$run_dir/target-table-deltas.json"
jq -e --slurpfile expected "$expectations" '. == $expected[0].target_table_deltas' "$run_dir/target-table-deltas.json" >/dev/null \
  || fail 'target table-count deltas differ from sealed expectations'

resource_snapshot="$run_dir/resources.jsonl"
container_ids="$(docker compose --project-name "$project" --file "$compose_file" ps -q)"
[ -n "$container_ids" ] || fail 'isolated rehearsal containers disappeared before resource capture'
# Docker's JSON fields vary slightly by release, so retain the private raw
# snapshots and bind their exact bytes into the canonical aggregate report.
# shellcheck disable=SC2086
docker stats --no-stream --format '{{json .}}' $container_ids > "$resource_snapshot"
jq -e -s 'length > 0 and all(.[]; type == "object")' "$resource_snapshot" >/dev/null \
  || fail 'Docker resource snapshot is malformed'
chmod 0600 "$resource_snapshot"

spy_projection="$run_dir/spies.json"
jq -S -n '{lifecycle_event:0,mail_attempt:0,outbound_http_attempt:0}' > "$spy_projection"
for role in source target; do
  spy="$evidence_dir/rehearsal-spy-${role}.jsonl"
  baseline_lines="$(jq -r --arg role "$role" '.[$role].lines' "$run_dir/spy-baseline.json")"
  baseline_sha="$(jq -r --arg role "$role" '.[$role].sha256' "$run_dir/spy-baseline.json")"
  if [ -e "$spy" ]; then
    jq -e -s 'all(.[]; (.kind == "lifecycle_event" or .kind == "mail_attempt" or .kind == "outbound_http_attempt") and (.utc | test("^[0-9]{4}-")))' "$spy" >/dev/null \
      || fail 'spy evidence is malformed'
    current_lines="$(wc -l < "$spy" | tr -d ' ')"
    [ "$current_lines" -ge "$baseline_lines" ] || fail 'spy evidence was truncated during rehearsal'
    prefix="$run_dir/.spy-prefix-${role}.jsonl"
    if [ "$baseline_lines" -eq 0 ]; then : > "$prefix"; else head -n "$baseline_lines" "$spy" > "$prefix"; fi
    if [ -n "$baseline_sha" ] && [ "$(digest_file "$prefix")" != "$baseline_sha" ]; then
      fail 'pre-existing spy evidence changed during rehearsal'
    fi
    new_spies="$run_dir/.spy-new-${role}.jsonl"
    tail -n "+$((baseline_lines + 1))" "$spy" > "$new_spies"
    if [ ! -s "$new_spies" ]; then continue; fi
    jq -s 'group_by(.kind) | map({key:.[0].kind,value:length}) | from_entries' "$new_spies" > "$run_dir/.spy-one.json"
    jq -S -s 'reduce .[] as $doc ({}; reduce ($doc|to_entries[]) as $entry (.; .[$entry.key] = ((.[$entry.key] // 0) + $entry.value)))' \
      "$spy_projection" "$run_dir/.spy-one.json" > "$run_dir/.spy-total.json"
    mv "$run_dir/.spy-total.json" "$spy_projection"
  fi
done
rm -f -- "$run_dir/.spy-one.json" "$run_dir"/.spy-prefix-*.jsonl "$run_dir"/.spy-new-*.jsonl
jq -e --slurpfile expected "$expectations" '. == $expected[0].spies' "$spy_projection" >/dev/null \
  || fail 'rehearsal emitted a lifecycle event, email, or outbound HTTP attempt'

rollback_restoration="$run_dir/rollback-restoration.json"
if [ ! -f "$rollback_restoration" ]; then
  jq -S -n '{status:"not_applicable"}' > "$rollback_restoration"
  chmod 0600 "$rollback_restoration"
fi

hash_rows="$run_dir/.command-hashes.txt"
: > "$hash_rows"
while IFS= read -r file; do printf '%s  %s\n' "$(digest_file "$file")" "$(basename "$file")" >> "$hash_rows"; done \
  < <(find "$run_dir" -maxdepth 1 -type f -name '*.json' | sort)
commands_hash="$(digest_file "$hash_rows")"
jq -S -n \
  --arg status passed --arg mode "$mode" --arg project "$project" --arg descriptor "$descriptor" \
  --arg package_manifest_sha256 "$manifest_sha" --arg decision_set_sha256 "$decision_sha" \
  --arg candidate_zip_sha256 "$candidate_zip_sha" --arg candidate_tree_sha256 "$candidate_tree_sha" \
  --arg expectations_sha256 "$expectations_sha" --arg approval_reference "$approval_reference" \
  --arg selection_fingerprint "$selection" --arg commands_sha256 "$commands_hash" \
  --arg restore_report_sha256 "$(digest_file "$restore_report")" --arg isolation_report_sha256 "$(digest_file "$fresh_isolation")" \
  --arg resource_snapshot_sha256 "$(digest_file "$resource_snapshot")" \
  --arg target_database_before_sha256 "$(digest_file "$run_dir/target-database-before.json")" \
  --arg target_database_after_sha256 "$(digest_file "$run_dir/target-database-after.json")" \
  --argjson dangling_maps "$dangling" --argjson outbox_rows "$outbox_rows" \
  --slurpfile final_status "$status_file" --slurpfile spies "$spy_projection" \
  --slurpfile actions "$run_dir/receipt-action-counts.json" --slurpfile maps "$run_dir/map-counts.json" \
  --slurpfile money "$run_dir/money.json" --slurpfile table_deltas "$run_dir/target-table-deltas.json" \
  --slurpfile target_files "$run_dir/target-files-delta.json" \
  --slurpfile preexisting_target "$run_dir/preexisting-target-preservation.json" \
  --slurpfile semantic_receipts "$run_dir/semantic-receipts.json" \
  --slurpfile rollback_restoration "$rollback_restoration" \
  --slurpfile durations "$durations_file" --slurpfile expected "$expectations" \
  '{status:$status,mode:$mode,project:$project,descriptor:$descriptor,package_manifest_sha256:$package_manifest_sha256,decision_set_sha256:$decision_set_sha256,candidate_zip_sha256:$candidate_zip_sha256,candidate_tree_sha256:$candidate_tree_sha256,expectations_sha256:$expectations_sha256,approval_reference:$approval_reference,selection_fingerprint:$selection_fingerprint,restore_report_sha256:$restore_report_sha256,isolation_report_sha256:$isolation_report_sha256,commands_sha256:$commands_sha256,resource_snapshot_sha256:$resource_snapshot_sha256,target_database_before_sha256:$target_database_before_sha256,target_database_after_sha256:$target_database_after_sha256,target_database_unchanged:($target_database_before_sha256 == $target_database_after_sha256),durations_seconds:$durations[0],record_counts:$expected[0].record_counts,final_status:$final_status[0],outcomes:$expected[0].outcomes,receipt_action_counts:$actions[0],semantic_receipts:$semantic_receipts[0],map_counts:$maps[0],target_table_deltas:$table_deltas[0],target_files:$target_files[0],preexisting_target:$preexisting_target[0],rollback_restoration:$rollback_restoration[0],money:$money[0],spies:$spies[0],outbox_rows:$outbox_rows,blocking_findings:0,dangling_maps:$dangling_maps}' \
  > "$output"
chmod 0600 "$output"
printf '%s\n' "$output"
