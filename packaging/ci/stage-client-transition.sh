#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Stage source-only client transition candidate; does not include certificates or publish.
set -euo pipefail
[[ $# == 1 ]] || { echo 'usage: bash stage-client-transition.sh NEW_DIRECTORY' >&2; exit 1; }
root=$(cd "$(dirname "$0")/../.." && pwd)
output=$1
[[ "$output" = /* && ! -e "$output" && ! -L "$output" && -d "$(dirname "$output")" ]] || {
  echo 'Output must be a new absolute directory in an existing parent.' >&2; exit 1;
}
mkdir "$output"
mkdir -p "$output/packaging/native" "$output/packaging/ci"
for file in packaging/native/prepare-client-key-transition.py packaging/native/check-apt-key-transition.py \
            packaging/native/stage-client-transition-data.py \
            packaging/native/uninstall-apt.py packaging/ci/apt-proof.py \
            packaging/native/client-transition-README.md; do
  cp "$root/$file" "$output/$file"
done
(cd "$output" && sha256sum packaging/native/prepare-client-key-transition.py \
  packaging/native/check-apt-key-transition.py packaging/native/uninstall-apt.py \
  packaging/native/stage-client-transition-data.py packaging/ci/apt-proof.py \
  packaging/native/client-transition-README.md > SHA256SUMS)
