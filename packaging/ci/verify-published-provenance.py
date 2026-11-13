#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Allow renewal after the original build artifact expires, using signed provenance."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile

here=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('pxe_apt_proof',here/'apt-proof.py')
if spec is None or spec.loader is None:raise RuntimeError('APT proof verifier unavailable')
publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)


def verify(inrelease: Path, keyring: Path, manifest: dict, primary: str, signing: str,
           packages: Path | None = None) -> None:
    # The signed previous metadata may have expired: it is used only as proof
    # of an earlier publication, not accepted as a currently usable APT index.
    publisher.validate_keyring(keyring,primary,signing)
    with tempfile.TemporaryDirectory(prefix='pxe-prior-proof-') as tmp:
        clear=Path(tmp)/'Release'
        publisher.verify_signature(keyring,inrelease,signing,primary,output=clear)
        release=clear.read_bytes()
        module = ((manifest['ansible_version'], manifest['ansible_package_sha256'])
                  if 'ansible_version' in manifest else None)
        publisher.verify_provenance(release,manifest['version'],manifest['package_sha256'],
                                    manifest['source_commit'],manifest['build_run_id'],module)
        lines=release.decode('utf-8').splitlines()
        try: offset=lines.index('SHA512:')+1
        except ValueError as exc:raise ValueError('Previous signed index has no SHA512') from exc
        matches=[]
        for line in lines[offset:]:
            if not line.startswith(' '):break
            parts=line.split()
            if len(parts)!=3:raise ValueError('Malformed prior SHA512 section')
            if parts[2]=='main/binary-amd64/Packages':matches.append(parts)
        if len(matches)!=1 or not re.fullmatch(r'[a-f0-9]{128}',matches[0][0]):
            raise ValueError('Prior signed package index is missing')
        expected,size=matches[0][0],int(matches[0][1])
        if size > 16*1024*1024:raise ValueError('Prior package index is too large')
        if packages is None:
            packages=Path(tmp)/'Packages'
            url=('https://packages.pxetools.com/apt/dists/stable/main/binary-amd64/'
                 'by-hash/SHA512/'+expected)
            r=subprocess.run(['curl','--proto','=https','--silent','--show-error','--fail',
                '--max-time','60','--user-agent','curl/8.14.1','--output',str(packages),
                '--write-out','%{http_code}',url],capture_output=True)
            if r.returncode or r.stdout != b'200':raise ValueError('Prior public by-hash index unavailable')
        data=packages.read_bytes()
        if len(data)!=size or hashlib.sha512(data).hexdigest()!=expected:
            raise ValueError('Prior package index differs from signed hash')
        version,sha=publisher.package_identity({'dists/stable/main/binary-amd64/Packages':data}, module)
        if (version,sha)!=(manifest['version'],manifest['package_sha256']):
            raise ValueError('Prior signed index does not contain the approved package')


def main() -> None:
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--inrelease',type=Path,required=True);p.add_argument('--keyring',type=Path,required=True)
    p.add_argument('--manifest',type=Path,required=True);p.add_argument('--primary-fingerprint',required=True)
    p.add_argument('--signing-fingerprint',required=True);p.add_argument('--packages',type=Path)
    args=p.parse_args()
    verify(args.inrelease,args.keyring,json.loads(args.manifest.read_text()),
           args.primary_fingerprint,args.signing_fingerprint,args.packages)
    print('Previously published signed provenance matches the approved build and package.')


if __name__=='__main__':
    try:main()
    except (OSError,ValueError,KeyError,TypeError,RuntimeError,UnicodeError) as exc:raise SystemExit(str(exc))
