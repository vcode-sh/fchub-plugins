#!/usr/bin/env bash
#
# The only test in this project that runs against a real WordPress.
#
# It stands up a disposable WordPress, MariaDB and fixture release host, drives
# the real FCHub interface in a browser to install and update a real plugin,
# refuses a release whose checksum does not match, removes FCHub through
# WordPress, and then checks that the product it installed is still there and
# still on the version FCHub put it on. Everything it touches is created by this
# script and destroyed by it, on every exit path — success, failure, Ctrl-C —
# and the teardown is asserted rather than assumed: a run that leaks a container
# exits non-zero even if every test passed.
#
#   cd plugins/fchub
#   bash tests/e2e/run-lifecycle.sh
#
# Environment:
#   FCHUB_TEST_RUN_ID           Suffix for the Compose project name. Generated
#                               when unset, which is how two runs stay apart.
#   FCHUB_LIFECYCLE_SKIP_BUILD  Reuse dist/<zip> instead of rebuilding it. For
#                               iterating on the harness, not for trusting it —
#                               and, today, the only mode in which two runs may
#                               safely overlap, because the default path runs
#                               `npm ci` in one shared plugin directory.
#   FCHUB_LIFECYCLE_KEEP        Leave the stack up after a run that did not
#                               succeed, and print the command to tear it down.
#                               An interrupt counts as not succeeding, so with
#                               this set Ctrl-C leaves the containers running —
#                               which is the entire point of the flag. Cleanup
#                               is unconditional without it.
#
# What it will never do: use, stop or remove anything belonging to the
# fchub-playground environment, bind a fixed host port, or prune Docker
# globally. The project name is validated before anything is brought up or
# down, and the only teardown is scoped to that one project.

set -Eeuo pipefail

# ── Where everything lives ───────────────────────────────────────────────────

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd "$here/../.." && pwd)"
repo_root="$(cd "$plugin_dir/../.." && pwd)"
compose_file="$here/docker-compose.yml"

# The long-lived environment this script must never touch, named here so the
# footprint report can show it was left alone rather than merely claim it.
playground_prefix='fchub-playground'

# ── Output ───────────────────────────────────────────────────────────────────

step()    { printf '\n\033[0;36m▸\033[0m \033[1m%s\033[0m\n' "$*"; }
info()    { printf '  %s\n' "$*"; }
success() { printf '\033[0;32m✓\033[0m %s\n' "$*"; }
warn()    { printf '\033[1;33m⚠\033[0m %s\n' "$*" >&2; }
fail()    { printf '\033[0;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

# ── Naming, and the guard that makes teardown safe ───────────────────────────

run_id="${FCHUB_TEST_RUN_ID:-}"

if [ -z "$run_id" ]; then
  # Seconds, this shell's pid, and four random hex digits: two runs started in
  # the same second by the same parent still get two different projects.
  run_id="$(date +%s)-$$-$(od -An -N2 -tx1 /dev/urandom | tr -d ' \n')"
fi

if ! printf '%s' "$run_id" | grep -Eq '^[a-z0-9][a-z0-9_-]*$'; then
  fail "FCHUB_TEST_RUN_ID must be lowercase alphanumerics, dashes and underscores: got '$run_id'"
fi

project="fchub-lifecycle-$run_id"

# The prefix is not decoration. Everything this script tears down is scoped to
# one project name, and a project name that did not come from here is a project
# name belonging to somebody else.
case "$project" in
  fchub-lifecycle-*) ;;
  *) fail "refusing to run under project name '$project'" ;;
esac

results_dir="$plugin_dir/test-results/lifecycle-$run_id"

# ── Docker helpers, all scoped to this one project ───────────────────────────

dc() {
  # --progress quiet silences the container-creation chatter, which for a script
  # that runs a dozen one-shot WP-CLI containers is most of the output. Errors
  # still surface; only the spinner is gone.
  docker compose --progress quiet -p "$project" -f "$compose_file" "$@"
}

