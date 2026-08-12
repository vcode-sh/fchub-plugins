#!/usr/bin/env bash

set -euo pipefail

state_file="${1:-}"
[ -f "$state_file" ] || { printf 'A generated state file is required.\n' >&2; exit 1; }

# shellcheck disable=SC1090
. "$state_file"

[[ "$CARTSHIFT_INTEGRATION_PROJECT" =~ ^cartshift-contract-[a-z0-9-]+$ ]] \
  || { printf 'Refusing an invalid Compose project.\n' >&2; exit 1; }
[[ "$CARTSHIFT_CONTRACT_TEMP_ROOT" == "${TMPDIR:-/tmp}"/cartshift-contract.* ]] \
  || { printf 'Refusing an unexpected temporary root.\n' >&2; exit 1; }
[ -f "${CARTSHIFT_CONTRACT_TEMP_ROOT}/.cartshift-contract-project" ] \
  || { printf 'Generated project marker is missing.\n' >&2; exit 1; }
[ "$(cat "${CARTSHIFT_CONTRACT_TEMP_ROOT}/.cartshift-contract-project")" = "$CARTSHIFT_INTEGRATION_PROJECT" ] \
  || { printf 'Generated project marker does not match.\n' >&2; exit 1; }

export CARTSHIFT_EVIDENCE_DIR CARTSHIFT_SOURCE_DIR CARTSHIFT_ARTIFACT_DIR
docker compose \
  --project-name "$CARTSHIFT_INTEGRATION_PROJECT" \
  --file "$CARTSHIFT_INTEGRATION_COMPOSE_FILE" \
  down --volumes --remove-orphans

rm -rf -- "$CARTSHIFT_CONTRACT_TEMP_ROOT"
rm -f -- "$state_file"
