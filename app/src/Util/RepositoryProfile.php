<?php
declare(strict_types=1);

namespace Admin\Util;

use Admin\Models\Setting;
use InvalidArgumentException;
use RuntimeException;

/** Local, read-only view of the repository profile; never clones or runs Git. */
final class RepositoryProfile
{
    public static function active(): bool
    {
        return trim((string)Setting::get(Setting::KEY_ANSIBLE_REPO_PROFILE, '')) !== '';
    }

    public static function validateBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '' || strlen($branch) > 255 || $branch === 'HEAD'
            || !preg_match('~\A[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*\z~D', $branch)
            || str_contains($branch, '..') || str_contains($branch, '@{')) {
            throw new InvalidArgumentException('Branche Git invalide. Utilisez un nom de branche simple, sans espace, option ou caractère spécial.');
        }
        foreach (explode('/', $branch) as $segment) {
            if (str_ends_with($segment, '.') || str_ends_with($segment, '.lock')) {
                throw new InvalidArgumentException('Branche Git invalide : un segment ne peut pas finir par un point ou .lock.');
            }
        }
        return $branch;
    }

    public static function validateManifestPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        $path = self::validateRelativePath($path);
        if (!str_ends_with($path, '.json')) {
            throw new InvalidArgumentException('Le profil doit désigner un manifeste relatif se terminant par .json.');
        }
        return $path;
    }

    public static function validateRelativePath(string $path): string
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, '..')
            || !preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D', $path)) {
            throw new InvalidArgumentException('Chemin de profil invalide : utilisez un chemin relatif sans .., URL, espace ou joker.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || strtolower($segment) === '.git') {
                throw new InvalidArgumentException('Un chemin de profil ne peut pas viser la racine ou les métadonnées Git.');
            }
        }
        return $path;
    }

    /**
     * Optional values support a form preview without saving the submitted fields.
     * An empty profile reads no manifest and checks only the sparse marker.
     * @return array<string,mixed>
     */
    public static function status(?string $manifest = null, ?string $directory = null, ?string $branch = null): array
    {
        $manifest = trim($manifest ?? (string)Setting::get(Setting::KEY_ANSIBLE_REPO_PROFILE, ''));
        $branch = trim($branch ?? (string)Setting::get(Setting::KEY_ANSIBLE_REPO_BRANCH, 'main'));
        $branch = $branch !== '' ? $branch : 'main';
        $directory = self::directory($directory);
        $result = [
            'active' => $manifest !== '', 'valid' => $manifest === '',
            'state' => $manifest === '' ? 'legacy' : 'invalid',
            'message' => $manifest === ''
                ? 'Mode historique : le dépôt complet est récupéré, sans restriction de profil.'
                : 'Le profil configuré est indisponible.',
            'name' => null, 'schema_version' => null, 'branch' => $branch,
            'root' => $directory, 'manifest' => $manifest, 'source' => null,
            'presets_path' => null, 'presets_output_present' => null,
            'paths' => [], 'playbooks' => [], 'missing_paths' => [],
        ];
        if ($manifest === '') {
            if (self::hasScopedCheckout($directory)) {
                $result['valid'] = false;
                $result['state'] = 'invalid';
                $result['message'] = 'Ce clone est encore partiel. Préparez un clone complet contrôlé avant de retirer le profil et de reprendre les playbooks.';
            }
            return $result;
        }

        try {
            $result['branch'] = self::validateBranch($branch);
            $result['manifest'] = self::validateManifestPath($manifest);
            if (!str_starts_with($directory, '/') || preg_match('/[\x00-\x1f\x7f]/', $directory)) {
                throw new InvalidArgumentException('Le répertoire local du dépôt doit être un chemin absolu.');
            }
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) {
                $result['state'] = 'missing';
                $result['message'] = 'Le clone local du dépôt est introuvable. Le profil ne peut pas être vérifié.';
                return $result;
            }
            $root = rtrim($root, '/');
            if ($root === '') throw new InvalidArgumentException('La racine du système ne peut pas servir de dépôt Ansible.');
            $result['root'] = $root;
            $source = $root . '/' . $result['manifest'];
            $result['source'] = $source;
            if (!file_exists($source) && !is_link($source)) {
                $result['state'] = 'missing';
                $result['message'] = 'Manifeste local introuvable. Les opérations liées au profil restent bloquées.';
                return $result;
            }
            self::checkLocalPath($root, $result['manifest']);
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('Le manifeste local doit être un fichier JSON lisible.');
            }
            $size = filesize($source);
            if ($size === false || $size > 131072) {
                throw new RuntimeException('Le manifeste local dépasse la taille maximale de 128 Kio.');
            }
            $raw = @file_get_contents($source);
            if ($raw === false) throw new RuntimeException('Le manifeste local ne peut pas être lu.');
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($raw);
            if (!is_array($data) || array_is_list($data) || ($data['schema_version'] ?? null) !== 1) {
                throw new InvalidArgumentException('Le manifeste doit être un objet JSON avec schema_version égal à 1.');
            }
            $name = $data['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new InvalidArgumentException('Le manifeste doit définir un nom de profil non vide.');
            }
            if (!is_string($data['presets_path'] ?? null)) {
                throw new InvalidArgumentException('Le manifeste doit définir presets_path comme chemin relatif.');
            }
            $presets = self::validateRelativePath($data['presets_path']);
            $paths = $data['paths'] ?? null;
            if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
                throw new InvalidArgumentException('Le manifeste doit définir une liste paths non vide.');
            }
            $normalizedPaths = [];
            foreach ($paths as $path) {
                if (!is_string($path)) throw new InvalidArgumentException('Chaque entrée paths doit être un chemin relatif.');
                $path = self::validateRelativePath($path);
                if (in_array($path, $normalizedPaths, true)) {
                    throw new InvalidArgumentException('Le manifeste contient un chemin paths dupliqué.');
                }
                $normalizedPaths[] = $path;
            }
            $playbooks = $data['playbooks'] ?? null;
            if (!is_array($playbooks) || $playbooks === [] || array_is_list($playbooks)) {
                throw new InvalidArgumentException('Le manifeste doit définir les playbooks autorisés par rôle.');
            }
            foreach ($playbooks as $role => $path) {
                if (!is_string($role) || ctype_digit($role) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $role) || !is_string($path)) {
                    throw new InvalidArgumentException('Chaque playbook doit associer un rôle simple à un chemin relatif.');
                }
                $path = self::validateRelativePath($path);
                if (!preg_match('/\.(?:yml|yaml)\z/D', $path) || !self::covered($path, $normalizedPaths)) {
                    throw new InvalidArgumentException('Chaque playbook doit être un fichier YAML couvert par paths.');
                }
            }
            if (!self::covered($presets, $normalizedPaths)) {
                throw new InvalidArgumentException('Le répertoire presets_path doit être couvert par paths.');
            }
            $result['name'] = trim($name);
            $result['schema_version'] = 1;
            $result['presets_path'] = $presets;
            $result['paths'] = $normalizedPaths;
            $result['playbooks'] = $playbooks;
            // Git does not retain an empty output directory after delete-last.
            // Only this exact directory may be absent; no creation happens here.
            $result['presets_output_present'] = self::checkPresetOutput($root, $presets);
            foreach (array_unique([...$normalizedPaths, ...array_values($playbooks)]) as $path) {
                if ($path === $presets) continue;
                if (!self::checkLocalPath($root, $path)) {
                    $result['missing_paths'][] = $path;
                } elseif (is_dir($root . '/' . $path)) {
                    self::rejectScopedLinks($root . '/' . $path);
                }
            }
            if ($result['presets_output_present']) {
                self::rejectScopedLinks($root . '/' . $presets);
            }
            if ($result['missing_paths'] !== []) {
                $result['state'] = 'incomplete';
                $result['message'] = 'Profil local incomplet : certains fichiers ou répertoires déclarés sont absents.';
                return $result;
            }
            foreach ($playbooks as $path) {
                if (!is_file($root . '/' . $path) || !is_readable($root . '/' . $path)) {
                    throw new InvalidArgumentException('Un playbook autorisé ne désigne pas un fichier local lisible.');
                }
            }
            $result['valid'] = true;
            $result['state'] = 'ready';
            $result['message'] = 'Profil actif disponible localement. Seuls ses chemins et playbooks autorisés sont utilisés.';
            if (!$result['presets_output_present']) {
                $result['message'] .= ' Le répertoire de sortie des modèles sera créé uniquement lors d’une publication.';
            }
        } catch (\Throwable $error) {
            $result['valid'] = false;
            $result['state'] = 'invalid';
            $result['message'] = $error instanceof \JsonException
                ? 'Le manifeste local contient un JSON invalide.'
                : $error->getMessage();
        }
        return $result;
    }

    public static function allowedRole(string $role): bool
    {
        $profile = self::status();
        return $profile['valid'] && (!$profile['active'] || array_key_exists($role, $profile['playbooks']));
    }

    /** Presence only: never read Git configuration or sparse-checkout contents. */
    public static function hasScopedCheckout(?string $directory = null): bool
    {
        $marker = rtrim(self::directory($directory), '/') . '/.git/info/sparse-checkout';
        return file_exists($marker) || is_link($marker);
    }

    private static function directory(?string $directory): string
    {
        $environment = getenv('ANSIBLE_WORKDIR');
        $directory = $environment !== false && trim($environment) !== ''
            ? trim($environment)
            : trim($directory ?? (string)Setting::get(Setting::KEY_ANSIBLE_PLAYBOOKS_DIR, '/home/ansible/ansible'));
        return $directory !== '' ? $directory : '/home/ansible/ansible';
    }

    /** @param list<string> $paths */
    private static function covered(string $path, array $paths): bool
    {
        foreach ($paths as $entry) {
            if ($path === $entry || str_starts_with($path, $entry . '/')) return true;
        }
        return false;
    }

    /** Reject links and non-directory ancestors, including for a missing target. */
    private static function checkLocalPath(string $root, string $relative): bool
    {
        $candidate = $root;
        $segments = explode('/', $relative);
        foreach ($segments as $index => $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) throw new InvalidArgumentException('Les chemins du profil ne peuvent pas utiliser de liens symboliques.');
            if (!file_exists($candidate)) return false;
            $resolved = realpath($candidate);
            if ($resolved === false || !str_starts_with($resolved, $root . '/')) {
                throw new InvalidArgumentException('Un chemin du profil sort du dépôt local ou utilise un lien non résolu.');
            }
            if ($index < count($segments) - 1 && !is_dir($candidate)) {
                throw new InvalidArgumentException('Un ancêtre de chemin du profil n’est pas un répertoire.');
            }
            if (!is_file($candidate) && !is_dir($candidate)) {
                throw new InvalidArgumentException('Un chemin du profil ne désigne pas un fichier ou répertoire ordinaire.');
            }
        }
        return true;
    }

    /** Allow an absent preset output only after checking all existing ancestors. */
    private static function checkPresetOutput(string $root, string $relative): bool
    {
        $candidate = $root;
        $present = true;
        foreach (explode('/', $relative) as $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) {
                throw new InvalidArgumentException('Le répertoire de sortie des modèles et ses ancêtres ne peuvent pas être des liens symboliques.');
            }
            if (!file_exists($candidate)) {
                $present = false;
                continue;
            }
            $resolved = realpath($candidate);
            if (!is_dir($candidate) || $resolved === false || !str_starts_with($resolved, $root . '/')) {
                throw new InvalidArgumentException('Le répertoire de sortie des modèles doit avoir des ancêtres ordinaires dans le dépôt.');
            }
        }
        return $present;
    }

    /** Match the transport contract for every descendant selected by a directory. */
    private static function rejectScopedLinks(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || strtolower($entry->getFilename()) === '.git') {
                throw new InvalidArgumentException('Un répertoire du profil contient un lien symbolique ou des métadonnées Git.');
            }
        }
    }

    /** json_decode accepts duplicate object keys; the Git transport rejects them. */
    private static function rejectDuplicateKeys(string $raw): void
    {
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]~s', $raw, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'nextKey' => true, 'keys' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack !== []) {
                $index = count($stack) - 1;
                if ($token === ',' && $stack[$index]['object']) {
                    $stack[$index]['nextKey'] = true;
                } elseif ($token[0] === '"' && $stack[$index]['object'] && $stack[$index]['nextKey']) {
                    $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $stack[$index]['keys'])) {
                        throw new InvalidArgumentException('Le manifeste contient des clés JSON dupliquées.');
                    }
                    $stack[$index]['keys'][$key] = true;
                    $stack[$index]['nextKey'] = false;
                }
            }
        }
    }
}
