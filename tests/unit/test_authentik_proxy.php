<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/src/Auth.php';
use Admin\Auth;

putenv('AUTH_MODE=authentik');
$_SERVER['HTTP_X_FORWARDED_USER'] = 'admin-fixture';
$_SERVER['REMOTE_ADDR'] = '192.0.2.23';
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS');
if (Auth::user() !== null) throw new RuntimeException('Unconfigured proxy must fail closed.');
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.7';
if (Auth::user() !== null) throw new RuntimeException('Forwarded source cannot replace the actual peer.');
$_SERVER['REMOTE_ADDR'] = '192.0.2.7';
if ((Auth::user()['username'] ?? '') !== 'admin-fixture') throw new RuntimeException('Configured proxy must pass its identity.');
$_SERVER['HTTP_X_FORWARDED_USER'] = "admin\nforged";
if (Auth::user() !== null) throw new RuntimeException('Control characters in identity must fail closed.');
$_SERVER['HTTP_X_FORWARDED_USER'] = 'admin-fixture';
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.0/33');
if (Auth::user() !== null) throw new RuntimeException('Invalid CIDR must not trust its peer.');
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32,invalid');
if (Auth::user() !== null) throw new RuntimeException('A partially invalid proxy list must fail closed.');
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS=0.0.0.0/0');
if (Auth::user() !== null) throw new RuntimeException('A catch-all network is not an explicit proxy.');
putenv('AUTHENTIK_TRUSTED_PROXY_CIDRS=2001:db8::/48');
$_SERVER['REMOTE_ADDR'] = '2001:db8::42';
if ((Auth::user()['username'] ?? '') !== 'admin-fixture') throw new RuntimeException('Explicit IPv6 proxy should be supported.');
$_SERVER['REMOTE_ADDR'] = '2001:db9::42';
if (Auth::user() !== null) throw new RuntimeException('IPv6 peer outside the trusted prefix must be rejected.');
echo "OK: Authentik identities require an explicit trusted network peer\n";
