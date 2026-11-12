#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Exercise the actual signed snapshot builder with disposable data and key.
set -euo pipefail

if [[ "${PXE_RELEASE_APT_FIXTURE:-}" != 1 ]]; then
    echo 'Set PXE_RELEASE_APT_FIXTURE=1 in a disposable Debian test environment.' >&2
    exit 1
fi
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /var/tmp/pxe-apt-release-test-XXXXXXXX)
gnupg="$fixture/gnupg"
mkdir -m 0700 "$gnupg"
cleanup() {
    gpgconf --homedir "$gnupg" --kill gpg-agent >/dev/null 2>&1 || true
    rm -rf -- "$fixture"
}
trap cleanup EXIT

make_package() {
    local name="$1" destination="$2" dir="$fixture/pkg-$1"
    mkdir -p "$dir/DEBIAN"
    cat > "$dir/DEBIAN/control" <<CONTROL
Package: $name
Version: 1.0~fixture1
Architecture: amd64
Maintainer: Fixture <fixture@example.invalid>
Description: synthetic fixture, no PXE Tools application
CONTROL
    dpkg-deb --build --root-owner-group "$dir" "$destination" >/dev/null
}
make_package pxe-tools "$fixture/good.deb"
make_package pxe-tools-local-test "$fixture/wrong.deb"
gpg --homedir "$gnupg" --batch --pinentry-mode loopback --passphrase '' \
    --quick-generate-key 'PXE fixture <fixture@example.invalid>' ed25519 sign 1d >/dev/null 2>&1