wp() {
  dc run --rm --no-deps -T wpcli wp "$@"
}

# Everything Docker is holding under *this* project name, one object per line.
# Also the emptiness check teardown is asserted against, which is why it is a
# function and not three inline pipelines.
residue() {
  docker ps -a --filter "name=$project" --format '{{.Names}}'
  docker volume ls --filter "name=$project" --format '{{.Name}}'
  docker network ls --filter "name=$project" --format '{{.Name}}'
}

# The isolation evidence, printed rather than promised.
#
# Scoped to $project rather than the shared `fchub-lifecycle-` prefix: two
# concurrent runs would otherwise each report the other's containers, so the
# report would read like a leak when it is not — and would read clean if the
# other run finished first while this one leaked. The playground line keeps its
# own prefix, because that one is about somebody else's environment on purpose.
footprint() {
  local when="$1"

  printf '\n  Docker footprint — %s\n' "$when"
  printf '    containers  %s*: %s\n' "$project" \
    "$(docker ps -a --filter "name=$project" --format '{{.Names}}' | tr '\n' ' ')"
  printf '    volumes     %s*: %s\n' "$project" \
    "$(docker volume ls --filter "name=$project" --format '{{.Name}}' | tr '\n' ' ')"
  printf '    networks    %s*: %s\n' "$project" \
    "$(docker network ls --filter "name=$project" --format '{{.Name}}' | tr '\n' ' ')"
  printf '    playground volumes (untouched): %s\n' \
    "$(docker volume ls --filter "name=$playground_prefix" --format '{{.Name}}' | tr '\n' ' ')"
}

# ── Cleanup, on every exit path ──────────────────────────────────────────────

# Created before the trap is armed, because the trap and the Compose file both
# interpolate it. `pwd -P` because macOS hands out a /var/folders path that is a
# symlink into /private, and Docker Desktop shares the resolved one.
fixture_dir="$(cd "$(mktemp -d "${TMPDIR:-/tmp}/fchub-lifecycle-XXXXXXXX")" && pwd -P)"
export FCHUB_FIXTURE_DIR="$fixture_dir"

# Deliberate, and please leave it alone.
#
# `mktemp -d` creates 0700 owned by the invoking user. The wpcli service is
# pinned to uid 33 — it has to be, because Debian's www-data is 33 and Alpine's
# is 82, and Apache must be able to overwrite what the CLI wrote. On Linux uid
# 33 cannot traverse a 0700 directory owned by somebody else, so
# /fchub-fixtures is simply invisible inside that container: WP-CLI reports
# "plugin could not be found", falls back to treating the path as a
# wordpress.org slug, and the run dies forty lines further on than the actual
# fault. That is how a GitHub runner failed while this had been green here nine
# times in a row — Docker Desktop virtualises bind-mount ownership, so macOS
# never sees it.
#
# Nothing under here is secret: generated fixture plugins, catalogues, and a
# copy of an archive that is about to be published anyway. The directory is
# removed on every exit path.
chmod 0755 "$fixture_dir"

cleanup_done=0

