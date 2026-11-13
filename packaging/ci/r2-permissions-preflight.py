#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise R2 permissions only on a uniquely named disposable object."""
import argparse
import importlib.util
from pathlib import Path
import uuid

here=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('pxe_r2',here/'publish-r2-snapshot.py')
if spec is None or spec.loader is None:raise RuntimeError('R2 publisher unavailable')
publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)


def check(store) -> None:
    key='preflight/pxe-ci-'+uuid.uuid4().hex
    # A missing HEAD must be 404, not an ambiguous 403, before any write.
    if store.head(key) is not None:raise RuntimeError('Unexpected preflight object already exists')
    created=False
    try:
        if not store.put(key,b'pxe-preflight-a\n','no-store',('--if-none-match','*')):
            raise RuntimeError('Create-only preflight object was not created')
        created=True
        if store.put(key,b'pxe-preflight-b\n','no-store',('--if-none-match','*')):
            raise RuntimeError('Create-only condition was not enforced')
        first=store.head(key)
        if not first or store.get(key)!=b'pxe-preflight-a\n':raise RuntimeError('Preflight readback failed')
        if not store.put(key,b'pxe-preflight-b\n','no-store',('--if-match',first)):
            raise RuntimeError('Matching conditional update failed')
        if store.put(key,b'pxe-preflight-c\n','no-store',('--if-match',first)):
            raise RuntimeError('Stale conditional update unexpectedly succeeded')
        if store.get(key)!=b'pxe-preflight-b\n':raise RuntimeError('Preflight conditional data differs')
    finally:
        if created:
            store.delete_fixture(key)
    if store.head(key) is not None:raise RuntimeError('Preflight cleanup could not be verified')


def main() -> None:
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--endpoint',required=True)
    args=parser.parse_args();check(publisher.AwsR2(args.endpoint))
    print('R2 missing-object HEAD, conditional create/update, readback and fixture cleanup verified.')


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,RuntimeError,UnicodeError) as exc:raise SystemExit(str(exc))
