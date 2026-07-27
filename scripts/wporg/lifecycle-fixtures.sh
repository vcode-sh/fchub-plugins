#!/usr/bin/env bash

set -Eeuo pipefail

slug="${1:-}"

case "$slug" in
  fchub-multi-currency|fchub-memberships)
    printf '%s\n' "$slug"
    ;;
  fchub-fakturownia|fchub-p24|fchub-wishlist)
    ;;
  *)
    printf 'Unsupported WordPress.org lifecycle fixture target: %s\n' "$slug" >&2
    exit 2
    ;;
esac
