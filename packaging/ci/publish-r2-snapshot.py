#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Publish an already-signed APT snapshot to the dedicated PXE Tools R2 bucket.

Requires AWS CLI v2 with conditional PutObject support. No object is deleted;
content-addressed objects are create-only, and InRelease is committed last.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

BUCKET = 'pxe-tools-packages'
PREFIX = 'apt/'
MUTABLE_CACHE = 'no-store, max-age=0'
IMMUTABLE_CACHE = 'public, max-age=31536000, immutable'
SOURCE_PREFIX = 'sources/static-helpers/2026-11-12/'
SOURCE_NAMES = {'README.md', 'relink-helpers.sh', 'pxe-tools-static-helper-sources.tar',
                'pxe-tools-static-helper-relink-materials.tar.gz', 'SHA256SUMS'}


def digest(data: bytes, algorithm: str = 'sha256') -> str:
    return hashlib.new(algorithm, data).hexdigest()


def verify_signature(keyring: Path, infile: Path, signing: str, primary: str,
                     *more: Path, output: Path | None = None) -> None:
    args = ['gpgv', '--status-fd', '1', '--keyring', str(keyring)]
    if output is not None:
        args += ['--output', str(output)]
    result = subprocess.run([*args, str(infile), *map(str, more)], capture_output=True)
    if result.returncode:
        raise ValueError('APT signature verification failed')
    rows = [line.split() for line in result.stdout.decode('utf-8').splitlines()
            if line.startswith('[GNUPG:] VALIDSIG ')]
    if len(rows) != 1 or rows[0][2].upper() != signing or rows[0][-1].upper() != primary:
        raise ValueError('Unexpected APT signing subkey or primary')


def validate_keyring(keyring: Path, primary: str, signing: str) -> None:
    if keyring.is_symlink() or not keyring.is_file() or keyring.stat().st_size > 131072:
        raise ValueError('Public keyring must be a small regular file')
    shown = subprocess.run(['gpg', '--batch', '--with-colons', '--show-keys', str(keyring)], capture_output=True)
    packets = subprocess.run(['gpg', '--batch', '--list-packets', str(keyring)], capture_output=True)
    if shown.returncode or packets.returncode:
        raise ValueError('Cannot inspect public keyring')
    rows = [line.split(':') for line in shown.stdout.decode('utf-8').splitlines()]
    fingerprints = [row[9].upper() for row in rows if row[0] == 'fpr']
    if (len([row for row in rows if row[0] == 'pub']) != 1
            or len([row for row in rows if row[0] == 'sub']) != 1
            or fingerprints != [primary, signing]
            or any(row[0] in ('sec', 'ssb') for row in rows)
            or re.search(rb':secret (?:sub )?key packet:', packets.stdout)):
        raise ValueError('Keyring must contain only the expected public primary and signing subkey')


