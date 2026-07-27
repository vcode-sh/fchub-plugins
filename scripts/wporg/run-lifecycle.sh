#!/usr/bin/env bash

set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repository="$(cd "$here/../.." && pwd)"
compose_file="$here/docker-compose.yml"

random_suffix() {
  od -An -N4 -tx1 /dev/urandom | tr -d ' \n'
}

project_name() {
  local slug="$1"
  printf 'wporg-lifecycle-%s-%s-%s\n' "$slug" "$$" "$(random_suffix)"
}

zip_path="${1:-}"
slug="${2:-}"
wordpress_image="${3:-${WPORG_WORDPRESS_IMAGE:-wordpress:7.0.2-php8.5-apache}}"
wpcli_image="${4:-${WPORG_WPCLI_IMAGE:-wordpress:cli-php8.5}}"
previous_zip_path="${5:-${WPORG_PREVIOUS_ZIP:-}}"

if [ -z "$zip_path" ] || [ -z "$slug" ]; then
  printf 'Usage: %s <absolute-zip-path> <slug> [wordpress-image] [wpcli-image] [absolute-previous-zip-path]\n' "$0" >&2
  exit 2
fi

if [ "${zip_path#/}" = "$zip_path" ] || [ ! -f "$zip_path" ]; then
  printf 'The candidate ZIP must be an existing absolute path.\n' >&2
  exit 2
fi

if [ -n "$previous_zip_path" ] && {
  [ "${previous_zip_path#/}" = "$previous_zip_path" ] || [ ! -f "$previous_zip_path" ]
}; then
  printf 'The previous release ZIP must be an existing absolute path.\n' >&2
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

fixture_slug="$(bash "$here/lifecycle-fixtures.sh" "$slug")"

project="$(project_name "$slug")"
case "$project" in
  wporg-lifecycle-"$slug"-*) ;;
  *)
    printf 'Refusing unsafe Compose project name: %s\n' "$project" >&2
    exit 2
    ;;
esac

fixture_dir="$(cd "$(mktemp -d "${TMPDIR:-/tmp}/wporg-lifecycle-XXXXXXXX")" && pwd -P)"
case "$fixture_dir" in
  */wporg-lifecycle-*) ;;
  *)
    printf 'Refusing unsafe temporary path: %s\n' "$fixture_dir" >&2
    exit 2
    ;;
esac

cleanup_fixture_before_runtime() {
  local status=$?
  trap - EXIT INT TERM
  case "$fixture_dir" in
    */wporg-lifecycle-*) rm -rf "$fixture_dir" ;;
    *)
      printf 'Refusing to remove unsafe temporary path %s\n' "$fixture_dir" >&2
      status=1
      ;;
  esac
  exit "$status"
}
trap cleanup_fixture_before_runtime EXIT INT TERM

chmod 0755 "$fixture_dir"
cp "$zip_path" "$fixture_dir/candidate.zip"
chmod 0644 "$fixture_dir/candidate.zip"
if [ -n "$previous_zip_path" ]; then
  cp "$previous_zip_path" "$fixture_dir/previous.zip"
  chmod 0644 "$fixture_dir/previous.zip"
fi
if [ -n "$fixture_slug" ]; then
  fixture_source="$here/lifecycle-fixtures/$fixture_slug"
  if [ ! -f "$fixture_source/before-update.php" ] || [ ! -f "$fixture_source/after-update.php" ]; then
    printf 'Lifecycle fixtures are incomplete for %s.\n' "$fixture_slug" >&2
    exit 2
  fi
  cp "$fixture_source/before-update.php" "$fixture_dir/lifecycle-before-update.php"
  cp "$fixture_source/after-update.php" "$fixture_dir/lifecycle-after-update.php"
  cp "$here/lifecycle-admin-context.php" "$fixture_dir/lifecycle-admin-context.php"
  chmod 0644 \
    "$fixture_dir/lifecycle-before-update.php" \
    "$fixture_dir/lifecycle-after-update.php" \
    "$fixture_dir/lifecycle-admin-context.php"
fi

export WPORG_FIXTURE_DIR="$fixture_dir"
export WPORG_WORDPRESS_IMAGE="$wordpress_image"
export WPORG_WPCLI_IMAGE="$wpcli_image"

