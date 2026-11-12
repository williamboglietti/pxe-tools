#!/usr/bin/env python3
"""Prepare a PXE CA/server identity offline; never starts an HTTPS listener."""
import argparse
import hashlib
import hmac
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys

HELPER = Path(__file__).with_name('build-initrd-ca.py')
spec = importlib.util.spec_from_file_location('pxe_initrd_ca', HELPER)
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
BuildError = helper.BuildError


def run_openssl(args, data=None):
    try:
        result = subprocess.run(['openssl', *args], input=data, stdin=None if data is not None else subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise BuildError('OpenSSL indisponible ou délai dépassé.') from None
    if result.returncode:
        raise BuildError('Clé ou certificat invalide, identité non vérifiée, ou opération cryptographique impossible.')
    return result.stdout


def valid_identity(value):
    try:
        ip = ipaddress.ip_address(value)
        return str(ip), 'IP', '-verify_ip'
    except ValueError:
        pass
    value = value.lower()
    labels = value.split('.')
    if (not value or len(value) > 253 or all(c in '0123456789.' for c in value)
            or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in labels)):
        raise BuildError('Nom DNS ou adresse IP de serveur invalide.')
    return value, 'DNS', '-verify_hostname'


def read_regular(path, *, private=False):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(source.fileno())
            if (not stat.S_ISREG(info.st_mode) or info.st_size > 1024 * 1024
                    or (private and info.st_mode & 0o077)):
                raise BuildError('Fichier de certificat ou de clé inaccessible ou non protégé.')
            if private:
                helper.reject_extended_acl(source.fileno())
            data = source.read(1024 * 1024 + 1)
    except OSError:
        raise BuildError('Fichier de certificat ou de clé inaccessible ou non protégé.') from None
    if not data or len(data) > 1024 * 1024:
        raise BuildError('Fichier de certificat ou de clé invalide.')
    return data


