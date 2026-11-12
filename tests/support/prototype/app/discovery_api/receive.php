<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

use Admin\Discovery\ReportEndpoint;
use Admin\Util\DiscoveryPayload;
use Admin\Util\PrivateDatabase;

// No web server or route is installed or enabled by this file. The future
// dedicated FastCGI pool must supply fixed HTTPS and environment parameters.
ini_set('display_errors', '0');
header_remove('X-Powered-By');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (PHP_SAPI !== 'fpm-fcgi' || getenv('PXE_DISCOVERY_ENABLED') !== '1') {
    http_response_code(503);
    exit;
}
$app = dirname(__DIR__);
foreach (['Util/PrivateDatabase.php', 'Util/DiscoveryPayload.php', 'Util/DiskIdentity.php',
          'Discovery/EnrollmentToken.php', 'Discovery/InventoryService.php', 'Discovery/ReportEndpoint.php'] as $file) {
    require $app . '/src/' . $file;
}
try {
    $database = getenv('PXE_DB_PATH');
    if (!is_string($database)) throw new RuntimeException('database unavailable');
    $length = $_SERVER['CONTENT_LENGTH'] ?? null;
    $body = '';
    if (is_string($length) && preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', $length) === 1
        && (int)$length <= DiscoveryPayload::MAX_JSON_BYTES) {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) throw new RuntimeException('input unavailable');
        try { $body = stream_get_contents($stream, DiscoveryPayload::MAX_JSON_BYTES + 1); }
        finally { fclose($stream); }
        if (!is_string($body)) throw new RuntimeException('input unavailable');
    }
    http_response_code((new ReportEndpoint(PrivateDatabase::open($database)))->handle($_SERVER, $body));
} catch (Throwable $error) {
    http_response_code(503);
}
