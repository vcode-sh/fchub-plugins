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
    "fchub-stream|fchub-stream.php"
    "fchub-multi-currency|fchub-multi-currency.php"
    "wc-fc|wc-fc.php"
)

# ── Helpers ──────────────────────────────────────────────────────────────────
info()    { printf "${CYAN}▸${NC} %s\n" "$*"; }
success() { printf "${GREEN}✓${NC} %s\n" "$*"; }
warn()    { printf "${YELLOW}⚠${NC} %s\n" "$*"; }
error()   { printf "${RED}✗${NC} %s\n" "$*"; exit 1; }

human_size() {
    local bytes=$1
    if   (( bytes >= 1048576 )); then printf "%.1f MB" "$(echo "scale=1; $bytes/1048576" | bc)"
    elif (( bytes >= 1024 ));    then printf "%.1f KB" "$(echo "scale=1; $bytes/1024" | bc)"
    else printf "%d B" "$bytes"
    fi
}

usage() {
    printf "${BOLD}Usage:${NC} ./build.sh [plugin-slug]\n\n"
    printf "Build distribution ZIPs for FCHub plugins.\n\n"
    printf "${BOLD}Arguments:${NC}\n"
    printf "  plugin-slug    Build only the specified plugin (optional)\n"
    printf "                 Valid slugs: fchub-p24, fchub-fakturownia, fchub-memberships, fchub-portal-extender, fchub-wishlist, fchub-stream, fchub-multi-currency, wc-fc\n\n"
    printf "${BOLD}Examples:${NC}\n"
    printf "  ./build.sh                    Build all plugins\n"
    printf "  ./build.sh fchub-p24          Build only fchub-p24\n"
    printf "  ./build.sh fchub-memberships  Build only fchub-memberships (runs npm build)\n"
    exit 0
}

# ── Parse arguments ──────────────────────────────────────────────────────────
FILTER_SLUG=""

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    usage
fi

if [ -n "$1" ]; then
    FILTER_SLUG="$1"
    # Validate slug
    found=0
    for entry in "${ALL_PLUGINS[@]}"; do
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
    for entry in "${ALL_PLUGINS[@]}"; do
        IFS='|' read -r slug _ <<< "$entry"
        if [ "$slug" = "$FILTER_SLUG" ]; then
            PLUGINS+=("$entry")
            break
        fi
    done
else
    PLUGINS=("${ALL_PLUGINS[@]}")
fi

# ── Clean dist/ ─────────────────────────────────────────────────────────────
printf "\n${BOLD}Building FCHub plugin ZIPs${NC}\n"
printf "%s\n\n" "──────────────────────────────────────"

if [ -n "$FILTER_SLUG" ]; then
    info "Building: $FILTER_SLUG"
else
    info "Building all plugins"
fi

# Sync shared updater into all plugins
info "Syncing GitHubUpdater into plugins ..."
for dir in "$PLUGINS_DIR"/fchub-* "$PLUGINS_DIR"/wc-fc; do
    if [ -d "$dir" ]; then
        mkdir -p "$dir/lib"
        cp "$ROOT_DIR/lib/GitHubUpdater.php" "$dir/lib/GitHubUpdater.php"
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

    # Read version from plugin header
    version=$(grep -i "^[[:space:]]*\*[[:space:]]*Version:" "$plugin_dir/$main_file" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
    if [ -z "$version" ]; then
        warn "Could not read version from $main_file — skipping"
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
    trap "rm -rf '$tmp_dir'" EXIT

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

    BUILT_ZIPS+=("$zip_path")

    # Cleanup temp
    rm -rf "$tmp_dir"

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
