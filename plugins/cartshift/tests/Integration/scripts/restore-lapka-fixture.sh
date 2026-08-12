#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift Łapka fixture restore failed: %s\n' "$1" >&2; exit 1; }

digest_file() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}';
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

canonical_file() {
  local label="$1" path="$2"
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -f "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute regular non-symlink file"
  local canonical
  canonical="$(cd "$(dirname "$path")" && pwd -P)/$(basename "$path")"
  [ "$canonical" = "$path" ] || fail "${label} must be canonical"
  printf '%s\n' "$canonical"
}

canonical_private_directory() {
  local label="$1" path="$2"
  [ -n "$path" ] && [ "${path#/}" != "$path" ] && [ -d "$path" ] && [ ! -L "$path" ] \
    || fail "${label} must be an absolute non-symlink directory"
  local canonical mode
  canonical="$(cd "$path" && pwd -P)"
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

validate_archive() {
  local label="$1" archive="$2" listing
  listing="$(mktemp "${TMPDIR:-/tmp}/cartshift-archive-list.XXXXXX")"
  tar -tf "$archive" > "$listing" || { rm -f -- "$listing"; fail "${label} cannot be listed"; }
  [ -s "$listing" ] || { rm -f -- "$listing"; fail "${label} is empty"; }
  awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    $0 !~ /^wp-content\// && $0 != "wp-content" { bad=1 }
    END { exit bad ? 1 : 0 }
  ' "$listing" || { rm -f -- "$listing"; fail "${label} escapes wp-content"; }
  if tar -tvf "$archive" | awk 'substr($1,1,1) ~ /[lh]/ { exit 1 }'; then :; else
    rm -f -- "$listing"; fail "${label} contains a symbolic or hard link"
  fi
  rm -f -- "$listing"
}

validate_wordpress_root_archive() {
  local archive="$1" listing
  listing="$(mktemp "${TMPDIR:-/tmp}/cartshift-wordpress-root-list.XXXXXX")"
  tar -tf "$archive" > "$listing" || { rm -f -- "$listing"; fail 'WordPress root backup cannot be listed'; }
  [ -s "$listing" ] || { rm -f -- "$listing"; fail 'WordPress root backup is empty'; }
  awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    $0 !~ /^wordpress-root(\/|$)/ { bad=1 }
    $0 ~ /^wordpress-root\/wp-content($|\/)/ { bad=1 }
    $0 ~ /^wordpress-root\/wp-config\.php$/ { bad=1 }
    END { exit bad ? 1 : 0 }
  ' "$listing" || { rm -f -- "$listing"; fail 'WordPress root backup contains content, configuration, or an escaping path'; }
  if tar -tvf "$archive" | awk 'substr($1,1,1) ~ /[lh]/ { exit 1 }'; then :; else
    rm -f -- "$listing"; fail 'WordPress root backup contains a symbolic or hard link'
  fi
  rm -f -- "$listing"
}

validate_candidate_archive() {
  local archive="$1" listing
  command -v unzip >/dev/null 2>&1 || fail 'unzip is unavailable'
  listing="$(mktemp "${TMPDIR:-/tmp}/cartshift-candidate-list.XXXXXX")"
  unzip -Z1 "$archive" > "$listing" || { rm -f -- "$listing"; fail 'candidate ZIP cannot be listed'; }
  [ -s "$listing" ] || { rm -f -- "$listing"; fail 'candidate ZIP is empty'; }
  awk '
    /^\// { bad=1 }
    /(^|\/)\.\.($|\/)/ { bad=1 }
    /\\/ { bad=1 }
    $0 !~ /^cartshift(\/|$)/ { bad=1 }
    END { exit bad ? 1 : 0 }
  ' "$listing" || { rm -f -- "$listing"; fail 'candidate ZIP escapes its CartShift plugin root'; }
  grep -Fx 'cartshift/cartshift.php' "$listing" >/dev/null \
    || { rm -f -- "$listing"; fail 'candidate ZIP has no CartShift plugin entrypoint'; }
  rm -f -- "$listing"
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

scan_sql() {
  local label="$1" path="$2"
  local reader=(cat "$path")
  case "$path" in *.gz) reader=(gzip -dc "$path") ;; esac
  if "${reader[@]}" | LC_ALL=C grep -Ei '^[[:space:]]*(CREATE|DROP)[[:space:]]+DATABASE|^[[:space:]]*USE[[:space:]]+' >/dev/null; then
    fail "${label} attempts to select or mutate a database outside the assigned fresh database"
  fi
}

mode=''
project=''
source_sql=''; source_sql_sha=''; target_sql=''; target_sql_sha=''
source_content=''; source_content_sha=''; target_content=''; target_content_sha=''
source_baseline=''; target_baseline=''; package_dir=''; manifest_sha=''
evidence_dir=''; state_file=''; source_prefix=''; target_prefix=''
candidate_zip=''; candidate_sha=''
wordpress_root=''; wordpress_root_sha=''
mariadb_image="${CARTSHIFT_REHEARSAL_MARIADB_IMAGE:-mariadb@sha256:78a5047d3ba33975f183f183c2464cc7f1eab13ec8667e57cc9a5821d6da7577}"
wpcli_image="${CARTSHIFT_REHEARSAL_WPCLI_IMAGE:-wordpress@sha256:c9ecfd0ef73102cdc6666f20ccc3a0ae16c9a170160ef70bad4e9141ae856054}"
wordpress_image="${CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE:-wordpress@sha256:ffef0dca1f0fc4357bfef3856ebd1ba18f7b394378277122eaa4524ca2619d43}"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --mode) mode="${2:-}"; shift 2 ;;
    --project) project="${2:-}"; shift 2 ;;
    --source-sql) source_sql="${2:-}"; shift 2 ;;
    --source-sql-sha256) source_sql_sha="${2:-}"; shift 2 ;;
    --target-sql) target_sql="${2:-}"; shift 2 ;;
    --target-sql-sha256) target_sql_sha="${2:-}"; shift 2 ;;
    --source-wp-content) source_content="${2:-}"; shift 2 ;;
    --source-wp-content-sha256) source_content_sha="${2:-}"; shift 2 ;;
    --target-wp-content) target_content="${2:-}"; shift 2 ;;
    --target-wp-content-sha256) target_content_sha="${2:-}"; shift 2 ;;
    --source-baseline) source_baseline="${2:-}"; shift 2 ;;
    --target-baseline) target_baseline="${2:-}"; shift 2 ;;
    --source-prefix) source_prefix="${2:-}"; shift 2 ;;
    --target-prefix) target_prefix="${2:-}"; shift 2 ;;
    --package-dir) package_dir="${2:-}"; shift 2 ;;
    --manifest-sha256) manifest_sha="${2:-}"; shift 2 ;;
    --candidate-zip) candidate_zip="${2:-}"; shift 2 ;;
    --candidate-sha256) candidate_sha="${2:-}"; shift 2 ;;
    --wordpress-root) wordpress_root="${2:-}"; shift 2 ;;
    --wordpress-root-sha256) wordpress_root_sha="${2:-}"; shift 2 ;;
    --evidence-dir) evidence_dir="${2:-}"; shift 2 ;;
    --state-file) state_file="${2:-}"; shift 2 ;;
    *) fail "unknown argument $1" ;;
  esac
