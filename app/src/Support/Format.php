<?php
declare(strict_types=1);

namespace Admin\Support;

/**
 * Format — helpers de présentation partagés entre les vues.
 *
 * POURQUOI ce namespace séparé (Support vs Util) : Util/ contient des helpers
 * techniques (crypto, réseau, config builder). Support/ est réservé aux helpers
 * d'affichage/formatage, sans dépendance vers Db ou les Models.
 */
final class Format
{
    /**
     * Formate une durée en secondes en chaîne lisible.
     *
     * @param int|null $s Durée en secondes (null ou ≤ 0 → '—')
     * @param bool $compact Forme abrégée pour colonnes de table : '2m 5s' vs '2 min 5 s'
     * @return string
     *
     * Valeurs remarquables :
     *   null  → '—'
     *   0     → '—'
     *   45    → '45 s'      ($compact=false) | '45s'     ($compact=true)
     *   125   → '2 min 5 s' ($compact=false) | '2m 5s'   ($compact=true)
     *   3665  → '1 h 1 min' ($compact=false) | '1h 1m'   ($compact=true)
     */
    public static function humanDuration(?int $s, bool $compact = false): string
    {
        if ($s === null || $s <= 0) return '—';
        if ($compact) {
            if ($s < 60)   return $s . 's';
            if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
            return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
        }
        if ($s < 60)   return $s . ' s';
        if ($s < 3600) return floor($s / 60) . ' min ' . ($s % 60) . ' s';
        return floor($s / 3600) . ' h ' . floor(($s % 3600) / 60) . ' min';
    }
}
