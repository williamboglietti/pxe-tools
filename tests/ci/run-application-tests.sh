#!/usr/bin/env bash
set -euo pipefail

# This runner writes only to disposable /etc, /tmp and the test workspace.
[[ -f /.dockerenv && ! -e /etc/pxe-tools/db.conf && ! -L /etc/pxe-tools/db.conf ]] || {
  echo 'A disposable container without appliance configuration is required.' >&2
  exit 1
}
export PXE_APP_DIR="$PWD/app" PYTHONDONTWRITEBYTECODE=1

echo '::group::First boot and master key preservation'
python3 tests/test_master_key_bootstrap.py
echo '::endgroup::'

echo '::group::Concurrent image selection and real onboarding HTTP'
php tests/unit/test_wizard_image_race.php
PXE_WIZARD_RACE_ISOLATED=1 python3 tests/integration/test_wizard_image_race_http.py
echo '::endgroup::'

echo '::group::CSRF, trusted proxy and HTTP authentication'
bash tests/unit/test_csrf.sh
php tests/unit/test_authentik_proxy.php
python3 tests/integration/test_authentik_http.py
echo '::endgroup::'

echo '::group::External database fail-closed and SQLite worker'
PXE_EXTERNAL_DB_TEST=1 python3 tests/integration/test_external_db_fail_closed.py
PXE_WIZARD_DB_TEST=1 python3 tests/integration/test_wizard_database_support.py
python3 tests/unit/test_worker_database_selection.py
PXE_WORKER_DB_TEST=1 python3 tests/integration/test_worker_database_process.py
echo '::endgroup::'

echo '::group::Debian preseed, signed bootloaders and disk guards'
python3 tests/unit/test_preseed_install_invariants.py
echo '::endgroup::'

echo '::group::Signed Debian netboot freshness and integrity'
python3 tests/unit/test_debian_release_freshness.py
PXE_FRESHNESS_ISOLATED=1 python3 tests/integration/test_debian_netboot_freshness.py
python3 tests/unit/test_prototype_debian_netboot_freshness.py
echo '::endgroup::'

echo '::group::Native PXE HTTP interface binding'
python3 tests/unit/test_native_pxe_http_bind.py
echo '::endgroup::'

echo '::group::Concurrent installation log append'
PXE_INSTALL_LOG_ISOLATED=1 python3 tests/integration/test_install_log_concurrency.py
echo '::endgroup::'
