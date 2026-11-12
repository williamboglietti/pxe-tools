#!/usr/bin/env python3
"""Exercise signed APT publication ordering and failures without contacting R2."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import functools
import http.server
import shutil
import threading
from unittest.mock import patch

if os.environ.get('PXE_R2_PUBLISH_FIXTURE') != '1':
    raise SystemExit('Run only with PXE_R2_PUBLISH_FIXTURE=1 in a disposable environment')
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pxe_r2', ROOT/'packaging/ci/publish-r2-snapshot.py')
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
offer_spec = importlib.util.spec_from_file_location('pxe_offer', ROOT/'packaging/ci/publish-source-offer.py')
assert offer_spec and offer_spec.loader
offer = importlib.util.module_from_spec(offer_spec)
offer_spec.loader.exec_module(offer)
release_spec = importlib.util.spec_from_file_location('pxe_approved', ROOT/'packaging/ci/verify-approved-release.py')
assert release_spec and release_spec.loader
approved = importlib.util.module_from_spec(release_spec)
release_spec.loader.exec_module(approved)
proof_spec = importlib.util.spec_from_file_location('pxe_proof', ROOT/'packaging/ci/verify-published-provenance.py')
assert proof_spec and proof_spec.loader
proof = importlib.util.module_from_spec(proof_spec)
proof_spec.loader.exec_module(proof)
preflight_spec=importlib.util.spec_from_file_location('pxe_preflight', ROOT/'packaging/ci/r2-permissions-preflight.py')
assert preflight_spec and preflight_spec.loader
preflight=importlib.util.module_from_spec(preflight_spec);preflight_spec.loader.exec_module(preflight)


def run(*args):
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout


class Store:
    def __init__(self): self.objects={}; self.calls=[]; self.cas_failure=False
    def head(self, key):
        self.calls.append(('head', key))
        return '"etag"' if key in self.objects else None
    def get(self, key):
        self.calls.append(('get', key)); return self.objects[key]
    def put(self, key, data, cache, condition=None):
        self.calls.append(('put', key, cache, condition))
        if self.cas_failure and key.endswith('/InRelease'): return False
        if condition == ('--if-none-match', '*') and key in self.objects: return False
        self.objects[key]=data; return True


class SnapshotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory(prefix='pxe-r2-publish-test-')
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
        extra=package/'usr/share/fixture';extra.mkdir(parents=True)
        (extra/'changed').write_text('different content with same version\n')
        changed=cls.root/'changed.deb';run('dpkg-deb','--build','--root-owner-group',str(package),str(changed))
        cls.conflicting_repo=cls.root/'conflicting-repository'
        run('python3',str(ROOT/'packaging/native/build-apt-release-candidate.py'),str(changed),str(cls.conflicting_repo),
            '--gnupg-home',str(cls.home),'--fingerprint',cls.signing,'--valid-days','2',
            '--source-commit','a'*40,'--build-run-id','123','--approved-package-sha256',mod.digest(changed.read_bytes()))
        cls.sources_dir=cls.root/'sources';cls.sources_dir.mkdir()
        content={name: ('fixture '+name+'\n').encode() for name in mod.SOURCE_NAMES if name != 'SHA256SUMS'}
        sums=''.join(mod.digest(data)+'  '+name+'\n' for name,data in sorted(content.items())).encode()
        content['SHA256SUMS']=sums
        for name,data in content.items():(cls.sources_dir/name).write_bytes(data)
        cls.manifest=cls.root/'sources.json'
        import json
        cls.manifest.write_text(json.dumps({name:mod.digest(data) for name,data in content.items()}))

    @classmethod
    def tearDownClass(cls):
        subprocess.run(['gpgconf','--homedir',str(cls.home),'--kill','gpg-agent'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        cls.tmp.cleanup()

    def snapshot(self): return mod.validate_snapshot(self.repo,self.keyring,self.primary,self.signing)
    def sources(self): return mod.validate_sources(self.sources_dir,self.manifest)

    def test_verify_and_publish_create_only_then_inrelease_last(self):
        snapshot=self.snapshot();store=Store();mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        puts=[x for x in store.calls if x[0]=='put']
        self.assertEqual(puts[-1][1],'apt/dists/stable/InRelease')
        self.assertEqual(puts[-1][3],('--if-none-match','*'))
        for _,key,cache,condition in puts:
            if '/by-hash/' in key or '/pool/' in key or '/versions/' in key or key.startswith('sources/') or key=='pxe-tools-archive-keyring.gpg':
                self.assertEqual(condition,('--if-none-match','*'));self.assertEqual(cache,mod.IMMUTABLE_CACHE)
            else:self.assertEqual(cache,mod.MUTABLE_CACHE)
        # A renewal keeps prior immutable bytes, uses CAS and performs no delete.
        mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        self.assertEqual(store.calls[-1][3],('--if-match','"etag"'))
        self.assertTrue(all(x[0] in ('head','put','get') for x in store.calls))

    def test_conflicting_immutable_object_blocks_inrelease(self):
        snapshot=self.snapshot();store=Store()
        name=next(x for x in snapshot if x.startswith('pool/'))
        store.objects['apt/'+name]=b'not-the-expected-content'
        with self.assertRaisesRegex(RuntimeError,'immutable'):mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        self.assertNotIn('apt/dists/stable/InRelease',store.objects)

    def test_concurrent_pointer_change_is_refused(self):
        snapshot=self.snapshot();store=Store();store.cas_failure=True
        with self.assertRaisesRegex(RuntimeError,'concurrently'):mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        self.assertNotIn('apt/dists/stable/InRelease',store.objects)

    def test_public_source_failure_prevents_release_pointer(self):
        snapshot=self.snapshot();store=Store()
        def unavailable(_sources,_keyring):raise RuntimeError('public offer unavailable')
        with self.assertRaisesRegex(RuntimeError,'public offer'):
            mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,unavailable)
        self.assertNotIn('apt/dists/stable/InRelease',store.objects)

    def test_untrusted_existing_pointer_blocks_every_write(self):
        snapshot=self.snapshot();store=Store()
        store.objects['apt/dists/stable/InRelease']=b'untrusted existing repository'
        with self.assertRaisesRegex(ValueError,'signature'):mod.publish(snapshot,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        self.assertFalse(any(x[0]=='put' for x in store.calls))

    def test_same_debian_version_cannot_change_package_bytes(self):
        store=Store();first=self.snapshot()
        mod.publish(first,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        prior=store.objects['apt/dists/stable/InRelease']
        changed=mod.validate_snapshot(self.conflicting_repo,self.keyring,self.primary,self.signing)
        with self.assertRaisesRegex(RuntimeError,'different package bytes'):
            mod.publish(changed,self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
        self.assertEqual(store.objects['apt/dists/stable/InRelease'],prior)

    def test_tampered_package_and_unknown_file_are_rejected(self):
        self.snapshot()
        pkg=next((self.repo/'pool').rglob('*.deb'));before=pkg.read_bytes()
        try:
            pkg.write_bytes(before+b'x')
            with self.assertRaisesRegex(ValueError,'digest'):self.snapshot()
        finally:pkg.write_bytes(before)
        extra=self.repo/'unexpected';extra.write_text('not approved')
        try:
            with self.assertRaisesRegex(ValueError,'Unexpected or corrupt'):self.snapshot()
        finally:extra.unlink()

    def test_source_offer_checked_before_any_publication(self):
        original=(self.sources_dir/'README.md').read_bytes()
        try:
            (self.sources_dir/'README.md').write_bytes(b'tampered')
            with self.assertRaisesRegex(ValueError,'checksum'):self.sources()
        finally:(self.sources_dir/'README.md').write_bytes(original)

    def test_source_only_publish_is_idempotent_and_never_touches_apt(self):
        store=Store();mod.publish_sources(self.sources(),store)
        self.assertEqual(len(store.objects),5)
        self.assertTrue(all(key.startswith(mod.SOURCE_PREFIX) for key in store.objects))
        mod.publish_sources(self.sources(),store)
        self.assertTrue(all(key.startswith(mod.SOURCE_PREFIX) for key in store.objects))

    def test_existing_source_collision_is_rejected(self):
        store=Store();key=mod.SOURCE_PREFIX+'README.md';store.objects[key]=b'wrong bytes'
        with self.assertRaisesRegex(RuntimeError,'source object differs'):
            mod.publish_sources(self.sources(),store)
        self.assertFalse(any(key.startswith('apt/') for key in store.objects))

    def test_source_release_metadata_must_match_the_pinned_offer(self):
        content=self.sources()
        release={'tag_name':'sources-2026-11-12','draft':False,'published_at':'fixture',
                 'assets':[{'name':name,'state':'uploaded','size':len(data),'digest':'sha256:'+mod.digest(data)}
                           for name,data in content.items()]}
        self.assertEqual(offer.verify_release(release,self.sources_dir,self.manifest),content)
        release['assets'][0]['digest']='sha256:'+'0'*64
        with self.assertRaisesRegex(ValueError,'pinned digest'):
            offer.verify_release(release,self.sources_dir,self.manifest)

    def test_aws_cli_adapter_with_isolated_fake_s3(self):
        with tempfile.TemporaryDirectory(prefix='pxe-fake-r2-') as tmp:
            root=Path(tmp);binary=root/'bin';binary.mkdir();storage=root/'objects';storage.mkdir()
            aws=binary/'aws'
            aws.write_text('''#!/usr/bin/env python3
import hashlib,json,os,pathlib,sys
a=sys.argv[1:];a=a[a.index('s3api')+1:];operation=a[0]
if '--generate-cli-skeleton' in a:
 print(json.dumps({'IfNoneMatch':'','IfMatch':''}));sys.exit(0)
def get(k):return a[a.index(k)+1]
assert get('--bucket')=='pxe-tools-packages'
key=get('--key');assert '..' not in key.split('/')
p=pathlib.Path(os.environ['PXE_FAKE_R2_ROOT'])/key
def etag():return '"'+hashlib.md5(p.read_bytes()).hexdigest()+'"'
if operation=='head-object':
 if not p.exists():print('404 NotFound',file=sys.stderr);sys.exit(1)
 print(json.dumps({'ETag':etag()}));sys.exit(0)
if operation=='get-object':
 pathlib.Path(a[-1]).write_bytes(p.read_bytes());print('{}');sys.exit(0)
if operation=='delete-object':
 assert key.startswith('preflight/pxe-ci-');p.unlink(missing_ok=True);print('{}');sys.exit(0)
if operation=='put-object':
 if '--if-none-match' in a and p.exists():print('412 PreconditionFailed',file=sys.stderr);sys.exit(1)
 if '--if-match' in a and (not p.exists() or get('--if-match')!=etag()):print('412 PreconditionFailed',file=sys.stderr);sys.exit(1)
 p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(pathlib.Path(get('--body')).read_bytes());print('{}');sys.exit(0)
raise RuntimeError('Unexpected S3 operation')
''')
            aws.chmod(0o755)
            with patch.dict(os.environ,{'PATH':str(binary)+':'+os.environ['PATH'],'PXE_FAKE_R2_ROOT':str(storage)}):
                store=mod.AwsR2('https://'+'0'*32+'.r2.cloudflarestorage.com')
                preflight.check(store)
                mod.publish(self.snapshot(),self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
                first=(storage/'apt/dists/stable/InRelease').read_bytes()
                mod.publish(self.snapshot(),self.sources(),store,self.keyring.read_bytes(),self.primary,self.signing,lambda _sources,_keyring:None)
                self.assertEqual((storage/'apt/dists/stable/InRelease').read_bytes(),first)

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

    def test_real_apt_fetches_content_addressed_index_over_http(self):
        with tempfile.TemporaryDirectory(prefix='pxe-apt-byhash-') as tmp:
            root=Path(tmp);web=root/'web';shutil.copytree(self.repo,web)
            index=web/'dists/stable/main/binary-amd64'
            (index/'Packages').write_text('stale')
            (index/'Packages.gz').write_text('stale')
            class Handler(http.server.SimpleHTTPRequestHandler):
                def log_message(self,*_args):pass
            handler=functools.partial(Handler,directory=str(web))
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),handler)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                lists=root/'lists';(lists/'partial').mkdir(parents=True)
                status=root/'status';status.touch()
                source=root/'source.list'
                source.write_text(f'deb [signed-by={self.keyring}] http://127.0.0.1:{server.server_port} stable main\n')
                options=['-o','APT::Sandbox::User=root','-o',f'Dir::Etc::sourcelist={source}',
                         '-o','Dir::Etc::sourceparts=-','-o',f'Dir::State::lists={lists}',
                         '-o',f'Dir::State::status={status}','-o','Dir::Cache::pkgcache=',
                         '-o','Dir::Cache::srcpkgcache=']
                result=subprocess.run(['apt-get',*options,'update'],text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                if result.returncode:
                    self.fail('APT rejected the signed by-hash fixture: '+result.stdout[-2500:]+result.stderr[-1000:])
                output=run('apt-cache',*options,'policy','pxe-tools').decode()
                self.assertIn('Candidate: 1.0~fixture1',output)
            finally:
                server.shutdown();server.server_close();thread.join()

    def test_unexpected_signer_is_rejected(self):
        with self.assertRaisesRegex(ValueError,'signing subkey'):
            mod.validate_snapshot(self.repo,self.keyring,self.primary,'A'*40)


if __name__=='__main__':unittest.main(verbosity=2)
