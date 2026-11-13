#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Upload and deploy a unique version; domain/route triggers are managed separately.
set -euo pipefail
if [[ "$#" != 2 || ! "$GITHUB_RUN_ID" =~ ^[1-9][0-9]{0,19}$ || ! "$GITHUB_RUN_ATTEMPT" =~ ^[1-9][0-9]{0,9}$ ]]; then
    echo 'Expected wrangler, assets directory, and valid GitHub run identifiers.' >&2
    exit 1
fi
wrangler="$1"
assets="$2"
if [[ ! -x "$wrangler" || ! -d "$assets" ]]; then
    echo 'Wrangler or verified assets directory is missing.' >&2
    exit 1
fi
tag="apt-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
config=packaging/cloudflare/wrangler.jsonc
"$wrangler" versions upload --config "$config" --assets "$assets" --tag "$tag"
"$wrangler" versions deploy --config "$config" --version-tag "$tag@100%" --yes
