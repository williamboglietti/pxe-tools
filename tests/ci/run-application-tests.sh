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

echo '::group::Encrypted offline recovery and standby restore'
PXE_OFFLINE_RECOVERY_TEST=1 python3 tests/integration/test_offline_recovery_archive.py
PXE_RECOVERY_ASSET_TEST=1 python3 tests/integration/test_recovery_asset_trees.py
python3 tests/unit/test_prune_package_cache.py
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
php tests/unit/test_first_visit_database.php
PXE_EXTERNAL_DB_TEST=1 python3 tests/integration/test_external_db_fail_closed.py
PXE_INIT_PATH_TEST=1 python3 tests/integration/test_php_init_sqlite_path_guard.py
PXE_WIZARD_DB_TEST=1 python3 tests/integration/test_wizard_database_support.py
python3 tests/unit/test_worker_database_selection.py
PXE_WORKER_DB_TEST=1 python3 tests/integration/test_worker_database_process.py
echo '::endgroup::'

echo '::group::Debian preseed, signed bootloaders and disk guards'
python3 tests/unit/test_preseed_install_invariants.py
python3 tests/integration/test_install_callback_authorization.py
python3 tests/integration/test_install_artifact_phase.py
python3 tests/integration/test_pxe_agent_callback_authorization.py
python3 tests/integration/test_discovery_identity_conflict.py
python3 tests/unit/test_grub_sbat_distribution.py
python3 tests/unit/test_debian_shim_acquisition.py
echo '::endgroup::'

echo '::group::Signed Debian netboot freshness and integrity'
python3 tests/unit/test_debian_release_freshness.py
PXE_FRESHNESS_ISOLATED=1 python3 tests/integration/test_debian_netboot_freshness.py
PXE_DOWNLOAD_ISOLATED=1 python3 tests/integration/test_verified_image_publication.py
python3 tests/unit/test_prototype_debian_netboot_freshness.py
echo '::endgroup::'

echo '::group::Native PXE HTTP interface binding'
python3 tests/unit/test_atomic_publish.py
python3 tests/unit/test_pxe_menu_rows.py
python3 tests/unit/test_native_pxe_http_bind.py
python3 tests/unit/test_native_worker_keyring.py
python3 tests/unit/test_configure_secure_runtime.py
python3 tests/unit/test_restored_publication_guard.py
python3 tests/unit/test_publish_public_mok.py
python3 tests/unit/test_native_runtime_paths.py
python3 tests/integration/test_native_dnsmasq_private_config.py
python3 tests/unit/test_native_image_path_guard.py
python3 tests/integration/test_native_package_permissions.py
bash tests/integration/test_clean_release_checkout.sh
echo '::endgroup::'

echo '::group::Protected image deletion and crash recovery'
PXE_APPLIANCE_HTTP_FIXTURE_TEST=1 python3 tests/integration/test_appliance_http_fixture.py
PXE_IMAGE_DELETE_TEST=1 python3 tests/integration/test_image_delete_worker.py
PXE_DOCKER_HTTP_TEST=1 python3 tests/integration/test_docker_http_tree.py
echo '::endgroup::'

echo '::group::Concurrent installation log append'
PXE_INSTALL_LOG_ISOLATED=1 python3 tests/integration/test_install_log_concurrency.py
echo '::endgroup::'

echo '::group::Install-log HTTP request under SQLite contention'
PXE_INSTALL_LOG_HTTP_ISOLATED=1 python3 tests/integration/test_install_log_http_contention.py
echo '::endgroup::'

echo '::group::Optional Ansible package and Vault execution'
python3 tests/unit/test_ansible_runtime_observation.py
python3 tests/integration/test_ansible_runtime.py
python3 tests/integration/test_ansible_module_candidate.py
echo '::endgroup::'