fingerprint=$(gpg --homedir "$gnupg" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="fpr" {print $10;exit}')
gpg --homedir "$gnupg" --batch --export "$fingerprint" > "$fixture/key.gpg"
builder="$root/packaging/native/build-apt-release-candidate.py"
python3 "$builder" "$fixture/good.deb" "$fixture/repository" --gnupg-home "$gnupg" --fingerprint "$fingerprint" --valid-days 1 >/dev/null

mkdir -p "$fixture/lists/partial" "$fixture/badlists/partial" "$fixture/existing"
touch "$fixture/status"
printf 'deb [signed-by=%s/key.gpg] file:%s/repository stable main\n' "$fixture" "$fixture" > "$fixture/source.list"
opts=(-o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/source.list" -o Dir::Etc::sourceparts=-
      -o "Dir::State::lists=$fixture/lists" -o "Dir::State::status=$fixture/status" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=)
apt-get "${opts[@]}" update > "$fixture/update.log" 2>&1
apt-cache "${opts[@]}" policy pxe-tools | grep -q 'Candidate: 1.0~fixture1'
apt-get -s "${opts[@]}" install pxe-tools > "$fixture/simulate.log" 2>&1
grep -q '^Inst pxe-tools ' "$fixture/simulate.log"

# A client may keep an old signed Release while a new version is deployed.
# Its content-addressed index and package must remain available in the new tree.
grep -q '^Acquire-By-Hash: yes$' "$fixture/repository/dists/stable/Release"
old_index="$fixture/repository/dists/stable/main/binary-amd64/Packages.gz"
old_hash=$(sha256sum "$old_index" | awk '{print $1}')
old_object="$fixture/repository/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
cmp "$old_index" "$old_object"
old_package=$(find "$fixture/repository/pool" -name '*.deb' -print -quit)
sed -i 's/Version: 1.0~fixture1/Version: 1.0~fixture2/' "$fixture/pkg-pxe-tools/DEBIAN/control"
dpkg-deb --build --root-owner-group "$fixture/pkg-pxe-tools" "$fixture/new.deb" >/dev/null
python3 "$builder" "$fixture/new.deb" "$fixture/next" --gnupg-home "$gnupg" --fingerprint "$fingerprint" --valid-days 1 --previous "$fixture/repository" >/dev/null
cmp "$old_object" "$fixture/next/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
cmp "$old_package" "$fixture/next/pool/main/p/pxe-tools/$(basename "$old_package")"
test "$(find "$fixture/next/pool" -name '*.deb' | wc -l | tr -d ' ')" = 2
printf 'deb [signed-by=%s/key.gpg] file:%s/next stable main\n' "$fixture" "$fixture" > "$fixture/source.list"
apt-get "${opts[@]}" update > "$fixture/update-next.log" 2>&1
apt-cache "${opts[@]}" policy pxe-tools | grep -q 'Candidate: 1.0~fixture2'
cp -a "$fixture/repository" "$fixture/tampered-previous"
printf corrupt >> "$fixture/tampered-previous/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
if python3 "$builder" "$fixture/new.deb" "$fixture/rejected-previous" --gnupg-home "$gnupg" --fingerprint "$fingerprint" --previous "$fixture/tampered-previous" > "$fixture/rejected.log" 2>&1; then
    echo 'Corrupt previous immutable index unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/rejected-previous"

mkdir -p "$fixture/bad/dists/stable"
cp "$fixture/repository/dists/stable/InRelease" "$fixture/bad/dists/stable/InRelease"
sed -i 's/PXE Tools release candidate/PXE Tools modified candidate/' "$fixture/bad/dists/stable/InRelease"
printf 'deb [signed-by=%s/key.gpg] file:%s/bad stable main\n' "$fixture" "$fixture" > "$fixture/bad.list"
bad_opts=(-o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/bad.list" -o Dir::Etc::sourceparts=-
          -o "Dir::State::lists=$fixture/badlists" -o "Dir::State::status=$fixture/status" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=)
if apt-get "${bad_opts[@]}" update > "$fixture/bad.log" 2>&1; then
    echo 'Modified signature unexpectedly accepted' >&2; exit 1
fi
grep -Eq 'manipulated|BADSIG|invalid' "$fixture/bad.log"
if python3 "$builder" "$fixture/wrong.deb" "$fixture/wrong" --gnupg-home "$gnupg" --fingerprint "$fingerprint" > "$fixture/wrong.log" 2>&1; then
    echo 'Wrong package unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/wrong"
printf keep > "$fixture/existing/marker"
if python3 "$builder" "$fixture/good.deb" "$fixture/existing" --gnupg-home "$gnupg" --fingerprint "$fingerprint" > "$fixture/existing.log" 2>&1; then
    echo 'Existing destination unexpectedly accepted' >&2; exit 1
fi
test "$(cat "$fixture/existing/marker")" = keep
# Exercise a certification-only primary and a passphrase-protected signing subkey.
protected="$fixture/protected"
mkdir -m 0700 "$protected"
printf '%s\n' 'fixture-passphrase-only' > "$fixture/passphrase"
chmod 0600 "$fixture/passphrase"
gpg --homedir "$protected" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --quick-generate-key 'Protected fixture <protected@example.invalid>' ed25519 cert 1d >/dev/null 2>&1
primary=$(gpg --homedir "$protected" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="fpr" {print $10;exit}')
gpg --homedir "$protected" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --quick-add-key "$primary" ed25519 sign 1d >/dev/null 2>&1
subkey=$(gpg --homedir "$protected" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="ssb" {subkey=1;next} subkey && $1=="fpr" {print $10;exit}')
test -n "$subkey"
ci="$fixture/ci"
mkdir -m 0700 "$ci"
gpg --homedir "$protected" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" --export-secret-subkeys "$primary" > "$fixture/ci-subkeys.gpg"
gpg --homedir "$ci" --batch --import "$fixture/ci-subkeys.gpg" >/dev/null 2>&1
# GnuPG marks the missing primary secret material as a stub (#).
gpg --homedir "$ci" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="sec" && $15=="#" {found=1} END {exit !found}'
gpgconf --homedir "$protected" --kill gpg-agent >/dev/null 2>&1 || true
printf '%s\n' 'wrong-fixture-passphrase' > "$fixture/wrong-passphrase"
chmod 0600 "$fixture/wrong-passphrase"
if python3 "$builder" "$fixture/good.deb" "$fixture/wrong-pass-repo" --gnupg-home "$ci" --fingerprint "$subkey" --passphrase-file "$fixture/wrong-passphrase" > "$fixture/wrong-pass.log" 2>&1; then
    echo 'Wrong passphrase unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/wrong-pass-repo"
gpgconf --homedir "$ci" --kill gpg-agent >/dev/null 2>&1 || true
python3 "$builder" "$fixture/good.deb" "$fixture/protected-repo" --gnupg-home "$ci" --fingerprint "$subkey" --passphrase-file "$fixture/passphrase" >/dev/null
gpg --homedir "$ci" --batch --status-fd 1 --verify "$fixture/protected-repo/dists/stable/InRelease" 2>/dev/null | grep -q "^\[GNUPG:\] VALIDSIG $subkey "
if grep -q 'fixture-passphrase-only' "$fixture/wrong-pass.log"; then
    echo 'Passphrase leaked into output' >&2; exit 1
fi
keyring_builder="$root/packaging/native/build-archive-keyring.py"
gpg --homedir "$protected" --batch --export "$primary" > "$fixture/public.gpg"
python3 "$keyring_builder" "$fixture/public.gpg" "$fixture/keyring.deb" --fingerprint "$primary" --version '1.0~fixture1' --maintainer 'Fixture <fixture@example.invalid>' >/dev/null
mkdir "$fixture/keyring-unpacked"
dpkg-deb -x "$fixture/keyring.deb" "$fixture/keyring-unpacked"
cmp "$fixture/public.gpg" "$fixture/keyring-unpacked/usr/share/keyrings/pxe-tools-archive-keyring.gpg"
if python3 "$keyring_builder" "$fixture/public.gpg" "$fixture/mismatch.deb" --fingerprint "$fingerprint" --version 1 --maintainer Fixture > "$fixture/mismatch.log" 2>&1; then
    echo 'Wrong public key unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/mismatch.deb"
gpg --homedir "$protected" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" --export-secret-keys "$primary" > "$fixture/secret.gpg"
if python3 "$keyring_builder" "$fixture/secret.gpg" "$fixture/secret.deb" --fingerprint "$primary" --version 1 --maintainer Fixture > "$fixture/secret.log" 2>&1; then
    echo 'Secret key unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/secret.deb"
gpgconf --homedir "$protected" --kill gpg-agent >/dev/null 2>&1 || true
gpgconf --homedir "$ci" --kill gpg-agent >/dev/null 2>&1 || true
echo 'OK signed candidate, APT simulation, tampering, exact protected subkey, public keyring and preservation checks'
