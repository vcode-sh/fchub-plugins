#!/usr/bin/env bash
set -e

# Sync the shared GitHubUpdater into every plugin's lib/ directory.
# Run this before tagging releases or building distribution ZIPs.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SOURCE="$ROOT_DIR/lib/GitHubUpdater.php"

if [ ! -f "$SOURCE" ]; then
    echo "Error: $SOURCE not found" >&2
    exit 1
fi

for dir in "$ROOT_DIR"/plugins/fchub-* "$ROOT_DIR"/plugins/wc-fc; do
    if [ -d "$dir" ]; then
        mkdir -p "$dir/lib"
        cp "$SOURCE" "$dir/lib/GitHubUpdater.php"
        echo "Synced → $(basename "$dir")/lib/GitHubUpdater.php"
    fi
done

echo "Done."
