#!/usr/bin/env python3
"""Opt-in native action: stop only nginx.service after verifying TCP/80 owners."""
import os
from pathlib import Path
import subprocess
import sys
import re
import sqlite3
import time


def fail(message):
    print(message, file=sys.stderr)
    raise SystemExit(1)


def systemctl(*args, check=True):
    return subprocess.run(['/usr/bin/systemctl', *args], text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=check)


def listeners():
    inodes = set()
    for table in ('/proc/net/tcp', '/proc/net/tcp6'):
        with open(table, encoding='ascii') as stream:
            next(stream)
            for line in stream:
                fields = line.split()
                if len(fields) >= 10 and fields[3] == '0A' and fields[1].split(':')[-1] == '0050':
                    inodes.add(fields[9])
    return inodes


def verify_owners(inodes, cgroup):
    if not inodes:
        fail('Aucun programme n’écoute sur le port TCP 80 ; aucune modification effectuée.')
    found = set()
    for proc in Path('/proc').iterdir():
        if not proc.name.isdecimal():
            continue
        try:
            fds = list((proc / 'fd').iterdir())
        except PermissionError:
            fail('Un processus ne peut pas être inspecté ; aucune modification effectuée.')
        except (FileNotFoundError, ProcessLookupError):
            continue
        for fd in fds:
            try:
                target = os.readlink(fd)
            except PermissionError:
                fail('Un descripteur ne peut pas être inspecté ; aucune modification effectuée.')
            except (FileNotFoundError, ProcessLookupError):
                continue
            if not target.startswith('socket:[') or not target.endswith(']'):
                continue
            inode = target[8:-1]
            if inode not in inodes:
                continue
            try:
                groups = (proc / 'cgroup').read_text().splitlines()
                exe = os.readlink(proc / 'exe')
            except (OSError, PermissionError):
                fail('Identité d’un processus non vérifiable ; aucune modification effectuée.')
            if not any(line.split(':', 2)[-1] == cgroup for line in groups) or exe != '/usr/sbin/nginx':
                fail('Un autre programme occupe le port 80 ; aucune modification effectuée.')
            found.add(inode)
    if found != inodes:
        fail('Tous les détenteurs du port ne sont pas identifiés ; aucune modification effectuée.')


def main():
    if os.geteuid() != 0 or os.environ.get('PXE_SERVICE_MANAGER') != 'systemd' or not Path('/run/systemd/system').is_dir():
        fail('Action réservée au worker natif systemd.')
    job_id = os.environ.get('WORKER_JOB_ID', '')
    db_path = os.environ.get('PXE_DB_PATH', '')
    if not re.fullmatch(r'[1-9][0-9]*', job_id) or not db_path.startswith('/'):
        fail('Demande explicite introuvable ; aucune modification effectuée.')
    try:
        with sqlite3.connect(f'file:{db_path}?mode=ro', uri=True) as db:
            row = db.execute("SELECT job_type, status, message FROM init_jobs WHERE id = ?", (int(job_id),)).fetchone()
    except sqlite3.Error:
        fail('Demande explicite invérifiable ; aucune modification effectuée.')
    if not row or row[:2] != ('system:release_nginx_port', 'running'):
        fail('Demande explicite invalide ; aucune modification effectuée.')
    match = re.fullmatch(r'nginx-consent-v1:([1-9][0-9]*):([0-9]+):([0-9]+):([0-9a-f-]{36})', row[2] or '')
    if not match:
        fail('Confirmation invalide ; aucune modification effectuée.')
    pid, start, requested, boot = match.groups()
    if not 0 <= time.time() - int(requested) <= 300 or Path('/proc/sys/kernel/random/boot_id').read_text().strip() != boot:
        fail('Confirmation expirée ; aucune modification effectuée.')
    if systemctl('is-active', '--quiet', 'nginx.service', check=False).returncode != 0:
        fail('Le service nginx.service n’est pas actif ; aucune modification effectuée.')
    master = systemctl('show', '--property=MainPID', '--value', 'nginx.service').stdout.strip()
    try:
        stat = Path('/proc', pid, 'stat').read_text()
        start_current = stat[stat.rindex(') ') + 2:].split()[19]
    except (OSError, ValueError, IndexError):
        fail('Processus nginx initial introuvable ; aucune modification effectuée.')
    if pid != master or start_current != start:
        fail('Le processus nginx a changé depuis la confirmation ; aucune modification effectuée.')
    cgroup = systemctl('show', '--property=ControlGroup', '--value', 'nginx.service').stdout.strip()
    if not cgroup or cgroup == '/' or not cgroup.endswith('/nginx.service'):
        fail('Identité systemd nginx.service non vérifiable ; aucune modification effectuée.')
    current = listeners()
    verify_owners(current, cgroup)
    # Recheck immediately before the fixed action; reject any observed change.
    if (listeners() != current or systemctl('is-active', '--quiet', 'nginx.service', check=False).returncode != 0
            or systemctl('show', '--property=MainPID', '--value', 'nginx.service').stdout.strip() != pid):
        fail('Le conflit a changé pendant la vérification ; aucune modification effectuée.')
    result = systemctl('disable', '--now', 'nginx.service', check=False)
    if result.returncode:
        fail('Impossible de désactiver ou arrêter nginx.service ; vérifiez son état avec systemctl.')
    if systemctl('is-active', '--quiet', 'nginx.service', check=False).returncode == 0:
        fail('nginx.service est encore actif ; vérifiez son état avec systemctl.')
    enabled = systemctl('is-enabled', 'nginx.service', check=False).stdout.strip()
    if enabled not in ('disabled', 'masked', 'masked-runtime'):
        fail('Désactivation durable de nginx.service non confirmée ; vérifiez son état avec systemctl.')
    if listeners():
        fail('Un programme écoute encore sur le port 80 ; vérifiez le conflit avant de continuer.')
    print('nginx.service arrêté et désactivé ; le port 80 est libre.')


if __name__ == '__main__':
    main()
