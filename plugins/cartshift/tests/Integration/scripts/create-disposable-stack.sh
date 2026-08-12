#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
plugin_root="$(cd "${script_dir}/../../.." && pwd -P)"
compose_file="${plugin_root}/docker-compose.integration.yml"
state_file="${CARTSHIFT_CONTRACT_STATE_FILE:-}"

fail() {
  printf 'CartShift installed-contract setup failed: %s\n' "$1" >&2
  exit 1
}

digest_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

copy_verified_artifact() {
  local source_path="$1"
  local expected_sha="$2"
  local destination_name="$3"

  [ -f "$source_path" ] || fail "missing ${destination_name} artifact"
  [[ "$expected_sha" =~ ^[a-f0-9]{64}$ ]] || fail "invalid ${destination_name} SHA-256"

  local actual_sha
  actual_sha="$(digest_file "$source_path")"
  [ "$actual_sha" = "$expected_sha" ] || fail "${destination_name} SHA-256 mismatch"

  cp "$source_path" "${artifact_dir}/${destination_name}"
  chmod 0600 "${artifact_dir}/${destination_name}"
}

[ -n "$state_file" ] || fail 'CARTSHIFT_CONTRACT_STATE_FILE is required'
[ -f "$compose_file" ] || fail 'docker-compose.integration.yml is missing'
command -v docker >/dev/null 2>&1 || fail 'docker is unavailable'

for name in \
  CARTSHIFT_CANDIDATE_ZIP CARTSHIFT_CANDIDATE_SHA256 \
  CARTSHIFT_WOO_ZIP CARTSHIFT_WOO_SHA256 \
  CARTSHIFT_WCS_ZIP CARTSHIFT_WCS_SHA256 \
  CARTSHIFT_FLUENTCART_ZIP CARTSHIFT_FLUENTCART_SHA256; do
  [ -n "${!name:-}" ] || fail "${name} is required"
done

temp_root="$(mktemp -d "${TMPDIR:-/tmp}/cartshift-contract.XXXXXX")"
chmod 0700 "$temp_root"
project="cartshift-contract-$(date -u +%Y%m%d%H%M%S)-$$"
artifact_dir="${temp_root}/artifacts"
evidence_dir="${temp_root}/evidence"
mkdir -m 0700 "$artifact_dir" "$evidence_dir"
printf '%s\n' "$project" > "${temp_root}/.cartshift-contract-project"
chmod 0600 "${temp_root}/.cartshift-contract-project"

copy_verified_artifact "$CARTSHIFT_CANDIDATE_ZIP" "$CARTSHIFT_CANDIDATE_SHA256" 'cartshift-candidate.zip'
copy_verified_artifact "$CARTSHIFT_WOO_ZIP" "$CARTSHIFT_WOO_SHA256" 'woocommerce.zip'
copy_verified_artifact "$CARTSHIFT_FLUENTCART_ZIP" "$CARTSHIFT_FLUENTCART_SHA256" 'fluent-cart.zip'
copy_verified_artifact "$CARTSHIFT_WCS_ZIP" "$CARTSHIFT_WCS_SHA256" 'woocommerce-subscriptions.zip'

if [ -n "${CARTSHIFT_WORDPRESS_CORE_ARCHIVE:-}" ] || [ -n "${CARTSHIFT_WORDPRESS_CORE_SHA256:-}" ]; then
  [ -n "${CARTSHIFT_WORDPRESS_CORE_ARCHIVE:-}" ] && [ -n "${CARTSHIFT_WORDPRESS_CORE_SHA256:-}" ] \
    || fail 'both WordPress core artifact variables are required together'
  copy_verified_artifact "$CARTSHIFT_WORDPRESS_CORE_ARCHIVE" "$CARTSHIFT_WORDPRESS_CORE_SHA256" 'wordpress-core.tar.gz'
fi

if [ -n "${CARTSHIFT_FLUENTCART_PRO_ZIP:-}" ] || [ -n "${CARTSHIFT_FLUENTCART_PRO_SHA256:-}" ]; then
  [ -n "${CARTSHIFT_FLUENTCART_PRO_ZIP:-}" ] && [ -n "${CARTSHIFT_FLUENTCART_PRO_SHA256:-}" ] \
    || fail 'both FluentCart Pro artifact variables are required together'
  copy_verified_artifact "$CARTSHIFT_FLUENTCART_PRO_ZIP" "$CARTSHIFT_FLUENTCART_PRO_SHA256" 'fluent-cart-pro.zip'
