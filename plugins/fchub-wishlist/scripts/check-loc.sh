#!/usr/bin/env bash
#
# LOC Budget Enforcer for fchub-wishlist
# Checks that files stay within their LOC budgets.
#
# Usage: ./scripts/check-loc.sh
# Exit 0 = all clear, Exit 1 = violations found.

set -euo pipefail

# Resolve plugin root relative to this script
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Colours (disabled if not a terminal)
if [[ -t 1 ]]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[0;33m'
    BOLD='\033[1m'
    RESET='\033[0m'
else
    RED='' GREEN='' YELLOW='' BOLD='' RESET=''
fi

# Counters
violations=0
checked=0

# Check a single file against its budget
check_file() {
    local file="$1"
    local max="$2"
    local relative="${file#"$PLUGIN_DIR"/}"

    # Skip index.php stub files (just <?php // Silence is golden)
    local loc
    loc=$(wc -l < "$file")

    if [[ $loc -le 2 ]]; then
        return
    fi

    checked=$((checked + 1))

    if [[ $loc -gt $max ]]; then
        violations=$((violations + 1))
        printf "${RED}  FAIL${RESET}  %-60s %4d / %d\n" "$relative" "$loc" "$max"
    else
        printf "${GREEN}  OK${RESET}    %-60s %4d / %d\n" "$relative" "$loc" "$max"
    fi
}

# Recursively scan a directory for files with given extension
scan_dir() {
    local dir="$1"
    local ext="$2"
    local max="$3"

    local full_dir="$PLUGIN_DIR/$dir"
    if [[ ! -d "$full_dir" ]]; then
        return
    fi

    while IFS= read -r -d '' file; do
        check_file "$file" "$max"
    done < <(find "$full_dir" -name "*.$ext" -type f -print0 | sort -z)
}

echo ""
printf "${BOLD}LOC Budget Check${RESET} — fchub-wishlist\n"
echo "================================================"
echo ""

# --- Bootstrap / module files: max 120 LOC ---
printf "${YELLOW}Bootstrap & Modules${RESET} (max 120 LOC)\n"
scan_dir "app/Bootstrap" "php" 120
echo ""

# --- Controllers: max 140 LOC ---
printf "${YELLOW}Controllers${RESET} (max 140 LOC)\n"
scan_dir "app/Http/Controllers" "php" 140
echo ""

# --- Domain services / actions: max 160 LOC ---
printf "${YELLOW}Domain Services & Actions${RESET} (max 160 LOC)\n"
scan_dir "app/Domain" "php" 160
echo ""

# --- Storage / repositories: max 220 LOC ---
printf "${YELLOW}Storage & Repositories${RESET} (max 220 LOC)\n"
scan_dir "app/Storage" "php" 220
echo ""

# --- Frontend JS modules: max 150 LOC ---
printf "${YELLOW}Frontend JS Modules${RESET} (max 150 LOC)\n"
scan_dir "resources/js" "js" 150
echo ""

# --- View templates: max 120 LOC ---
printf "${YELLOW}View Templates${RESET} (max 120 LOC)\n"
scan_dir "views" "php" 120
echo ""

# --- Summary ---
echo "================================================"
if [[ $violations -gt 0 ]]; then
    printf "${RED}${BOLD}FAILED${RESET}: %d file(s) over budget out of %d checked.\n" "$violations" "$checked"
    echo ""
    exit 1
else
    printf "${GREEN}${BOLD}PASSED${RESET}: All %d file(s) within budget.\n" "$checked"
    echo ""
    exit 0
fi