done

case "$mode" in empty|populated|repeat|rollback) ;; *) fail 'mode must be empty, populated, repeat, or rollback' ;; esac
[[ "$project" =~ ^cartshift-lapka-${mode}-[a-z0-9][a-z0-9-]{5,47}$ ]] || fail 'project is not a generated mode-bound rehearsal identity'
[[ "$source_prefix" =~ ^[A-Za-z0-9_]{1,48}$ ]] || fail 'source table prefix is invalid'
[[ "$target_prefix" =~ ^[A-Za-z0-9_]{1,48}$ ]] || fail 'target table prefix is invalid'
for image in "$mariadb_image" "$wpcli_image" "$wordpress_image"; do
  [[ "$image" =~ ^([a-z0-9./_-]+@sha256:[a-f0-9]{64}|sha256:[a-f0-9]{64})$ ]] \
    || fail 'rehearsal images must use immutable SHA-256 references'
done
[ -n "$candidate_zip" ] || fail 'candidate ZIP is required'
if [ -n "$wordpress_root" ] || [ -n "$wordpress_root_sha" ]; then
  [ -n "$wordpress_root" ] && [ -n "$wordpress_root_sha" ] || fail 'WordPress root backup and SHA-256 must be supplied together'
fi

source_sql="$(canonical_file 'source SQL backup' "$source_sql")"
target_sql="$(canonical_file 'target SQL backup' "$target_sql")"
source_content="$(canonical_file 'source wp-content backup' "$source_content")"
target_content="$(canonical_file 'target wp-content backup' "$target_content")"
source_baseline="$(canonical_file 'source baseline' "$source_baseline")"
target_baseline="$(canonical_file 'target baseline' "$target_baseline")"
candidate_zip="$(canonical_file 'candidate ZIP' "$candidate_zip")"
if [ -n "$wordpress_root" ]; then wordpress_root="$(canonical_file 'WordPress root backup' "$wordpress_root")"; fi
package_dir="$(canonical_private_directory 'sealed package directory' "$package_dir")"
evidence_dir="$(canonical_private_directory 'evidence directory' "$evidence_dir")"
[ -n "$state_file" ] && [ "${state_file#/}" != "$state_file" ] || fail 'state file must be absolute'
[ ! -e "$state_file" ] && [ ! -L "$state_file" ] || fail 'state file already exists'
case "$state_file" in "$evidence_dir"/*) ;; *) fail 'state file must stay inside the private evidence directory' ;; esac

verify_digest 'source SQL backup' "$source_sql" "$source_sql_sha"
verify_digest 'target SQL backup' "$target_sql" "$target_sql_sha"
verify_digest 'source wp-content backup' "$source_content" "$source_content_sha"
verify_digest 'target wp-content backup' "$target_content" "$target_content_sha"
verify_digest 'candidate ZIP' "$candidate_zip" "$candidate_sha"
if [ -n "$wordpress_root" ]; then verify_digest 'WordPress root backup' "$wordpress_root" "$wordpress_root_sha"; fi
[[ "$manifest_sha" =~ ^[a-f0-9]{64}$ ]] || fail 'manifest SHA-256 is invalid'
[ -f "$package_dir/manifest.json" ] && [ ! -L "$package_dir/manifest.json" ] || fail 'sealed package manifest is missing'
[ "$(digest_file "$package_dir/manifest.json")" = "$manifest_sha" ] || fail 'sealed package manifest changed'
validate_archive 'source wp-content backup' "$source_content"
validate_archive 'target wp-content backup' "$target_content"
if [ -n "$wordpress_root" ]; then validate_wordpress_root_archive "$wordpress_root"; fi
validate_candidate_archive "$candidate_zip"
scan_sql 'source SQL backup' "$source_sql"
scan_sql 'target SQL backup' "$target_sql"
for baseline in "$source_baseline" "$target_baseline"; do
  jq -e '.version == 1 and (.role == "source" or .role == "target") and (.backup_sha256 | test("^[a-f0-9]{64}$")) and (.wp_content_sha256 | test("^[a-f0-9]{64}$")) and (.table_prefix | type == "string") and (.table_counts | type == "object") and (.table_checksums | type == "object")' "$baseline" >/dev/null \
    || fail "baseline $(basename "$baseline") has an invalid contract"
done
[ "$(jq -r '.role' "$source_baseline")" = source ] && [ "$(jq -r '.backup_sha256' "$source_baseline")" = "$source_sql_sha" ] \
  && [ "$(jq -r '.wp_content_sha256' "$source_baseline")" = "$source_content_sha" ] && [ "$(jq -r '.table_prefix' "$source_baseline")" = "$source_prefix" ] \
  || fail 'source baseline is not bound to the supplied backups and prefix'
[ "$(jq -r '.role' "$target_baseline")" = target ] && [ "$(jq -r '.backup_sha256' "$target_baseline")" = "$target_sql_sha" ] \
  && [ "$(jq -r '.wp_content_sha256' "$target_baseline")" = "$target_content_sha" ] && [ "$(jq -r '.table_prefix' "$target_baseline")" = "$target_prefix" ] \
  || fail 'target baseline is not bound to the supplied backups and prefix'

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
fixture_root="$(mktemp -d "${TMPDIR:-/tmp}/cartshift-lapka-fixture.XXXXXX")"
fixture_root="$(cd "$fixture_root" && pwd -P)"
chmod 0700 "$fixture_root"
artifact_dir="$fixture_root/artifacts"
candidate_extract="$fixture_root/candidate"
mkdir -m 0700 "$artifact_dir" "$candidate_extract"
printf '%s\n' "$project" > "$fixture_root/.cartshift-lapka-fixture"
chmod 0600 "$fixture_root/.cartshift-lapka-fixture"
compose_file="$fixture_root/compose.yaml"
isolation_report="$evidence_dir/${project}-isolation.json"
restore_report="$evidence_dir/${project}-restore.json"

case "$source_sql" in *.gz) source_sql_name='source.sql.gz' ;; *) source_sql_name='source.sql' ;; esac
case "$target_sql" in *.gz) target_sql_name='target.sql.gz' ;; *) target_sql_name='target.sql' ;; esac
cp "$source_sql" "$artifact_dir/$source_sql_name"
cp "$target_sql" "$artifact_dir/$target_sql_name"
cp "$source_content" "$artifact_dir/source-wp-content.tar"
cp "$target_content" "$artifact_dir/target-wp-content.tar"
cp "$candidate_zip" "$artifact_dir/cartshift-candidate.zip"
if [ -n "$wordpress_root" ]; then cp "$wordpress_root" "$artifact_dir/wordpress-root.tar"; fi
chmod 0600 "$artifact_dir"/*
unzip -q "$artifact_dir/cartshift-candidate.zip" -d "$candidate_extract"
[ -d "$candidate_extract/cartshift" ] && [ ! -L "$candidate_extract/cartshift" ] \
  && [ -f "$candidate_extract/cartshift/cartshift.php" ] && [ ! -L "$candidate_extract/cartshift/cartshift.php" ] \
  || fail 'candidate ZIP did not extract to one CartShift plugin root'
if find "$candidate_extract" -type l -print -quit | grep -q .; then fail 'candidate ZIP contains a symbolic link'; fi
candidate_dir="$candidate_extract/cartshift"
candidate_tree_sha="$(candidate_tree_digest "$candidate_dir")"
chmod -R go-rwx "$candidate_extract"

cat > "$artifact_dir/000-cartshift-rehearsal-guard.php" <<'PHP'
<?php
defined('ABSPATH') || exit;
define('CARTSHIFT_REHEARSAL_ISOLATED', true);
function cartshift_rehearsal_trace(): array {
    $trace = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
        $function = (string) ($frame['function'] ?? '');
        if ($function === '' || str_starts_with($function, 'cartshift_rehearsal_')) {
            continue;
        }
        $callable = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . $function;
        if (!in_array($callable, $trace, true)) {
            $trace[] = $callable;
        }
        if (count($trace) === 8) {
            break;
        }
    }
    return $trace;
}
function cartshift_rehearsal_spy(string $kind, array $context = []): void {
    $database = preg_replace('/[^a-z]/', '', (string) getenv('WORDPRESS_DB_NAME'));
    $path = '/cartshift-evidence/rehearsal-spy-' . ($database ?: 'unknown') . '.jsonl';
    $row = wp_json_encode(array_merge(['kind' => $kind, 'utc' => gmdate('Y-m-d\TH:i:s\Z')], $context));
    if (!is_string($row) || file_put_contents($path, $row . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('cartshift_rehearsal_spy_write_failed');
    }
}
add_filter('pre_site_transient_update_core', static fn () => (object) [
    'last_checked' => time(),
    'version_checked' => get_bloginfo('version'),
    'updates' => [],
]);
add_filter('pre_site_transient_update_plugins', static fn () => (object) [
    'last_checked' => time(),
    'checked' => [],
    'response' => [],
    'no_update' => [],
    'translations' => [],
]);
add_filter('pre_site_transient_update_themes', static fn () => (object) [
    'last_checked' => time(),
    'checked' => [],
    'response' => [],
    'no_update' => [],
    'translations' => [],
]);
add_filter('woocommerce_geolocate_ip', static function ($country, string $ip) {
    $effectiveIp = $ip;
    if ($effectiveIp === '' && class_exists('WC_Geolocation')) {
        $effectiveIp = (string) WC_Geolocation::get_ip_address();
    }
    if ($country !== false || !in_array($effectiveIp, ['127.0.0.1', '::1'], true)) {
        return $country;
    }
    $base = function_exists('wc_get_base_location') ? wc_get_base_location() : [];
    return is_array($base) ? (string) ($base['country'] ?? '') : '';
}, PHP_INT_MIN, 2);
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    cartshift_rehearsal_spy('outbound_http_attempt', [
        'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'path' => (string) wp_parse_url($url, PHP_URL_PATH),
        'trace' => cartshift_rehearsal_trace(),
    ]);
    return new WP_Error('cartshift_rehearsal_http_blocked');
}, PHP_INT_MIN, 3);
add_filter('pre_wp_mail', static function () {
    cartshift_rehearsal_spy('mail_attempt');
    return false;
}, PHP_INT_MIN);
add_filter('woocommerce_available_payment_gateways', '__return_empty_array', PHP_INT_MIN);
add_filter('fluent_cart/payment_methods', '__return_empty_array', PHP_INT_MIN);
add_filter('action_scheduler_queue_runner_concurrent_batches', '__return_zero', PHP_INT_MIN);
$cartshift_rehearsal_events = [
    'woocommerce_payment_complete', 'woocommerce_order_status_changed',
    'fluent_cart/order_paid_done', 'fluent_cart/order_fully_refunded', 'fluent_cart/order_canceled',
    'fluent_cart/subscription_activated', 'fluent_cart/subscription_renewed',
    'fluent_cart/subscription_canceled', 'fluent_cart/subscription_eot', 'fluent_cart/subscription_expired_validity',
];
foreach ($cartshift_rehearsal_events as $cartshift_rehearsal_event) {
    add_action($cartshift_rehearsal_event, static fn () => cartshift_rehearsal_spy('lifecycle_event'), PHP_INT_MIN);
}
PHP
chmod 0600 "$artifact_dir/000-cartshift-rehearsal-guard.php"

cat > "$compose_file" <<'YAML'
services:
  source-db:
    image: ${CARTSHIFT_REHEARSAL_MARIADB_IMAGE:-mariadb@sha256:78a5047d3ba33975f183f183c2464cc7f1eab13ec8667e57cc9a5821d6da7577}
    environment: {MARIADB_DATABASE: source, MARIADB_USER: rehearsal, MARIADB_PASSWORD: rehearsal, MARIADB_ROOT_PASSWORD: rehearsal-root}
    healthcheck: {test: [CMD, healthcheck.sh, --connect, --innodb_initialized], interval: 2s, timeout: 3s, retries: 90}
    networks: [isolated]
    volumes: [source-db:/var/lib/mysql, "${CARTSHIFT_FIXTURE_ARTIFACTS}:/fixture-artifacts:ro"]
  target-db:
    image: ${CARTSHIFT_REHEARSAL_MARIADB_IMAGE:-mariadb@sha256:78a5047d3ba33975f183f183c2464cc7f1eab13ec8667e57cc9a5821d6da7577}
    environment: {MARIADB_DATABASE: target, MARIADB_USER: rehearsal, MARIADB_PASSWORD: rehearsal, MARIADB_ROOT_PASSWORD: rehearsal-root}
    healthcheck: {test: [CMD, healthcheck.sh, --connect, --innodb_initialized], interval: 2s, timeout: 3s, retries: 90}
    networks: [isolated]
    volumes: [target-db:/var/lib/mysql, "${CARTSHIFT_FIXTURE_ARTIFACTS}:/fixture-artifacts:ro"]
  source-cli:
    image: ${CARTSHIFT_REHEARSAL_WPCLI_IMAGE:-wordpress@sha256:c9ecfd0ef73102cdc6666f20ccc3a0ae16c9a170160ef70bad4e9141ae856054}
    user: "0:0"
    entrypoint: [sh, -c]
    command: ["while :; do sleep 3600; done"]
    environment:
      WORDPRESS_DB_HOST: source-db
      WORDPRESS_DB_NAME: source
      WORDPRESS_DB_USER: rehearsal
      WORDPRESS_DB_PASSWORD: rehearsal
      WORDPRESS_TABLE_PREFIX: "${CARTSHIFT_SOURCE_PREFIX}"
      WORDPRESS_CONFIG_EXTRA: |
        define('DISABLE_WP_CRON', true);
        define('WP_HTTP_BLOCK_EXTERNAL', true);
        define('AUTOMATIC_UPDATER_DISABLED', true);
        define('WP_REDIS_DISABLED', true);
        define('WP_MEMORY_LIMIT', '512M');
        define('WP_MAX_MEMORY_LIMIT', '512M');
    networks: [isolated]
    volumes: [source-wordpress:/var/www/html, "${CARTSHIFT_FIXTURE_ARTIFACTS}:/fixture-artifacts:ro", "${CARTSHIFT_CANDIDATE_DIR}:/var/www/html/wp-content/plugins/cartshift:ro", "${CARTSHIFT_PACKAGE_DIR}:/sealed-package:ro", "${CARTSHIFT_EVIDENCE_DIR}:/cartshift-evidence"]
  target-cli:
    image: ${CARTSHIFT_REHEARSAL_WPCLI_IMAGE:-wordpress@sha256:c9ecfd0ef73102cdc6666f20ccc3a0ae16c9a170160ef70bad4e9141ae856054}
    user: "0:0"
    entrypoint: [sh, -c]
    command: ["while :; do sleep 3600; done"]
    environment:
      WORDPRESS_DB_HOST: target-db
      WORDPRESS_DB_NAME: target
      WORDPRESS_DB_USER: rehearsal
      WORDPRESS_DB_PASSWORD: rehearsal
      WORDPRESS_TABLE_PREFIX: "${CARTSHIFT_TARGET_PREFIX}"
      WORDPRESS_CONFIG_EXTRA: |
        define('DISABLE_WP_CRON', true);
        define('WP_HTTP_BLOCK_EXTERNAL', true);
        define('AUTOMATIC_UPDATER_DISABLED', true);
        define('WP_REDIS_DISABLED', true);
        define('WP_MEMORY_LIMIT', '512M');
        define('WP_MAX_MEMORY_LIMIT', '512M');
    networks: [isolated]
    volumes: [target-wordpress:/var/www/html, "${CARTSHIFT_FIXTURE_ARTIFACTS}:/fixture-artifacts:ro", "${CARTSHIFT_CANDIDATE_DIR}:/var/www/html/wp-content/plugins/cartshift:ro", "${CARTSHIFT_PACKAGE_DIR}:/sealed-package:ro", "${CARTSHIFT_EVIDENCE_DIR}:/cartshift-evidence"]
  source-wordpress:
    image: ${CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE:-wordpress@sha256:ffef0dca1f0fc4357bfef3856ebd1ba18f7b394378277122eaa4524ca2619d43}
    depends_on: {source-db: {condition: service_healthy}}
    environment:
      WORDPRESS_DB_HOST: source-db
      WORDPRESS_DB_NAME: source
      WORDPRESS_DB_USER: rehearsal
      WORDPRESS_DB_PASSWORD: rehearsal
      WORDPRESS_TABLE_PREFIX: "${CARTSHIFT_SOURCE_PREFIX}"
      WORDPRESS_CONFIG_EXTRA: |
        define('DISABLE_WP_CRON', true);
        define('WP_HTTP_BLOCK_EXTERNAL', true);
        define('AUTOMATIC_UPDATER_DISABLED', true);
        define('WP_REDIS_DISABLED', true);
        define('WP_MEMORY_LIMIT', '512M');
        define('WP_MAX_MEMORY_LIMIT', '512M');
    networks: [isolated]
    volumes: [source-wordpress:/var/www/html, "${CARTSHIFT_CANDIDATE_DIR}:/var/www/html/wp-content/plugins/cartshift:ro", "${CARTSHIFT_PACKAGE_DIR}:/sealed-package:ro", "${CARTSHIFT_EVIDENCE_DIR}:/cartshift-evidence"]
  target-wordpress:
    image: ${CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE:-wordpress@sha256:ffef0dca1f0fc4357bfef3856ebd1ba18f7b394378277122eaa4524ca2619d43}
    depends_on: {target-db: {condition: service_healthy}}
    environment:
      WORDPRESS_DB_HOST: target-db
      WORDPRESS_DB_NAME: target
      WORDPRESS_DB_USER: rehearsal
      WORDPRESS_DB_PASSWORD: rehearsal
      WORDPRESS_TABLE_PREFIX: "${CARTSHIFT_TARGET_PREFIX}"
      WORDPRESS_CONFIG_EXTRA: |
        define('DISABLE_WP_CRON', true);
        define('WP_HTTP_BLOCK_EXTERNAL', true);
        define('AUTOMATIC_UPDATER_DISABLED', true);
        define('WP_REDIS_DISABLED', true);
        define('WP_MEMORY_LIMIT', '512M');
        define('WP_MAX_MEMORY_LIMIT', '512M');
    networks: [isolated]
    volumes: [target-wordpress:/var/www/html, "${CARTSHIFT_CANDIDATE_DIR}:/var/www/html/wp-content/plugins/cartshift:ro", "${CARTSHIFT_PACKAGE_DIR}:/sealed-package:ro", "${CARTSHIFT_EVIDENCE_DIR}:/cartshift-evidence"]
networks: {isolated: {internal: true}}
volumes: {source-db: {}, target-db: {}, source-wordpress: {}, target-wordpress: {}}
YAML
chmod 0600 "$compose_file"

export CARTSHIFT_FIXTURE_ARTIFACTS="$artifact_dir" CARTSHIFT_CANDIDATE_DIR="$candidate_dir" CARTSHIFT_PACKAGE_DIR="$package_dir" CARTSHIFT_EVIDENCE_DIR="$evidence_dir"
export CARTSHIFT_SOURCE_PREFIX="$source_prefix" CARTSHIFT_TARGET_PREFIX="$target_prefix"
export CARTSHIFT_REHEARSAL_MARIADB_IMAGE="$mariadb_image" CARTSHIFT_REHEARSAL_WPCLI_IMAGE="$wpcli_image" CARTSHIFT_REHEARSAL_WORDPRESS_IMAGE="$wordpress_image"

success=false
cleanup_failure() {
  if [ "$success" != true ] && [ -f "$fixture_root/.cartshift-lapka-fixture" ] && [ "$(cat "$fixture_root/.cartshift-lapka-fixture")" = "$project" ]; then
    docker compose --project-name "$project" --file "$compose_file" down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf -- "$fixture_root"
  fi
}
trap cleanup_failure EXIT INT TERM

"$script_dir/assert-isolated-stack.sh" --project "$project" --compose-file "$compose_file" \
  --fixture-root "$fixture_root" --evidence-dir "$evidence_dir" --package-dir "$package_dir" \
  --candidate-dir "$candidate_dir" --output "$isolation_report"

docker compose --project-name "$project" --file "$compose_file" up -d source-db target-db source-cli target-cli
for db_service in source-db target-db; do
  ready=false
  for _ in $(seq 1 90); do
    if docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" \
      mariadb -B -N -urehearsal -prehearsal -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
    sleep 1
  done
  [ "$ready" = true ] || fail "${db_service} did not become ready"
done
for service in source-cli target-cli; do
  ready=false
  for _ in $(seq 1 90); do
    if docker compose --project-name "$project" --file "$compose_file" exec -T "$service" test -d /var/www/html >/dev/null 2>&1; then ready=true; break; fi
    sleep 1
  done
  [ "$ready" = true ] || fail "${service} volume did not become ready"
done

if [ -n "$wordpress_root" ]; then
  docker compose --project-name "$project" --file "$compose_file" exec -T source-cli sh -eu -c \
    'tar --warning=no-unknown-keyword -xf /fixture-artifacts/wordpress-root.tar --strip-components=1 -C /var/www/html'
  docker compose --project-name "$project" --file "$compose_file" exec -T target-cli sh -eu -c \
    'tar --warning=no-unknown-keyword -xf /fixture-artifacts/wordpress-root.tar --strip-components=1 -C /var/www/html'
fi

docker compose --project-name "$project" --file "$compose_file" exec -T source-cli sh -eu -c \
  'tar --warning=no-unknown-keyword --exclude="wp-content/plugins/cartshift" --exclude="wp-content/plugins/cartshift/*" -xf /fixture-artifacts/source-wp-content.tar -C /var/www/html && mkdir -p /var/www/html/wp-content/mu-plugins && cp /fixture-artifacts/000-cartshift-rehearsal-guard.php /var/www/html/wp-content/mu-plugins/'
docker compose --project-name "$project" --file "$compose_file" exec -T target-cli sh -eu -c \
  'tar --warning=no-unknown-keyword --exclude="wp-content/plugins/cartshift" --exclude="wp-content/plugins/cartshift/*" -xf /fixture-artifacts/target-wp-content.tar -C /var/www/html && mkdir -p /var/www/html/wp-content/mu-plugins && cp /fixture-artifacts/000-cartshift-rehearsal-guard.php /var/www/html/wp-content/mu-plugins/'

import_sql() {
  local db_service="$1" database="$2" filename="$3"
  case "$filename" in
    *.gz) docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" sh -eu -c "gzip -dc /fixture-artifacts/${filename} | mariadb -urehearsal -prehearsal ${database}" ;;
    *) docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" sh -eu -c "mariadb -urehearsal -prehearsal ${database} < /fixture-artifacts/${filename}" ;;
  esac
}
import_sql source-db source "$source_sql_name"
import_sql target-db target "$target_sql_name"

database_projection() {
  local db_service="$1" database="$2" prefix="$3" destination="$4"
  local tables_file rows_file
  tables_file="$fixture_root/${db_service}-tables.txt"
  rows_file="$fixture_root/${db_service}-table-rows.txt"
  docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" \
    mariadb -N -urehearsal -prehearsal "$database" -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${database}' AND LEFT(TABLE_NAME, ${#prefix})='${prefix}' ORDER BY TABLE_NAME;" > "$tables_file"
  [ -s "$tables_file" ] || fail "${db_service} has no tables for the approved prefix"
  : > "$rows_file"
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail 'restored table name is unsafe'
    count="$(docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" mariadb -N -urehearsal -prehearsal "$database" -e "SELECT COUNT(*) FROM \`${table}\`;" < /dev/null | tr -d '\r')"
    checksum="$(docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" mariadb -N -urehearsal -prehearsal "$database" -e "CHECKSUM TABLE \`${table}\`;" < /dev/null | awk '{print $2}' | tr -d '\r')"
    [[ "$count" =~ ^[0-9]+$ ]] && [[ "$checksum" =~ ^[0-9]+$ ]] || fail "${table} count or checksum is unavailable"
    printf '%s\t%s\t%s\n' "$table" "$count" "$checksum" >> "$rows_file"
  done < "$tables_file"
  jq -R -s -S 'split("\n") | map(select(length>0) | split("\t")) | {table_counts:(map({key:.[0],value:(.[1]|tonumber)})|from_entries),table_checksums:(map({key:.[0],value:(.[2]|tonumber)})|from_entries)}' "$rows_file" > "$destination"
  rm -f -- "$tables_file" "$rows_file"
}

source_projection="$fixture_root/source-projection.json"
target_projection="$fixture_root/target-projection.json"
database_projection source-db source "$source_prefix" "$source_projection"
database_projection target-db target "$target_prefix" "$target_projection"

verify_database_projection() {
  local role="$1" baseline="$2" actual="$3" mismatch
  if jq -e --slurpfile actual "$actual" \
    '.table_counts == $actual[0].table_counts and .table_checksums == $actual[0].table_checksums' \
    "$baseline" >/dev/null; then
    return
  fi
  mismatch="$evidence_dir/${project}-${role}-baseline-mismatch.json"
  jq -S -n --arg role "$role" --slurpfile sealed "$baseline" --slurpfile actual "$actual" \
    '{role:$role,sealed:{table_counts:$sealed[0].table_counts,table_checksums:$sealed[0].table_checksums},actual:$actual[0]}' \
    > "$mismatch"
  chmod 0600 "$mismatch"
  fail "restored ${role} table counts or checksums differ from sealed baseline; diagnostic: ${mismatch}"
}

verify_database_projection source "$source_baseline" "$source_projection"
verify_database_projection target "$target_baseline" "$target_projection"

# Boot only after the byte-independent database identity has been proven. The two
# URL changes below are the sole declared baseline mutations made by this script.
docker compose --project-name "$project" --file "$compose_file" up -d source-wordpress target-wordpress
source_url=''
target_url=''

update_isolated_urls() {
  local db_service="$1" database="$2" prefix="$3" web="$4" destination_name="$5"
  local url escaped_url values
  url="http://${web}"
  escaped_url="${url//\'/\'\'}"
  docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" \
    mariadb -N -urehearsal -prehearsal "$database" -e \
    "UPDATE \`${prefix}options\` SET option_value='${escaped_url}' WHERE option_name IN ('home','siteurl');" >/dev/null
  values="$(docker compose --project-name "$project" --file "$compose_file" exec -T "$db_service" \
    mariadb -N -urehearsal -prehearsal "$database" -e \
    "SELECT CONCAT(option_name, '=', option_value) FROM \`${prefix}options\` WHERE option_name IN ('home','siteurl') ORDER BY option_name;" | tr -d '\r')"
  [ "$values" = "home=${url}"$'\n'"siteurl=${url}" ] || fail "${web} isolated URL update was not exact"
  printf -v "$destination_name" '%s' "$url"
}

update_isolated_urls source-db source "$source_prefix" source-wordpress source_url
update_isolated_urls target-db target "$target_prefix" target-wordpress target_url

wp() { local service="$1"; shift; docker compose --project-name "$project" --file "$compose_file" exec -T "$service" wp --allow-root "$@"; }
for cli in source-cli target-cli; do
  ready=false
  for _ in $(seq 1 90); do if wp "$cli" core is-installed >/dev/null 2>&1; then ready=true; break; fi; sleep 1; done
  if [ "$ready" != true ]; then
    web="${cli%-cli}-wordpress"
    readiness_diagnostic="$evidence_dir/${project}-${cli}-wordpress-readiness-failure.txt"
    {
      printf '%s\n' 'wp core is-installed:'
      wp "$cli" core is-installed
      printf '%s\n' "${web} logs:"
      docker compose --project-name "$project" --file "$compose_file" logs --no-color "$web"
    } > "$readiness_diagnostic" 2>&1 || true
    chmod 0600 "$readiness_diagnostic"
    fail "${cli} restored WordPress is unavailable; diagnostic: ${readiness_diagnostic}"
  fi
done

jq -S -n \
  --arg status restored --arg mode "$mode" --arg project "$project" \
  --arg source_backup_sha256 "$source_sql_sha" --arg target_backup_sha256 "$target_sql_sha" \
  --arg source_wp_content_sha256 "$source_content_sha" --arg target_wp_content_sha256 "$target_content_sha" \
  --arg wordpress_root_sha256 "$wordpress_root_sha" \
  --arg candidate_zip_sha256 "$candidate_sha" --arg candidate_tree_sha256 "$candidate_tree_sha" \
  --arg manifest_sha256 "$manifest_sha" --arg source_url "$source_url" --arg target_url "$target_url" \
  --slurpfile source "$source_projection" --slurpfile target "$target_projection" \
  '{status:$status,mode:$mode,project:$project,source_backup_sha256:$source_backup_sha256,target_backup_sha256:$target_backup_sha256,source_wp_content_sha256:$source_wp_content_sha256,target_wp_content_sha256:$target_wp_content_sha256,wordpress_root_sha256:$wordpress_root_sha256,candidate_zip_sha256:$candidate_zip_sha256,candidate_tree_sha256:$candidate_tree_sha256,manifest_sha256:$manifest_sha256,source_url:$source_url,target_url:$target_url,source:$source[0],target:$target[0],cron:false,outbound_network:false,mail:false,payment_gateways:false}' \
  > "$restore_report"
chmod 0600 "$restore_report"

jq -S -n \
  --arg version '1' --arg mode "$mode" --arg project "$project" --arg compose_file "$compose_file" \
  --arg fixture_root "$fixture_root" --arg evidence_dir "$evidence_dir" --arg package_dir "$package_dir" \
  --arg candidate_dir "$candidate_dir" --arg candidate_zip_sha256 "$candidate_sha" --arg candidate_tree_sha256 "$candidate_tree_sha" \
  --arg source_prefix "$source_prefix" --arg target_prefix "$target_prefix" \
  --arg source_url "$source_url" --arg target_url "$target_url" --arg restore_report "$restore_report" \
  --arg isolation_report "$isolation_report" --arg manifest_sha256 "$manifest_sha" \
  --arg wordpress_root_sha256 "$wordpress_root_sha" \
  --arg mariadb_image "$mariadb_image" --arg wpcli_image "$wpcli_image" --arg wordpress_image "$wordpress_image" \
  --arg compose_sha256 "$(digest_file "$compose_file")" --arg restore_report_sha256 "$(digest_file "$restore_report")" \
  --arg isolation_report_sha256 "$(digest_file "$isolation_report")" \
  '{version:($version|tonumber),mode:$mode,project:$project,compose_file:$compose_file,compose_sha256:$compose_sha256,fixture_root:$fixture_root,evidence_dir:$evidence_dir,package_dir:$package_dir,manifest_sha256:$manifest_sha256,candidate_dir:$candidate_dir,candidate_zip_sha256:$candidate_zip_sha256,candidate_tree_sha256:$candidate_tree_sha256,wordpress_root_sha256:$wordpress_root_sha256,source_prefix:$source_prefix,target_prefix:$target_prefix,source_url:$source_url,target_url:$target_url,restore_report:$restore_report,restore_report_sha256:$restore_report_sha256,isolation_report:$isolation_report,isolation_report_sha256:$isolation_report_sha256,mariadb_image:$mariadb_image,wpcli_image:$wpcli_image,wordpress_image:$wordpress_image}' \
  > "$state_file"
chmod 0600 "$state_file"
success=true
printf '%s\n' "$state_file"