fi

if [ -n "${CARTSHIFT_FAKTUROWNIA_ZIP:-}" ] || [ -n "${CARTSHIFT_FAKTUROWNIA_SHA256:-}" ]; then
  [ -n "${CARTSHIFT_FAKTUROWNIA_ZIP:-}" ] && [ -n "${CARTSHIFT_FAKTUROWNIA_SHA256:-}" ] \
    || fail 'both CARTSHIFT_FAKTUROWNIA_ZIP and CARTSHIFT_FAKTUROWNIA_SHA256 are required together'
  copy_verified_artifact "$CARTSHIFT_FAKTUROWNIA_ZIP" "$CARTSHIFT_FAKTUROWNIA_SHA256" 'fchub-fakturownia.zip'
fi

export CARTSHIFT_SOURCE_DIR="$plugin_root"
export CARTSHIFT_ARTIFACT_DIR="$artifact_dir"
export CARTSHIFT_EVIDENCE_DIR="$evidence_dir"

# Persist cleanup identity before the first Docker mutation. The wrapper's trap
# can then remove a half-created project when image boot, WordPress setup, plugin
# activation, or a version assertion fails.
{
  printf 'CARTSHIFT_INTEGRATION_PROJECT=%q\n' "$project"
  printf 'CARTSHIFT_INTEGRATION_COMPOSE_FILE=%q\n' "$compose_file"
  printf 'CARTSHIFT_CONTRACT_TEMP_ROOT=%q\n' "$temp_root"
  printf 'CARTSHIFT_EVIDENCE_DIR=%q\n' "$evidence_dir"
  printf 'CARTSHIFT_SOURCE_DIR=%q\n' "$plugin_root"
  printf 'CARTSHIFT_ARTIFACT_DIR=%q\n' "$artifact_dir"
} > "$state_file"
chmod 0600 "$state_file"

docker compose --project-name "$project" --file "$compose_file" up -d db wordpress wpcli

ready=0
for _ in $(seq 1 90); do
  if docker compose --project-name "$project" --file "$compose_file" exec -T wpcli \
    test -f /var/www/html/wp-config.php >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || fail 'WordPress files did not become ready'

if [ -f "${artifact_dir}/wordpress-core.tar.gz" ]; then
  docker compose --project-name "$project" --file "$compose_file" exec -T wordpress \
    tar -xzf /cartshift-artifacts/wordpress-core.tar.gz -C /var/www/html
fi

wp() {
  docker compose --project-name "$project" --file "$compose_file" exec -T wpcli wp --allow-root "$@"
}

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install \
    --url='http://wordpress.invalid' \
    --title='CartShift Installed Contract' \
    --admin_user='contract-operator' \
    --admin_password='contract-only-not-exposed' \
    --admin_email='contract@example.invalid' \
    --skip-email >/dev/null
fi

docker compose --project-name "$project" --file "$compose_file" exec -T wpcli \
  mkdir -p /var/www/html/wp-content/mu-plugins
docker compose --project-name "$project" --file "$compose_file" exec -T wpcli \
  cp /cartshift-source/tests/Integration/fixtures/contract-spies.php \
  /var/www/html/wp-content/mu-plugins/cartshift-contract-spies.php

wp plugin install /cartshift-artifacts/woocommerce.zip --force --activate >/dev/null
wp plugin install /cartshift-artifacts/fluent-cart.zip --force --activate >/dev/null

if [ -f "${artifact_dir}/fluent-cart-pro.zip" ]; then
  wp plugin install /cartshift-artifacts/fluent-cart-pro.zip --force --activate >/dev/null
fi

wp plugin install /cartshift-artifacts/woocommerce-subscriptions.zip --force --activate >/dev/null

if [ -f "${artifact_dir}/fchub-fakturownia.zip" ]; then
  wp plugin install /cartshift-artifacts/fchub-fakturownia.zip --force --activate >/dev/null
fi

wp plugin install /cartshift-artifacts/cartshift-candidate.zip --force --activate >/dev/null

