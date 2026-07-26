#!/usr/bin/env bash

set -Eeuo pipefail

repository="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
slug="${1:-}"
php_lane="${2:-}"

case "$php_lane" in
  8.4|8.5) ;;
  *)
    printf 'Unsupported PHP source-gate lane: %s\n' "$php_lane" >&2
    exit 2
    ;;
esac

case "$slug" in
  fchub-p24|fchub-fakturownia|fchub-wishlist|fchub-multi-currency)
    (
      cd "$repository/plugins/$slug"
      if [ "$php_lane" = '8.5' ]; then
        composer check
      else
        composer test
      fi
    )
    ;;
  fchub-memberships)
    (
      cd "$repository/plugins/fchub-memberships"
      composer test
    )
    ;;
  *)
    printf 'Unsupported WordPress.org source-gate target: %s\n' "$slug" >&2
    exit 2
    ;;
esac
