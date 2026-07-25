#!/usr/bin/env bash
set -e

# ── Colors ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# ── Project root (where this script lives) ──────────────────────────────────
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
PLUGINS_DIR="$ROOT_DIR/plugins"

# ── Plugin definitions: slug|main-file ──────────────────────────────────────
ALL_PLUGINS=(
    "fchub-p24|fchub-p24.php"
    "fchub-fakturownia|fchub-fakturownia.php"
    "fchub-memberships|fchub-memberships.php"
    "fchub-portal-extender|fchub-portal-extender.php"
    "fchub-wishlist|fchub-wishlist.php"
    "fchub-multi-currency|fchub-multi-currency.php"
    "cartshift|cartshift.php"
)

# Discontinued, but still buildable on purpose.
#
# Stream's source and tooling stay in the repository — the project may return,
# and other people may fork it — so `./build.sh fchub-stream` still works. What
# it must not do is happen by accident: a bare `./build.sh` used to copy the
# shared updater into Stream, run `npm ci` in both of its app directories, and
# emit a ZIP for a plugin nobody asked about. Keeping the entry out of
# ALL_PLUGINS is the whole fix — everything below iterates the selection, so the
# only way to reach Stream now is to name it.
ARCHIVED_PLUGINS=(
    "fchub-stream|fchub-stream.php"
)

# ── Helpers ──────────────────────────────────────────────────────────────────
#
# Progress, warnings and failures go to stderr. The lifecycle harness runs this
# script as `bash build.sh fchub >/dev/null`, and on stdout a lock wait ("waiting
# for another build") is invisible there — it reads as a hang of up to fifteen
# minutes — while the reason for a failed build was swallowed entirely. Only the
# per-artefact `success` lines and the summary table stay on stdout, so a quiet
# run stays quiet.
info()    { printf "${CYAN}▸${NC} %s\n" "$*" >&2; }
success() { printf "${GREEN}✓${NC} %s\n" "$*"; }
warn()    { printf "${YELLOW}⚠${NC} %s\n" "$*" >&2; }
error()   { printf "${RED}✗${NC} %s\n" "$*" >&2; exit 1; }

human_size() {
    local bytes=$1
    if   (( bytes >= 1048576 )); then printf "%.1f MB" "$(echo "scale=1; $bytes/1048576" | bc)"
    elif (( bytes >= 1024 ));    then printf "%.1f KB" "$(echo "scale=1; $bytes/1024" | bc)"
    else printf "%d B" "$bytes"
    fi
}

usage() {
    printf "${BOLD}Usage:${NC} ./build.sh [plugin-slug]\n\n"
    printf "Build distribution ZIPs for FCHub plugins, each with a SHA-256 sidecar.\n\n"
    printf "${BOLD}Arguments:${NC}\n"
    printf "  plugin-slug    Build only the specified plugin (optional)\n"
    printf "                 Valid slugs: fchub, fchub-p24, fchub-fakturownia, fchub-memberships, fchub-portal-extender, fchub-wishlist, fchub-multi-currency, cartshift\n"
    printf "                 Archived (built only when named): fchub-stream\n\n"
    printf "${BOLD}Examples:${NC}\n"
    printf "  ./build.sh                    Build all plugins except the archived ones\n"
    printf "  ./build.sh fchub-p24          Build only fchub-p24\n"
    printf "  ./build.sh fchub-memberships  Build only fchub-memberships (runs npm build)\n"
    printf "  ./build.sh fchub              Build only the FCHub product centre (runs npm build)\n\n"
    printf "${BOLD}Environment:${NC}\n"
    printf "  FCHUB_BUILD_LOCK_TIMEOUT  Seconds to wait for a concurrent build of the\n"
    printf "                            same plugin to finish (default: 900)\n"
    exit 0
}