# Plugin activation may legitimately schedule its own housekeeping. The contract
# evidence boundary begins only after the exact candidate and vendors are active.
printf '%s\n' '{"action_scheduler":0,"events":0,"http":0,"mail":0,"payment":0,"stock":0}' \
  > "${evidence_dir}/spies.json"

wp core version > "${evidence_dir}/wordpress-version.txt"
wp eval 'echo PHP_VERSION;' > "${evidence_dir}/php-version.txt"
wp plugin list --status=active --fields=name,version --format=json > "${evidence_dir}/active-plugins.json"
docker compose --project-name "$project" --file "$compose_file" exec -T db \
  mariadb --batch --skip-column-names -ucartshift_contract -pcartshift_contract \
  -e 'SELECT VERSION()' cartshift_contract > "${evidence_dir}/mariadb-version.txt"
docker image inspect "${CARTSHIFT_WORDPRESS_IMAGE:-wordpress@sha256:ffef0dca1f0fc4357bfef3856ebd1ba18f7b394378277122eaa4524ca2619d43}" \
  --format '{{json .RepoDigests}}' > "${evidence_dir}/wordpress-image.json"

set +e
wp cartshift transfer compatibility --role=source --format=json \
  > "${evidence_dir}/compatibility-source.json" 2> "${evidence_dir}/compatibility-source.stderr"
source_compatibility_status=$?
wp cartshift transfer compatibility --role=target --format=json \
  > "${evidence_dir}/compatibility-target.json" 2> "${evidence_dir}/compatibility-target.stderr"
target_compatibility_status=$?
set -e
printf '%s\n' "$source_compatibility_status" > "${evidence_dir}/compatibility-source.exit-code"
printf '%s\n' "$target_compatibility_status" > "${evidence_dir}/compatibility-target.exit-code"

for artifact in "${artifact_dir}"/*; do
  printf '%s  %s\n' "$(digest_file "$artifact")" "$(basename "$artifact")"
done | LC_ALL=C sort > "${evidence_dir}/artifact-sha256.txt"
printf '%s\n' "$(digest_file "${evidence_dir}/active-plugins.json")" \
  > "${evidence_dir}/active-plugins.sha256"
chmod 0600 "${evidence_dir}"/*

actual_wp="$(tr -d '\r\n' < "${evidence_dir}/wordpress-version.txt")"
actual_php="$(tr -d '\r\n' < "${evidence_dir}/php-version.txt")"
actual_woo="$(wp plugin get woocommerce --field=version | tr -d '\r\n')"
actual_fc="$(wp plugin get fluent-cart --field=version | tr -d '\r\n')"
actual_wcs="$(wp plugin get woocommerce-subscriptions --field=version | tr -d '\r\n')"
actual_cartshift="$(wp plugin get cartshift --field=version | tr -d '\r\n')"

[ "$actual_wp" = "${CARTSHIFT_EXPECT_WORDPRESS_VERSION:-7.0.3}" ] || fail "unexpected WordPress version ${actual_wp}"
[[ "$actual_php" == 8.3.* ]] || fail "unexpected WordPress PHP version ${actual_php}"
[ "$actual_woo" = "${CARTSHIFT_EXPECT_WOO_VERSION:-11.0.0}" ] || fail "unexpected WooCommerce version ${actual_woo}"
[ "$actual_fc" = "${CARTSHIFT_EXPECT_FLUENTCART_VERSION:-1.6.0}" ] || fail "unexpected FluentCart version ${actual_fc}"
[ "$actual_wcs" = "${CARTSHIFT_EXPECT_WCS_VERSION:-8.7.1}" ] || fail "unexpected WooCommerce Subscriptions version ${actual_wcs}"
[ "$actual_cartshift" = "${CARTSHIFT_EXPECT_CANDIDATE_VERSION:?CARTSHIFT_EXPECT_CANDIDATE_VERSION is required}" ] \
  || fail "unexpected CartShift candidate version ${actual_cartshift}"
printf '%s\n' "$CARTSHIFT_CANDIDATE_SHA256" > "${evidence_dir}/cartshift-candidate.sha256"

printf 'Disposable project: %s\nEvidence directory: %s\n' "$project" "$evidence_dir"
