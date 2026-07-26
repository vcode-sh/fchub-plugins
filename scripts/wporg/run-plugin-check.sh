#!/usr/bin/env bash

set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository="$(cd "$here/../.." && pwd)"
compose_file="$here/docker-compose.yml"
allowlist="$here/allowlist.json"

random_suffix() {
  od -An -N4 -tx1 /dev/urandom | tr -d ' \n'
}

project_name() {
  local slug="$1"
  printf 'wporg-check-%s-%s-%s\n' "$slug" "$$" "$(random_suffix)"
}

zip_path="${1:-}"
slug="${2:-}"
wordpress_image="${3:-${WPORG_WORDPRESS_IMAGE:-wordpress:7.0.2-php8.5-apache}}"
wpcli_image="${4:-${WPORG_WPCLI_IMAGE:-wordpress:cli-php8.5}}"

if [ -z "$zip_path" ] || [ -z "$slug" ]; then
  printf 'Usage: %s <absolute-zip-path> <slug> [wordpress-image] [wpcli-image]\n' "$0" >&2
  exit 2
fi

if [ "${zip_path#/}" = "$zip_path" ] || [ ! -f "$zip_path" ]; then
  printf 'The candidate ZIP must be an existing absolute path.\n' >&2
  exit 2
fi

if ! printf '%s' "$slug" | grep -Eq '^[a-z0-9][a-z0-9-]*$'; then
  printf 'Invalid plugin slug: %s\n' "$slug" >&2
  exit 2
fi

case "$wordpress_image|$wpcli_image" in
  'wordpress:7.0.2-php8.3-apache|wordpress:cli-php8.3'|\
  'wordpress:7.0.2-php8.4-apache|wordpress:cli-php8.4'|\
  'wordpress:7.0.2-php8.5-apache|wordpress:cli-php8.5') ;;
  *)
    printf 'Unsupported WordPress/WP-CLI image pair: %s + %s\n' "$wordpress_image" "$wpcli_image" >&2
    exit 2
    ;;
esac

project="$(project_name "$slug")"
case "$project" in
  wporg-check-"$slug"-*) ;;
  *)
    printf 'Refusing unsafe Compose project name: %s\n' "$project" >&2
    exit 2
    ;;
esac

fixture_dir="$(cd "$(mktemp -d "${TMPDIR:-/tmp}/wporg-check-XXXXXXXX")" && pwd -P)"
case "$fixture_dir" in
  */wporg-check-*) ;;
  *)
    printf 'Refusing unsafe temporary path: %s\n' "$fixture_dir" >&2
    exit 2
    ;;
esac

chmod 0755 "$fixture_dir"
cp "$zip_path" "$fixture_dir/candidate.zip"
chmod 0644 "$fixture_dir/candidate.zip"

export WPORG_FIXTURE_DIR="$fixture_dir"
export WPORG_WORDPRESS_IMAGE="$wordpress_image"
export WPORG_WPCLI_IMAGE="$wpcli_image"

results_dir="$repository/test-results/wporg/$slug"
mkdir -p "$results_dir"
runtime_raw="$results_dir/runtime-raw.log"
static_raw="$results_dir/static-raw.log"
runtime_report="$results_dir/runtime-plugin-check.json"
final_report="$results_dir/plugin-check.json"
runtime_log="$results_dir/runtime.log"
: > "$runtime_log"

dc() {
  docker compose --progress quiet -p "$project" -f "$compose_file" "$@"
}

wp() {
  dc run --rm --no-deps -T wpcli wp "$@"
}

clone_custom_runtime_tables() {
  wp eval '
global $wpdb;
$core = array_values($wpdb->tables("all"));
$tables = $wpdb->get_col("SHOW TABLES LIKE '"'"'" . $wpdb->esc_like($wpdb->prefix) . "%'"'"'");
foreach ($tables as $table) {
    if (in_array($table, $core, true) || str_starts_with($table, $wpdb->base_prefix . "pc_")) {
        continue;
    }
    $suffix = substr($table, strlen($wpdb->base_prefix));
    if (!preg_match("/^[a-zA-Z0-9_]+$/", $suffix)) {
        throw new RuntimeException("Unsafe custom table suffix: " . $suffix);
    }
    $runtimeTable = $wpdb->base_prefix . "pc_" . $suffix;
    $wpdb->query("CREATE TABLE IF NOT EXISTS `{$runtimeTable}` LIKE `{$table}`");
}
' >>"$runtime_log" 2>&1
}