def write_private(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as output:
        output.write(data)
        output.flush()
        os.fsync(output.fileno())


def validate_chain(stage, identity, flag):
    ca_path, cert_path, key_path = (stage / name for name in ('ca.pem', 'server.pem', 'server.key'))
    ca_pem, _, ca_fingerprint = helper.validate_ca(ca_path)
    chain = read_regular(cert_path)
    if b'PRIVATE KEY' in chain:
        raise BuildError('La chaîne publique ne doit contenir aucune clé privée.')
    blocks = re.findall(rb'-----BEGIN CERTIFICATE-----\s+.*?-----END CERTIFICATE-----', chain, re.S)
    if not blocks or re.sub(rb'-----BEGIN CERTIFICATE-----\s+.*?-----END CERTIFICATE-----', b'', chain, flags=re.S).strip():
        raise BuildError('Chaîne de certificats PEM invalide.')
    key = read_regular(key_path, private=True)
    if key.count(b'-----BEGIN ') != 1 or b'PRIVATE KEY-----' not in key or b'CERTIFICATE-----' in key:
        raise BuildError('La clé serveur doit être un unique fichier PEM privé.')
    constraints = run_openssl(['x509', '-noout', '-ext', 'basicConstraints'], blocks[0])
    if b'Basic Constraints' in constraints and not re.search(rb'\bCA\s*:\s*FALSE\b', constraints, re.I):
        raise BuildError('Le certificat serveur ne peut pas être une autorité de certification.')
    extensions = run_openssl(['x509', '-noout', '-ext', 'subjectAltName'], blocks[0])
    if b'Subject Alternative Name' not in extensions:
        raise BuildError('Le certificat serveur doit contenir un nom alternatif SAN.')
    # Ignore repository/host OpenSSL config; commands receive fixed argument lists.
    run_openssl(['verify', '-purpose', 'sslserver', flag, identity, '-CAfile', str(ca_path),
                 '-untrusted', str(cert_path), str(cert_path)])
    certificate_public = run_openssl(['x509', '-pubkey', '-noout'], blocks[0])
    certificate_der = run_openssl(['pkey', '-pubin', '-outform', 'DER'], certificate_public)
    ca_public = run_openssl(['x509', '-pubkey', '-noout'], ca_pem)
    ca_der = run_openssl(['pkey', '-pubin', '-outform', 'DER'], ca_public)
    if hmac.compare_digest(certificate_der, ca_der):
        raise BuildError('La clé du serveur doit être distincte de la clé de la CA.')
    key_der = run_openssl(['pkey', '-pubout', '-outform', 'DER', '-passin', 'pass:'], key)
    if not hmac.compare_digest(certificate_der, key_der):
        raise BuildError('La clé privée ne correspond pas au certificat serveur.')
    leaf_der = run_openssl(['x509', '-outform', 'DER'], blocks[0])
    return ca_fingerprint, hashlib.sha256(leaf_der).hexdigest()


def generate(stage, identity, kind):
    ca_key, ca_cert = stage / 'ca.key', stage / 'ca.pem'
    server_key, server_csr, server_cert, extensions = (stage / n for n in ('server.key', 'server.csr', 'server.pem', 'server.ext'))
    run_openssl(['req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-days', '3650', '-sha256',
                 '-keyout', str(ca_key), '-out', str(ca_cert), '-subj', '/CN=PXE Tools Local Root',
                 '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign'])
    run_openssl(['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-keyout', str(server_key),
                 '-out', str(server_csr), '-subj', '/CN=' + identity])
    extensions.write_text('basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
                          'extendedKeyUsage=serverAuth\nsubjectAltName=' + kind + ':' + identity + '\n')
    run_openssl(['x509', '-req', '-in', str(server_csr), '-CA', str(ca_cert), '-CAkey', str(ca_key),
                 '-set_serial', '0x' + secrets.token_hex(16), '-days', '365', '-sha256',
                 '-extfile', str(extensions), '-out', str(server_cert)])
    server_csr.unlink()
    extensions.unlink()
    os.chmod(ca_key, 0o600)
    os.chmod(server_key, 0o600)


def do_import(stage, ca, certificate, private_key):
    write_private(stage / 'ca.pem', read_regular(ca))
    write_private(stage / 'server.pem', read_regular(certificate))
    write_private(stage / 'server.key', read_regular(private_key, private=True))


def prepare(args):
    identity, kind, flag = valid_identity(args.identity)
    directory_fd, name = helper.open_safe_output_directory(args.output)
    parent = os.path.dirname(os.path.abspath(args.output))
    staging_name = '.pxe-pki-' + secrets.token_hex(16)
    staging = Path(parent) / staging_name
    destination_fd = None
    destination_created = False
    staging_created = False
    complete = False
    published = []
    try:
        os.mkdir(staging_name, 0o700, dir_fd=directory_fd)
        staging_created = True
        old_umask = os.umask(0o077)
        try:
            if args.action == 'generate':
                generate(staging, identity, kind)
            else:
                do_import(staging, args.ca, args.certificate, args.private_key)
        finally:
            os.umask(old_umask)
        ca_fingerprint, leaf_fingerprint = validate_chain(staging, identity, flag)
        info = {'identity': identity, 'mode': 'local' if args.action == 'generate' else 'imported',
                'ca_sha256': ca_fingerprint, 'server_sha256': leaf_fingerprint}
        write_private(staging / 'ready.json', (json.dumps(info, sort_keys=True) + '\n').encode())
        for name_public in ('ca.pem', 'server.pem', 'ready.json'):
            os.chmod(staging / name_public, 0o644)
        # Reserve an empty private destination; never replace any existing path.
        os.mkdir(name, 0o700, dir_fd=directory_fd)
        destination_created = True
        destination_fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory_fd)
        stage_fd = os.open(staging_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory_fd)
        try:
            files = ['ca.pem', 'server.pem', 'server.key']
            if args.action == 'generate': files.append('ca.key')
            # ready.json is linked last: readers must reject a directory without it.
            for file_name in files + ['ready.json']:
                file_fd = os.open(file_name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=stage_fd)
                try:
                    os.fsync(file_fd)
                finally:
                    os.close(file_fd)
                os.link(file_name, file_name, src_dir_fd=stage_fd, dst_dir_fd=destination_fd, follow_symlinks=False)
                published.append(file_name)
            os.fsync(destination_fd)
            os.fsync(directory_fd)
            complete = True
        finally:
            os.close(stage_fd)
        return info
    except BuildError:
        raise
    except OSError:
        raise BuildError('Préparation PKI ou publication impossible ; aucune configuration activée.') from None
    finally:
        if destination_fd is not None:
            # No marker means publication did not complete; remove only our own files.
            if not complete:
                for file_name in published:
                    try: os.unlink(file_name, dir_fd=destination_fd)
                    except FileNotFoundError: pass
            os.close(destination_fd)
        if destination_created and not complete:
            try: os.rmdir(name, dir_fd=directory_fd)
            except OSError: pass
        try:
            if staging_created:
                for file_name in ('ca.pem', 'ca.key', 'server.pem', 'server.key', 'server.csr', 'server.ext', 'ready.json'):
                    try: (staging / file_name).unlink()
                    except FileNotFoundError: pass
                os.rmdir(staging_name, dir_fd=directory_fd)
        except FileNotFoundError:
            pass
        finally:
            os.close(directory_fd)


