#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Exercise an explicit same-primary subkey transition with disposable APT state.
set -euo pipefail

[[ "${PXE_APT_ROTATION_FIXTURE:-}" == 1 ]] || {
  echo 'Set PXE_APT_ROTATION_FIXTURE=1 in a disposable test environment.' >&2
  exit 1
}
root=$(cd "$(dirname "$0")/../.." && pwd)
python3 "$root/tests/test_client_transition_policy.py" -v
fixture=$(mktemp -d /tmp/pxe-rotation-apt-XXXXXXXX)
home="$fixture/gnupg"
mkdir -m 0700 "$home"
cleanup() {
  gpgconf --homedir "$home" --kill gpg-agent >/dev/null 2>&1 || true
  python3 - "$fixture" <<'PY'
import shutil, sys
shutil.rmtree(sys.argv[1])
PY
}
trap cleanup EXIT

passfile="$fixture/passphrase"
printf '%s\n' 'temporary-fixture-passphrase' > "$passfile"
chmod 0600 "$passfile"
gpg_base=(gpg --homedir "$home" --batch --no-tty --pinentry-mode loopback --passphrase-file "$passfile")
"${gpg_base[@]}" --quick-generate-key 'APT rotation fixture <apt-rotation@example.invalid>' ed25519 cert 1d >/dev/null 2>&1
primary=$("${gpg_base[@]}" --with-colons --with-fingerprint --list-secret-keys | awk -F: '$1=="fpr" {print $10; exit}')
"${gpg_base[@]}" --quick-add-key "$primary" ed25519 sign 1d >/dev/null 2>&1
old_signer=$("${gpg_base[@]}" --with-colons --with-fingerprint --with-subkey-fingerprint --list-secret-keys | awk -F: '$1=="fpr" {n++; if(n==2) {print $10; exit}}')
"${gpg_base[@]}" --export "$primary" > "$fixture/old-public.gpg"
"${gpg_base[@]}" --quick-add-key "$primary" ed25519 sign 1d >/dev/null 2>&1
new_signer=$("${gpg_base[@]}" --with-colons --with-fingerprint --with-subkey-fingerprint --list-secret-keys | awk -F: '$1=="fpr" {n++; if(n==3) {print $10; exit}}')
"${gpg_base[@]}" --export "$primary" > "$fixture/new-public.gpg"
[[ -n "$primary" && -n "$old_signer" && -n "$new_signer" && "$old_signer" != "$new_signer" ]]

pkg="$fixture/package"
mkdir -p "$pkg/DEBIAN"
cat > "$pkg/DEBIAN/control" <<'CONTROL'
Package: pxe-tools
Version: 1.0~fixture1
Architecture: amd64
Maintainer: Fixture <fixture@example.invalid>
Description: Synthetic package for APT rotation rehearsal
CONTROL
dpkg-deb --build --root-owner-group "$pkg" "$fixture/old.deb" >/dev/null
builder="$root/packaging/native/build-apt-release-candidate.py"
old_sha=$(sha256sum "$fixture/old.deb" | awk '{print $1}')
old_source=$(printf 'a%.0s' {1..40})
python3 "$builder" "$fixture/old.deb" "$fixture/old" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$old_signer" --valid-days 1 \
  --source-commit "$old_source" --build-run-id 123 --approved-package-sha256 "$old_sha" >/dev/null
sed -i 's/Version: 1.0~fixture1/Version: 1.0~fixture2/' "$pkg/DEBIAN/control"
dpkg-deb --build --root-owner-group "$pkg" "$fixture/new.deb" >/dev/null
new_sha=$(sha256sum "$fixture/new.deb" | awk '{print $1}')
new_source=$(printf 'b%.0s' {1..40})

# The existing default remains fail-closed when the previous signer differs.
if python3 "$builder" "$fixture/new.deb" "$fixture/no-transition" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$new_signer" --previous "$fixture/old" > "$fixture/default.log" 2>&1; then
  echo 'Unexpected acceptance without explicit transition identities' >&2; exit 1