cleanup() {
  local status=$?

  if [ "$cleanup_done" -eq 1 ]; then
    return
  fi

  cleanup_done=1

  # An interrupt is a non-zero status, so Ctrl-C counts as "did not succeed" and
  # leaves the stack up when this flag is set. That is what the flag is for, and
  # the header says so.
  if [ "$status" -ne 0 ] && [ -n "${FCHUB_LIFECYCLE_KEEP:-}" ]; then
    warn "FCHUB_LIFECYCLE_KEEP is set and the run did not succeed — leaving $project up."
    warn "  docker compose -p $project -f $compose_file down --volumes --remove-orphans"
    warn "  fixtures: $fixture_dir"
    return
  fi

  step "Tearing down $project"

  case "$project" in
    fchub-lifecycle-*) ;;
    *) warn "refusing to tear down '$project'"; return ;;
  esac

  # Scoped to one project and its own volumes. No prune, no
  # `docker stop $(docker ps -q)`, nothing that can reach a container this
  # script did not create.
  local torn_down=1

  if ! dc down --volumes --remove-orphans --timeout 15 >/dev/null 2>&1; then
    warn 'compose down reported a problem'
    torn_down=0
  fi

  # Same guard as the project name, for the same reason: this is the one
  # recursive delete in the script, and it only ever runs against a path this
  # script asked mktemp for.
  case "$fixture_dir" in
    */fchub-lifecycle-*) rm -rf "$fixture_dir" ;;
    *) warn "refusing to remove fixture directory '$fixture_dir'" ;;
  esac

  footprint 'after cleanup'

  # Teardown is the property every other claim in this harness rests on, so it
  # is asserted rather than reported. A green run that leaked a container is not
  # a green run, and a warning buried in several hundred lines of output is not
  # an assertion.
  local leftover
  leftover="$(residue | tr '\n' ' ')"

  if [ -n "${leftover// /}" ]; then
    warn "teardown left Docker objects behind: $leftover"
    torn_down=0
  fi

  if [ "$torn_down" -eq 0 ]; then
    # An existing failure keeps its own exit code; a run that was otherwise
    # green fails here, because this is the failure.
    [ "$status" -ne 0 ] || status=1

    exit "$status"
  fi
}

trap cleanup EXIT
trap 'cleanup; exit 130' INT
trap 'cleanup; exit 143' TERM

# ── Preflight ────────────────────────────────────────────────────────────────

step 'Preflight'

for tool in docker node npm npx zip curl; do
  command -v "$tool" >/dev/null 2>&1 || fail "$tool is required and is not on PATH"
done

docker info >/dev/null 2>&1 || fail 'Docker is not running'

info "project    $project"
info "fixtures   $fixture_dir"
info "artefacts  $results_dir"

footprint 'before the run'

# ── The archive under test ───────────────────────────────────────────────────

# One awk rather than grep | head | sed | tr. Under `set -o pipefail`, `head`
# closing early makes `grep` take SIGPIPE, which fails the pipeline, which fails
# the assignment, which trips `set -e` — and the friendly message below never
# gets a chance to print.
hub_version="$(
  awk '
    /^[[:space:]]*\*[[:space:]]*Version:/ {
      sub(/^.*Version:[[:space:]]*/, "")
      gsub(/[[:space:]]/, "")
      print
      exit
    }
  ' "$plugin_dir/fchub.php"
)"

[ -n "$hub_version" ] || fail 'could not read the version out of fchub.php'

hub_zip="$repo_root/dist/fchub-$hub_version.zip"

if [ -n "${FCHUB_LIFECYCLE_SKIP_BUILD:-}" ]; then
  step "Reusing $(basename "$hub_zip")"
  [ -f "$hub_zip" ] || fail "FCHUB_LIFECYCLE_SKIP_BUILD is set but $hub_zip does not exist"
else
  step "Building $(basename "$hub_zip")"
  # The real build, producing the real archive. A harness that installed a
  # directory somebody rsynced by hand would prove the source works and say
  # nothing whatsoever about what ships.
  bash "$repo_root/build.sh" fchub >/dev/null || fail 'build.sh failed'
  [ -f "$hub_zip" ] || fail "build.sh did not produce $hub_zip"
fi

success "$(basename "$hub_zip") ready"

# ── Fixtures ─────────────────────────────────────────────────────────────────

step 'Generating fixtures'

node "$here/prepare-fixtures.mjs" "$fixture_dir" "$hub_zip" | sed 's/^/  /'

