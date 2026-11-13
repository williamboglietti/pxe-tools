#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Bind a published .deb to an approved version, tag, source and build run."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess


def verify(manifest: dict, release: dict, build: dict, jobs: dict, package: Path, version_file: Path,
           tag_commit: str, ansible_package: Path | None = None) -> None:
    required = {'version', 'release_tag', 'source_commit', 'build_run_id', 'package_asset', 'package_sha256'}
    module_fields = {'ansible_version', 'ansible_package_asset', 'ansible_package_sha256'}
    if not isinstance(manifest, dict) or set(manifest) not in (required, required | module_fields):
        raise ValueError('Release manifest fields are incomplete or unexpected')
    has_module = module_fields <= set(manifest)
    if has_module != (ansible_package is not None):
        raise ValueError('Approved module and its package must be supplied together')
    version, tag, source, run_id, asset, checksum = (manifest[k] for k in
        ('version', 'release_tag', 'source_commit', 'build_run_id', 'package_asset', 'package_sha256'))
    if (not isinstance(version, str) or not re.fullmatch(r'[0-9][A-Za-z0-9.+~_-]{0,100}', version)
            or not isinstance(tag, str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,99}', tag)
            or not isinstance(source, str) or not re.fullmatch(r'[a-f0-9]{40}', source)
            or not isinstance(run_id, str) or not re.fullmatch(r'[1-9][0-9]{0,19}', run_id)
            or not isinstance(checksum, str) or not re.fullmatch(r'[a-f0-9]{64}', checksum)
            or source == '0'*40 or checksum == '0'*64
            or not isinstance(asset, str) or not re.fullmatch(r'pxe-tools_[A-Za-z0-9._-]+_amd64\.deb', asset)):
        raise ValueError('Invalid release manifest value')
    if version_file.read_text(encoding='utf-8').strip() != version:
        raise ValueError('Manifest version does not match the tracked version')
    if subprocess.run(['dpkg', '--validate-version', version], capture_output=True).returncode:
        raise ValueError('Invalid Debian version')
    if tag_commit != source:
        raise ValueError('Release tag does not point to the approved product commit')
    if (release.get('tag_name') != tag or release.get('draft') is not False
            or not release.get('published_at') or release.get('prerelease') is not ('~' in version)):
        raise ValueError('Published release status or tag is inconsistent')
    if (build.get('path') != '.github/workflows/apt-package-candidate.yml'
            or str(build.get('id')) != run_id or build.get('head_sha') != source
            or build.get('head_branch') != 'main' or build.get('event') != 'workflow_dispatch'
            or build.get('conclusion') != 'success'):
        raise ValueError('Approved build run does not match the product commit')
    all_jobs = jobs.get('jobs')
    if not isinstance(all_jobs, list): raise ValueError('Build job information is missing')
    package_jobs = [job for job in all_jobs if job.get('name') == 'package']
    behavior_jobs = [job for job in all_jobs if job.get('name') == 'application-behavior / Application behavior (Debian 13)']
    if (len(package_jobs) != 1 or len(behavior_jobs) != 1
            or any(job.get('conclusion') != 'success' for job in package_jobs+behavior_jobs)):
        raise ValueError('Package build and application behavior checks must both succeed')
    matches = [item for item in release.get('assets', []) if item.get('name') == asset]
    if len(matches) != 1 or matches[0].get('state') != 'uploaded':
        raise ValueError('Exactly one uploaded release asset is required')
    if package.is_symlink() or not package.is_file() or package.name != asset:
        raise ValueError('Package file does not match the manifest')
    record = matches[0]
    if record.get('size') != package.stat().st_size or record.get('digest') != 'sha256:'+checksum:
        raise ValueError('Release metadata differs from the approved package')
    with package.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != checksum:
            raise ValueError('Downloaded package differs from the approved package')
    def field(name):
        result = subprocess.run(['dpkg-deb', '--field', str(package), name],capture_output=True,text=True,check=True)
        return result.stdout.strip()
    if field('Package') != 'pxe-tools' or field('Version') != version or field('Architecture') != 'amd64':
        raise ValueError('Package metadata differs from the approved release')
    if has_module:
        module_version, module_asset, module_sha = (manifest[k] for k in
            ('ansible_version', 'ansible_package_asset', 'ansible_package_sha256'))
        if (module_version != version or not isinstance(module_asset, str)
                or not re.fullmatch(r'pxe-tools-ansible_[A-Za-z0-9._-]+_all\.deb', module_asset)
                or not isinstance(module_sha, str) or not re.fullmatch(r'[a-f0-9]{64}', module_sha)
                or module_sha == '0'*64):
            raise ValueError('Invalid approved module identity')
        matches = [item for item in release.get('assets', []) if item.get('name') == module_asset]
        if (len(matches) != 1 or matches[0].get('state') != 'uploaded' or ansible_package.is_symlink()
                or not ansible_package.is_file() or ansible_package.name != module_asset
                or matches[0].get('size') != ansible_package.stat().st_size
                or matches[0].get('digest') != 'sha256:'+module_sha):
            raise ValueError('Published module does not match the approved asset')
        with ansible_package.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != module_sha:
                raise ValueError('Downloaded module differs from the approved build')
        def module_field(name):
            result = subprocess.run(['dpkg-deb', '--field', str(ansible_package), name],
                                    capture_output=True, text=True, check=True)
            return result.stdout.strip()
        if (module_field('Package') != 'pxe-tools-ansible' or module_field('Version') != module_version
                or module_field('Architecture') != 'all'):
            raise ValueError('Module metadata differs from the approved release')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest',type=Path,required=True)
    parser.add_argument('--release-json',type=Path,required=True)
    parser.add_argument('--build-json',type=Path,required=True)
    parser.add_argument('--build-jobs-json',type=Path,required=True)
    parser.add_argument('--package',type=Path,required=True)
    parser.add_argument('--version-file',type=Path,required=True)
    parser.add_argument('--tag-commit',required=True)
    parser.add_argument('--ansible-package',type=Path)
    args=parser.parse_args()
    verify(json.loads(args.manifest.read_text()), json.loads(args.release_json.read_text()),
           json.loads(args.build_json.read_text()), json.loads(args.build_jobs_json.read_text()),
           args.package, args.version_file, args.tag_commit, args.ansible_package)
    print('Approved product commit, successful build, release tag, package version and SHA-256 verified.')


if __name__=='__main__':
    try:main()
    except (OSError, ValueError, TypeError, KeyError, subprocess.CalledProcessError) as exc:raise SystemExit(str(exc))
