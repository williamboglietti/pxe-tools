<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Models\Setting;

final class AnsibleRuntimeStatus
{
    /** The worker's observation is advisory and expires if it stops or is busy. */
    public static function current(?int $now = null): string
    {
        $raw = Setting::get('ansible_runtime_status', '') ?? '';
        $value = json_decode($raw, true);
        $now ??= time();
        if (!is_array($value) || !is_string($value['state'] ?? null)
            || !is_int($value['checked_at'] ?? null)
            || $value['checked_at'] > $now + 30 || $now - $value['checked_at'] > 180
            || !in_array($value['state'], ['ready', 'unavailable', 'not_configured'], true)) {
            return 'unknown';
        }
        return $value['state'];
    }
}
