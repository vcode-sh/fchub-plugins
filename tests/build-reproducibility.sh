#!/usr/bin/env bash

set -euo pipefail
umask 077

fail() { printf 'CartShift reproducibility gate failed: %s\n' "$1" >&2; exit 1; }

digest_file() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
    else shasum -a 256 "$1" | awk '{print $1}'; fi
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
source_root="$(cd "$script_dir/.." && pwd -P)"

for command in rsync zip unzip npm jq; do
    command -v "$command" >/dev/null 2>&1 || fail "${command} is unavailable"
done

epoch="${SOURCE_DATE_EPOCH:-}"
if [ -z "$epoch" ]; then
    epoch="$(git -C "$source_root" log -1 --format=%ct 2>/dev/null || true)"
fi
[[ "$epoch" =~ ^[0-9]+$ ]] && [ "$epoch" -ge 315532800 ] \
    || fail 'SOURCE_DATE_EPOCH is unavailable or predates the ZIP timestamp range'

version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$source_root/plugins/cartshift/cartshift.php" | head -1 | tr -d '[:space:]')"
constant="$(sed -n "s/.*define('CARTSHIFT_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" "$source_root/plugins/cartshift/cartshift.php" | head -1)"
manifest_version="$(jq -r '.plugins.cartshift.version' "$source_root/web-docs/lib/versions.json")"
filename="$(jq -r '.plugins.cartshift.zipFilename' "$source_root/web-docs/lib/versions.json")"
[ -n "$version" ] && [ "$version" = "$constant" ] && [ "$version" = "$manifest_version" ] \
    || fail 'plugin header, version constant, and versions.json disagree'
[ "$filename" = "cartshift-${version}.zip" ] || fail 'versions.json ZIP filename disagrees with the candidate version'

root_one="$(mktemp -d "${TMPDIR:-/tmp}/cartshift-repro-one.XXXXXX")"
root_two="$(mktemp -d "${TMPDIR:-/tmp}/cartshift-repro-two.XXXXXX")"
chmod 0700 "$root_one" "$root_two"

cleanup() {
    for root in "$root_one" "$root_two"; do
        case "$root" in
            "${TMPDIR:-/tmp}"/cartshift-repro-one.*|"${TMPDIR:-/tmp}"/cartshift-repro-two.*)
                [ -d "$root" ] && [ ! -L "$root" ] && rm -rf -- "$root"
                ;;
            *) fail 'temporary reproducibility root lost its generated identity' ;;
        esac
    done
}
trap cleanup EXIT INT TERM

copy_source() {
    local destination="$1"
    rsync -a \
        --exclude='.git/' --exclude='.build-locks/' --exclude='dist/' \
        --exclude='node_modules/' --exclude='vendor/' --exclude='.phpunit.cache/' \
        "$source_root/" "$destination/repository/"
}

build_copy() {
    local root="$1"
    copy_source "$root"
    (
        cd "$root/repository"
        SOURCE_DATE_EPOCH="$epoch" FCHUB_BUILD_LOCK_TIMEOUT=30 ./build.sh cartshift >/dev/null
    )
    local archive="$root/repository/dist/$filename"
    [ -f "$archive" ] && [ ! -L "$archive" ] || fail 'a fresh build produced no candidate ZIP'
    [ -f "$archive.sha256" ] || fail 'a fresh build produced no checksum sidecar'
    expected_line="$(digest_file "$archive")  $filename"
    [ "$(cat "$archive.sha256")" = "$expected_line" ] || fail 'checksum sidecar does not bind the candidate filename and bytes'
    printf '%s\n' "$archive"
}

archive_one="$(build_copy "$root_one")"
archive_two="$(build_copy "$root_two")"
hash_one="$(digest_file "$archive_one")"
hash_two="$(digest_file "$archive_two")"
[ "$hash_one" = "$hash_two" ] || fail "fresh builds differ: ${hash_one} != ${hash_two}"

inventory_one="$root_one/inventory.txt"
inventory_two="$root_two/inventory.txt"
unzip -Z1 "$archive_one" > "$inventory_one"
unzip -Z1 "$archive_two" > "$inventory_two"
cmp -s "$inventory_one" "$inventory_two" || fail 'fresh archive inventories differ'
[ -s "$inventory_one" ] || fail 'candidate archive is empty'
head -1 "$inventory_one" | grep -Fx 'cartshift/' >/dev/null || fail 'candidate ZIP has the wrong root directory'

if LC_ALL=C grep -E '/(tests?|fixtures?|evidence|packages?|backups?|node_modules|vendor|\.git|\.phpunit\.cache)(/|$)|(^|/)(\.env|composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml(\.dist)?|vite\.config\.js|docker-compose[^/]*)$' "$inventory_one" >/dev/null; then
    fail 'candidate ZIP contains development, evidence, backup, dependency, or credential-prone files'
fi

unpack="$root_one/unpacked"
mkdir -m 0700 "$unpack"
unzip -q "$archive_one" -d "$unpack"
[ -f "$unpack/cartshift/cartshift.php" ] || fail 'candidate ZIP is not a clean-installable WordPress plugin tree'
[ -f "$unpack/cartshift/resources/admin/dist/.vite/manifest.json" ] || fail 'candidate ZIP is missing the built admin manifest'
php -l "$unpack/cartshift/cartshift.php" >/dev/null || fail 'candidate plugin entrypoint is not valid PHP'

printf 'CartShift reproducibility gate passed: %s  %s\n' "$hash_one" "$filename"