fi
test ! -e "$fixture/no-transition"

# Explicit transition pins the old signer and primary, retaining old objects.
args=(--gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$new_signer" --previous "$fixture/old" --valid-days 1
      --previous-signing-fingerprint "$old_signer" --previous-primary-fingerprint "$primary"
      --source-commit "$new_source" --build-run-id 456 --approved-package-sha256 "$new_sha")
python3 "$builder" "$fixture/new.deb" "$fixture/new" "${args[@]}" >/dev/null

# A client can have been offline beyond the old index's validity. Retain a
# genuinely signed historical proof while requiring a fresh new-signer index.
sed 's/^Valid-Until:.*/Valid-Until: Thu, 01 Jan 1970 00:00:00 UTC/' \
  "$fixture/old/dists/stable/Release" > "$fixture/expired-old-release"
"${gpg_base[@]}" --local-user "${old_signer}!" --clearsign \
  --output "$fixture/expired-old.InRelease" "$fixture/expired-old-release"

# Prepare the public client data using the same copied tool that the workflow
# retains. Neither this fixture nor the output contains production material.
client_tool=${PXE_CLIENT_TRANSITION_TOOL:-$root/packaging/native/prepare-client-key-transition.py}
client_data="$(dirname "$client_tool")/stage-client-transition-data.py"
data_args=(--old-certificate "$fixture/old-public.gpg" --new-certificate "$fixture/new-public.gpg"
           --old-inrelease "$fixture/old/dists/stable/InRelease" --new-inrelease "$fixture/new/dists/stable/InRelease"
           --primary-fingerprint "$primary" --old-signing-fingerprint "$old_signer" --new-signing-fingerprint "$new_signer")
python3 "$client_data" "$fixture/client-data" "${data_args[@]}" >/dev/null
cmp "$fixture/client-data/new-public.gpg" "$fixture/new-public.gpg"
cmp "$fixture/client-data/old.InRelease" "$fixture/old/dists/stable/InRelease"
cmp "$fixture/client-data/new.InRelease" "$fixture/new/dists/stable/InRelease"
python3 - "$fixture/client-data" <<'PY'
import hashlib, json, sys
from pathlib import Path
root = Path(sys.argv[1])
manifest = json.loads((root / 'manifest.json').read_text())
for name, expected in manifest['files'].items():
    assert hashlib.sha256((root / name).read_bytes()).hexdigest() == expected
assert set(manifest['files']) == {'old-public.gpg', 'new-public.gpg', 'old.InRelease', 'new.InRelease'}
PY
if python3 "$client_data" "$fixture/invalid-client-data" "${data_args[@]}" --new-inrelease "$fixture/old/dists/stable/InRelease" > "$fixture/invalid-client-data.log" 2>&1; then
  echo 'Unexpected acceptance of the wrong new index signer' >&2; exit 1
fi
test ! -e "$fixture/invalid-client-data"
old_index="$fixture/old/dists/stable/main/binary-amd64/Packages.gz"
old_sha256=$(sha256sum "$old_index" | awk '{print $1}')
old_sha512=$(sha512sum "$old_index" | awk '{print $1}')
cmp "$fixture/old/dists/stable/main/binary-amd64/by-hash/SHA256/$old_sha256" "$fixture/new/dists/stable/main/binary-amd64/by-hash/SHA256/$old_sha256"
cmp "$fixture/old/dists/stable/main/binary-amd64/by-hash/SHA512/$old_sha512" "$fixture/new/dists/stable/main/binary-amd64/by-hash/SHA512/$old_sha512"
old_package=$(find "$fixture/old/pool" -name '*.deb' -print -quit)
cmp "$old_package" "$fixture/new/pool/main/p/pxe-tools/$(basename "$old_package")"
# Recover genuine signed fixture snapshots and exercise failure/retry ordering.
PXE_ROTATION_FIXTURE_DIR="$fixture" PXE_ROTATION_PRIMARY="$primary" \
  PXE_ROTATION_OLD_SIGNER="$old_signer" PXE_ROTATION_NEW_SIGNER="$new_signer" \
  python3 "$root/tests/integration/test_apt_snapshot_recovery_fixture.py" -v