# ── Build locks ──────────────────────────────────────────────────────────────
#
# Two builds of the same plugin in one checkout share a node_modules and an
# output path: `npm ci` in one process deletes the directory the other is
# reading from, and both write the same ZIP. The lifecycle harness runs
# `build.sh fchub` on every default run, so two of those overlapping is a
# nameable way to lose an afternoon.
#
# mkdir is the portable atomic test-and-set. flock is not on macOS, and this
# repository is written there and built on Linux.
LOCK_ROOT="$ROOT_DIR/.build-locks"
LOCK_TIMEOUT="${FCHUB_BUILD_LOCK_TIMEOUT:-900}"
BUILD_LOCK_DIR=""
BUILD_REAP_DIR=""
CURRENT_TMP_DIR=""

# The pid a lock claims to be held by, or nothing if it claims nothing legible.
#
# Only a well-formed pid is returned. A lock holding anything else was not
# written by this script, and "unreadable" is not the same as "dead": the caller
# waits it out and says which directory to remove, rather than guessing.
lock_holder() {
    local lock_dir="$1"
    local pid=""

    if [ -f "$lock_dir/pid" ]; then
        pid=$(cat "$lock_dir/pid" 2>/dev/null || true)
    fi

    case "$pid" in
        ''|*[!0-9]*) printf '' ;;
        *)           printf '%s' "$pid" ;;
    esac
}

# Clear a lock whose owner no longer exists.
#
# A lock whose owner is gone is not a lock, it is litter. Only a -9 or a power
# cut leaves one behind, and refusing to ever build again would be a worse
# outcome than the collision the lock prevents — but the clearing itself has to
# be atomic, or it becomes the bug:
#
#   B and C both read the dead holder's pid and both decide to reap. B clears
#   the lock, wins the mkdir, writes its pid and starts building. C — still
#   between its own check and its clear — then removes B's *live* lock, wins a
#   mkdir of its own, and starts building too. Two builds, one node_modules, one
#   ZIP, and neither process any the wiser.
#
# Renaming before deleting narrows that window but does not close it: once B has
# re-created the lock, C's `mv` finds a source again and takes B's live lock with
# it. What closes it is doing the check and the act under a second lock that is
# never itself reaped, and re-reading the pid inside it. In there the state
# cannot move: nobody else can be reaping, and nobody can acquire while the stale
# directory still stands. The `mv` is kept because it means another waiter never
# observes a half-deleted lock — a directory whose pid file has already gone.
#
# Worst case, a -9 inside the reap window leaks the reap directory and reaping
# stops working. Locking still works; a stale lock then waits out the timeout,
# which names the directory to remove. Degradation, not corruption.
#
# One known limit, harmless here: `kill -0` also fails with EPERM for a live
# process owned by a *different* user, so on a shared box a lock held by another
# account would be judged stale. Every runner this builds on is single-user.
reap_build_lock() {
    local slug="$1"
    local lock_dir="$2"
    local reap_dir="$LOCK_ROOT/$slug.reap"
    local holder=""
    local stale_dir=""

    mkdir "$reap_dir" 2>/dev/null || return 0
    BUILD_REAP_DIR="$reap_dir"

    holder=$(lock_holder "$lock_dir")

    if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
        stale_dir="$lock_dir.stale.$$"

        if mv "$lock_dir" "$stale_dir" 2>/dev/null; then
            warn "Removed stale $slug build lock left by process $holder"
            rm -rf "$stale_dir"
        fi
    fi

    BUILD_REAP_DIR=""
    rmdir "$reap_dir" 2>/dev/null || true
}

acquire_build_lock() {
    local slug="$1"
    local lock_dir="$LOCK_ROOT/$slug.lock"
    local waited=0
    local holder=""

    mkdir -p "$LOCK_ROOT"

    until mkdir "$lock_dir" 2>/dev/null; do
        holder=$(lock_holder "$lock_dir")

        if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
            reap_build_lock "$slug" "$lock_dir"
            continue
        fi

        if [ "$waited" -eq 0 ]; then
            info "Another build of $slug is running (process ${holder:-unknown}) — waiting ..."
        fi

        if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
            error "Timed out after ${LOCK_TIMEOUT}s waiting for $lock_dir"
        fi

        sleep 1
        waited=$((waited + 1))
    done

    echo "$$" > "$lock_dir/pid"
    BUILD_LOCK_DIR="$lock_dir"
}