php_lane="${wordpress_image#*php}"
php_lane="${php_lane%-apache}"
results_dir="$repository/test-results/wporg/$slug"
mkdir -p "$results_dir"
runtime_log="$results_dir/lifecycle-php${php_lane}.log"
debug_log="$results_dir/lifecycle-php${php_lane}-debug.log"
: > "$runtime_log"

dc() {
  docker compose --progress quiet -p "$project" -f "$compose_file" "$@"
}

# shellcheck source=scripts/wporg/runtime-readiness.sh
source "$here/runtime-readiness.sh"

wp() {
  dc run --rm --no-deps -T wpcli wp "$@"
}

wp_admin() {
  dc run --rm --no-deps -T wpcli wp \
    --require=/wporg-fixture/lifecycle-admin-context.php "$@"
}

install_http_observer() {
  wp option update wporg_lifecycle_observed_slug "$slug" >>"$runtime_log" 2>&1
  wp option update wporg_lifecycle_http_attempts 0 >>"$runtime_log" 2>&1
  dc exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins >>"$runtime_log" 2>&1
  dc cp \
    "$here/lifecycle-http-observer.php" \
    wordpress:/var/www/html/wp-content/mu-plugins/wporg-lifecycle-http-observer.php \
    >>"$runtime_log" 2>&1
}

assert_no_plugin_http() {
  local phase="$1"
  local attempts
  attempts="$(wp option get wporg_lifecycle_http_attempts | tr -d '\r')"
  if [ "$attempts" != '0' ]; then
    printf '%s made %s plugin-originated HTTP request(s) during %s.\n' \
      "$slug" "$attempts" "$phase" >&2
    exit 1
  fi
}

