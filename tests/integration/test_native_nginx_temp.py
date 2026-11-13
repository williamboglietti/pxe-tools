#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run as root in an isolated Debian guest with nginx and Python installed.

Uses only temporary files and Unix sockets; no system nginx or services are changed.
"""
import http.client
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import socketserver
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[2]


class UnixHTTP(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__('localhost', timeout=5)
        self.path = str(path)

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect(self.path)


class Backend(socketserver.StreamRequestHandler):
    def handle(self):
        request = self.rfile.readline()
        if not request:
            return
        length = 0
        while line := self.rfile.readline():
            if line == b'\r\n':
                break
            if line.lower().startswith(b'content-length:'):
                length = int(line.split(b':', 1)[1])
        body = self.rfile.read(length)
        self.wfile.write(b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK' if len(body) == length else b'HTTP/1.1 500 Error\r\nContent-Length: 0\r\n\r\n')


def post(path):
    client = UnixHTTP(path)
    try:
        client.request('POST', '/upload', body=b'x' * 262144)
        response = client.getresponse()
        response.read()
        return response.status
    finally:
        client.close()


def run():
    assert os.geteuid() == 0, 'run as root in an isolated test guest'
    assert shutil.which('nginx'), 'nginx required'
    www = pwd.getpwnam('www-data').pw_uid
    nobody = pwd.getpwnam('nobody').pw_uid
    with tempfile.TemporaryDirectory(prefix='pxe-nginx-test-', dir='/tmp') as tmp:
        root = Path(tmp)
        root.chmod(0o755)
        backend_path = root/'backend.sock'
        backend = socketserver.UnixStreamServer(str(backend_path), Backend)
        os.chmod(backend_path, 0o666)
        thread = threading.Thread(target=backend.serve_forever, daemon=True)
        thread.start()
        try:
            for config_name, runtime in [('nginx.conf', 'pxe-tools-nginx'), ('pxe-nginx.conf', 'pxe-tools-pxe-http')]:
                original = (ROOT/'packaging/native'/config_name).read_text()
                for broken in (True, False):
                    case = root/(runtime + ('-old' if broken else '-new'))
                    case.mkdir(mode=0o755)
                    run_dir = case/'run'
                    run_dir.mkdir(mode=0o755)
                    hostile = case/'foreign-body'
                    hostile.mkdir(mode=0o700)
                    os.chown(hostile, nobody, -1)
                    text = original.replace('/run/' + runtime, str(run_dir))
                    text = text.replace('/var/log/pxe-tools/nginx-access.log', str(case/'access.log'))
                    if broken:
                        # Substitute the compiled-in default with a separate hostile fixture.
                        text = re.sub(r'client_body_temp_path [^;]+;', f'client_body_temp_path {hostile};', text)
                    text = re.sub(r'    include '+re.escape(str(run_dir))+r'/[^;]+;',
                        f'    client_body_buffer_size 1k;\n    server {{ listen unix:{case}/http.sock; location /upload {{ proxy_pass http://unix:{backend_path}; }} }}', text)
                    config = case/'nginx.conf'
                    config.write_text(text)
                    subprocess.run(['nginx', '-t', '-c', str(config)], check=True, capture_output=True)
                    for iteration in range(2):
                        process = subprocess.Popen(['nginx', '-c', str(config), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
                        try:
                            sock = case/'http.sock'
                            for _ in range(100):
                                if sock.exists():
                                    break
                                if process.poll() is not None:
                                    raise RuntimeError(process.stderr.read().decode())
                                time.sleep(0.05)
                            # An unrelated nginx can change ownership after this master starts.
                            os.chown(hostile, nobody, -1)
                            os.chmod(hostile, 0o700)
                            assert post(sock) == (500 if broken else 200), (config_name, broken, iteration)
                            if not broken:
                                for name in ('client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'):
                                    item = run_dir/name
                                    assert item.is_dir() and item.stat().st_uid == www, (item, item.stat())
                                assert hostile.stat().st_uid == nobody
                        finally:
                            process.terminate()
                            process.wait(timeout=10)
                            process.stderr.close()
                            (case/'http.sock').unlink(missing_ok=True)
                print(f'{config_name}: hostile shared body fails; isolated body succeeds after two starts')
        finally:
            backend.shutdown()
            backend.server_close()
            thread.join()


if __name__ == '__main__':
    run()
