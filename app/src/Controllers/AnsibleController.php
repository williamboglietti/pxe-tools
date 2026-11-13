<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Models\Setting;
use Admin\Util\AnsibleVaultUnlock;
use Admin\Util\Crypto;
use Admin\Util\RepositoryProfile;
use Admin\View;

/**
 * Spec F — Configuration du module Ansible (post-install provisioning).
 *
 * Routes :
 *   GET  /settings/ansible  → form (toggle + champs conditionnels)
 *   POST /settings/ansible  → sauve toutes les clés Ansible
 *
 * Quand `ansible_enabled = off` :
 *   - Le menu "Installations" est masqué dans layout.php
 *   - worker.py skip les jobs ansible:playbook (passe les machines en
 *     status='installed' direct sans phase de provisioning)
 *   - Les machines fraîchement installées ne reçoivent PAS la pubkey ansible
 *     (preseed.php.j2 génère un /home/ansible/.ssh/authorized_keys vide ou
 *     skippe carrément la création du user ansible)
 */
final class AnsibleController
{
    public function form(array $params): void
    {
        $this->renderForm(null);
    }

    /**
     * Rendu de la page /settings/ansible. Factorisé pour permettre à update()
     * d'afficher directement le secret webhook fraîchement généré dans la
     * réponse du POST (pas de redirect, pas de cookie/session — robuste face
     * aux reverse proxies qui peuvent drop les cookies sur le 302).
     */
    private function renderForm(?string $oneShotWebhookSecret, array $submitted = [], ?string $error = null): void
    {
        $pubkeyDb  = Setting::get(Setting::KEY_ANSIBLE_SSH_PUBKEY, '') ?? '';
        $privkeyDb = Setting::get(Setting::KEY_ANSIBLE_SSH_PRIVKEY, '') ?? '';

        // Filesystem paths (montés depuis WORKER_SSH_DIR sur l'host).
        // Source de vérité runtime : ces fichiers (le worker consomme la
        // privkey via `ssh -i ~/.ssh/ansible_automation`). Les settings DB
        // sont legacy et purement informatives si les fichiers sont là.
        $ansibleHome = rtrim((string)(getenv('PXE_ANSIBLE_HOME') ?: '/home/ansible'), '/');
        $pubkeyFs  = $ansibleHome . '/.ssh/ansible_automation.pub';
        $privkeyFs = $ansibleHome . '/.ssh/ansible_automation';

        // Pubkey affichable (public, OK en clair). Préférence DB, fallback fs.
        $pubkey = $pubkeyDb;
        $pubkeySource = '';
        if ($pubkeyDb !== '') {
            $pubkeySource = 'base de données';
        } elseif (is_readable($pubkeyFs)) {
            $pubkey = trim((string)@file_get_contents($pubkeyFs));
            $pubkeySource = 'volume monté (WORKER_SSH_DIR)';
        }

        // Privkey : ne JAMAIS afficher le contenu. Statut seul :
        //   'fs'   : fichier présent dans le volume monté (source officielle)
        //   'db'   : setting DB non vide (legacy, plain text — à migrer)
        //   'none' : aucune source
        $privkeyStatus = 'none';
        if (file_exists($privkeyFs)) {
            $privkeyStatus = 'fs';
        } elseif ($privkeyDb !== '') {
            $privkeyStatus = 'db';
        }

        $ghTokenRaw = Setting::get(Setting::KEY_GITHUB_PUSH_TOKEN, '');
        $webhookSecretRaw = Setting::get(Setting::KEY_ANSIBLE_REPO_WEBHOOK_SECRET, '');

        // URL publique du webhook (info read-only à coller dans GitHub).
        // Détection scheme : derrière un reverse proxy (Cloudflare Tunnel,
        // Traefik, nginx-proxy-manager…), $_SERVER['HTTPS'] est 'off' car le
        // proxy parle HTTP au backend. On lit X-Forwarded-Proto qui transporte
        // le scheme vu par le client. Idem X-Forwarded-Host pour le host.
        $scheme = 'http';
        $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($xfp === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
            $scheme = 'https';
        }
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        $webhookUrl = "{$scheme}://{$host}/api/ansible-repo/webhook";

        $data = [
            'enabled'                  => Setting::get(Setting::KEY_ANSIBLE_ENABLED, 'off') === 'on',
            'runtime_status'           => \Admin\Util\AnsibleRuntimeStatus::current(),
            'ssh_pubkey'               => $pubkey,
            'ssh_pubkey_source'        => $pubkeySource,
            'privkey_status'           => $privkeyStatus,
            'privkey_db_present'       => $privkeyDb !== '',
            'privkey_fs_path'          => $privkeyFs,
            'playbooks_dir'            => Setting::get(Setting::KEY_ANSIBLE_PLAYBOOKS_DIR, (string)(getenv('ANSIBLE_WORKDIR') ?: '/home/ansible/ansible')) ?? '/home/ansible/ansible',
            'vault_unlock_pending'     => AnsibleVaultUnlock::isPending(),
            'repo_url'                 => Setting::get(Setting::KEY_ANSIBLE_REPO_URL, '') ?? '',
            'repo_branch'              => Setting::get(Setting::KEY_ANSIBLE_REPO_BRANCH, 'main') ?? 'main',
            'repo_profile'             => Setting::get(Setting::KEY_ANSIBLE_REPO_PROFILE, '') ?? '',
            'github_push_token_set'    => $ghTokenRaw !== null && $ghTokenRaw !== '',
            'github_push_user'         => Setting::get(Setting::KEY_GITHUB_PUSH_USER, '') ?? '',
            'webhook_enabled'          => Setting::get(Setting::KEY_ANSIBLE_REPO_WEBHOOK_ENABLED, 'off') === 'on',
            'webhook_secret_set'       => $webhookSecretRaw !== null && $webhookSecretRaw !== '',
            'webhook_url'              => $webhookUrl,
            'one_shot_webhook_secret'  => $oneShotWebhookSecret,
        ];
        if ($submitted !== []) {
            foreach (['ssh_pubkey', 'playbooks_dir', 'repo_url', 'github_push_user'] as $field) {
                $data[$field] = (string)($submitted[$field] ?? '');
            }
            foreach (['repo_branch', 'repo_profile'] as $field) {
                $data[$field] = (string)($submitted[$field] ?? $data[$field]);
            }
            $data['enabled'] = isset($submitted['ansible_enabled']);
            $data['webhook_enabled'] = isset($submitted['webhook_enabled']);
            $data['ssh_pubkey_source'] = 'saisie à corriger, non enregistrée';
        }
        $data['error'] = $error;
        $data['profile_preview'] = $submitted !== [];
        $data['profile_status'] = RepositoryProfile::status($data['repo_profile'], $data['playbooks_dir'], $data['repo_branch']);
        if (trim($data['repo_url']) === '' && !$data['profile_status']['active'] && $data['profile_status']['valid']) {
            $data['profile_status']['message'] = 'Playbooks locaux : aucun dépôt Git ne sera synchronisé.';
        }
        View::render('settings/ansible/form', $data, 'Module Ansible');
    }

    public function update(array $params): void
    {
        try {
            $result = \Admin\Util\AnsibleConfiguration::save($_POST);
        } catch (\Throwable $error) {
            http_response_code(422);
            $this->renderForm(null, $_POST, $error->getMessage());
            return;
        }
        if ($result['secret'] !== null) {
            $this->renderForm($result['secret']);
            return;
        }
        View::flash('success', 'Configuration Ansible enregistrée. Elle sera utilisée par les prochaines opérations.');
        View::redirect('/settings/ansible');
    }

    /** Le mot de passe Vault ne sert qu'à la prochaine exécution : il n'est jamais enregistré. */
    public function unlockVault(array $params): void
    {
        try {
            AnsibleVaultUnlock::store((string)($_POST['vault_password'] ?? ''));
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            View::flash('error', $error->getMessage());
            View::redirect('/settings/ansible');
            return;
        }
        View::flash('success', 'Déverrouillage enregistré pour la prochaine exécution Ansible. Il sera effacé après cette exécution.');
        View::redirect('/settings/ansible');
    }
}