def render_nginx(args):
    """Create an inactive HTTPS candidate from the exact PXE HTTP route config."""
    identity_dir = os.path.abspath(args.identity_dir)
    if not re.fullmatch(r'/[A-Za-z0-9_./-]+', identity_dir):
        raise BuildError('Le chemin des certificats ne peut pas être représenté sans risque dans nginx.')
    try:
        identity_fd, _ = helper.open_safe_output_directory(os.path.join(identity_dir, '.probe'))
    except OSError:
        raise BuildError('Répertoire PKI inaccessible ou non sûr.') from None
    try:
        if os.fstat(identity_fd).st_mode & 0o077:
            raise BuildError('Le répertoire PKI doit être privé (0700).')
        ready_path = Path(identity_dir) / 'ready.json'
        try:
            info = json.loads(read_regular(ready_path))
        except (ValueError, UnicodeDecodeError):
            raise BuildError('Publication PKI incomplète ou invalide.') from None
        if not isinstance(info, dict) or not isinstance(info.get('identity'), str):
            raise BuildError('Publication PKI incomplète ou invalide.')
        identity, _, flag = valid_identity(info['identity'])
        ca_sha, server_sha = validate_chain(Path(identity_dir), identity, flag)
        if info.get('ca_sha256') != ca_sha or info.get('server_sha256') != server_sha:
            raise BuildError('La publication PKI ne correspond plus aux certificats validés.')
        try:
            port = int(args.port)
        except ValueError:
            raise BuildError('Port HTTPS PXE invalide.') from None
        if port < 1 or port > 65535 or port == 80 or str(port) != args.port:
            raise BuildError('Port HTTPS PXE invalide.')
        try:
            config = read_regular(args.http_config).decode('utf-8')
        except UnicodeDecodeError:
            raise BuildError('Configuration HTTP PXE invalide.') from None
        replacements = {
            'listen 80 default_server;': 'listen %d ssl;' % port,
            'listen [::]:80 default_server;': 'listen [::]:%d ssl;' % port,
            'root /srv/http;': ('root /srv/http;\n'
                                '    ssl_certificate %s/server.pem;\n'
                                '    ssl_certificate_key %s/server.key;\n'
                                '    ssl_protocols TLSv1.2 TLSv1.3;') % (identity_dir, identity_dir),
        }
        if 'ssl_certificate' in config or any(config.count(old) != 1 for old in replacements):
            raise BuildError('Configuration HTTP PXE inattendue ; candidat HTTPS non généré.')
        for old, new in replacements.items():
            config = config.replace(old, new, 1)
        output_fd, output_name = helper.open_safe_output_directory(args.output)
        temporary = '.pxe-nginx-' + secrets.token_hex(16)
        created = False
        linked = False
        complete = False
        try:
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                         0o600, dir_fd=output_fd)
            created = True
            with os.fdopen(fd, 'wb') as output:
                output.write(config.encode('utf-8'))
                output.flush()
                os.fchmod(output.fileno(), 0o644)
                os.fsync(output.fileno())
            os.link(temporary, output_name, src_dir_fd=output_fd, dst_dir_fd=output_fd, follow_symlinks=False)
            linked = True
            os.fsync(output_fd)
            complete = True
        finally:
            if linked and not complete:
                os.unlink(output_name, dir_fd=output_fd)
            if created:
                os.unlink(temporary, dir_fd=output_fd)
            os.close(output_fd)
        return {'identity': identity, 'port': port, 'active': False}
    finally:
        os.close(identity_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    actions = parser.add_subparsers(dest='action', required=True)
    for name in ('generate', 'import'):
        command = actions.add_parser(name)
        command.add_argument('--identity', required=True, help='nom DNS ou adresse IP du serveur HTTPS PXE')
        command.add_argument('--output', required=True, help='nouveau répertoire privé à créer')
        if name == 'import':
            command.add_argument('--ca', required=True, help='certificat public de la CA racine')
            command.add_argument('--certificate', required=True, help='certificat serveur puis intermédiaires PEM')
            command.add_argument('--private-key', required=True, help='fichier PEM privé du serveur, permissions 0600')
    nginx = actions.add_parser('render-nginx')
    nginx.add_argument('--identity-dir', required=True, help='répertoire PKI publié et privé')
    nginx.add_argument('--http-config', required=True, help='configuration HTTP PXE existante')
    nginx.add_argument('--port', required=True, help='port HTTPS distinct du port HTTP')
    nginx.add_argument('--output', required=True, help='fichier candidat inactif à créer')
    args = parser.parse_args()
    try:
        result = render_nginx(args) if args.action == 'render-nginx' else prepare(args)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (BuildError, OSError) as error:
        print('Erreur : ' + (str(error) if isinstance(error, BuildError) else 'opération PKI impossible.'), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