def validate_snapshot(root: Path, keyring: Path, primary: str, signing: str) -> dict[str, bytes]:
    if root.is_symlink():
        raise ValueError('Snapshot root must not be a symlink')
    root = root.resolve(strict=True)
    if not root.is_dir() or keyring.is_symlink() or not keyring.is_file():
        raise ValueError('Snapshot and public keyring required')
    for value in (primary, signing):
        if not re.fullmatch(r'(?:[A-F0-9]{40}|[A-F0-9]{64})', value):
            raise ValueError('Full uppercase fingerprint required')
    validate_keyring(keyring, primary, signing)
    entries: dict[str, bytes] = {}
    for path in root.rglob('*'):
        if path.is_symlink() or not (path.is_file() or path.is_dir()):
            raise ValueError('Snapshot has a symlink or special file')
        if path.is_file():
            if path.stat().st_size > 1024 * 1024 * 1024:
                raise ValueError('Snapshot object too large')
            entries[path.relative_to(root).as_posix()] = path.read_bytes()
    base = 'dists/stable/'
    required = {base+'InRelease', base+'Release', base+'Release.gpg',
                base+'main/binary-amd64/Packages', base+'main/binary-amd64/Packages.gz'}
    if not required <= entries.keys():
        raise ValueError('APT snapshot is incomplete')
    with tempfile.TemporaryDirectory(prefix='pxe-r2-verify-') as tmp:
        extracted = Path(tmp)/'release'
        verify_signature(keyring, root/(base+'InRelease'), signing, primary, output=extracted)
        verify_signature(keyring, root/(base+'Release.gpg'), signing, primary, root/(base+'Release'))
        if extracted.read_bytes() != entries[base+'Release']:
            raise ValueError('InRelease and detached Release differ')
    release = entries[base+'Release'].decode('utf-8')
    fields = {}
    for line in release.splitlines():
        if line and not line[0].isspace() and ': ' in line:
            name, value = line.split(': ', 1)
            if name in fields:
                raise ValueError('Duplicate Release field')
            fields[name] = value
    if fields.get('Suite') != 'stable' or fields.get('Acquire-By-Hash') != 'yes':
        raise ValueError('Unexpected suite or missing by-hash')
    expires = parsedate_to_datetime(fields.get('Valid-Until', ''))
    if expires.tzinfo is None or expires <= datetime.now(timezone.utc) + timedelta(hours=24):
        raise ValueError('Release expires too soon')
    packages = entries[base+'main/binary-amd64/Packages']
    packed = entries[base+'main/binary-amd64/Packages.gz']
    if gzip.decompress(packed) != packages:
        raise ValueError('Compressed index differs')
    lines = release.splitlines()
    for algorithm in ('SHA256', 'SHA512'):
        try: offset = lines.index(algorithm+':') + 1
        except ValueError as exc: raise ValueError('Signed index hash missing') from exc
        signed = {}
        for line in lines[offset:]:
            if not line.startswith(' '): break
            parts = line.split()
            if len(parts) != 3: raise ValueError('Invalid signed hash row')
            signed[parts[2]] = (parts[0].lower(), int(parts[1]))
        for name, data in (('Packages', packages), ('Packages.gz', packed)):
            rel = 'main/binary-amd64/'+name
            if signed.get(rel) != (digest(data, algorithm.lower()), len(data)):
                raise ValueError('Signed APT index mismatch')
            byhash = base+'main/binary-amd64/by-hash/'+algorithm+'/'+digest(data, algorithm.lower())
            if entries.get(byhash) != data:
                raise ValueError('Content-addressed APT index missing')
    pool = set()
    for paragraph in packages.decode('utf-8').strip().split('\n\n'):
        metadata = {}
        for line in paragraph.splitlines():
            if line and not line[0].isspace() and ': ' in line:
                name, value = line.split(': ', 1)
                if name in metadata: raise ValueError('Duplicate package field')
                metadata[name] = value
        name = metadata.get('Filename', '')
        if not re.fullmatch(r'pool/main/p/pxe-tools/pxe-tools-[a-f0-9]{64}\.deb', name):
            raise ValueError('Unexpected package path')
        data = entries.get(name)
        if data is None or metadata.get('SHA256') != digest(data) or name.rsplit('-', 1)[-1] != digest(data)+'.deb':
            raise ValueError('Package digest mismatch')
        if metadata.get('Size') != str(len(data)):
            raise ValueError('Package size mismatch')
        pool.add(name)
    # Previous releases may need their package and by-hash objects until
    # their Valid-Until expires. Accept only content-addressed retained files
    # and verify each against its filename; no mutable old metadata is copied.
    for name, data in entries.items():
        if name in required:
            continue
        match = re.fullmatch(r'pool/main/p/pxe-tools/pxe-tools-([a-f0-9]{64})\.deb', name)
        algorithm = 'sha256'
        if not match:
            match = re.fullmatch(re.escape(base)+r'main/binary-amd64/by-hash/SHA256/([a-f0-9]{64})', name)
        if not match:
            match = re.fullmatch(re.escape(base)+r'main/binary-amd64/by-hash/SHA512/([a-f0-9]{128})', name)
            algorithm = 'sha512'
        if not match or digest(data, algorithm) != match.group(1):
            raise ValueError('Unexpected or corrupt retained immutable object')
    return entries