PXE_ROTATION_FIXTURE_DIR="$fixture" python3 "$root/tests/integration/test_apt_key_rotation_uninstall_fixture.py" -v
PXE_ROTATION_FIXTURE_DIR="$fixture" PXE_ROTATION_PRIMARY="$primary" \
  PXE_ROTATION_OLD_SIGNER="$old_signer" PXE_ROTATION_NEW_SIGNER="$new_signer" \
  python3 "$root/tests/integration/test_apt_client_transition_fixture.py" -v

# CI transition uses only the new secret subkey; the old certificate is public.
"${gpg_base[@]}" --armor --export-secret-subkeys "${new_signer}!" > "$fixture/new-secret.asc"
APT_SIGNING_KEY=$(< "$fixture/new-secret.asc")
export APT_SIGNING_KEY
export APT_SIGNING_PASSPHRASE='temporary-fixture-passphrase'
ci_signer="$root/packaging/native/ci-sign-apt-candidate.py"
ci_args=(--primary-fingerprint "$primary" --signing-fingerprint "$new_signer" --previous "$fixture/old"
         --transition-previous-signing-fingerprint "$old_signer" --transition-previous-public-key "$fixture/old-public.gpg")
python3 "$ci_signer" "$fixture/new.deb" "$fixture/ci-new" "${ci_args[@]}" --public-key-output "$fixture/ci-public.gpg" >/dev/null
gpgv --keyring "$fixture/ci-public.gpg" "$fixture/old/dists/stable/InRelease" >/dev/null 2>&1
gpgv --keyring "$fixture/ci-public.gpg" "$fixture/ci-new/dists/stable/InRelease" >/dev/null 2>&1

# Stage the same static asset layout used by the publication workflow, locally.
python3 "$root/packaging/native/prepare-static-apt.py" "$fixture/ci-new" "$fixture/static-transition" \
  --keyring "$fixture/ci-public.gpg" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" >/dev/null
cmp "$fixture/ci-new/dists/stable/InRelease" "$fixture/static-transition/apt/dists/stable/InRelease"
gpgv --keyring "$fixture/static-transition/pxe-tools-archive-keyring.gpg" \
  "$fixture/old/dists/stable/InRelease" >/dev/null 2>&1
gpgv --keyring "$fixture/static-transition/pxe-tools-archive-keyring.gpg" \
  "$fixture/static-transition/apt/dists/stable/InRelease" >/dev/null 2>&1

# Exercise the exact publication hook with an empty default configuration and
# with separately reviewed fixture-only transition data. No Worker is deployed.
publication_hook="$root/packaging/ci/stage-published-client-transitions.py"
cp "$fixture/static-transition/_headers" "$fixture/original-headers"
python3 "$publication_hook" --assets "$fixture/static-transition" \
  --config "$root/packaging/ci/client-transitions.json" --data-root "$fixture/no-data" \
  --primary-fingerprint "$primary" >/dev/null