release_build_lock() {
    if [ -n "$BUILD_LOCK_DIR" ]; then
        rm -rf "$BUILD_LOCK_DIR"
        BUILD_LOCK_DIR=""
    fi
}

# One trap for the working directory, the lock and the reap mutex, on every exit
# path. A per-iteration `trap rm -rf $tmp_dir EXIT` could only ever clean up the
# last one, and could not release a lock at all.
cleanup() {
    if [ -n "$CURRENT_TMP_DIR" ]; then
        rm -rf "$CURRENT_TMP_DIR"
        CURRENT_TMP_DIR=""
    fi

    if [ -n "$BUILD_REAP_DIR" ]; then
        rmdir "$BUILD_REAP_DIR" 2>/dev/null || true
        BUILD_REAP_DIR=""
    fi

    release_build_lock
}

trap cleanup EXIT
trap 'cleanup; exit 130' INT
trap 'cleanup; exit 143' TERM

# ── Checksums ────────────────────────────────────────────────────────────────
#
# The two-field layout sha256sum writes — digest, two spaces, the name of the
# file it describes — which is what FCHub's VerifiedPackageDownloader parses and
# what `sha256sum -c` expects after a download. Written from inside dist/ so the
# sidecar names the archive rather than somebody's home directory.
#
# macOS has no sha256sum. shasum -a 256 writes the same shape.
write_checksum() {
    local file="$1"
    local dir
    local name

    dir="$(dirname "$file")"
    name="$(basename "$file")"

    if command -v sha256sum >/dev/null 2>&1; then
        (cd "$dir" && sha256sum "$name" > "$name.sha256")
    elif command -v shasum >/dev/null 2>&1; then
        (cd "$dir" && shasum -a 256 "$name" > "$name.sha256")
    else
        error "neither sha256sum nor shasum is available — cannot write a checksum sidecar"
    fi
}

# ── Parse arguments ──────────────────────────────────────────────────────────
FILTER_SLUG=""

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    usage
fi

if [ -n "$1" ]; then
    FILTER_SLUG="$1"
    # Validate slug. Archived plugins are valid here and only here — naming one
    # is the deliberate act that makes building it acceptable.
    found=0
    for entry in "${ALL_PLUGINS[@]}" "${ARCHIVED_PLUGINS[@]}"; do
        IFS='|' read -r slug _ <<< "$entry"
        if [ "$slug" = "$FILTER_SLUG" ]; then
            found=1
            break
        fi
    done
    if [ "$found" -eq 0 ]; then
        error "Unknown plugin: $FILTER_SLUG"
    fi
fi

# ── Determine which plugins to build ─────────────────────────────────────────
PLUGINS=()
if [ -n "$FILTER_SLUG" ]; then
    for entry in "${ALL_PLUGINS[@]}" "${ARCHIVED_PLUGINS[@]}"; do
        IFS='|' read -r slug _ <<< "$entry"
        if [ "$slug" = "$FILTER_SLUG" ]; then
            PLUGINS+=("$entry")
            break
        fi
    done
else
    # ARCHIVED_PLUGINS is deliberately absent: a bare run builds what is
    # maintained, and nothing else.
    PLUGINS=("${ALL_PLUGINS[@]}")
fi

# ── Clean dist/ ─────────────────────────────────────────────────────────────
printf "\n${BOLD}Building FCHub plugin ZIPs${NC}\n"
printf "%s\n\n" "──────────────────────────────────────"

if [ -n "$FILTER_SLUG" ]; then
    info "Building: $FILTER_SLUG"
else
    info "Building all maintained plugins"
fi