# The rest of the same problem. Node's mkdir and writeFile take their mode from
# the umask, and so does zip: under the 022 most people run, that is 0755 and
# 0644 and everything is readable. Under a hardened 077 it is 0700 and 0600, and
# then neither uid 33 in the CLI container nor uid 101 in the Nginx one can read
# a thing — the fixture host included. `a+rX` adds read everywhere and execute
# on directories only, which is the whole of what these containers need.
chmod -R a+rX "$fixture_dir"

success 'three P24 releases (one with a deliberately wrong sidecar), a FluentCart, three catalogues'

# ── The disposable site ──────────────────────────────────────────────────────

step 'Starting the disposable site'

dc up -d --wait db catalogue >/dev/null
dc up -d wordpress >/dev/null

port_mapping="$(dc port wordpress 80)"

# First line only. Some Compose and daemon combinations report IPv4 and IPv6
# separately, and `${…##*:}` over two lines yields a mangled port, a malformed
# base URL, and a five-minute poll before a failure that names the wrong thing.
port_mapping="${port_mapping%%$'\n'*}"
host_port="${port_mapping##*:}"

[ -n "$host_port" ] || fail 'Compose did not publish a host port for WordPress'

base_url="http://127.0.0.1:$host_port"

info "WordPress on $base_url"

# -f matters: Apache answers before the image has finished copying WordPress
# into the volume, and a 404 accepted as readiness would send `wp core install`
# looking for files that are not there yet.
deadline=$((SECONDS + 300))

until curl -fsS -o /dev/null --max-time 5 "$base_url/wp-admin/install.php"; do
  if [ "$SECONDS" -ge "$deadline" ]; then
    dc logs --tail 60 wordpress >&2 || true
    fail "WordPress never served install.php on $base_url"
  fi

  sleep 1
done

success 'Apache is serving WordPress'

# ── Installing WordPress ─────────────────────────────────────────────────────

step 'Installing WordPress'

wp core install \
  --url="$base_url" \
  --title='FCHub lifecycle' \
  --admin_user=admin \
  --admin_password=pass \
  --admin_email=lifecycle@example.test \
  --skip-email >/dev/null

wp_version="$(wp core version | tr -d '\r\n')"

success "WordPress $wp_version, admin/pass"

# ── The harness MU-plugin ────────────────────────────────────────────────────

step 'Installing the harness MU-plugin'

