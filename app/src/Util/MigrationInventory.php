<?php
declare(strict_types=1);

namespace Admin\Util;

use PDO;

/**
 * Instantané informatif des données et opérations avant une migration.
 *
 * Ne suspend ni les écritures ni les services et n'autorise aucune bascule :
 * les valeurs peuvent changer immédiatement après la lecture. Les opérations
 * de capture devront établir leur propre verrou et revérifier cet inventaire.
 * Aucune valeur, identité, configuration ou clé n'est retournée.
 */
final class MigrationInventory
{
    public static function inspect(PDO $pdo): array
    {
        // Une seule requête fournit une vue cohérente des compteurs ; les états
        // inconnus sont comptés parmi les opérations à examiner, jamais ignorés.
        $sql = <<<'SQL'
SELECT
  (SELECT COUNT(*) FROM machines) AS machines,
  (SELECT COUNT(*) FROM presets) AS presets,
  (SELECT COUNT(*) FROM users) AS users,
  (SELECT COUNT(*) FROM os_images WHERE status = 'downloaded') AS downloaded_images,
  (SELECT COUNT(*) FROM actions WHERE status IS NULL OR status NOT IN ('success','failed','cancelled','aborted')) AS actions_to_review,
  (SELECT COUNT(*) FROM init_jobs WHERE status IS NULL OR status NOT IN ('success','failed','skipped','cancelled','aborted')) AS jobs_to_review,
  (SELECT COUNT(*) FROM installations WHERE
     (install_status IS NULL OR install_status NOT IN ('success','failed','aborted','cancelled')) OR
     (ansible_status IS NOT NULL AND ansible_status NOT IN ('success','failed','aborted','cancelled','no_role','skipped'))) AS installations_to_review,
  (SELECT COUNT(*) FROM wipes WHERE wipe_status IS NULL OR wipe_status NOT IN ('success','failed','aborted','cancelled')) AS wipes_to_review,
  (SELECT COUNT(*) FROM disk_tests WHERE job_status IS NULL OR job_status NOT IN ('success','failed','aborted','cancelled')) AS disk_tests_to_review,
  (SELECT COUNT(*) FROM discoveries WHERE discovery_status IS NULL OR discovery_status NOT IN ('success','failed','aborted','cancelled')) AS discoveries_to_review,
  (SELECT COUNT(*) FROM os_images WHERE status IS NULL OR status NOT IN ('downloaded','failed')) AS images_to_review,
  (SELECT COUNT(*) FROM boot_sessions WHERE selected_menu IS NULL OR selected_at IS NULL) AS boot_sessions_to_review
SQL;
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Inventaire de migration indisponible.');
        }
        return array_map(static fn ($count): int => (int) $count, $row);
    }
}
