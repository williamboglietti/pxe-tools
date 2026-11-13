<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Models\Setting;
use InvalidArgumentException;

final class AnsibleConfiguration
{
    /** Validate and encrypt everything before any SQL or filesystem mutation. */
    public static function save(array $post): array
    {
        $oneShot = null;
        $values = [];
        // The worker reads a key file, not the historical plaintext DB value.
        // Do not accept a secret that would be stored but never used.
        $privateKey = $post['ssh_privkey'] ?? '';
        if (!is_string($privateKey) || trim($privateKey) !== '') {
            throw new InvalidArgumentException('La saisie de clé privée dans le formulaire n’est pas disponible. Configurez le fichier du worker séparément.');
        }
        $clearPrivateKey = $post['ssh_privkey_clear'] ?? '';
        if (!is_string($clearPrivateKey) || !in_array($clearPrivateKey, ['', '1'], true)) {
            throw new InvalidArgumentException('Demande de suppression de clé invalide.');
        }
        if (($post['webhook_action'] ?? '') === 'regen_secret') {
            $oneShot = bin2hex(random_bytes(32));
            $values['ansible_repo_webhook_secret'] = Crypto::encrypt($oneShot);
            $values['ansible_repo_webhook_enabled'] = isset($post['webhook_enabled']) ? 'on' : 'off';
            SettingsMutation::apply($values);
            return ['secret' => $oneShot];
        }
        if ($clearPrivateKey === '1') $values['ansible_ssh_privkey'] = '';
        $enabled = isset($post['ansible_enabled']);
        $values['ansible_enabled'] = $enabled ? 'on' : 'off';
        // Omitted fields preserve existing deployments and older callers.
        // Validation happens before secret encryption or SQL changes.
        $branch = RepositoryProfile::validateBranch((string)($post['repo_branch']
            ?? Setting::get(Setting::KEY_ANSIBLE_REPO_BRANCH, 'main')));
        $profile = RepositoryProfile::validateManifestPath((string)($post['repo_profile']
            ?? Setting::get(Setting::KEY_ANSIBLE_REPO_PROFILE, '')));
        if ($profile === '' && RepositoryProfile::hasScopedCheckout()) {
            throw new InvalidArgumentException('Ce clone est encore partiel. Préparez un clone complet contrôlé avant de retirer le profil. Aucun paramètre n’a été modifié.');
        }
        $values[Setting::KEY_ANSIBLE_REPO_BRANCH] = $branch;
        $values[Setting::KEY_ANSIBLE_REPO_PROFILE] = $profile;
        if ($enabled) {
            $repo = trim((string)($post['repo_url'] ?? Setting::get(Setting::KEY_ANSIBLE_REPO_URL, '') ?? ''));
            $pubkey = trim((string)($post['ssh_pubkey'] ?? ''));
            if ($repo !== '' && !preg_match('#^(git@[a-zA-Z0-9._-]+:|ssh://|https?://)\S+\.git$#', $repo)) {
                throw new InvalidArgumentException('URL du dépôt Git invalide. Utilisez une URL SSH ou HTTPS terminant par .git.');
            }
            if ($repo === '' && isset($post['webhook_enabled'])) {
                throw new InvalidArgumentException('Désactivez le webhook GitHub avant de passer aux playbooks locaux sans dépôt Git.');
            }
            if ($pubkey !== '' && !preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-[a-z0-9-]+)\s+[A-Za-z0-9+\/]+={0,2}(?:\s+[^\r\n]*)?$/D', $pubkey)) {
                throw new InvalidArgumentException('Clé publique SSH invalide. Copiez une clé publique SSH complète sur une seule ligne.');
            }
            $directory = trim((string)($post['playbooks_dir'] ?? '')) ?: '/home/ansible/ansible';
            if (!str_starts_with($directory, '/') || preg_match('/[\x00-\x1f]/', $directory)) {
                throw new InvalidArgumentException('Le répertoire des playbooks doit être un chemin absolu.');
            }
            $values += ['ansible_repo_url' => $repo, 'ansible_ssh_pubkey' => $pubkey, 'ansible_playbooks_dir' => $directory,
                'ansible_repo_webhook_enabled' => isset($post['webhook_enabled']) ? 'on' : 'off'];
            $user = trim((string)($post['github_push_user'] ?? ''));
            if ($user !== '') $values['github_push_user'] = $user;
            foreach (['github_push_token' => 'github_push_token', 'webhook_secret' => 'ansible_repo_webhook_secret'] as $field => $key) {
                $plain = (string)($post[$field] ?? '');
                if (!empty($post[$field . '_clear'])) {
                    $values[$key] = '';
                } elseif ($field === 'webhook_secret' && !empty($post['webhook_secret_regen'])) {
                    $oneShot = bin2hex(random_bytes(32));
                    $values[$key] = Crypto::encrypt($oneShot);
                } elseif ($plain !== '') {
                    $values[$key] = Crypto::encrypt($plain);
                }
            }
        }
        SettingsMutation::apply($values);
        return ['secret' => $oneShot];
    }
}