def validate_sources(root: Path, manifest: Path) -> dict[str, bytes]:
    if root.is_symlink() or not root.is_dir() or manifest.is_symlink() or not manifest.is_file():
        raise ValueError('Source offer and pinned manifest must be regular paths')
    expected = json.loads(manifest.read_text(encoding='utf-8'))
    if not isinstance(expected, dict) or set(expected) != SOURCE_NAMES:
        raise ValueError('Unexpected source-offer manifest')
    actual = {path.name: path for path in root.iterdir()}
    if set(actual) != SOURCE_NAMES:
        raise ValueError('Source offer is incomplete or has unexpected files')
    result = {}
    for name, path in actual.items():
        if (path.is_symlink() or not path.is_file() or path.stat().st_size > 1024 * 1024 * 1024
                or not isinstance(expected[name], str) or not re.fullmatch(r'[a-f0-9]{64}', expected[name])):
            raise ValueError('Invalid source-offer file')
        data = path.read_bytes()
        if digest(data) != expected[name]:
            raise ValueError('Source-offer checksum mismatch')
        result[name] = data
    declared = {}
    for line in result['SHA256SUMS'].decode('utf-8').splitlines():
        match = re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9._-]+)', line)
        if not match or match.group(2) in declared:
            raise ValueError('Invalid source-offer SHA256SUMS')
        declared[match.group(2)] = match.group(1)
    if declared != {name: value for name, value in expected.items() if name != 'SHA256SUMS'}:
        raise ValueError('Source-offer checksums are inconsistent')
    return result


def package_identity(snapshot: dict[str, bytes]) -> tuple[str, str]:
    path = 'dists/stable/main/binary-amd64/Packages'
    paragraphs = snapshot[path].decode('utf-8').strip().split('\n\n')
    if len(paragraphs) != 1:
        raise ValueError('A release must contain exactly one base package')
    fields = {}
    for line in paragraphs[0].splitlines():
        if line and not line[0].isspace() and ': ' in line:
            key, value = line.split(': ', 1)
            if key in fields: raise ValueError('Duplicate package field')
            fields[key] = value
    version, sha = fields.get('Version', ''), fields.get('SHA256', '')
    if (fields.get('Package') != 'pxe-tools' or fields.get('Architecture') != 'amd64'
            or not re.fullmatch(r'[0-9][A-Za-z0-9.+~_-]{0,100}', version)
            or not re.fullmatch(r'[a-f0-9]{64}', sha)):
        raise ValueError('Unexpected package identity')
    return version, sha


def verify_provenance(release: bytes, version: str, sha: str, source: str, build_run: str) -> None:
    if (not re.fullmatch(r'[a-f0-9]{40}', source) or not re.fullmatch(r'[1-9][0-9]{0,19}', build_run)
            or not re.fullmatch(r'[a-f0-9]{64}', sha)):
        raise ValueError('Invalid approved build provenance')
    text = release.decode('utf-8').splitlines()
    for field, value in (('X-PXE-Source-Commit', source), ('X-PXE-Build-Run', build_run),
                         ('X-PXE-Package-SHA256', sha), ('X-PXE-Package-Version', version)):
        matches = [line for line in text if line.startswith(field+':')]
        if matches != [field+': '+value]:
            raise ValueError('Signed APT provenance does not match the approved build')


