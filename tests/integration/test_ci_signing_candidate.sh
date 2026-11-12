#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Test the real CI signer with disposable keys and synthetic packages only.
set -euo pipefail
if [[ "${PXE_CI_SIGNING_FIXTURE:-}" != 1 ]]; then
    echo 'Run only in a disposable Debian/Ubuntu test environment.' >&2
    exit 1
fi
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /var/tmp/pxe-ci-signing-test-XXXXXXXX)
gnupg="$fixture/gnupg"
mkdir -m 0700 "$gnupg"
cleanup() {
    gpgconf --homedir "$gnupg" --kill gpg-agent >/dev/null 2>&1 || true
    rm -rf -- "$fixture"
}
trap cleanup EXIT
mkdir -p "$fixture/package/DEBIAN"
cat > "$fixture/package/DEBIAN/control" <<'CONTROL'
Package: pxe-tools
Version: 1.0~fixture1
Architecture: amd64
Maintainer: Fixture <fixture@example.invalid>
Description: synthetic package, no PXE Tools application
CONTROL
dpkg-deb --build --root-owner-group "$fixture/package" "$fixture/package.deb" >/dev/null
printf '%s\n' 'ci-fixture-passphrase' > "$fixture/passphrase"
chmod 0600 "$fixture/passphrase"
gpg --homedir "$gnupg" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --quick-generate-key 'CI Fixture <fixture@example.invalid>' ed25519 cert 1d >/dev/null 2>&1
primary=$(gpg --homedir "$gnupg" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="fpr" {print $10;exit}')
gpg --homedir "$gnupg" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --quick-add-key "$primary" ed25519 sign 1d >/dev/null 2>&1
sub=$(gpg --homedir "$gnupg" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="ssb" {s=1;next} s && $1=="fpr" {print $10;exit}')
gpg --homedir "$gnupg" --batch --armor --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --export-secret-subkeys "$sub!" > "$fixture/sub.asc"
gpg --homedir "$gnupg" --batch --armor --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --export-secret-keys "$primary" > "$fixture/full.asc"
export APT_SIGNING_KEY="$(cat "$fixture/sub.asc")" APT_SIGNING_PASSPHRASE='ci-fixture-passphrase'
helper="$root/packaging/native/ci-sign-apt-candidate.py"
python3 "$helper" "$fixture/package.deb" "$fixture/good" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" --valid-days 1 > "$fixture/good.log" 2>&1
test -s "$fixture/good/dists/stable/InRelease"
gpg --homedir "$gnupg" --batch --status-fd 1 --verify "$fixture/good/dists/stable/InRelease" 2>/dev/null | grep -q "^\[GNUPG:\] VALIDSIG $sub "
old_index="$fixture/good/dists/stable/main/binary-amd64/Packages.gz"
old_hash=$(sha256sum "$old_index" | awk '{print $1}')
sed -i 's/Version: 1.0~fixture1/Version: 1.0~fixture2/' "$fixture/package/DEBIAN/control"
dpkg-deb --build --root-owner-group "$fixture/package" "$fixture/package-next.deb" >/dev/null
python3 "$helper" "$fixture/package-next.deb" "$fixture/next" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" --valid-days 1 --previous "$fixture/good" > "$fixture/next.log" 2>&1
cmp "$old_index" "$fixture/next/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
test "$(find "$fixture/next/pool" -name '*.deb' | wc -l | tr -d ' ')" = 2
APT_SIGNING_KEY="$(cat "$fixture/full.asc")" python3 "$helper" "$fixture/package.deb" "$fixture/with-primary" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/full.log" 2>&1 && {
    echo 'Secret primary key unexpectedly accepted' >&2; exit 1;
}
test ! -e "$fixture/with-primary"
APT_SIGNING_PASSPHRASE='wrong-ci-fixture-passphrase' python3 "$helper" "$fixture/package.deb" "$fixture/wrong-pass" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/wrong.log" 2>&1 && {
    echo 'Incorrect passphrase unexpectedly accepted' >&2; exit 1;
}
test ! -e "$fixture/wrong-pass"
python3 "$helper" "$fixture/package.deb" "$fixture/wrong-sub" \
    --primary-fingerprint "$primary" --signing-fingerprint 1111111111111111111111111111111111111111 > "$fixture/sub.log" 2>&1 && {
    echo 'Incorrect subkey unexpectedly accepted' >&2; exit 1;
}
test ! -e "$fixture/wrong-sub"
if grep -Eq 'ci-fixture-passphrase|BEGIN PGP PRIVATE KEY' "$fixture/"*.log >/dev/null 2>&1; then
    echo 'Fixture credential leaked into output' >&2; exit 1
fi
echo 'OK: isolated protected subkey, exact signer, primary-secret rejection and wrong-passphrase rejection'
