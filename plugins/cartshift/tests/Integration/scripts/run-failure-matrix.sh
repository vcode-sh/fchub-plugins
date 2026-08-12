#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
plugin_root="$(cd "${script_dir}/../../.." && pwd -P)"

cd "$plugin_root"

matrix_digest="$({
  find tests/Integration/Failure -type f -name '*.php' -print0
  printf '%s\0' tests/Integration/Contract/runtime-contract.php
  printf '%s\0' tests/Unit/Domain/Transfer/Execution/LoadedTargetSettingsInspectorTest.php
  printf '%s\0' tests/Unit/Domain/Transfer/Order/FluentCartOrderWriterTest.php
} | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | awk '{print $1}')"

printf 'CartShift Task 25 failure-matrix source SHA-256: %s\n' "$matrix_digest"
"${script_dir}/run-installed-contracts.sh" \
  tests/Integration/Failure \
  tests/Unit/Domain/Transfer/Execution/LoadedTargetSettingsInspectorTest.php \
  tests/Unit/Domain/Transfer/Order/FluentCartOrderWriterTest.php