class AwsR2:
    def __init__(self, endpoint: str):
        if not re.fullmatch(r'https://[0-9a-f]{32}\.r2\.cloudflarestorage\.com', endpoint):
            raise ValueError('Unexpected R2 account endpoint')
        self.base = ['aws', '--no-cli-pager', '--region', 'auto', '--endpoint-url', endpoint, 's3api']
        model = self.call(['put-object', '--generate-cli-skeleton', 'input'])
        skeleton = json.loads(model.stdout)
        if not {'IfNoneMatch', 'IfMatch'} <= skeleton.keys():
            raise RuntimeError('AWS CLI lacks conditional PutObject support')

    def call(self, args: list[str], *, allow_missing: bool = False) -> subprocess.CompletedProcess:
        result = subprocess.run([*self.base, *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode and not allow_missing:
            raise RuntimeError('R2 operation failed; details suppressed')
        return result

    def head(self, key: str) -> str | None:
        result = self.call(['head-object', '--bucket', BUCKET, '--key', key], allow_missing=True)
        if result.returncode:
            if re.search(r'\b(404|NoSuchKey|NotFound)\b', result.stderr): return None
            raise RuntimeError('Could not inspect R2 object; details suppressed')
        etag = json.loads(result.stdout).get('ETag')
        if not isinstance(etag, str) or not re.fullmatch(r'"[0-9a-f]{32}(?:-[0-9]+)?"', etag):
            raise RuntimeError('Unexpected R2 ETag')
        return etag

    def get(self, key: str) -> bytes:
        with tempfile.TemporaryDirectory(prefix='pxe-r2-get-') as tmp:
            path = Path(tmp)/'object'
            self.call(['get-object', '--bucket', BUCKET, '--key', key, str(path)])
            return path.read_bytes()

    def delete_fixture(self, key: str) -> None:
        if not re.fullmatch(r'preflight/pxe-ci-[a-f0-9]{32}', key):
            raise ValueError('Deletion is restricted to a unique preflight object')
        self.call(['delete-object', '--bucket', BUCKET, '--key', key])

    def put(self, key: str, data: bytes, cache: str, condition: tuple[str, str] | None = None) -> bool:
        with tempfile.TemporaryDirectory(prefix='pxe-r2-put-') as tmp:
            path = Path(tmp)/'object'; path.write_bytes(data)
            args = ['put-object', '--bucket', BUCKET, '--key', key, '--body', str(path),
                    '--cache-control', cache, '--content-type', 'application/octet-stream']
            if condition: args.extend(condition)
            result = self.call(args, allow_missing=True)
            if not result.returncode: return True
            if condition and re.search(r'\b(412|PreconditionFailed)\b', result.stderr): return False
            raise RuntimeError('R2 upload failed; details suppressed')


def publish_sources(sources: dict[str, bytes], store: AwsR2) -> None:
    if set(sources) != SOURCE_NAMES:
        raise ValueError('The complete source offer is required')
    for name in sorted(sources):
        key = SOURCE_PREFIX+name
        store.put(key, sources[name], IMMUTABLE_CACHE, ('--if-none-match', '*'))
        if digest(store.get(key)) != digest(sources[name]):
            raise RuntimeError('Existing immutable source object differs')


def verify_public_offer(sources: dict[str, bytes], keyring: bytes) -> None:
    objects = {SOURCE_PREFIX+name: data for name, data in sources.items()}
    objects['pxe-tools-archive-keyring.gpg'] = keyring
    with tempfile.TemporaryDirectory(prefix='pxe-r2-public-verify-') as tmp:
        path = Path(tmp)/'object'
        for name, expected in sorted(objects.items()):
            # Require a direct 200 from the intended public host; do not follow
            # redirects or rely on S3 API visibility to prove public access.
            result = subprocess.run(['curl', '--proto', '=https', '--silent', '--show-error',
                '--fail', '--max-time', '600', '--user-agent', 'curl/8.14.1', '--output', str(path),
                '--write-out', '%{http_code}', 'https://packages.pxetools.com/'+name], capture_output=True)
            if result.returncode or result.stdout != b'200':
                raise RuntimeError('Corresponding source or public key is unavailable over HTTPS')
            with path.open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != digest(expected):
                    raise RuntimeError('Public source or keyring checksum differs')


def verify_public_inrelease(expected: bytes) -> None:
    with tempfile.TemporaryDirectory(prefix='pxe-r2-public-index-') as tmp:
        path = Path(tmp)/'InRelease'
        result = subprocess.run(['curl', '--proto', '=https', '--silent', '--show-error',
            '--fail', '--max-time', '60', '--user-agent', 'curl/8.14.1', '--output', str(path),
            '--write-out', '%{http_code}', 'https://packages.pxetools.com/apt/dists/stable/InRelease'],
            capture_output=True)
        if result.returncode or result.stdout != b'200' or path.read_bytes() != expected:
            raise RuntimeError('InRelease was uploaded but public HTTPS verification failed')


def publish(snapshot: dict[str, bytes], sources: dict[str, bytes], store: AwsR2,
            keyring: bytes, primary: str, signing: str, public_verifier=verify_public_offer) -> None:
    base = 'dists/stable/'
    last = base+'InRelease'
    version, package_hash = package_identity(snapshot)
    # Compare-and-swap for the final pointer, protecting concurrent publishers.
    old_etag = store.head(PREFIX+last)
    if old_etag is not None:
        # Do not take ownership of an existing untrusted repository. The
        # current public pointer must already be signed by our exact key.
        with tempfile.TemporaryDirectory(prefix='pxe-r2-old-release-') as tmp:
            old = Path(tmp)/'InRelease'; old.write_bytes(store.get(PREFIX+last))
            trusted = Path(tmp)/'key.gpg'; trusted.write_bytes(keyring)
            verify_signature(trusted, old, signing, primary)
    key = 'pxe-tools-archive-keyring.gpg'
    store.put(key, keyring, IMMUTABLE_CACHE, ('--if-none-match', '*'))
    if digest(store.get(key)) != digest(keyring):
        raise RuntimeError('Existing immutable public keyring differs')
    # Required corresponding source and relink material must be available
    # before a signed index can expose the binaries.
    publish_sources(sources, store)
    # Reserve each Debian version for exactly one package digest. A failed or
    # interrupted publication can be retried only with the same bytes.
    ledger = json.dumps({'package': 'pxe-tools', 'version': version, 'sha256': package_hash},
                        sort_keys=True, separators=(',', ':')).encode('ascii') + b'\n'
    ledger_key = PREFIX+'versions/'+version+'.json'
    store.put(ledger_key, ledger, IMMUTABLE_CACHE, ('--if-none-match', '*'))
    if store.get(ledger_key) != ledger:
        raise RuntimeError('Debian version is already bound to different package bytes')
    immutable = sorted(name for name in snapshot if name.startswith('pool/') or '/by-hash/' in name)
    for name in immutable:
        key = PREFIX+name
        store.put(key, snapshot[name], IMMUTABLE_CACHE, ('--if-none-match', '*'))
        if digest(store.get(key)) != digest(snapshot[name]):
            raise RuntimeError('Existing immutable R2 object differs')
    for name in (base+'main/binary-amd64/Packages', base+'main/binary-amd64/Packages.gz'):
        store.put(PREFIX+name, snapshot[name], MUTABLE_CACHE)
        if store.get(PREFIX+name) != snapshot[name]: raise RuntimeError('R2 index verification failed')
    # Detached fallback can transiently fail closed during an update; normal
    # APT clients use the previously signed InRelease until the last operation.
    for name in (base+'Release', base+'Release.gpg'):
        store.put(PREFIX+name, snapshot[name], MUTABLE_CACHE)
        if store.get(PREFIX+name) != snapshot[name]: raise RuntimeError('R2 release verification failed')
    public_verifier(sources, keyring)
    condition = ('--if-none-match', '*') if old_etag is None else ('--if-match', old_etag)
    if not store.put(PREFIX+last, snapshot[last], MUTABLE_CACHE, condition):
        raise RuntimeError('InRelease changed concurrently; publication aborted')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', type=Path)
    parser.add_argument('keyring', type=Path)
    parser.add_argument('--sources-dir', type=Path, required=True)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--signing-fingerprint', required=True)
    parser.add_argument('--expected-version', required=True)
    parser.add_argument('--expected-sha256', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--build-run-id', required=True)
    parser.add_argument('--endpoint', required=True)
    args = parser.parse_args()
    snap = validate_snapshot(args.snapshot, args.keyring, args.primary_fingerprint, args.signing_fingerprint)
    if package_identity(snap) != (args.expected_version, args.expected_sha256):
        raise ValueError('Signed snapshot does not contain the approved package bytes')
    verify_provenance(snap['dists/stable/Release'], args.expected_version, args.expected_sha256,
                      args.source_commit, args.build_run_id)
    sources = validate_sources(args.sources_dir, Path(__file__).with_name('static-helpers-sources.json'))
    store = AwsR2(args.endpoint)
    publish(snap, sources, store, args.keyring.read_bytes(), args.primary_fingerprint, args.signing_fingerprint)
    verify_public_inrelease(snap['dists/stable/InRelease'])
    print('APT snapshot published to dedicated R2 bucket; InRelease committed last.')


if __name__ == '__main__':
    try: main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc: raise SystemExit(str(exc))
