#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Upload a harmless Worker version without changing a deployment or a route."""
from __future__ import annotations
import json
import os
import re
import secrets
import urllib.request

WORKER = 'packages-pxetools-com'
HOST = 'packages.pxetools.com'
FIXTURE = b'export default { fetch() { return new Response(null, { status: 404 }); } };\n'


def call(account: str, token: str, path: str, body: bytes | None = None, content_type: str = '') -> object:
    url = f'https://api.cloudflare.com/client/v4/accounts/{account}/workers/{path}'
    headers = {'Authorization': 'Bearer ' + token, 'User-Agent': 'PXE-Tools-preflight/1'}
    if content_type:
        headers['Content-Type'] = content_type
    req = urllib.request.Request(url, data=body, headers=headers, method='POST' if body else 'GET')
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            data = response.read(1024 * 1024 + 1)
        if len(data) > 1024 * 1024:
            raise ValueError('response too large')
        payload = json.loads(data)
        if payload.get('success') is not True:
            raise ValueError('API call unsuccessful')
        return payload['result']
    except Exception as exc:
        # Do not emit the API response, request headers or token.
        raise ValueError('Cloudflare request failed; no response details logged') from None


def observe(account: str, token: str) -> dict:
    base = f'scripts/{WORKER}'
    subdomain = call(account, token, f'{base}/subdomain')
    if not isinstance(subdomain, dict) or subdomain.get('enabled') is not False or subdomain.get('previews_enabled') is not False:
        raise ValueError('Worker or version preview URLs are not explicitly disabled')
    deployments = call(account, token, f'{base}/deployments')
    if not isinstance(deployments, dict) or not isinstance(deployments.get('deployments'), list):
        raise ValueError('Cannot verify current Worker deployment')
    # The list describes deployed traffic, not merely uploaded versions.
    deploy = deployments['deployments']
    if not deploy:
        raise ValueError('Current deployment is missing')
    current = max(deploy, key=lambda item: item['created_on'])
    if not isinstance(current.get('versions'), list) or not current['versions']:
        raise ValueError('Current deployment has no traffic versions')
    domains = call(account, token, f'domains?hostname={HOST}&service={WORKER}')
    if not isinstance(domains, list) or len(domains) != 1:
        raise ValueError('Cannot verify the dedicated domain binding')
    domain = domains[0]
    if (domain.get('hostname') != HOST or domain.get('service') != WORKER
            or domain.get('enabled') is not True or domain.get('previews_enabled') is not False):
        raise ValueError('Dedicated domain or preview routing is not in the expected state')
    return {'subdomain': subdomain, 'deployment': current, 'domain': domain}


def multipart() -> tuple[bytes, str]:
    boundary = 'pxe' + secrets.token_hex(16)
    metadata = json.dumps({'main_module': 'fixture.mjs', 'compatibility_date': '2026-11-12',
                           'annotations': {'workers/message': 'Inert upload permission check; do not deploy'}}).encode()
    data = (f'--{boundary}\r\nContent-Disposition: form-data; name="metadata"\r\nContent-Type: application/json\r\n\r\n'.encode()
            + metadata + f'\r\n--{boundary}\r\nContent-Disposition: form-data; name="fixture.mjs"; filename="fixture.mjs"\r\nContent-Type: application/javascript+module\r\n\r\n'.encode()
            + FIXTURE + f'\r\n--{boundary}--\r\n'.encode())
    return data, f'multipart/form-data; boundary={boundary}'


def check(account: str, token: str) -> None:
    if not re.fullmatch(r'[0-9a-f]{32}', account) or not token:
        raise ValueError('Cloudflare credentials are missing or invalid')
    before = observe(account, token)
    body, content_type = multipart()
    try:
        uploaded = call(account, token, f'scripts/{WORKER}/versions', body, content_type)
        if not isinstance(uploaded, dict) or not re.fullmatch(r'[0-9a-fA-F-]{36}', str(uploaded.get('id', ''))):
            raise ValueError('Version upload response lacks a version ID')
    finally:
        after = observe(account, token)
        if before != after:
            raise ValueError('Worker deployment or domain changed during upload; investigate')
    print('An inert version was uploaded; current deployment and domain are unchanged.')


if __name__ == '__main__':
    try:
        check(os.environ.get('CLOUDFLARE_ACCOUNT_ID', ''), os.environ.get('CLOUDFLARE_API_TOKEN', ''))
    except (ValueError, KeyError, TypeError) as exc:
        raise SystemExit(str(exc))
