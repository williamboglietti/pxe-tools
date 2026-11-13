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
    if [[ -n "${worker_pid:-}" ]]; then kill "$worker_pid" 2>/dev/null || true; wait "$worker_pid" 2>/dev/null || true; fi
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
primary=$(gpg --homedir "$gnupg" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="fpr" && !found {print $10;found=1}')
gpg --homedir "$gnupg" --batch --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --quick-add-key "$primary" ed25519 sign 1d >/dev/null 2>&1
sub=$(gpg --homedir "$gnupg" --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1=="ssb" {s=1;next} s && $1=="fpr" && !found {print $10;found=1}')
gpg --homedir "$gnupg" --batch --armor --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --export-secret-subkeys "$sub!" > "$fixture/sub.asc"
gpg --homedir "$gnupg" --batch --armor --pinentry-mode loopback --passphrase-file "$fixture/passphrase" \
    --export-secret-keys "$primary" > "$fixture/full.asc"
export APT_SIGNING_KEY="$(cat "$fixture/sub.asc")" APT_SIGNING_PASSPHRASE='ci-fixture-passphrase'
helper="$root/packaging/native/ci-sign-apt-candidate.py"
fixture_sha=$(sha256sum "$fixture/package.deb" | awk '{print $1}')
fixture_commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
python3 "$helper" "$fixture/package.deb" "$fixture/good" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" --valid-days 1 \
    --source-commit "$fixture_commit" --build-run-id 123 --approved-package-sha256 "$fixture_sha" > "$fixture/good.log" 2>&1
test -s "$fixture/good/dists/stable/InRelease"
grep -Fx "X-PXE-Source-Commit: $fixture_commit" "$fixture/good/dists/stable/Release" >/dev/null
grep -Fx 'X-PXE-Build-Run: 123' "$fixture/good/dists/stable/Release" >/dev/null
grep -Fx "X-PXE-Package-SHA256: $fixture_sha" "$fixture/good/dists/stable/Release" >/dev/null
grep -Fx 'X-PXE-Package-Version: 1.0~fixture1' "$fixture/good/dists/stable/Release" >/dev/null
# Verify the CI signing entry point handles both separately approved packages.
mkdir -p "$fixture/module/DEBIAN" "$fixture/module-base/DEBIAN"
sed 's/Version: 1.0~fixture1/Version: 0.1.0~beta1/' "$fixture/package/DEBIAN/control" > "$fixture/module-base/DEBIAN/control"
cat > "$fixture/module/DEBIAN/control" <<'CONTROL'
Package: pxe-tools-ansible
Version: 0.1.0~beta1
Architecture: all
Maintainer: Fixture <fixture@example.invalid>
Depends: pxe-tools (>= 0.1.0~beta1), pxe-tools (<< 0.2~), ansible-core
Description: synthetic module
CONTROL
dpkg-deb --build --root-owner-group "$fixture/module-base" "$fixture/module-base.deb" >/dev/null
dpkg-deb --build --root-owner-group "$fixture/module" "$fixture/module.deb" >/dev/null
base_sha=$(sha256sum "$fixture/module-base.deb" | awk '{print $1}')
module_sha=$(sha256sum "$fixture/module.deb" | awk '{print $1}')
python3 "$helper" "$fixture/module-base.deb" "$fixture/with-module" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" --valid-days 1 \
    --source-commit "$fixture_commit" --build-run-id 123 --approved-package-sha256 "$base_sha" \
    --ansible-package "$fixture/module.deb" --approved-ansible-sha256 "$module_sha" > "$fixture/module.log" 2>&1
grep -Fx "X-PXE-Ansible-SHA256: $module_sha" "$fixture/with-module/dists/stable/Release" >/dev/null
grep -Fx 'Package: pxe-tools-ansible' "$fixture/with-module/dists/stable/main/binary-amd64/Packages" >/dev/null
if python3 "$helper" "$fixture/module-base.deb" "$fixture/with-bad-module" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" \
    --source-commit "$fixture_commit" --build-run-id 123 --approved-package-sha256 "$base_sha" \
    --ansible-package "$fixture/module.deb" --approved-ansible-sha256 "$(printf '%064d' 0)" > "$fixture/bad-module.log" 2>&1; then
    echo 'Mismatched module unexpectedly signed' >&2; exit 1
fi
test ! -e "$fixture/with-bad-module"
if python3 "$helper" "$fixture/package.deb" "$fixture/wrong-provenance" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" \
    --source-commit "$fixture_commit" --build-run-id 123 --approved-package-sha256 "$(printf '%064d' 0)" > "$fixture/provenance-error.log" 2>&1; then
    echo 'Mismatched provenance unexpectedly signed' >&2; exit 1
fi
test ! -e "$fixture/wrong-provenance"
gpg --homedir "$gnupg" --batch --status-fd 1 --verify "$fixture/good/dists/stable/InRelease" > "$fixture/verify.status" 2>/dev/null
grep -q "^\[GNUPG:\] VALIDSIG $sub " "$fixture/verify.status"
old_index="$fixture/good/dists/stable/main/binary-amd64/Packages.gz"
old_hash=$(sha256sum "$old_index" | awk '{print $1}')
sed -i 's/Version: 1.0~fixture1/Version: 1.0~fixture2/' "$fixture/package/DEBIAN/control"
dpkg-deb --build --root-owner-group "$fixture/package" "$fixture/package-next.deb" >/dev/null
python3 "$helper" "$fixture/package-next.deb" "$fixture/next" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" --valid-days 1 --previous "$fixture/good" --public-key-output "$fixture/exported-public.gpg" > "$fixture/next.log" 2>&1
cmp "$old_index" "$fixture/next/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
test "$(find "$fixture/next/pool" -name '*.deb' | wc -l | tr -d ' ')" = 2
gpg --homedir "$gnupg" --batch --export "$primary" > "$fixture/public.gpg"
cmp "$fixture/public.gpg" "$fixture/exported-public.gpg"
gpgv --keyring "$fixture/exported-public.gpg" "$fixture/next/dists/stable/InRelease" > /dev/null 2>&1
# Exercise a real APT client against the signed two-package snapshot, without
# installing anything on the test host or changing its configured sources.
mkdir -p "$fixture/module-lists/partial" "$fixture/module-download"
touch "$fixture/module-status"
printf 'deb [signed-by=%s/public.gpg] file:%s/with-module stable main\n' "$fixture" "$fixture" > "$fixture/module.list"
apt_options=(-o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/module.list"
    -o Dir::Etc::sourceparts=- -o "Dir::State::lists=$fixture/module-lists"
    -o "Dir::State::status=$fixture/module-status" -o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=)
apt-get "${apt_options[@]}" update > "$fixture/module-apt-update.log" 2>&1 || {
    cat "$fixture/module-apt-update.log" >&2; exit 1;
}
(cd "$fixture/module-download" && apt-get "${apt_options[@]}" download pxe-tools-ansible > "$fixture/module-apt-download.log" 2>&1) || {
    cat "$fixture/module-apt-download.log" >&2; exit 1;
}
downloaded=("$fixture"/module-download/*.deb)
test "${#downloaded[@]}" = 1
test "$(sha256sum "${downloaded[0]}" | awk '{print $1}')" = "$module_sha"
prepare="$root/packaging/native/prepare-static-apt.py"
python3 "$prepare" "$fixture/next" "$fixture/static" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static.log" 2>&1 || {
    cat "$fixture/static.log" >&2; exit 1;
}
cmp "$old_index" "$fixture/static/apt/dists/stable/main/binary-amd64/by-hash/SHA256/$old_hash"
gpgv --keyring "$fixture/static/pxe-tools-archive-keyring.gpg" "$fixture/static/apt/dists/stable/InRelease" > /dev/null 2>&1
if [[ "${PXE_STATIC_WRANGLER:-}" == 1 ]]; then
    "$root/packaging/cloudflare/node_modules/.bin/wrangler" versions upload \
        --config "$root/packaging/cloudflare/wrangler.jsonc" --assets "$fixture/static" --dry-run
    "$root/packaging/cloudflare/node_modules/.bin/wrangler" dev --local \
        --config "$root/packaging/cloudflare/wrangler.jsonc" --assets "$fixture/static" \
        --ip 127.0.0.1 --port 18787 > "$fixture/worker.log" 2>&1 &
    worker_pid=$!
    ready=0
    for attempt in {1..30}; do
        if curl --silent --fail http://127.0.0.1:18787/pxe-tools-archive-keyring.gpg -o "$fixture/http-key.gpg"; then ready=1; break; fi
        sleep 1
    done
    if [[ "$ready" != 1 ]]; then cat "$fixture/worker.log" >&2; exit 1; fi
    cmp "$fixture/public.gpg" "$fixture/http-key.gpg"
    mkdir -p "$fixture/httplists/partial"
    touch "$fixture/httpstatus"
    printf 'deb [signed-by=%s/public.gpg] http://127.0.0.1:18787/apt stable main\n' "$fixture" > "$fixture/http.list"
    apt-get -o "APT::Sandbox::User=$(id -un)" -o "Dir::Etc::sourcelist=$fixture/http.list" \
        -o Dir::Etc::sourceparts=- -o "Dir::State::lists=$fixture/httplists" \
        -o "Dir::State::status=$fixture/httpstatus" -o Dir::Cache::pkgcache= \
        -o Dir::Cache::srcpkgcache= update > "$fixture/http-update.log" 2>&1 || {
        cat "$fixture/http-update.log" >&2; exit 1;
    }
fi
cp -a "$fixture/next" "$fixture/injected-static"
injected_hash=$(printf 'unsigned object' | sha256sum | awk '{print $1}')
printf 'unsigned object' > "$fixture/injected-static/dists/stable/main/binary-amd64/by-hash/SHA256/$injected_hash"
if python3 "$prepare" "$fixture/injected-static" "$fixture/static-injected" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-injected.log" 2>&1; then
    echo 'Unsigned retained object unexpectedly staged' >&2; exit 1
fi
grep -q 'signed snapshot object manifest does not match' "$fixture/static-injected.log"
test ! -e "$fixture/static-injected"
cp -a "$fixture/next" "$fixture/tampered-static"
printf corrupt >> "$fixture/tampered-static/dists/stable/main/binary-amd64/Packages"
if python3 "$prepare" "$fixture/tampered-static" "$fixture/static-bad" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-bad.log" 2>&1; then
    echo 'Modified signed index unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-bad"
if python3 "$prepare" "$fixture/next" "$fixture/static-wrong-key" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint 1111111111111111111111111111111111111111 > "$fixture/static-wrong-key.log" 2>&1; then
    echo 'Wrong signer unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-wrong-key"
cp -a "$fixture/next" "$fixture/extra-static"
printf 'private fixture' > "$fixture/extra-static/unexpected-secret.txt"
if python3 "$prepare" "$fixture/extra-static" "$fixture/static-extra" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-extra.log" 2>&1; then
    echo 'Unexpected extra file unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-extra"
cp -a "$fixture/next" "$fixture/tampered-package"
package_to_corrupt=$(find "$fixture/tampered-package/pool" -name '*.deb' -print -quit)
printf corrupt >> "$package_to_corrupt"
if python3 "$prepare" "$fixture/tampered-package" "$fixture/static-package-bad" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-package-bad.log" 2>&1; then
    echo 'Modified package unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-package-bad"
cp -a "$fixture/next" "$fixture/symlink-static"
ln -s "$fixture/passphrase" "$fixture/symlink-static/unexpected-link"
if python3 "$prepare" "$fixture/symlink-static" "$fixture/static-symlink" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-symlink.log" 2>&1; then
    echo 'Symlink unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-symlink"
if python3 "$prepare" "$fixture/next" "$fixture/next/nested-output" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-nested.log" 2>&1; then
    echo 'Output inside snapshot unexpectedly accepted' >&2; exit 1
fi
test ! -e "$fixture/next/nested-output"
cp -a "$fixture/next" "$fixture/oversized-static"
truncate -s 26214401 "$fixture/oversized-static/pool/main/p/pxe-tools/pxe-tools-$(printf '%064d' 0).deb"
if python3 "$prepare" "$fixture/oversized-static" "$fixture/static-oversized" --keyring "$fixture/public.gpg" \
    --primary-fingerprint "$primary" --signing-fingerprint "$sub" > "$fixture/static-oversized.log" 2>&1; then
    echo 'Oversized asset unexpectedly staged' >&2; exit 1
fi
test ! -e "$fixture/static-oversized"
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