# Copied rather than bind-mounted onto the volume: a read-only mount nested
# inside /var/www/html has to survive the WordPress image's own ownership pass
# on first boot, and when it does not the failure looks like something else
# entirely. The chown is the other half — the CLI container writes as uid 33 so
# that Apache, which is also uid 33, can overwrite what it wrote.
dc exec -T -u root wordpress sh -euc '
  mkdir -p /var/www/html/wp-content/mu-plugins
  cp /fchub-fixtures/mu-plugins/*.php /var/www/html/wp-content/mu-plugins/
  chown -R www-data:www-data /var/www/html/wp-content
' >/dev/null

success 'the fixture host is allowed over HTTP, in this container only'

# ── Proving the fixture host is the only thing reachable ─────────────────────

step 'Checking the fixture release host'

# End to end through WordPress's own HTTP stack: DNS on the Compose network, the
# private-address filter the MU-plugin narrows, WP_ACCESSIBLE_HOSTS, and nginx.
# If this says 200, none of those can be what the browser test trips over.
catalogue_code="$(
  wp eval 'echo (int) wp_remote_retrieve_response_code(wp_safe_remote_get(FCHUB_CATALOGUE_URL));' \
    | tr -d '\r\n '
)"

[ "$catalogue_code" = '200' ] || fail "the fixture catalogue answered '$catalogue_code', not 200"

# The other half of the claim. With WP_HTTP_BLOCK_EXTERNAL on and only
# `catalogue` accessible, the public internet is not merely unused here — it is
# shut, so a fixture that quietly stopped being consulted could not be covered
# for by the real endpoint answering in its place.
blocked="$(
  wp eval '$r = wp_safe_remote_get("https://api.wordpress.org/core/version-check/1.7/"); echo is_wp_error($r) ? $r->get_error_code() : "reached";' \
    | tr -d '\r\n '
)"

[ "$blocked" = 'http_request_not_executed' ] \
  || fail "this site can reach the public internet: api.wordpress.org gave '$blocked'"

success 'fixture host reachable, everything else refused'

# ── And that the containers can actually read the fixtures ───────────────────

step 'Checking the fixtures are readable inside the containers'

# Asserted as its own step because the failure is otherwise unrecognisable.
# When uid 33 cannot traverse the fixture directory, WP-CLI does not say
# "permission denied" — it says the plugin could not be found, then treats the
# path as a wordpress.org slug and blames the network. The real fault is here,
# and this is where it should be named.
#
# Both readers are checked: the CLI container mounts the whole tree, while the
# fixture host mounts only www/ and so never traverses the top directory. That
# asymmetry is exactly why a CI run reported the fixture host healthy one step
# before failing to see a file inside it.
if ! dc run --rm --no-deps -T --entrypoint sh wpcli \
  -c "test -r /fchub-fixtures/www/packages/$(basename "$hub_zip") && test -r /fchub-fixtures/mu-plugins/fchub-lifecycle-harness.php" \
  >/dev/null 2>&1; then
  fail "the CLI container (uid 33) cannot read $fixture_dir — its permissions are wrong for this platform"
fi

dc exec -T catalogue test -r /usr/share/nginx/html/catalogue.json \
  || fail 'the fixture host cannot read its own catalogue'

success 'uid 33 can read the packages, and Nginx can read the catalogue'

# ── Seeding ──────────────────────────────────────────────────────────────────

step 'Seeding the site'

# Installed and deliberately left switched off. The browser test opens on a P24
# that FCHub declines to install because FluentCart is not running, which is the
# compatibility gate working against a real site rather than a JSON file.
wp plugin install /fchub-fixtures/www/packages/fluent-cart-1.2.0.zip >/dev/null

wp plugin install "/fchub-fixtures/www/packages/$(basename "$hub_zip")" --activate >/dev/null

active="$(wp plugin list --field=name --status=active | tr -d '\r' | tr '\n' ' ')"

case " $active " in
  *' fchub '*) ;;
  *) fail "FCHub is not active after install (active: $active)" ;;
esac

success "FCHub $hub_version active; the FluentCart fixture is installed and off"

# ── The browser ──────────────────────────────────────────────────────────────

step 'Driving the FCHub interface'

mkdir -p "$results_dir"

cd "$plugin_dir"

# FCHUB_LIFECYCLE is what gates the `lifecycle` project in playwright.config.js;
# without it the project does not exist and this fails by name rather than by
# quietly running the smoke suite against the wrong thing.
#
# --global-timeout is a wall-clock ceiling on the whole suite, not a per-step
# budget; the per-test timeout already covers that. It is here because the
# containers are only torn down once this command returns, so a suite that can
# hang is a stack that can be left running.
FCHUB_LIFECYCLE=1 \
FCHUB_LIFECYCLE_BASE_URL="$base_url" \
FCHUB_LIFECYCLE_PROJECT="$project" \
FCHUB_LIFECYCLE_COMPOSE_FILE="$compose_file" \
FCHUB_LIFECYCLE_FIXTURE_DIR="$fixture_dir" \
FCHUB_LIFECYCLE_HUB_ARCHIVE="$(basename "$hub_zip")" \
FCHUB_LIFECYCLE_HUB_VERSION="$hub_version" \
  npx playwright test \
    --project=lifecycle \
    --output="$results_dir" \
    --global-timeout="$((20 * 60 * 1000))"

footprint 'before cleanup'

printf '\n\033[0;32m\033[1mThe lifecycle holds.\033[0m FCHub installed a product, updated it, refused a\n'
printf 'release that failed its checksum, and left the product standing after being removed.\n'
