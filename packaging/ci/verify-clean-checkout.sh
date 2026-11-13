#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Release artifacts must be built from the exact reviewed commit.
set -euo pipefail
if [[ "$#" != 1 || ! "$1" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Expected the full reviewed commit SHA.' >&2
    exit 1
fi
if [[ "$(git rev-parse --verify HEAD)" != "$1" ]] || \
   ! git diff --no-ext-diff --quiet || \
   ! git diff --cached --no-ext-diff --quiet; then
    echo 'Checkout differs from the reviewed commit; refusing release build.' >&2
    exit 1
fi