drop_custom_runtime_tables() {
  wp eval '
global $wpdb;
$tables = $wpdb->get_col("SHOW TABLES LIKE '"'"'" . $wpdb->esc_like($wpdb->base_prefix . "pc_") . "%'"'"'");
foreach ($tables as $table) {
    $suffix = substr($table, strlen($wpdb->base_prefix . "pc_"));
    if (preg_match("/^[a-zA-Z0-9_]+$/", $suffix)) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}
' >>"$runtime_log" 2>&1
}

cleanup() {
  local status=$?
  case "$project" in
    wporg-check-"$slug"-*)
      dc down --volumes --remove-orphans --timeout 15 >>"$runtime_log" 2>&1 || status=1
      ;;
    *)
      printf 'Refusing to tear down unsafe project %s\n' "$project" >&2
      status=1
      ;;
  esac

  case "$fixture_dir" in
    */wporg-check-*) rm -rf "$fixture_dir" ;;
    *)
      printf 'Refusing to remove unsafe temporary path %s\n' "$fixture_dir" >&2
      status=1
      ;;
  esac

  local residue
  residue="$(
    {
      docker ps -a --filter "name=$project" --format '{{.Names}}'
      docker volume ls --filter "name=$project" --format '{{.Name}}'
      docker network ls --filter "name=$project" --format '{{.Name}}'
    } | tr '\n' ' '
  )"
  if [ -n "${residue// /}" ]; then
    printf 'Plugin Check harness leaked Docker objects: %s\n' "$residue" >&2
    status=1
  fi

  exit "$status"
}
trap cleanup EXIT INT TERM

{
  printf 'project=%s\n' "$project"
  printf 'wordpress=%s\n' "$wordpress_image"
  printf 'wpcli=%s\n' "$wpcli_image"
  printf 'zip=%s\n' "$zip_path"
} >> "$runtime_log"

dc up -d db wordpress >>"$runtime_log" 2>&1
wp core install \
  --url=http://wordpress \
  --title='WordPress.org package check' \
  --admin_user=admin \
  --admin_password=plugin-check \
  --admin_email=checks@example.test \
  --skip-email >>"$runtime_log" 2>&1

wp plugin install plugin-check --version=2.0.0 --activate >>"$runtime_log" 2>&1
installed_plugin_check="$(wp plugin get plugin-check --field=version | tr -d '\r')"
if [ "$installed_plugin_check" != '2.0.0' ]; then
  printf 'Expected Plugin Check 2.0.0, got %s.\n' "$installed_plugin_check" >&2
  exit 1
fi

wp plugin install /wporg-fixture/candidate.zip >>"$runtime_log" 2>&1
wp plugin install fluent-cart --activate >>"$runtime_log" 2>&1
wp plugin activate "$slug" >>"$runtime_log" 2>&1

clone_custom_runtime_tables

wp eval '
$runtime = new \WordPress\Plugin_Check\Checker\Runtime_Environment_Setup();
$runtime->set_up();
global $wpdb;
$options = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->base_prefix . "pc_options"));
$posts = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->base_prefix . "pc_posts"));
if (!$options || !$posts) {
    fwrite(STDERR, "HARNESS_RUNTIME_DATABASE_BROKEN\n");
    $runtime->clean_up();
    exit(1);
}
$runtime->clean_up();
' >>"$runtime_log" 2>&1

wp plugin check "$slug" \
  --mode=new \
  --format=strict-json \
  --fields=file,line,column,type,code,message \
  --no-color >"$runtime_raw" 2>&1 || true
runtime_status=0
php "$here/parse-plugin-check.php" \
  "$slug" "$runtime_raw" "$allowlist" "$runtime_report" || runtime_status=$?

drop_custom_runtime_tables
wp plugin deactivate "$slug" >>"$runtime_log" 2>&1
wp plugin check "$slug" \
  --mode=new \
  --format=strict-json \
  --fields=file,line,column,type,code,message \
  --no-color >"$static_raw" 2>&1 || true
static_status=0
php "$here/parse-plugin-check.php" \
  "$slug" "$static_raw" "$allowlist" "$final_report" || static_status=$?

if [ "$runtime_status" -ne 0 ] || [ "$static_status" -ne 0 ]; then
  printf 'Plugin Check failed for %s (runtime=%s, static=%s).\n' \
    "$slug" "$runtime_status" "$static_status" >&2
  exit 1
fi

printf 'Plugin Check passed for %s under %s.\n' "$slug" "$wordpress_image"
