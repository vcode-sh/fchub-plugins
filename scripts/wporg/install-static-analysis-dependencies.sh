#!/usr/bin/env bash

set -Eeuo pipefail

repository="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
plugin_slug="${1:-}"
dependency_root="${FCHUB_STATIC_ANALYSIS_PLUGIN_ROOT:-${repository}/../fchub-playground/wp-content/plugins}"
download_root="$(mktemp -d)"

cleanup() {
    rm -rf -- "$download_root"
}
trap cleanup EXIT

verify_checksum() {
    local archive="$1"
    local expected="$2"
    local actual

    if command -v sha256sum >/dev/null 2>&1; then
        actual="$(sha256sum "$archive" | awk '{print $1}')"
    else
        actual="$(shasum -a 256 "$archive" | awk '{print $1}')"
    fi

    if [ "$actual" != "$expected" ]; then
        printf 'Checksum mismatch for %s: expected %s, received %s\n' "$archive" "$expected" "$actual" >&2
        exit 1
    fi
}

install_dependency() {
    local slug="$1"
    local version="$2"
    local checksum="$3"
    local archive="${download_root}/${slug}.${version}.zip"
    local entrypoint="${dependency_root}/${slug}/${slug}.php"

    curl --proto '=https' --tlsv1.2 --fail --location --retry 3 --retry-all-errors \
        --silent --show-error \
        "https://downloads.wordpress.org/plugin/${slug}.${version}.zip" \
        --output "$archive"

    verify_checksum "$archive" "$checksum"
    unzip -q "$archive" -d "$dependency_root"

    if [ ! -f "$entrypoint" ]; then
        printf 'Static-analysis dependency entrypoint is missing: %s\n' "$entrypoint" >&2
        exit 1
    fi

    printf 'Installed %s %s for static analysis.\n' "$slug" "$version"
}

case "$plugin_slug" in
    fchub-p24|fchub-fakturownia|fchub-multi-currency|fchub-wishlist) ;;
    *)
        printf 'Unsupported static-analysis target: %s\n' "$plugin_slug" >&2
        exit 2
        ;;
esac

mkdir -p "$dependency_root"

install_dependency \
    fluent-cart \
    1.6.0 \
    1c8463feee35527cdd344aa87558afa8cd0e9de07e1a5e14f4e1e8984784e6b4

case "$plugin_slug" in
    fchub-multi-currency|fchub-wishlist)
        install_dependency \
            fluent-crm \
            3.1.10 \
            d38ccfaf9f59cc8b40f964c4b706de1c779347f305b7ade32943681f9aec99b2
        ;;
esac
