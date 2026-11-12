<?php
declare(strict_types=1);

namespace Admin\Util;

/** Decode discovery transports without silently replacing hardware by a MAC-only report. */
final class DiscoveryPayload
{
    public const MAX_JSON_BYTES = 1048576;
    public const MAX_BODY_BYTES = 4198400;

    public static function decode(string $body, array $post = [], array $query = []): array
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('discovery payload too large', 413);
        }
        if (array_key_exists('b64', $post)) {
            $json = self::decodeBase64($post['b64']);
        } elseif (trim($body) !== '') {
            $json = $body;
        } elseif (array_key_exists('b64', $query)) {
            $json = self::decodeBase64($query['b64']);
        } else {
            throw new \InvalidArgumentException('complete inventory required; MAC-only discovery is incomplete', 422);
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('discovery payload too large', 413);
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('invalid discovery JSON', 400);
        }
        if (!is_array($data) || !is_string($data['mac'] ?? null) || trim($data['mac']) === '') {
            throw new \InvalidArgumentException('invalid JSON or missing mac', 400);
        }
        foreach (['inventory', 'disks', 'interfaces'] as $field) {
            if (!array_key_exists($field, $data) || !is_array($data[$field])) {
                throw new \InvalidArgumentException('complete inventory, disks and interfaces required', 422);
            }
        }
        return $data;
    }

    private static function decodeBase64(mixed $encoded): string
    {
        if (!is_string($encoded) || $encoded === '') {
            throw new \InvalidArgumentException('invalid discovery base64', 400);
        }
        if (strlen($encoded) > 4 * (int)ceil(self::MAX_JSON_BYTES / 3)) {
            throw new \InvalidArgumentException('discovery payload too large', 413);
        }
        $encoded = trim($encoded);
        if (!preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D', $encoded)) {
            throw new \InvalidArgumentException('invalid discovery base64', 400);
        }
        $encoded = strtr($encoded, '-_', '+/');
        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            throw new \InvalidArgumentException('invalid discovery base64', 400);
        }
        if ($remainder > 0) $encoded .= str_repeat('=', 4 - $remainder);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || $decoded === '') {
            throw new \InvalidArgumentException('invalid discovery base64', 400);
        }
        return $decoded;
    }
}