probe_fixture_runtime() {
  [ -n "$fixture_slug" ] || return 0

  if wp plugin deactivate fluent-cart >>"$runtime_log" 2>&1; then
    target_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
    if [ "$target_status" != 'active' ]; then
      printf '%s was not active after FluentCart dependency loss.\n' "$slug" >&2
      exit 1
    fi
    wp_admin eval '
$slug = (string) get_option("wporg_lifecycle_observed_slug", "");
if (defined("FLUENTCART_VERSION")) {
    throw new RuntimeException("FluentCart remained loaded after real deactivation.");
}
$loaded = $slug === "fchub-memberships"
    ? defined("FCHUB_MEMBERSHIPS_VERSION")
    : defined("FCHUB_MC_VERSION");
if (!$loaded) {
    throw new RuntimeException("The active target did not tolerate dependency loss.");
}
do_action("admin_init");
do_action("admin_menu");
' >>"$runtime_log" 2>&1
    wp plugin activate fluent-cart >>"$runtime_log" 2>&1
    printf 'Active dependency-loss tolerance passed for %s.\n' "$slug" \
      | tee -a "$runtime_log"
  else
    dependency_status="$(wp plugin get fluent-cart --field=status | tr -d '\r')"
    target_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
    if [ "$dependency_status" != 'active' ] || [ "$target_status" != 'active' ]; then
      printf 'The dependency guard left %s or FluentCart inactive.\n' "$slug" >&2
      exit 1
    fi
    printf 'WordPress dependency guard prevented active FluentCart loss for %s.\n' "$slug" \
      | tee -a "$runtime_log"
  fi

  wp plugin deactivate "$slug" >>"$runtime_log" 2>&1
  wp plugin deactivate fluent-cart >>"$runtime_log" 2>&1

  dependency_status="$(wp plugin get fluent-cart --field=status | tr -d '\r')"
  target_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
  if [ "$dependency_status" != 'inactive' ] || [ "$target_status" != 'inactive' ]; then
    printf 'The dependency transition did not leave %s and FluentCart inactive.\n' "$slug" >&2
    exit 1
  fi

  if wp plugin activate "$slug" >>"$runtime_log" 2>&1; then
    printf 'The target plugin activated while FluentCart was inactive.\n' >&2
    exit 1
  fi

  wp plugin activate fluent-cart >>"$runtime_log" 2>&1
  wp plugin activate "$slug" >>"$runtime_log" 2>&1

  wp_admin eval '
$slug = (string) get_option("wporg_lifecycle_observed_slug", "");
if (!defined("FLUENTCART_VERSION")) {
    throw new RuntimeException("FluentCart did not load after reactivation.");
}
$addons = apply_filters("fluent_cart/integration/addons", []);
$expectedAddon = $slug === "fchub-memberships" ? "memberships" : $slug;
if (!is_array($addons) || !array_key_exists($expectedAddon, $addons)) {
    throw new RuntimeException("The FluentCart integration did not recover.");
}
do_action("admin_init");
do_action("admin_menu");
if ($slug === "fchub-memberships") {
    global $menu, $submenu;
    $menuSlugs = array_map(
        static fn(array $item): string => (string) ($item[2] ?? ""),
        is_array($menu) ? $menu : []
    );
    if (!in_array("fchub-memberships", $menuSlugs, true)
        || count($submenu["fchub-memberships"] ?? []) !== 7
    ) {
        throw new RuntimeException("The Memberships admin SPA route was not registered.");
    }

    ob_start();
    \FChubMemberships\Support\AdminMenu::render();
    $markup = (string) ob_get_clean();
    if (!str_contains($markup, "fchub-memberships-app")
        || !wp_script_is("fchub-memberships-admin", "enqueued")
    ) {
        throw new RuntimeException("The Memberships admin SPA shell or script was not enqueued.");
    }

    $manifestPath = FCHUB_MEMBERSHIPS_PATH . "assets/dist/.vite/manifest.json";
    $manifest = is_readable($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;
    $entry = is_array($manifest) ? ($manifest["resources/admin/main.js"] ?? null) : null;
    $entryFile = is_array($entry) ? (string) ($entry["file"] ?? "") : "";
    if ($entryFile === "" || !is_file(FCHUB_MEMBERSHIPS_PATH . "assets/dist/" . $entryFile)) {
        throw new RuntimeException("The Memberships admin SPA entry asset is missing.");
    }
}
if ($slug === "fchub-multi-currency") {
    do_action("fchub_mc_refresh_rates");
} elseif ($slug === "fchub-memberships") {
    do_action("fchub_memberships_validity_check");
}
' >>"$runtime_log" 2>&1

  assert_no_plugin_http 'activation, admin, cron, and dependency recovery'
  printf 'Runtime dependency and no-HTTP probes passed for %s.\n' "$slug" \
    | tee -a "$runtime_log"
}

archive_version() {
  local archive_path="$1"
  unzip -p "$archive_path" "$slug/$slug.php" \
    | sed -n 's/^[[:space:]]*[*][[:space:]]*Version:[[:space:]]*//p; s/^[[:space:]]*Version:[[:space:]]*//p' \
    | head -n 1 \
    | tr -d '\r'
}

cleanup() {
  local status=$?
  case "$project" in
    wporg-lifecycle-"$slug"-*)
      dc down --volumes --remove-orphans --timeout 15 >>"$runtime_log" 2>&1 || status=1
      ;;
    *)
      printf 'Refusing to tear down unsafe project %s\n' "$project" >&2
      status=1
      ;;
  esac

  case "$fixture_dir" in
    */wporg-lifecycle-*) rm -rf "$fixture_dir" ;;
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
    printf 'Lifecycle harness leaked Docker objects: %s\n' "$residue" >&2
    status=1
  fi

  exit "$status"
}
trap cleanup EXIT INT TERM

{
  printf 'project=%s\n' "$project"
  printf 'wordpress=%s\n' "$wordpress_image"
  printf 'wpcli=%s\n' "$wpcli_image"
  printf 'candidate=%s\n' "$zip_path"
  printf 'previous=%s\n' "$previous_zip_path"
} >> "$runtime_log"

dc up -d db wordpress >>"$runtime_log" 2>&1
wait_for_wordpress_filesystem "$runtime_log"
wp core install \
  --url=http://wordpress \
  --title='WordPress.org lifecycle' \
  --admin_user=admin \
  --admin_password=lifecycle \
  --admin_email=lifecycle@example.test \
  --skip-email >>"$runtime_log" 2>&1

install_http_observer

wp plugin install /wporg-fixture/candidate.zip >>"$runtime_log" 2>&1

absent_log="$results_dir/lifecycle-php${php_lane}-dependency-absent.log"
if wp plugin activate "$slug" >"$absent_log" 2>&1; then
  printf '%s activated without its required fluent-cart dependency.\n' "$slug" >&2
  exit 1
fi

