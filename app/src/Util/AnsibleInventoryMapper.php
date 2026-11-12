<?php
declare(strict_types=1);

namespace Admin\Util;

/**
 * Mapping fichier modifié → set de hostnames impactés, pour le webhook GitHub
 * de redéploiement automatique Ansible (cf. AnsibleRepoWebhookController).
 *
 * Lit l'inventory.ini cloné côté worker (`/home/ansible/ansible/inventory.ini`)
 * pour résoudre les groupes → hostnames. Parse aussi les playbooks pour
 * extraire le champ `hosts:` du premier play.
 *
 * Règles de mapping (du plus spécifique au plus large, fallback safe → tous) :
 *   host_vars/{hostname}.yml       → [hostname]
 *   host_vars/{hostname}/*         → [hostname]
 *   group_vars/all.yml             → toutes machines de l'inventory
 *   group_vars/all/*               → toutes machines
 *   group_vars/{group}.yml         → machines du groupe (via inventory)
 *   group_vars/{group}/*           → machines du groupe
 *   playbooks/{name}.yml           → machines du groupe résolu via 'hosts:'
 *   inventory.ini                  → toutes machines (topologie changée)
 *   roles/* | scripts/* | autre    → toutes machines (fallback safe)
 */
final class AnsibleInventoryMapper
{
    public const DEFAULT_REPO_ROOT = '/home/ansible/ansible';

    /**
     * Parse inventory.ini. Format Ansible standard :
     *   [groupname]
     *   hostname ansible_host=192.0.2.X
     *
     * Sections `:children` et `:vars` sont skip (pas de récursion enfants au MVP).
     *
     * @return array<string, list<string>> [group_name => [hostname1, hostname2, ...]]
     */
    public static function parseInventory(?string $path = null): array
    {
        $path = $path ?? (self::DEFAULT_REPO_ROOT . '/inventory.ini');
        if (!is_readable($path)) return [];

        $groups = [];
        $currentGroup = null;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
            if (preg_match('/^\[([^\]:]+)(:children|:vars)?\]$/', $line, $m)) {
                $currentGroup = ($m[2] ?? '') === '' ? $m[1] : null;
                if ($currentGroup !== null) {
                    $groups[$currentGroup] = $groups[$currentGroup] ?? [];
                }
                continue;
            }
            if ($currentGroup === null) continue;
            $hostname = preg_split('/\s+/', $line)[0] ?? '';
            if ($hostname !== '') $groups[$currentGroup][] = $hostname;
        }
        return $groups;
    }

    /**
     * Parse le champ 'hosts:' du premier play d'un playbook YAML.
     * Lit les 4 premiers KB pour ne pas charger un playbook géant en mémoire.
     * Retourne le nom du groupe (string), 'all' si hosts: all, ou null si
     * fichier illisible/non parsable.
     */
    public static function playbookTargetGroup(string $playbookPath): ?string
    {
        if (!is_readable($playbookPath)) return null;
        $content = file_get_contents($playbookPath, false, null, 0, 4096);
        if ($content === false) return null;
        if (preg_match('/^\s*-\s*hosts:\s*([a-zA-Z0-9_-]+)/m', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Mapping principal : prend la liste des fichiers modifiés (chemins relatifs
     * au dépôt configuré, format GitHub push event commits[].modified[]),
     * retourne le set unique de hostnames impactés.
     *
     * Si la fallback "toutes machines" est déclenchée, retourne tous les
     * hostnames de l'inventory (union de tous les groupes).
     *
     * @param string[] $changedFiles
     * @param string|null $repoRoot   Chemin local du clone (default DEFAULT_REPO_ROOT)
     * @return list<string> hostnames uniques
     */
    public static function impactedHostnames(array $changedFiles, ?string $repoRoot = null): array
    {
        $repoRoot = $repoRoot ?? self::DEFAULT_REPO_ROOT;
        $groups = self::parseInventory($repoRoot . '/inventory.ini');
        $allHosts = [];
        foreach ($groups as $hosts) {
            foreach ($hosts as $h) $allHosts[$h] = true;
        }
        $allHosts = array_keys($allHosts);

        $impacted = [];
        $allImpacted = false;

        foreach ($changedFiles as $f) {
            $f = ltrim((string)$f, '/');
            if ($f === '') continue;

            // host_vars/{hostname}.yml ou host_vars/{hostname}/...
            if (preg_match('#^host_vars/([^/]+?)(?:\.ya?ml|/.*)$#', $f, $m)) {
                $impacted[$m[1]] = true;
                continue;
            }

            // group_vars/all OU group_vars/all.yml OU group_vars/all/*
            if (preg_match('#^group_vars/all(?:\.ya?ml|/.*)?$#', $f)) {
                $allImpacted = true;
                continue;
            }

            // group_vars/{group}.yml OU group_vars/{group}/*
            if (preg_match('#^group_vars/([^/]+?)(?:\.ya?ml|/.*)$#', $f, $m)) {
                $g = $m[1];
                foreach ($groups[$g] ?? [] as $h) $impacted[$h] = true;
                continue;
            }

            // playbooks/{name}.yml — résolution dynamique via 'hosts:'
            if (preg_match('#^playbooks/([^/]+)\.ya?ml$#', $f)) {
                $g = self::playbookTargetGroup($repoRoot . '/' . $f);
                if ($g === null || $g === 'all') { $allImpacted = true; continue; }
                foreach ($groups[$g] ?? [] as $h) $impacted[$h] = true;
                continue;
            }

            // inventory.ini → topologie changée, redéploie tout
            if ($f === 'inventory.ini') { $allImpacted = true; continue; }

            // Fallback safe : roles/, scripts/, ansible.cfg, .gitignore, README, etc.
            $allImpacted = true;
        }

        if ($allImpacted) return $allHosts;
        return array_keys($impacted);
    }
}