# Sync the shared updater into the plugins actually being built.
#
# Iterating the selection rather than a glob is the point: `./build.sh fchub`
# used to copy a file into every plugin directory in the repository, including
# discontinued Stream, which nobody asked it to touch — and the lifecycle
# harness runs exactly that command on every default run.
info "Syncing GitHubUpdater into plugins ..."
for entry in "${PLUGINS[@]}"; do
    IFS='|' read -r sync_slug _ <<< "$entry"

    sync_dir="$PLUGINS_DIR/$sync_slug"

    if [ -d "$sync_dir" ]; then
        mkdir -p "$sync_dir/lib"
        cp "$ROOT_DIR/lib/GitHubUpdater.php" "$sync_dir/lib/GitHubUpdater.php"
    fi
done
success "GitHubUpdater synced"
echo ""

if [ -z "$FILTER_SLUG" ] && [ -d "$DIST_DIR" ]; then
    info "Cleaning previous dist/ ..."
    rm -rf "$DIST_DIR"
fi
mkdir -p "$DIST_DIR"

# ── Build each plugin ───────────────────────────────────────────────────────
declare -a BUILT_ZIPS=()

for entry in "${PLUGINS[@]}"; do
    IFS='|' read -r slug main_file <<< "$entry"
    plugin_dir="$PLUGINS_DIR/$slug"

    printf "${BOLD}── %s ${NC}\n" "$slug"

    # Verify plugin directory exists
    if [ ! -d "$plugin_dir" ]; then
        warn "Plugin directory not found: $plugin_dir — skipping"
        echo ""
        continue
    fi

    # Held for this plugin only, so two builds of different plugins still run
    # side by side.
    acquire_build_lock "$slug"

    # Read version from plugin header
    version=$(grep -i "^[[:space:]]*\*[[:space:]]*Version:" "$plugin_dir/$main_file" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
    if [ -z "$version" ]; then
        warn "Could not read version from $main_file — skipping"
        # Released here rather than at the bottom of the loop: skipping past the
        # release would hold this plugin's lock for the rest of the run and then
        # leak the directory, because the next acquire overwrites the handle.
        release_build_lock
        echo ""
        continue
    fi
    info "Version: $version"

    # Run npm build for portal-extender
    if [ "$slug" = "fchub-portal-extender" ]; then
        if [ -f "$plugin_dir/package.json" ]; then
            info "Running npm build for $slug ..."
            (cd "$plugin_dir" && npm ci --silent && npm run build --silent)
            if [ ! -d "$plugin_dir/assets/dist" ] || [ -z "$(ls -A "$plugin_dir/assets/dist" 2>/dev/null)" ]; then
                error "npm build failed — assets/dist/ is empty"
            fi
            success "npm build complete"
        fi
    fi

    # Run npm build for memberships
    if [ "$slug" = "fchub-memberships" ]; then
        if [ -f "$plugin_dir/package.json" ]; then
            info "Running npm build for $slug ..."
            (cd "$plugin_dir" && npm ci --silent && npm run build --silent)
            if [ ! -d "$plugin_dir/assets/dist" ] || [ -z "$(ls -A "$plugin_dir/assets/dist" 2>/dev/null)" ]; then
                error "npm build failed — assets/dist/ is empty"
            fi
            success "npm build complete"
        fi
    fi

    # Run npm build for fchub
    # Run npm build for fchub-stream (admin-app + portal-app)
    if [ "$slug" = "fchub-stream" ]; then
        if [ -d "$plugin_dir/admin-app" ]; then
            info "Running npm build for $slug/admin-app ..."
            (cd "$plugin_dir/admin-app" && npm ci --silent && npm run build --silent)
            if [ ! -d "$plugin_dir/admin/dist" ] || [ -z "$(ls -A "$plugin_dir/admin/dist" 2>/dev/null)" ]; then
                error "npm build failed — admin/dist/ is empty"
            fi
            success "admin-app build complete"
        fi
        if [ -d "$plugin_dir/portal-app" ]; then
            info "Running npm build for $slug/portal-app ..."
            (cd "$plugin_dir/portal-app" && npm ci --silent && npm run build --silent)
            if [ ! -d "$plugin_dir/portal-app/dist" ] || [ -z "$(ls -A "$plugin_dir/portal-app/dist" 2>/dev/null)" ]; then
                error "npm build failed — portal-app/dist/ is empty"
            fi
            success "portal-app build complete"
        fi
    fi

    # Temp working directory
    tmp_dir=$(mktemp -d)
    CURRENT_TMP_DIR="$tmp_dir"

    # Build rsync exclude args from .distignore
    exclude_args=()
    distignore="$plugin_dir/.distignore"
    if [ -f "$distignore" ]; then
        while IFS= read -r line || [ -n "$line" ]; do
            # Skip empty lines and comments
            [[ -z "$line" || "$line" =~ ^# ]] && continue
            exclude_args+=(--exclude="$line")
        done < "$distignore"
    else
        # Fallback excludes if no .distignore
        warn "No .distignore found, using defaults"
        exclude_args=(
            --exclude='node_modules/'
            --exclude='vendor/'
            --exclude='tests/'
            --exclude='docs/'
            --exclude='.phpunit.cache/'
            --exclude='.git/'
            --exclude='.gitignore'
            --exclude='.distignore'
            --exclude='phpunit.xml'
            --exclude='phpunit.xml.dist'
            --exclude='composer.json'
            --exclude='composer.lock'
            --exclude='package.json'
            --exclude='package-lock.json'
            --exclude='vite.config.js'
            --exclude='*.md'
            --exclude='.DS_Store'
            --exclude='Thumbs.db'
        )
    fi

    # rsync plugin files
    rsync -a "${exclude_args[@]}" "$plugin_dir/" "$tmp_dir/$slug/"

    # Create ZIP (from tmp_dir so the root inside the ZIP is the slug directory)
    zip_name="${slug}-${version}.zip"
    zip_path="$DIST_DIR/$zip_name"
    rm -f "$zip_path"
    (cd "$tmp_dir" && zip -qr "$zip_path" "$slug/")

    success "Created $zip_name"

    # Every release ships one. FCHub treats a missing sidecar as
    # `checksum_unavailable` and installs the package anyway — a concession to
    # releases published before sidecars existed, and one that stays a legacy
    # path only for as long as every new archive comes with its digest.
    rm -f "$zip_path.sha256"
    write_checksum "$zip_path"
    success "Created $zip_name.sha256"

    BUILT_ZIPS+=("$zip_path")

    # Cleanup temp
    rm -rf "$tmp_dir"
    CURRENT_TMP_DIR=""

    release_build_lock

    echo ""
done

# ── Summary ─────────────────────────────────────────────────────────────────
if [ ${#BUILT_ZIPS[@]} -eq 0 ]; then
    error "No plugins were built."
fi

printf "${BOLD}Build Summary${NC}\n"
printf "%s\n" "──────────────────────────────────────"
printf "%-35s %10s  %-34s  %s\n" "File" "Size" "MD5" "Files"
printf "%s\n" "──────────────────────────────────────────────────────────────────────────────────────────────────"

for zip_path in "${BUILT_ZIPS[@]}"; do
    fname=$(basename "$zip_path")
    fsize=$(stat -f%z "$zip_path" 2>/dev/null || stat --printf="%s" "$zip_path")
    fsize_h=$(human_size "$fsize")
    md5=$(md5 -q "$zip_path" 2>/dev/null || md5sum "$zip_path" | awk '{print $1}')
    file_count=$(zipinfo -t "$zip_path" 2>/dev/null | grep -o '[0-9]* files' | awk '{print $1}')
    printf "%-35s %10s  %s  %s files\n" "$fname" "$fsize_h" "$md5" "$file_count"
done

printf "\n${GREEN}${BOLD}Done!${NC} ZIPs are in ${CYAN}dist/${NC}\n\n"
