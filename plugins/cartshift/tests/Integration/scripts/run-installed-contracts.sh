#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
plugin_root="$(cd "${script_dir}/../../.." && pwd -P)"
state_file="$(mktemp "${TMPDIR:-/tmp}/cartshift-contract-state.XXXXXX")"
chmod 0600 "$state_file"

cleanup() {
  local command_status=$?
  local cleanup_status=0

  if [ -s "$state_file" ]; then
    if [ -n "${CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR:-}" ]; then
      # shellcheck disable=SC1090
      . "$state_file"
      if [ "${CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR#/}" = "$CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR" ]; then
        printf 'Retained evidence destination must be absolute.\n' >&2
        cleanup_status=1
      elif [ -e "$CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR" ]; then
        printf 'Retained evidence destination already exists.\n' >&2
        cleanup_status=1
      elif ! mkdir -m 0700 "$CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR" \
        || ! cp -R "$CARTSHIFT_EVIDENCE_DIR"/. "$CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR"/ \
        || ! chmod -R go-rwx "$CARTSHIFT_CONTRACT_RETAIN_EVIDENCE_DIR"; then
        printf 'Retained evidence could not be copied completely.\n' >&2
        cleanup_status=1
      fi
    fi
    if ! "${script_dir}/destroy-disposable-stack.sh" "$state_file"; then
      cleanup_status=1
    fi
  else
    rm -f -- "$state_file" || cleanup_status=1
  fi

  trap - EXIT INT TERM
  if [ "$command_status" -ne 0 ]; then
    return "$command_status"
  fi
  return "$cleanup_status"
}
trap cleanup EXIT INT TERM

export CARTSHIFT_CONTRACT_STATE_FILE="$state_file"
"${script_dir}/create-disposable-stack.sh"

# shellcheck disable=SC1090
. "$state_file"
export CARTSHIFT_INTEGRATION_PROJECT CARTSHIFT_INTEGRATION_COMPOSE_FILE

cd "$plugin_root"
./vendor/bin/phpunit --do-not-cache-result --testsuite integration "$@"

contract_wp() {
  docker compose --project-name "$CARTSHIFT_INTEGRATION_PROJECT" \
    --file "$CARTSHIFT_INTEGRATION_COMPOSE_FILE" exec -T wpcli wp --allow-root "$@"
}

contract_wp cartshift transfer compatibility --role=source --format=json \
  > "$CARTSHIFT_EVIDENCE_DIR/compatibility-source-final.json"
contract_wp cartshift transfer compatibility --role=target --format=json \
  > "$CARTSHIFT_EVIDENCE_DIR/compatibility-target-final.json"
contract_wp plugin list --status=active --fields=name,version --format=json \
  > "$CARTSHIFT_EVIDENCE_DIR/active-plugins-final.json"
chmod 0600 "$CARTSHIFT_EVIDENCE_DIR"/*

# shellcheck disable=SC2016
php -r '
$path = $argv[1];
$spies = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$expected = ["action_scheduler", "events", "http", "mail", "payment", "stock"];
sort($expected);
$actual = array_keys($spies);
sort($actual);
if ($actual !== $expected || array_filter($spies, static fn ($count): bool => !is_int($count) || $count !== 0) !== []) {
    fwrite(STDERR, "Installed-contract side-effect evidence is not silent.\n");
    exit(1);
}
echo "Installed-contract cumulative side-effect evidence: zero calls.\n";
$directory = dirname($path);
$files = [];
foreach (glob($directory . "/*") ?: [] as $evidenceFile) {
    if (!is_file($evidenceFile) || basename($evidenceFile) === "evidence-manifest.json") {
        continue;
    }
    $files[basename($evidenceFile)] = [
        "sha256" => hash_file("sha256", $evidenceFile),
        "bytes" => filesize($evidenceFile),
    ];
}
ksort($files, SORT_STRING);
file_put_contents(
    $directory . "/evidence-manifest.json",
    json_encode(["version" => 1, "files" => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
chmod($directory . "/evidence-manifest.json", 0600);
' "$CARTSHIFT_EVIDENCE_DIR/spies.json"
