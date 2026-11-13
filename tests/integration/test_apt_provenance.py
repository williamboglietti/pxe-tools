#!/usr/bin/env python3
"""Verify approved build provenance with disposable signed fixtures."""
import hashlib
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

if os.environ.get('PXE_APT_PROOF_FIXTURE') != '1':
    raise SystemExit('Run only with PXE_APT_PROOF_FIXTURE=1 in a disposable environment')
ROOT = Path(__file__).resolve().parents[2]
def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
proof = load('proof', ROOT/'packaging/ci/verify-published-provenance.py')
approved = load('approved', ROOT/'packaging/ci/verify-approved-release.py')
class Mod:
    @staticmethod
    def digest(data): return hashlib.sha256(data).hexdigest()
mod = Mod()
def run(*args):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

class SnapshotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='pxe-apt-proof-test-')
        cls.root=Path(cls.tmp.name);cls.home=cls.root/'key';cls.home.mkdir(mode=0o700)
        gpg=['gpg','--homedir',str(cls.home),'--batch','--no-tty']
        run(*gpg,'--pinentry-mode','loopback','--passphrase','','--quick-generate-key',
            'Fixture <fixture@example.invalid>','ed25519','cert','1d')
        def fingers():
            return [x.split(':')[9] for x in run(*gpg,'--with-colons','--list-secret-keys').decode().splitlines() if x.startswith('fpr:')]
        cls.primary=fingers()[0]
        run(*gpg,'--pinentry-mode','loopback','--passphrase','','--quick-add-key',cls.primary,'ed25519','sign','1d')
        cls.signing=fingers()[1]
        cls.keyring=cls.root/'key.gpg';cls.keyring.write_bytes(run(*gpg,'--export',cls.primary))
        package=cls.root/'package';(package/'DEBIAN').mkdir(parents=True)
        (package/'DEBIAN/control').write_text('Package: pxe-tools\nVersion: 1.0~fixture1\nArchitecture: amd64\nMaintainer: Fixture <fixture@example.invalid>\nDescription: fixture only\n')
        deb=cls.root/'fixture.deb';run('dpkg-deb','--build','--root-owner-group',str(package),str(deb))
        cls.approved_deb=cls.root/'pxe-tools_1.0-fixture1_amd64.deb'
        cls.approved_deb.write_bytes(deb.read_bytes())
        cls.repo=cls.root/'repository'
        run('python3',str(ROOT/'packaging/native/build-apt-release-candidate.py'),str(deb),str(cls.repo),
            '--gnupg-home',str(cls.home),'--fingerprint',cls.signing,'--valid-days','2',
            '--source-commit','a'*40,'--build-run-id','123','--approved-package-sha256',mod.digest(deb.read_bytes()))
    @classmethod
    def tearDownClass(cls):
        subprocess.run(['gpgconf','--homedir',str(cls.home),'--kill','gpg-agent'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        cls.tmp.cleanup()

    def test_release_asset_is_bound_to_successful_build_and_approved_commit(self):
        package=self.approved_deb;version='1.0~fixture1';source='a'*40;tag='vfixture';run_id='123'
        sha=mod.digest(package.read_bytes());vf=self.root/'tracked-version';vf.write_text(version+'\n')
        manifest={'version':version,'release_tag':tag,'source_commit':source,'build_run_id':run_id,
                  'package_asset':package.name,'package_sha256':sha}
        release={'tag_name':tag,'draft':False,'prerelease':True,'published_at':'fixture',
                 'assets':[{'name':package.name,'state':'uploaded','size':package.stat().st_size,'digest':'sha256:'+sha}]}
        build={'path':'.github/workflows/apt-package-candidate.yml','id':123,'head_sha':source,
               'head_branch':'main','event':'workflow_dispatch','conclusion':'success'}
        jobs={'jobs':[{'name':'package','conclusion':'success'},
                      {'name':'application-behavior / Application behavior (Debian 13)','conclusion':'success'}]}
        approved.verify(manifest,release,build,jobs,package,vf,source)
        with self.assertRaisesRegex(ValueError,'tag'):
            approved.verify(manifest,release,build,jobs,package,vf,'b'*40)
        changed=dict(build,head_sha='b'*40)
        with self.assertRaisesRegex(ValueError,'build run'):
            approved.verify(manifest,release,changed,jobs,package,vf,source)
        changed=dict(release,prerelease=False)
        with self.assertRaisesRegex(ValueError,'release status'):
            approved.verify(manifest,changed,build,jobs,package,vf,source)
        data=package.read_bytes()
        try:
            package.write_bytes(data+b'changed')
            with self.assertRaisesRegex(ValueError,'metadata|differs'):
                approved.verify(manifest,release,build,jobs,package,vf,source)
        finally:package.write_bytes(data)

    def test_signed_prior_provenance_enables_renewal_after_artifact_expiry(self):
        manifest={'version':'1.0~fixture1','package_sha256':mod.digest(self.approved_deb.read_bytes()),
                  'source_commit':'a'*40,'build_run_id':'123'}
        signed=self.repo/'dists/stable/InRelease'
        packages=self.repo/'dists/stable/main/binary-amd64/Packages'
        proof.verify(signed,self.keyring,manifest,self.primary,self.signing,packages)
        with self.assertRaisesRegex(ValueError,'provenance'):
            proof.verify(signed,self.keyring,{**manifest,'source_commit':'b'*40},self.primary,self.signing,packages)
        with tempfile.TemporaryDirectory() as tmp:
            bad=Path(tmp)/'Packages';bad.write_bytes(packages.read_bytes()+b'tampered')
            with self.assertRaisesRegex(ValueError,'signed hash'):
                proof.verify(signed,self.keyring,manifest,self.primary,self.signing,bad)
            # An expired prior Release remains evidence of the earlier signed
            # publication, but is never served as a current APT release here.
            old=Path(tmp)/'Release';old_signed=Path(tmp)/'InRelease'
            text=(self.repo/'dists/stable/Release').read_text()
            import re
            old.write_text(re.sub(r'(?m)^Valid-Until: .+$','Valid-Until: Sat, 01 Jan 2000 00:00:00 GMT',text))
            run('gpg','--homedir',str(self.home),'--batch','--no-tty','--local-user',self.signing+'!',
                '--output',str(old_signed),'--clearsign',str(old))
            proof.verify(old_signed,self.keyring,manifest,self.primary,self.signing,packages)

    def test_optional_module_is_bound_to_release_build_and_signed_index(self):
        version='0.1.0~beta1';source='a'*40;run_id='123';tag='vfixture'
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            def package(name, arch, depends=''):
                folder=root/name;(folder/'DEBIAN').mkdir(parents=True)
                (folder/'DEBIAN/control').write_text(
                    f'Package: {name}\nVersion: {version}\nArchitecture: {arch}\n'
                    f'Maintainer: Fixture <fixture@example.invalid>\n'
                    + (f'Depends: {depends}\n' if depends else '') + 'Description: fixture\n')
                filename=f'{name}_0.1.0-beta1_{arch}.deb';deb=root/filename
                run('dpkg-deb','--build','--root-owner-group',str(folder),str(deb))
                return deb
            base=package('pxe-tools','amd64')
            module=package('pxe-tools-ansible','all',
                           'pxe-tools (>= 0.1.0~beta1), pxe-tools (<< 0.2~), ansible-core')
            base_sha=mod.digest(base.read_bytes());module_sha=mod.digest(module.read_bytes())
            manifest={'version':version,'release_tag':tag,'source_commit':source,'build_run_id':run_id,
                      'package_asset':base.name,'package_sha256':base_sha,'ansible_version':version,
                      'ansible_package_asset':module.name,'ansible_package_sha256':module_sha}
            release={'tag_name':tag,'draft':False,'prerelease':True,'published_at':'fixture','assets':[
                {'name':p.name,'state':'uploaded','size':p.stat().st_size,'digest':'sha256:'+sha}
                for p,sha in ((base,base_sha),(module,module_sha))]}
            build={'path':'.github/workflows/apt-package-candidate.yml','id':123,'head_sha':source,
                   'head_branch':'main','event':'workflow_dispatch','conclusion':'success'}
            jobs={'jobs':[{'name':'package','conclusion':'success'},
                          {'name':'application-behavior / Application behavior (Debian 13)','conclusion':'success'}]}
            vf=root/'version';vf.write_text(version+'\n')
            approved.verify(manifest,release,build,jobs,base,vf,source,module)
            with self.assertRaisesRegex(ValueError,'module'):
                approved.verify(manifest,release,build,jobs,base,vf,source,None)
            with self.assertRaisesRegex(ValueError,'module'):
                approved.verify({**manifest,'ansible_package_sha256':'b'*64},release,build,jobs,base,vf,source,module)
            repository=root/'repository'
            common=['python3',str(ROOT/'packaging/native/build-apt-release-candidate.py'),str(base),str(repository),
                    '--gnupg-home',str(self.home),'--fingerprint',self.signing,'--valid-days','2',
                    '--source-commit',source,'--build-run-id',run_id,'--approved-package-sha256',base_sha,
                    '--ansible-package',str(module),'--approved-ansible-sha256',module_sha,
                    '--previous',str(self.repo)]
            run(*common)
            signed=repository/'dists/stable/InRelease';packages=repository/'dists/stable/main/binary-amd64/Packages'
            proof.verify(signed,self.keyring,manifest,self.primary,self.signing,packages)
            with self.assertRaisesRegex(ValueError,'module'):
                proof.verify(signed,self.keyring,{**manifest,'ansible_package_sha256':'b'*64},self.primary,self.signing,packages)
            without_module={k:v for k,v in manifest.items() if not k.startswith('ansible_')}
            with self.assertRaisesRegex(ValueError,'module'):
                proof.verify(signed,self.keyring,without_module,self.primary,self.signing,packages)
            failed=subprocess.run(common[:3]+[str(root/'bad')]+common[4:-3]+['b'*64]+common[-2:],capture_output=True)
            self.assertNotEqual(failed.returncode,0)


if __name__ == '__main__': unittest.main()