cmp "$fixture/original-headers" "$fixture/static-transition/_headers"
test ! -e "$fixture/static-transition/apt-transition"
transition_id=$(python3 - "$root" "$fixture" "$primary" "$old_signer" "$new_signer" <<'PY'
import hashlib, importlib.util, json, pathlib, sys
root, fixture = map(pathlib.Path, sys.argv[1:3])
primary, old, new = sys.argv[3:]
spec = importlib.util.spec_from_file_location('publication_hook', root / 'packaging/ci/stage-published-client-transitions.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
files = {name: hashlib.sha256((fixture / 'client-data' / name).read_bytes()).hexdigest() for name in module.NAMES}
ident = module.identity(primary, old, new, files)
(fixture / 'client-transitions.json').write_text(json.dumps({'version': 1, 'transitions': [
    {'id': ident, 'primary': primary, 'old': old, 'new': new}]}))
print(ident)
PY
)
mkdir -p "$fixture/published-data/$transition_id"
for name in old-public.gpg new-public.gpg old.InRelease new.InRelease; do
  cp "$fixture/client-data/$name" "$fixture/published-data/$transition_id/$name"
done
python3 "$publication_hook" --assets "$fixture/static-transition" \
  --config "$fixture/client-transitions.json" --data-root "$fixture/published-data" \
  --primary-fingerprint "$primary" >/dev/null
cmp "$fixture/client-data/new-public.gpg" "$fixture/static-transition/apt-transition/sha256/$transition_id/new-public.gpg"
test "$(find "$fixture/static-transition/apt-transition/sha256/$transition_id" -type f | wc -l | tr -d ' ')" = 4
grep -q 'Cache-Control: public, max-age=31536000, immutable' "$fixture/static-transition/_headers"
mkdir "$fixture/invalid-publication-assets"
cp "$fixture/original-headers" "$fixture/invalid-publication-assets/_headers"
printf 'tampered\n' >> "$fixture/published-data/$transition_id/new.InRelease"
if python3 "$publication_hook" --assets "$fixture/invalid-publication-assets" \
    --config "$fixture/client-transitions.json" --data-root "$fixture/published-data" \
    --primary-fingerprint "$primary" > "$fixture/invalid-publication.log" 2>&1; then
  echo 'Unexpected acceptance of altered transition data' >&2; exit 1
fi
test ! -e "$fixture/invalid-publication-assets/apt-transition"

# Exact provenance checks allow only the explicitly pinned pair of subkeys.
python3 - "$fixture" "$old_sha" "$new_sha" "$old_source" "$new_source" <<'PYJSON'
import json, pathlib, sys
root = pathlib.Path(sys.argv[1])
for name, version, sha, source, run in (
        ('old', '1.0~fixture1', sys.argv[2], sys.argv[4], '123'),
        ('new', '1.0~fixture2', sys.argv[3], sys.argv[5], '456')):
    (root / f'{name}-manifest.json').write_text(json.dumps({
        'version': version, 'package_sha256': sha, 'source_commit': source, 'build_run_id': run}))
PYJSON
proof="$root/packaging/ci/verify-published-provenance.py"
python3 "$proof" --inrelease "$fixture/old/dists/stable/InRelease" --keyring "$fixture/old-public.gpg" \
  --manifest "$fixture/old-manifest.json" --primary-fingerprint "$primary" --signing-fingerprint "$old_signer" \
  --packages "$fixture/old/dists/stable/main/binary-amd64/Packages" >/dev/null
python3 "$proof" --inrelease "$fixture/new/dists/stable/InRelease" --keyring "$fixture/new-public.gpg" \
  --manifest "$fixture/new-manifest.json" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" \
  --additional-signing-fingerprint "$old_signer" --packages "$fixture/new/dists/stable/main/binary-amd64/Packages" >/dev/null
if python3 "$proof" --inrelease "$fixture/new/dists/stable/InRelease" --keyring "$fixture/new-public.gpg" \
  --manifest "$fixture/new-manifest.json" --primary-fingerprint "$primary" --signing-fingerprint "$old_signer" \
  --additional-signing-fingerprint "$new_signer" --packages "$fixture/new/dists/stable/main/binary-amd64/Packages" > "$fixture/proof-wrong-signer.log" 2>&1; then
  echo 'Provenance unexpectedly accepted the wrong signing subkey' >&2; exit 1
fi
if python3 "$proof" --inrelease "$fixture/new/dists/stable/InRelease" --keyring "$fixture/new-public.gpg" \
  --manifest "$fixture/new-manifest.json" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" \
  --packages "$fixture/new/dists/stable/main/binary-amd64/Packages" > "$fixture/proof-no-transition.log" 2>&1; then
  echo 'Provenance unexpectedly accepted a second subkey without explicit pinning' >&2; exit 1
fi
if python3 "$ci_signer" "$fixture/new.deb" "$fixture/ci-wrong-old" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" --previous "$fixture/old" --transition-previous-signing-fingerprint "$(printf '%040d' 0)" --transition-previous-public-key "$fixture/old-public.gpg" > "$fixture/ci-wrong-old.log" 2>&1; then
  echo 'CI signer unexpectedly accepted the wrong previous signer' >&2; exit 1
fi
test ! -e "$fixture/ci-wrong-old"
"${gpg_base[@]}" --export-secret-keys "$primary" > "$fixture/secret-key.gpg"
if python3 "$ci_signer" "$fixture/new.deb" "$fixture/ci-secret" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" --previous "$fixture/old" --transition-previous-signing-fingerprint "$old_signer" --transition-previous-public-key "$fixture/secret-key.gpg" > "$fixture/ci-secret.log" 2>&1; then
  echo 'CI signer unexpectedly accepted private material as a previous certificate' >&2; exit 1
fi
test ! -e "$fixture/ci-secret"
if python3 "$ci_signer" "$fixture/new.deb" "$fixture/ci-extra-subkey" --primary-fingerprint "$primary" --signing-fingerprint "$new_signer" --previous "$fixture/old" --transition-previous-signing-fingerprint "$old_signer" --transition-previous-public-key "$fixture/new-public.gpg" > "$fixture/ci-extra-subkey.log" 2>&1; then
  echo 'CI signer unexpectedly accepted an extra previous public subkey' >&2; exit 1
fi
test ! -e "$fixture/ci-extra-subkey"
unset APT_SIGNING_KEY APT_SIGNING_PASSPHRASE

# Wrong identities and incomplete transitions must not publish an output tree.
if python3 "$builder" "$fixture/new.deb" "$fixture/wrong-old" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$new_signer" --previous "$fixture/old" --previous-signing-fingerprint "$new_signer" --previous-primary-fingerprint "$primary" > "$fixture/wrong-old.log" 2>&1; then
  echo 'Unexpected acceptance of the wrong previous signer' >&2; exit 1
fi
test ! -e "$fixture/wrong-old"
if python3 "$builder" "$fixture/new.deb" "$fixture/wrong-primary" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$new_signer" --previous "$fixture/old" --previous-signing-fingerprint "$old_signer" --previous-primary-fingerprint "$(printf '%040d' 0)" > "$fixture/wrong-primary.log" 2>&1; then
  echo 'Unexpected acceptance of the wrong primary' >&2; exit 1
fi
test ! -e "$fixture/wrong-primary"
if python3 "$builder" "$fixture/new.deb" "$fixture/incomplete" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$new_signer" --previous "$fixture/old" --previous-signing-fingerprint "$old_signer" > "$fixture/incomplete.log" 2>&1; then
  echo 'Unexpected acceptance of an incomplete transition' >&2; exit 1
fi
test ! -e "$fixture/incomplete"

# A correctly signed previous release cannot authorize a new signer from a
# different primary, even if that signer's fingerprint was explicitly supplied.
"${gpg_base[@]}" --quick-generate-key 'Other fixture <other-rotation@example.invalid>' ed25519 sign 1d >/dev/null 2>&1
other_signer=$("${gpg_base[@]}" --with-colons --with-fingerprint --list-secret-keys 'other-rotation@example.invalid' | awk -F: '$1=="fpr" {print $10; exit}')
[[ -n "$other_signer" && "$other_signer" != "$primary" ]]
if python3 "$builder" "$fixture/new.deb" "$fixture/wrong-new-primary" --gnupg-home "$home" --passphrase-file "$passfile" --fingerprint "$other_signer" --previous "$fixture/old" --previous-signing-fingerprint "$old_signer" --previous-primary-fingerprint "$primary" > "$fixture/wrong-new-primary.log" 2>&1; then
  echo 'Unexpected acceptance of a new signer from another primary' >&2; exit 1
fi
test ! -e "$fixture/wrong-new-primary"

# A separate APT state is used for every attempt; no system sources are changed.
mkdir -p "$fixture/client/parts"
touch "$fixture/client/status"
printf 'deb [signed-by=%s/old-public.gpg] file:%s/old stable main\n' "$fixture" "$fixture" > "$fixture/client/old.list"
printf 'deb [signed-by=%s/old-public.gpg] file:%s/new stable main\n' "$fixture" "$fixture" > "$fixture/client/stale.list"
printf 'deb [signed-by=%s/new-public.gpg] file:%s/new stable main\n' "$fixture" "$fixture" > "$fixture/client/new.list"
apt_check() {
  local label="$1" source="$2" lists="$fixture/client/lists-$1"
  mkdir -p "$lists/partial"
  apt-get -o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$source" -o "Dir::Etc::sourceparts=$fixture/client/parts" \
    -o "Dir::State::lists=$lists" -o "Dir::State::status=$fixture/client/status" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache= \
    -o APT::Update::Error-Mode=any update > "$fixture/client/$label.log" 2>&1
}
apt_check old "$fixture/client/old.list"
if apt_check stale "$fixture/client/stale.list"; then
  echo 'Old client unexpectedly accepted the new signing subkey' >&2; exit 1
fi
grep -Eq 'NO_PUBKEY|Missing key' "$fixture/client/stale.log" || { cat "$fixture/client/stale.log" >&2; exit 1; }
apt_check updated "$fixture/client/new.list"

# Rehearse replacing a single client keyring path, not just switching between
# two source files. All paths and witnesses here belong to this fixture only.
client_key="$fixture/client/installed-key.gpg"
client_witness="$fixture/client/installed-key.created"
cp "$fixture/old-public.gpg" "$client_key"
ln "$client_key" "$client_witness"
printf 'deb [signed-by=%s] file:%s/old stable main\n' "$client_key" "$fixture" > "$fixture/client/same-old.list"
printf 'deb [signed-by=%s] file:%s/new stable main\n' "$client_key" "$fixture" > "$fixture/client/same-new.list"
apt_check same-old "$fixture/client/same-old.list"
cp "$fixture/new-public.gpg" "$fixture/client/staged-key.gpg"
# Before the atomic replacement, an interrupted staging preserves old trust.
cmp "$client_key" "$fixture/old-public.gpg"
apt_check staged-old "$fixture/client/same-old.list"
if apt_check staged-new "$fixture/client/same-new.list"; then
  echo 'A merely staged key unexpectedly changed client trust' >&2; exit 1
fi
mv -T "$fixture/client/staged-key.gpg" "$client_key"
cmp "$client_key" "$fixture/new-public.gpg"
cmp "$client_witness" "$fixture/old-public.gpg"
[[ ! "$client_key" -ef "$client_witness" ]]
apt_check same-new "$fixture/client/same-new.list"
# Preserve the old hard-link witness; no ownership of the new key is inferred.

apt-get -o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/client/new.list" \
  -o "Dir::Etc::sourceparts=$fixture/client/parts" -o "Dir::State::lists=$fixture/client/lists-updated" \
  -o "Dir::State::status=$fixture/client/status" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache= \
  --simulate install pxe-tools > "$fixture/client/install-simulation.log" 2>&1
grep -q '^Inst pxe-tools (1.0~fixture2 ' "$fixture/client/install-simulation.log"
# The updated certificate continues to authenticate the old release.
gpgv --keyring "$fixture/new-public.gpg" "$fixture/old/dists/stable/InRelease" >/dev/null 2>&1
echo 'OK disposable old/new signing subkeys, immutable objects and isolated APT client'
