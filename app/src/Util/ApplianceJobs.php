<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Db;
use Admin\Models\Setting;

final class ApplianceJobs
{
    /** Save a requested configuration and its exact application jobs in one transaction. */
    public static function enqueue(array $jobs, array $settings = [], string $trackingKey = 'appliance_last_job_ids'): array
    {
        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            // Serializes concurrent configuration changes on both supported SQL drivers.
            Setting::set('appliance_request_revision', bin2hex(random_bytes(8)));
            foreach ($settings as $key => $value) Setting::set($key, $value);
            $insert = $pdo->prepare("INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)");
            $ids = [];
            foreach ($jobs as $type => $message) {
                $insert->execute([$type, $message]);
                $ids[] = (int)$pdo->lastInsertId();
            }
            Setting::set($trackingKey, json_encode($ids, JSON_THROW_ON_ERROR));
            $pdo->commit();
            return $ids;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function tracked(string $key = 'appliance_last_job_ids'): array
    {
        $ids = json_decode(Setting::get($key, '[]') ?: '[]', true);
        if (!is_array($ids) || $ids === []) return [];
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) return [];
        $query = Db::get()->prepare('SELECT * FROM init_jobs WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $query->execute($ids);
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
}
