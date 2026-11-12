<?php
declare(strict_types=1);
namespace Admin\Util;

final class PxeHttpReadiness
{
    public static function unavailable(): bool
    {
        $file = getenv('PXE_HTTP_READY_FILE');
        return is_string($file) && $file !== '' && (!str_starts_with($file, '/') || !is_file($file));
    }
}