absent_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
if [ "$absent_status" != 'inactive' ]; then
  printf '%s was not left inactive after the dependency guard.\n' "$slug" >&2
  exit 1
fi

wp plugin uninstall "$slug" >>"$runtime_log" 2>&1
wp plugin install fluent-cart --activate >>"$runtime_log" 2>&1
wp plugin install /wporg-fixture/candidate.zip >>"$runtime_log" 2>&1
wp plugin activate "$slug" >>"$runtime_log" 2>&1

expected_version="$(archive_version "$zip_path")"
installed_version="$(wp plugin get "$slug" --field=version | tr -d '\r')"
if [ -z "$expected_version" ] || [ "$installed_version" != "$expected_version" ]; then
  printf 'Installed %s version %s, expected %s.\n' "$slug" "$installed_version" "$expected_version" >&2
  exit 1
fi

active_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
if [ "$active_status" != 'active' ]; then
  printf '%s was not active after lifecycle activation.\n' "$slug" >&2
  exit 1
fi

probe_fixture_runtime

wp plugin deactivate "$slug" >>"$runtime_log" 2>&1
wp plugin uninstall "$slug" >>"$runtime_log" 2>&1

if wp plugin is-installed "$slug"; then
  printf '%s remained installed after uninstall.\n' "$slug" >&2
  exit 1
fi

if [ -n "$previous_zip_path" ]; then
  previous_version="$(archive_version "$previous_zip_path")"
  if [ -z "$previous_version" ] || [ "$previous_version" = "$expected_version" ]; then
    printf 'Invalid previous release version %s for candidate %s.\n' \
      "$previous_version" "$expected_version" >&2
    exit 1
  fi

  wp plugin install /wporg-fixture/previous.zip >>"$runtime_log" 2>&1
  wp plugin activate "$slug" >>"$runtime_log" 2>&1

  installed_previous_version="$(wp plugin get "$slug" --field=version | tr -d '\r')"
  if [ "$installed_previous_version" != "$previous_version" ]; then
    printf 'Installed previous %s version %s, expected %s.\n' \
      "$slug" "$installed_previous_version" "$previous_version" >&2
    exit 1
  fi

  if [ -n "$fixture_slug" ]; then
    wp eval-file /wporg-fixture/lifecycle-before-update.php >>"$runtime_log" 2>&1
  fi

  wp plugin install /wporg-fixture/candidate.zip --force >>"$runtime_log" 2>&1
  updated_version="$(wp plugin get "$slug" --field=version | tr -d '\r')"
  if [ "$updated_version" != "$expected_version" ]; then
    printf 'Updated %s version %s, expected %s.\n' \
      "$slug" "$updated_version" "$expected_version" >&2
    exit 1
  fi

  updated_status="$(wp plugin get "$slug" --field=status | tr -d '\r')"
  if [ "$updated_status" != 'active' ]; then
    printf '%s was not active after updating from %s to %s.\n' \
      "$slug" "$previous_version" "$expected_version" >&2
    exit 1
  fi

  if [ -n "$fixture_slug" ]; then
    wp eval-file /wporg-fixture/lifecycle-after-update.php >>"$runtime_log" 2>&1
    probe_fixture_runtime
    printf 'Migration preservation passed for %s.\n' "$slug" | tee -a "$runtime_log"
  fi

  wp plugin deactivate "$slug" >>"$runtime_log" 2>&1
  wp plugin uninstall "$slug" >>"$runtime_log" 2>&1
  if wp plugin is-installed "$slug"; then
    printf '%s remained installed after update lifecycle uninstall.\n' "$slug" >&2
    exit 1
  fi
fi

wp eval '
$path = WP_CONTENT_DIR . "/debug.log";
if (is_readable($path)) {
    echo file_get_contents($path);
}
' >"$debug_log" 2>/dev/null || true

if grep -E "PHP (Warning|Deprecated|Fatal error).*wp-content/plugins/$slug|wp-content/plugins/$slug.*PHP (Warning|Deprecated|Fatal error)" "$debug_log"; then
  printf '%s emitted a plugin-originated PHP warning, deprecation, or fatal.\n' "$slug" >&2
  exit 1
fi

printf 'Lifecycle passed for %s on WordPress 7.0.2 / PHP %s.\n' "$slug" "$php_lane" \
  | tee -a "$runtime_log"
