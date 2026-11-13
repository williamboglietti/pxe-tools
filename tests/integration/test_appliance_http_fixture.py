#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Verify that isolated appliance benches satisfy the real Docker HTTP guard."""
import os
from pathlib import Path
import shutil
import sys

ROOT = Path(__file__).resolve().parents[2]
if (os.environ.get('PXE_APPLIANCE_HTTP_FIXTURE_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').is_file()):
    raise SystemExit('Run only as root in a disposable test container.')
sys.path.insert(0, str(ROOT/'app'))
from image_delete import require_trusted_docker_http_root
from appliance_http_fixture import prepare_http_fixture

http = Path('/srv/http')
http.mkdir(parents=True, exist_ok=True)
if any(http.iterdir()):
    raise SystemExit('Refusing to alter a nonempty HTTP root.')
old_manager = os.environ.get('PXE_SERVICE_MANAGER')
try:
    prepare_http_fixture(http)
    (http/'netboot').mkdir(mode=0o755)
    os.environ['PXE_SERVICE_MANAGER'] = 's6'
    require_trusted_docker_http_root(str(http), check_contents=True)
    assert (http/'static-bin/smartctl').is_file()
    print('Appliance fixture HTTP tree passes the real Docker guard.')
finally:
    if old_manager is None:
        os.environ.pop('PXE_SERVICE_MANAGER', None)
    else:
        os.environ['PXE_SERVICE_MANAGER'] = old_manager
    for child in http.iterdir():
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        else:
            child.unlink()
