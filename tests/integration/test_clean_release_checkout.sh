#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /tmp/pxe-clean-checkout-XXXXXXXX)
trap 'rm -rf -- "$fixture"' EXIT
git -C "$fixture" init -q
git -C "$fixture" config user.name Fixture
git -C "$fixture" config user.email fixture@example.invalid
printf '%s\n' original > "$fixture/tracked"
git -C "$fixture" add tracked
git -C "$fixture" commit -qm fixture
commit=$(git -C "$fixture" rev-parse HEAD)
cd "$fixture"
bash "$root/packaging/ci/verify-clean-checkout.sh" "$commit"
if bash "$root/packaging/ci/verify-clean-checkout.sh" 0000000000000000000000000000000000000000; then exit 1; fi
printf '%s\n' changed > tracked
if bash "$root/packaging/ci/verify-clean-checkout.sh" "$commit"; then exit 1; fi
git add tracked
if bash "$root/packaging/ci/verify-clean-checkout.sh" "$commit"; then exit 1; fi
printf '%s\n' 'Release checkout rejects wrong HEAD, tracked modifications and index changes.'
