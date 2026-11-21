#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Read-only receipt of the published APT repository using an isolated APT state.
set -euo pipefail
[[ "${PXE_PUBLIC_APT_READONLY:-}" == 1 ]] || {
  echo 'Set PXE_PUBLIC_APT_READONLY=1 for an isolated read-only public APT receipt.' >&2
  exit 1
}
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /tmp/pxe-public-apt-receipt-XXXXXXXX)
cleanup() {
  python3 - "$fixture" <<'PY'
import shutil, sys
shutil.rmtree(sys.argv[1])
PY
}
trap cleanup EXIT
mkdir -p "$fixture/lists/partial" "$fixture/archives/partial" "$fixture/parts" "$fixture/downloads"
touch "$fixture/status"
keyring="$root/packaging/ci/pxe-tools-public.gpg"
printf 'deb [arch=amd64 signed-by=%s] https://packages.pxetools.com/apt stable main\n' "$keyring" > "$fixture/source.list"
python3 - "$root/packaging/ci/release-manifest.json" "$fixture/packages.tsv" <<'PY'
import json, pathlib, re, sys
manifest = json.loads(pathlib.Path(sys.argv[1]).read_text())
rows = [('pxe-tools', manifest['version'], manifest['package_sha256'])]
if 'ansible_version' in manifest:
    rows.append(('pxe-tools-ansible', manifest['ansible_version'], manifest['ansible_package_sha256']))
for name, version, digest in rows:
    if not re.fullmatch(r'[0-9][A-Za-z0-9.+:~\-]{0,100}', version) or not re.fullmatch(r'[a-f0-9]{64}', digest):
        raise ValueError('invalid manifest version or digest')
pathlib.Path(sys.argv[2]).write_text(''.join('\t'.join(row) + '\n' for row in rows))
PY
opts=(-o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/source.list"
      -o "Dir::Etc::sourceparts=$fixture/parts" -o "Dir::State::lists=$fixture/lists"
      -o "Dir::State::status=$fixture/status" -o "Dir::Cache::archives=$fixture/archives"
      -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache= -o APT::Update::Error-Mode=any
      -o Acquire::Languages=none -o Debug::NoLocking=1)
apt-get "${opts[@]}" update > "$fixture/update.log" 2>&1 || {
  cat "$fixture/update.log" >&2; exit 1;
}
while IFS=$'\t' read -r name version expected; do
  (cd "$fixture/downloads" && apt-get "${opts[@]}" download "$name=$version") > "$fixture/download.log" 2>&1 || {
    cat "$fixture/download.log" >&2; exit 1;
  }
  shopt -s nullglob
  files=("$fixture/downloads/${name}_"*.deb)
  ((${#files[@]} == 1)) || { echo "Expected exactly one $name package." >&2; exit 1; }
  [[ $(dpkg-deb --field "${files[0]}" Package) == "$name" ]]
  [[ $(dpkg-deb --field "${files[0]}" Version) == "$version" ]]
  actual=$(sha256sum "${files[0]}" | awk '{print $1}')
  [[ "$actual" == "$expected" ]] || { echo "Manifest digest mismatch for $name." >&2; exit 1; }
  printf 'Verified public APT package %s %s against signed index and manifest.\n' "$name" "$version"
done < "$fixture/packages.tsv"
echo 'OK public signed APT receipt in isolated empty client state; no packages installed'
