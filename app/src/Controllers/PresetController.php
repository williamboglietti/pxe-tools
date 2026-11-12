<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\InitGuard;
use Admin\Models\Machine;
use Admin\Models\Preset;
use Admin\Models\Setting;
use Admin\Util\HardwareContext;
use Admin\Util\InstallConfigBuilder;
use Admin\View;

final class PresetController
{
    public function index(array $params): void
    {
        $presets = Preset::all();
        View::render('presets/index', ['presets' => $presets], 'Presets');
    }

    public function newForm(array $params): void
    {
        // ?based_on=<id> : permet de pré-ancrer un NOUVEAU modèle à l'inventaire
        // d'une machine dès le rendu (le sélecteur recharge vers cette URL). Sans
        // ça, le form restait en mode générique tant que le preset n'était pas
        // sauvé+ré-édité → disques/NICs jamais importés. Param absent OU machine
        // introuvable → modèle générique (HardwareContext::empty()).
        [$machineHw, $basedOn] = self::resolveBasedOnContext(
            array_key_exists('based_on', $_GET) ? $_GET['based_on'] : null,
            0
        );
        View::render('presets/edit', [
            'preset' => null,
            'allowed_distros' => self::allowedDistros(),
            'reference_machines' => self::referenceMachines(),
            'machineHw' => $machineHw,
            'cloneSeed' => null,
            'selected_based_on' => $basedOn,
        ], 'Nouveau preset');
    }

    /**
     * Résout le HardwareContext + l'id effectif d'inventaire pour le rendu du form.
     * $override = valeur ?based_on de l'URL (string|null) : si présente (même vide),
     * elle prime sur $saved (sélection rechargée depuis le dropdown) ; vide/0 →
     * modèle générique. Machine introuvable → générique (FK soft). $saved = valeur
     * persistée (édition d'un preset déjà lié) utilisée si pas d'override.
     *
     * @return array{0: array<string,mixed>, 1: int}  [machineHw, effectiveBasedOnId]
     */
    private static function resolveBasedOnContext($override, int $saved): array
    {
        if ($override !== null) {
            $id = is_numeric($override) ? (int)$override : 0;
        } else {
            $id = $saved;
        }
        if ($id > 0) {
            $refMachine = Machine::find($id);
            if ($refMachine) {
                return [HardwareContext::fromMachine($refMachine), $id];
            }
            $id = 0; // machine supprimée → générique
        }
        return [HardwareContext::empty(), $id];
    }

    public function edit(array $params): void
    {
        $id = (int) $params['id'];
        $preset = Preset::find($id);
        if (!$preset) { http_response_code(404); return; }

        // HardwareContext de la machine de référence (inventaire disques + NICs +
        // validation soft). ?based_on=<id> (sélection rechargée depuis le dropdown)
        // prime sur la valeur sauvée ; sinon on charge le based_on_machine_id
        // persisté. FK soft : machine supprimée → fallback générique.
        $savedBasedOn = isset($preset['based_on_machine_id']) ? (int)$preset['based_on_machine_id'] : 0;
        [$machineHw, $basedOnId] = self::resolveBasedOnContext(
            array_key_exists('based_on', $_GET) ? $_GET['based_on'] : null,
            $savedBasedOn
        );

        View::render('presets/edit', [
            'preset' => $preset,
            'allowed_distros' => self::allowedDistros(),
            'reference_machines' => self::referenceMachines(),
            'machineHw' => $machineHw,
            'cloneSeed' => null,
            'selected_based_on' => $basedOnId,
        ], 'Preset ' . $preset['name']);
    }

    /**
     * GET /presets/duplicate
     *   - Sans `source_id` : affiche un formulaire de sélection (liste des presets).
     *   - Avec `source_id`  : rend le form `presets/edit` pré-rempli avec les valeurs
     *                         du preset source (hérite `based_on_machine_id`).
     *   Le nom est forcé vide pour éviter la collision UNIQUE — l'utilisateur choisit.
     *   Aucune écriture DB : le preset est créé via le POST /presets standard.
     */
    public function duplicate(array $params): void
    {
        $sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

        if ($sourceId <= 0) {
            View::render('presets/duplicate', [
                'presets' => Preset::all(),
            ], 'Dupliquer un modèle');
            return;
        }

        $source = Preset::find($sourceId);
        if (!$source) {
            View::flash('error', 'Modèle source introuvable');
            View::redirect('/presets/duplicate');
            return;
        }

        // Charge HardwareContext si le source preset est lié à un inventaire.
        $machineHw = HardwareContext::empty();
        $basedOnId = isset($source['based_on_machine_id']) ? (int)$source['based_on_machine_id'] : 0;
        if ($basedOnId > 0) {
            $refMachine = Machine::find($basedOnId);
            if ($refMachine) {
                $machineHw = HardwareContext::fromMachine($refMachine);
            }
        }

        // source_data = toutes les colonnes utiles à pré-remplir le form. `name`
        // sera vidé côté view (cloneSeed merge logic) pour forcer un nouveau nom.
        $cloneSeed = [
            'source_label' => (string)$source['name'],
            'source_data'  => $source,
        ];

        View::render('presets/edit', [
            'preset' => null,
            'allowed_distros' => self::allowedDistros(),
            'reference_machines' => self::referenceMachines(),
            'machineHw' => $machineHw,
            'cloneSeed' => $cloneSeed,
        ], 'Nouveau modèle (dupliqué depuis ' . $source['name'] . ')');
    }

    /**
     * Liste des machines pouvant servir de référence hardware (status >= discovered,
     * donc disposant d'un inventory_json exploitable). Renvoie {id, label} pour le
     * <select> du form preset.
     *
     * @return array<int,array{id:int,label:string}>
     */
    private static function referenceMachines(): array
    {
        // Statuses "inventoriés" = ceux où inventory_json est attendu rempli :
        // discovered, configured, installing, installed, provisioned, failed,
        // wiping, wiped, testing, tested. On exclut unknown + inventorying.
        $excluded = ['unknown', 'inventorying'];
        $eligible = array_values(array_diff(Machine::STATUSES, $excluded));
        if (empty($eligible)) return [];
        $placeholders = implode(',', array_fill(0, count($eligible), '?'));
        $sql = "SELECT id, hostname, mac, status, inventory_json FROM machines "
             . "WHERE status IN ($placeholders) "
             . "ORDER BY (hostname IS NULL OR hostname = '') ASC, hostname ASC, mac ASC";
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($eligible);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $hn  = trim((string)($row['hostname'] ?? ''));
            $mac = (string)($row['mac'] ?? '');
            // Vendor + modèle système (DMI) depuis l'inventaire pour rendre le
            // choix lisible (« Packard Bell imedia S2185 ») plutôt qu'un MAC nu.
            // Fallback sur la carte mère ; champs absents → label = hostname/MAC.
            $hw  = '';
            $inv = json_decode((string)($row['inventory_json'] ?? ''), true);
            if (is_array($inv) && isset($inv['inventory']) && is_array($inv['inventory'])) {
                $i = $inv['inventory'];
                $vendor  = trim((string)($i['vendor']  ?? $i['board_vendor'] ?? ''));
                $product = trim((string)($i['product'] ?? $i['board_name']   ?? ''));
                $hw = trim("$vendor $product");
            }
            // « hostname — Vendor Modèle · MAC » (parties présentes uniquement).
            $label = ($hn !== '' ? "$hn — " : '') . ($hw !== '' ? "$hw · " : '') . $mac;
            $out[] = ['id' => (int)$row['id'], 'label' => $label];
        }
        return $out;
    }

    /**
     * Endpoint JSON : retourne le subset install-config d'un preset.
     * Consommé par le wizard d'install (chargement template).
     */
    public function showJson(array $params): void
    {
        $id = (int) $params['id'];
        $preset = Preset::find($id);
        header('Content-Type: application/json; charset=utf-8');
        if (!$preset) {
            http_response_code(404);
            echo json_encode(['error' => 'preset not found']);
            return;
        }
        $cfg = Preset::toInstallConfig($preset);
        // Décode le partition_custom_json (string JSON) en objet pour faciliter la consommation côté JS.
        if (isset($cfg['partition_custom_json']) && is_string($cfg['partition_custom_json'])) {
            $decoded = json_decode($cfg['partition_custom_json'], true);
            if (is_array($decoded)) $cfg['partition_custom_json'] = $decoded;
        }
        // Décode pre_wipe_mode JSON dict (string → objet) pour restauration per-disk dans le wizard.
        if (isset($cfg['pre_wipe_mode']) && is_string($cfg['pre_wipe_mode'])
            && str_starts_with(trim($cfg['pre_wipe_mode']), '{')) {
            $d = json_decode($cfg['pre_wipe_mode'], true);
            if (is_array($d)) {
                $cfg['pre_wipe_mode'] = $d;
            }
        }
        echo json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function create(array $params): void
    {
        $data = $this->extractFormData();
        $errors = $this->collectErrors($data);
        if ($errors) {
            $this->renderRejectedDraft($data, null, $errors);
            return;
        }
        try {
            $data['admin_user_password_cipher'] = InstallConfigBuilder::encryptPasswordFromPost();
            $id = Preset::create($data);
        } catch (\Exception $e) {
            $this->renderRejectedDraft($data, null, ['Enregistrement impossible : ' . $e->getMessage()]);
            return;
        }
        $this->ensureOsImageForPreset($data);
        self::enqueueGithubPush($id);
        View::flash('success', 'Modèle créé ; synchronisation GitHub programmée');
        View::redirect('/presets/' . $id);
    }

    /** Render this request's draft directly: no session draft and no lost textarea. */
    private function renderRejectedDraft(array $data, ?array $previous, array $errors): void
    {
        [$machineHw, $basedOn] = self::resolveBasedOnContext($data['based_on_machine_id'] ?? 0, 0);
        $submitted = $_POST;
        if (trim((string)($_POST['admin_user_password'] ?? '')) !== '') {
            // A rejected request keeps the previous saved credentials only.
            // The new secret must not survive in JSON, HTML or session state.
            $data['admin_user_pwhash'] = $previous['admin_user_pwhash'] ?? null;
            $data['admin_user_password_cipher'] = $previous['admin_user_password_cipher'] ?? null;
            unset($submitted['admin_user_pwhash_existing'], $submitted['admin_user_password_cipher_existing']);
            $errors[] = 'Ressaisissez le nouveau mot de passe après correction ; il n’a pas été enregistré.';
        }
        // Never echo plaintext credentials or CSRF tokens inside a JSON draft.
        unset($submitted['admin_user_password'], $submitted['root_password'], $submitted['csrf_token']);
        http_response_code(422);
        View::render('presets/edit', [
            'preset' => $previous,
            'draft' => $data,
            'submitted' => $submitted,
            'errors' => $errors,
            'allowed_distros' => self::allowedDistros(),
            'reference_machines' => self::referenceMachines(),
            'machineHw' => $machineHw,
            'cloneSeed' => null,
            'selected_based_on' => $basedOn,
        ], 'Modèle — corriger la saisie');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $previous = Preset::find($id);
        if (!$previous) { http_response_code(404); return; }
        $data = $this->extractFormData();
        $errors = $this->collectErrors($data);
        if (($previous['variant'] ?? 'debian') === 'proxmox-ve') {
            $errors[] = 'Ce modèle Proxmox VE historique est conservé en lecture seule pour le moment.';
        }
        if ($errors) {
            $this->renderRejectedDraft($data, $previous, $errors);
            return;
        }
        try {
            $data['admin_user_password_cipher'] = InstallConfigBuilder::encryptPasswordFromPost();
            Preset::update($id, $data);
        } catch (\Exception $e) {
            $this->renderRejectedDraft($data, $previous, ['Enregistrement impossible : ' . $e->getMessage()]);
            return;
        }
        $this->ensureOsImageForPreset($data);
        if ($previous['name'] !== $data['name']) {
            self::enqueueGithubDelete((string)$previous['name']);
        }
        self::enqueueGithubPush($id);
        View::flash('success', 'Modèle mis à jour ; synchronisation GitHub programmée');
        View::redirect('/presets/' . $id);
    }

    /**
     * Si le preset cible une version_full précise et que l'os_images correspondant
     * n'existe pas, déclenche un upsert pending (le worker le téléchargera).
     * Best-effort : on flash juste un info, on ne casse pas le save preset.
     */
    private function ensureOsImageForPreset(array $data): void
    {
        $distro = (string)($data['distro'] ?? '');
        $vf     = (string)($data['version_full'] ?? '');
        if ($distro === '' || $vf === '') return;
        $ok = \Admin\Models\OsImage::ensureForDistroVersion($distro, $vf);
        if (!$ok) {
            View::flash('warning', "Version $distro $vf inconnue du catalogue — vérifiez /settings/images");
        }
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $preset = Preset::find($id);
        if (!$preset) { http_response_code(404); return; }
        Preset::delete($id);
        self::enqueueGithubDelete((string)$preset['name']);
        View::flash('success', 'Modèle supprimé ; suppression GitHub programmée');
        View::redirect('/presets');
    }

    /**
     * Pousse le preset (JSON dump) dans le dépôt Ansible sous
     * host_vars/<slug>.json. Permet une restauration manuelle si
     * PXE Tools est réinitialisé. Le slug est dérivé de presets.name
     * par le worker (cf. run_preset_push_github).
     */
    public function pushGithub(array $params): void
    {
        $id = (int) $params['id'];
        $preset = Preset::find($id);
        if (!$preset) {
            View::flash('error', 'Modèle introuvable');
            View::redirect('/presets');
            return;
        }

        $jobType = 'preset:push_github:' . $id;
        $jobId = self::enqueueGithubPush($id);
        if (!$jobId) {
            View::flash('info', 'Une sauvegarde de ce modèle est déjà en cours');
            View::redirect('/presets/' . $id);
            return;
        }

        // BUG fix : ne PAS appeler InitGuard::resetCache() ni rediriger vers /init.
        // Le push GitHub est un job ponctuel — il ne doit pas bloquer la navigation
        // ni faire basculer toute l'UI sur l'écran d'initialisation PXE.
        // Toast "live" : on passe jobType au flash, toast.js poll
        // /api/init-job-status?job_type=... et met à jour in-place jusqu'au
        // statut final (success/failed).
        View::flash(
            'info',
            'Sauvegarde du modèle "' . $preset['name'] . '" en cours…',
            '/jobs/' . $jobId, 'Voir le suivi',
            $jobType
        );
        View::redirect('/presets/' . $id);
    }

    private static function enqueueGithubPush(int $id): int|false
    {
        $jobType = 'preset:push_github:' . $id;
        $pdo = Db::get();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM init_jobs WHERE job_type = ? AND status = 'pending'"
        );
        $stmt->execute([$jobType]);
        if ((int)$stmt->fetchColumn() > 0) return false;
        // Un second enregistrement pendant un push en cours doit être publié à
        // son tour : le job en cours a pu lire l'ancienne version du modèle.
        $pdo->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
        )->execute([$jobType, 'Synchronisation automatique du modèle #' . $id . ' vers GitHub']);
        return (int)$pdo->lastInsertId();
    }

    private static function enqueueGithubDelete(string $name): void
    {
        $slug = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');
        if ($slug === '') return;
        Db::get()->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
        )->execute(['preset:delete_github:' . $slug, 'Suppression du modèle « ' . $name . ' » sur GitHub']);
    }

    /**
     * GET /presets/import — affiche le formulaire d'import (file upload + textarea).
     */
    public function importForm(array $params): void
    {
        View::render('presets/import', [], 'Importer un modèle');
    }

    /**
     * POST /presets/import — parse + upsert un preset depuis un JSON
     * (fichier uploadé OU textarea). Le format attendu est celui produit par
     * `run_preset_push_github` (dump direct des colonnes presets).
     */
    public function importStore(array $params): void
    {
        $jsonRaw = '';
        if (
            isset($_FILES['preset_json'])
            && is_array($_FILES['preset_json'])
            && ($_FILES['preset_json']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file($_FILES['preset_json']['tmp_name'])
        ) {
            $jsonRaw = (string) file_get_contents($_FILES['preset_json']['tmp_name']);
        }
        if (trim($jsonRaw) === '') {
            $jsonRaw = (string) ($_POST['preset_json_raw'] ?? '');
        }
        if (trim($jsonRaw) === '') {
            View::flash('error', 'Aucun JSON fourni (fichier ou zone de texte vide)');
            View::redirect('/presets/import');
            return;
        }

        $payload = json_decode($jsonRaw, true);
        if (!is_array($payload)) {
            View::flash('error', 'JSON invalide : ' . json_last_error_msg());
            View::redirect('/presets/import');
            return;
        }

        $data = $this->dataFromJsonPayload($payload);
        $errors = $this->collectErrors($data);
        if (!empty($errors)) {
            foreach ($errors as $e) View::flash('error', $e);
            View::redirect('/presets/import');
            return;
        }

        try {
            $result = Preset::upsertByName($data);
        } catch (\Throwable $e) {
            View::flash('error', 'Erreur DB : ' . $e->getMessage());
            View::redirect('/presets/import');
            return;
        }

        self::enqueueGithubPush($result['id']);

        $verb = match ($result['action']) {
            'updated' => 'mis à jour',
            'unchanged' => 'déjà à jour',
            default => 'créé',
        };
        View::flash('success', "Modèle « {$data['name']} » $verb");
        View::redirect('/presets/' . $result['id']);
    }

    /**
     * POST /presets/pull-github — enqueue un job worker qui clone/pull le
     * dépôt Ansible et importe (UPSERT) tous les host_vars/*.json.
     */
    public function pullGithub(array $params): void
    {
        $repo = trim((string) (Setting::get(Setting::KEY_ANSIBLE_REPO_URL, '') ?? ''));
        if ($repo === '') {
            View::flash('error', 'Aucun dépôt Ansible configuré (cf. /settings/ansible)');
            View::redirect('/presets');
            return;
        }

        $pdo = Db::get();
        // Dédup large : couvre aussi `preset:pull_github:autoseed` enqueued
        // par le bootstrap, pour éviter de lancer deux pulls en parallèle.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM init_jobs "
            . "WHERE (job_type = 'preset:pull_github' OR job_type LIKE 'preset:pull_github:%') "
            . "AND status IN ('pending','running')"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() > 0) {
            View::flash('info', 'Une synchronisation depuis GitHub est déjà en cours');
            View::redirect('/init');
            return;
        }

        $pdo->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES ('preset:pull_github', 'pending', ?)"
        )->execute(['Synchronisation des modèles depuis GitHub']);
        InitGuard::resetCache();
        // Toast "live" : suit le job en place (cf. PresetController::pushGithub).
        View::flash('info', 'Synchronisation depuis GitHub demandée…', '/jobs/' . (int)$pdo->lastInsertId(), 'Voir le suivi', 'preset:pull_github');
        View::redirect('/presets');
    }

    /**
     * Normalise un payload JSON (format produit par push_github) en un array
     * prêt pour `Preset::upsertByName()`. Ignore les colonnes techniques
     * (`id`, `created_at`, `updated_at`) et accepte les variantes acceptables :
     *   - `partition_custom_json` / `advanced_config_json` en objet OU string JSON
     *   - `static_dns` en array OU string JSON
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function dataFromJsonPayload(array $payload): array
    {
        $strOrNull = static fn(mixed $v): ?string => (is_string($v) && trim($v) !== '') ? trim($v) : null;

        $reEncodeIfArray = static function (mixed $v): ?string {
            if (is_array($v))                    return json_encode($v, JSON_UNESCAPED_SLASHES);
            if (is_string($v) && trim($v) !== '') return $v;
            return null;
        };

        $sdns = $payload['static_dns'] ?? null;
        if (is_array($sdns)) {
            $sdns = json_encode(array_values(array_filter(
                array_map('trim', array_map('strval', $sdns)),
                static fn($x) => $x !== ''
            )), JSON_UNESCAPED_SLASHES);
        } elseif (!is_string($sdns) || trim($sdns) === '') {
            $sdns = json_encode([], JSON_UNESCAPED_SLASHES);
        }

        $cidrRaw = $payload['static_cidr'] ?? null;
        $cidr = (is_numeric($cidrRaw)) ? (int)$cidrRaw : null;

        $networkMode = in_array($payload['network_mode'] ?? '', Preset::NETWORK_MODES, true)
            ? (string)$payload['network_mode'] : 'dhcp';
        $partitionMode = in_array($payload['partition_mode'] ?? '', Preset::PARTITION_MODES, true)
            ? (string)$payload['partition_mode'] : 'default';
        $rootMode = in_array($payload['root_login_mode'] ?? '', Preset::ROOT_LOGIN_MODES, true)
            ? (string)$payload['root_login_mode'] : 'disabled';
        // Un ancien export peut omettre ce champ : ne pas écraser le choix
        // déjà enregistré lorsque l'import met à jour un preset existant.
        if (!array_key_exists('root_login_mode', $payload)) {
            $existingId = Preset::findIdByName(trim((string)($payload['name'] ?? '')));
            $existing = $existingId === null ? null : Preset::find($existingId);
            if (in_array($existing['root_login_mode'] ?? '', Preset::ROOT_LOGIN_MODES, true)) {
                $rootMode = (string)$existing['root_login_mode'];
            }
        }

        // pre_wipe_mode : accepte scalar ('aucun'|'partial'|'full') ou dict array/JSON
        $preWipeRaw = $payload['pre_wipe_mode'] ?? 'aucun';
        if (is_array($preWipeRaw)) {
            // Dict déjà décodé → filtrer les clés invalides puis ré-encoder
            $validModes = ['aucun', 'partial', 'full'];
            $preWipeDict = [];
            foreach ($preWipeRaw as $disk => $mode) {
                if (!preg_match('#^(/dev/[A-Za-z0-9/_-]+|\*)$#', $disk)) {
                    continue;
                }
                $m = (string)$mode;
                $preWipeDict[(string)$disk] = in_array($m, $validModes, true) ? $m : 'aucun';
            }
            // Si la seule clé est '*', stocker comme scalar pour rétrocompat (miroir extractFormData L387)
            if (count($preWipeDict) === 1 && isset($preWipeDict['*'])) {
                $preWipe = $preWipeDict['*'];
            } else {
                $preWipe = json_encode($preWipeDict, JSON_UNESCAPED_SLASHES);
            }
        } elseif (is_string($preWipeRaw) && str_starts_with(trim($preWipeRaw), '{')) {
            // JSON dict string → conserver tel quel (format nouveau)
            $preWipe = $reEncodeIfArray(json_decode($preWipeRaw, true)) ?? 'aucun';
        } else {
            // Scalar legacy
            $s = trim((string)$preWipeRaw);
            $preWipe = in_array($s, ['aucun', 'partial', 'full'], true) ? $s : 'aucun';
        }

        return [
            'name'                  => trim((string) ($payload['name'] ?? '')),
            'description'           => $strOrNull($payload['description'] ?? null),
            'distro'                => trim((string) ($payload['distro'] ?? '')),
            'codename'              => $strOrNull($payload['codename'] ?? null),
            'version_full'          => $strOrNull($payload['version_full'] ?? null),
            'network_mode'          => $networkMode,
            'static_ip'             => $strOrNull($payload['static_ip'] ?? null),
            'static_cidr'           => $cidr,
            'static_netmask'        => $cidr !== null ? Preset::cidrToNetmask($cidr) : null,
            'static_gateway'        => $strOrNull($payload['static_gateway'] ?? null),
            'static_dns'            => $sdns,
            'partition_mode'        => $partitionMode,
            'partition_custom_json' => $reEncodeIfArray($payload['partition_custom_json'] ?? null),
            'admin_user_login'      => trim((string) ($payload['admin_user_login'] ?? '')),
            'admin_user_pwhash'     => $strOrNull($payload['admin_user_pwhash'] ?? null),
            'admin_user_password_cipher' => $strOrNull($payload['admin_user_password_cipher'] ?? null),
            'admin_user_groups'     => trim((string) ($payload['admin_user_groups'] ?? '')) ?: 'sudo',
            'admin_user_pubkeys'    => $strOrNull($payload['admin_user_pubkeys'] ?? null),
            'admin_user_fullname'   => $strOrNull($payload['admin_user_fullname'] ?? null),
            'root_login_mode'       => $rootMode,
            'advanced_config_json'  => $reEncodeIfArray($payload['advanced_config_json'] ?? null),
            'ansible_role'          => $strOrNull($payload['ansible_role'] ?? null),
            'ansible_extra_vars'    => $strOrNull($payload['ansible_extra_vars'] ?? null),
            'domain'                => $strOrNull($payload['domain'] ?? null),
            // Preserve unknown values for validation rather than converting
            // them silently to another installation type.
            'variant'                => array_key_exists('variant', $payload)
                ? (is_string($payload['variant']) ? $payload['variant'] : '') : 'debian',
            'pre_wipe_mode'         => $preWipe,
            // Phase 2 chantier hardware-aware : round-trip JSON import/export
            'based_on_machine_id'   => isset($payload['based_on_machine_id']) && is_numeric($payload['based_on_machine_id']) && (int)$payload['based_on_machine_id'] > 0 ? (int)$payload['based_on_machine_id'] : null,
            // Le payload peut utiliser la clé moderne `hostname` ou l'ancienne
            // `hostname_pattern` (back-compat des dumps déjà sur disque).
            'hostname_pattern'      => $strOrNull($payload['hostname'] ?? $payload['hostname_pattern'] ?? null),
            'hostname_random'       => !empty($payload['hostname_random']) ? 1 : 0,
            'pre_wipe_mode_json'    => $reEncodeIfArray($payload['pre_wipe_mode_json'] ?? null),
        ];
    }

    /**
     * Point d'entrée non-HTTP (CLI worker) : upsert d'un preset depuis un
     * payload JSON déjà décodé. Retourne le résultat `upsertByName` ou lève
     * une exception listant les erreurs de validation.
     *
     * @param array<string,mixed> $payload
     * @return array{action: 'updated'|'created'|'unchanged', id: int, name: string}
     */
    public static function importFromArray(array $payload): array
    {
        $ctrl = new self();
        $data = $ctrl->dataFromJsonPayload($payload);
        $errors = $ctrl->collectErrors($data);
        if (!empty($errors)) {
            throw new \RuntimeException('Validation failed: ' . implode(' ; ', $errors));
        }
        $res = Preset::upsertByName($data);
        return ['action' => $res['action'], 'id' => $res['id'], 'name' => $data['name']];
    }

    /** Validate and describe an import without saving a preset or issuing jobs. */
    public static function validateFromArray(array $payload): array
    {
        $ctrl = new self();
        $data = $ctrl->dataFromJsonPayload($payload);
        return ['name' => $data['name'], 'errors' => $ctrl->collectErrors($data),
            'review' => InstallConfigBuilder::review(Preset::toInstallConfig($data))];
    }

    private function extractFormData(): array
    {
        // Hash sha512crypt (preseed Debian, irréversible) + cipher Crypto
        // (master.key, réversible pour affichage UI). Les deux dérivent du même
        // clair POSTé, ou tombent sur les valeurs existantes (input hidden) si
        // le user a laissé le champ vide en édition.
        $pwhash = null;
        $pwcipher = null;
        $pwRaw = (string)($_POST['admin_user_password'] ?? '');
        if (trim($pwRaw) !== '') {
            $salt = '$6$rounds=656000$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 16);
            $pwhash = crypt($pwRaw, $salt);
            // Encryption is deferred until validation succeeds, inside the save try/catch.
        } else {
            if (!empty($_POST['admin_user_pwhash_existing'])) {
                $pwhash = $_POST['admin_user_pwhash_existing'];
            }
            if (!empty($_POST['admin_user_password_cipher_existing'])) {
                $pwcipher = $_POST['admin_user_password_cipher_existing'];
            }
        }

        $cidrRaw = $_POST['static_cidr'] ?? '';
        $cidr = ($cidrRaw === '' || $cidrRaw === null) ? null : (int)$cidrRaw;

        $dns = array_values(array_filter(
            array_map('trim', (array)($_POST['dns'] ?? [])),
            fn($x) => $x !== ''
        ));

        $isCustom = ($_POST['partition_mode'] ?? '') === 'custom';
        $plan = InstallConfigBuilder::extractPartitioningPlan();

        $netmaskLegacy = $cidr !== null ? Preset::cidrToNetmask($cidr) : null;

        // Mode root + fullname admin (utilisé si root_login_mode=disabled)
        $rootMode = in_array($_POST['root_login_mode'] ?? '', Preset::ROOT_LOGIN_MODES, true)
            ? $_POST['root_login_mode'] : 'disabled';
        $fullname = trim($_POST['admin_user_fullname'] ?? '') ?: null;

        // Configuration avancée : POST imbriqué adv[section][key]
        // + plan multi-NIC issu de network[interfaces][N][...] si présent (Phase 4
        // chantier preset-hardware-aware). Le plan est stocké sous advanced.network.
        $advancedJson = $this->extractAdvancedConfig($_POST['adv'] ?? []);
        $diskConstraints = InstallConfigBuilder::diskConstraintsFromPost();
        if ($diskConstraints !== []) {
            $advancedWithConstraints = $advancedJson !== null ? (json_decode($advancedJson, true) ?: []) : [];
            $advancedWithConstraints['disk_target_constraints'] = $diskConstraints;
            $advancedJson = json_encode($advancedWithConstraints, JSON_UNESCAPED_SLASHES);
        }

        // based_on_machine_id (résolu plus bas pour la défense en profondeur réseau)
        $basedOnRawEarly = $_POST['based_on_machine_id'] ?? '';
        $basedOnIdEarly  = (is_numeric($basedOnRawEarly) && (int)$basedOnRawEarly > 0) ? (int)$basedOnRawEarly : null;

        $netPlan = [];
        if (isset($_POST['network']['interfaces']) && is_array($_POST['network']['interfaces'])) {
            $netPlan = InstallConfigBuilder::extractNetworkPlan();
            if (!empty($netPlan['interfaces'])) {
                // Défense en profondeur : si un inventaire matériel est lié, on
                // force les MACs du plan à correspondre à celles inventoriées
                // (un POST forgé modifiant network[interfaces][N][match][mac]
                // serait sinon persisté en DB).
                if ($basedOnIdEarly !== null) {
                    $refMachine = Machine::find($basedOnIdEarly);
                    if ($refMachine) {
                        $invNics = HardwareContext::fromMachine($refMachine)['nics'] ?? [];
                        $invMacsByIdx = [];
                        foreach (array_values($invNics) as $i => $nic) {
                            $m = strtolower(trim((string)($nic['mac'] ?? '')));
                            if ($m !== '') $invMacsByIdx[$i] = $m;
                        }
                        foreach ($netPlan['interfaces'] as $idx => &$iface) {
                            if (isset($invMacsByIdx[$idx])) {
                                $iface['match'] = ['mac' => $invMacsByIdx[$idx]];
                                $iface['match_by'] = 'mac';
                            }
                        }
                        unset($iface);
                    }
                }
                $advDecoded = $advancedJson !== null ? (json_decode($advancedJson, true) ?: []) : [];
                $advDecoded['network'] = $netPlan;
                $advancedJson = json_encode($advDecoded, JSON_UNESCAPED_SLASHES);
            }
        }
        $legacyNetwork = InstallConfigBuilder::legacyNetworkFromPlan($netPlan);
        if ($legacyNetwork !== null) {
            $cidr = $legacyNetwork['static_cidr'];
            $netmaskLegacy = $cidr !== null ? Preset::cidrToNetmask($cidr) : null;
            $dns = $legacyNetwork['static_dns'];
        }

        // Mode de wipe pré-installation : 2 chemins de POST possibles côté UI.
        //
        // 1. Champ moderne `pre_wipe_mode_json` (Phase 2 chantier hardware-aware) :
        //    JSON dict {"/dev/sda":"partial","/dev/sdb":"aucun"} produit par l'UI
        //    quand une machine de référence est sélectionnée (table per-disque
        //    identique au wizard install). Source de vérité dans la colonne
        //    presets.pre_wipe_mode_json.
        //
        // 2. Champ legacy `pre_wipe_mode` :
        //    - scalar global ('aucun'|'partial'|'full') = preset générique sans
        //      machine de réf (UI radio classique)
        //    - array per-disk legacy : préservé pour rétro-compat des UIs déjà
        //      câblées (Wizard install ou présets historiques)
        //
        // Lorsque pre_wipe_mode_json est fourni, il alimente la colonne dédiée.
        // La colonne scalar pre_wipe_mode reste alimentée pour la rétrocompat
        // (lecture par parseWipeMode + worker.py legacy).
        $validModes = ['aucun', 'partial', 'full'];

        $preWipeJsonRaw = trim((string)($_POST['pre_wipe_mode_json'] ?? ''));
        $preWipeJson    = null;
        if ($preWipeJsonRaw !== '') {
            $decoded = json_decode($preWipeJsonRaw, true);
            if (is_array($decoded)) {
                $clean = [];
                foreach ($decoded as $disk => $mode) {
                    if (!preg_match('#^(/dev/[A-Za-z0-9/_-]+|\*)$#', (string)$disk)) continue;
                    $m = (string)$mode;
                    $clean[(string)$disk] = in_array($m, $validModes, true) ? $m : 'aucun';
                }
                if (!empty($clean)) {
                    $preWipeJson = json_encode($clean, JSON_UNESCAPED_SLASHES);
                }
            }
        }

        $preWipeRaw = $_POST['pre_wipe_mode'] ?? 'aucun';
        if (is_array($preWipeRaw)) {
            // Format per-disk ou fallback global (clé '*')
            $preWipeDict = [];
            foreach ($preWipeRaw as $disk => $mode) {
                if (!preg_match('#^(/dev/[A-Za-z0-9/_-]+|\*)$#', $disk)) {
                    continue;
                }
                $m = (string)$mode;
                $preWipeDict[(string)$disk] = in_array($m, $validModes, true) ? $m : 'aucun';
            }
            if (array_keys($preWipeDict) === ['*']) {
                $preWipe = $preWipeDict['*'];
            } else {
                $preWipe = json_encode($preWipeDict, JSON_UNESCAPED_SLASHES);
            }
        } else {
            $preWipe = (string)$preWipeRaw;
            if (!in_array($preWipe, $validModes, true)) {
                $preWipe = 'aucun';
            }
        }

        // based_on_machine_id : int positif ou null (FK soft vers machines)
        // (déjà résolu plus haut sous $basedOnIdEarly pour la défense en profondeur réseau)
        $basedOnId = $basedOnIdEarly;

        // Nom d'hôte du preset : nom statique (la notion de "pattern" est
        // dépréciée, cf. presets/edit.php). Stocké tel quel dans la colonne
        // hostname_pattern (préservation du nom de colonne historique).
        // Si toggle "Aléatoire à l'installation" coché, on ignore la valeur
        // texte (vidée côté JS, mais défense en profondeur ici).
        $hostnameRandom = !empty($_POST['hostname_random']) ? 1 : 0;
        $hostnameValue  = trim((string)($_POST['hostname'] ?? '')) ?: null;
        if ($hostnameRandom === 1) {
            $hostnameValue = null;
        }

        // Keep an inherited variant visible to validation without assigning an
        // implementation or silently replacing it with a Debian preset.
        $variant        = (($_POST['variant_proxmox'] ?? '') === '1') ? 'proxmox-ve' : 'debian';
        $distroValue   = $_POST['distro'] ?? '';
        $codenameValue = trim((string)($_POST['codename'] ?? '')) ?: null;
        $ansibleRole   = trim($_POST['ansible_role'] ?? '') ?: null;

        return [
            'name'                  => trim($_POST['name'] ?? ''),
            'description'           => trim($_POST['description'] ?? '') ?: null,
            'distro'                => $distroValue,
            // codename (modèle distro-name) : release ('trixie'). NULL = dérivé runtime.
            'codename'              => $codenameValue,
            // version_full : point release de l'OS (ex 13.4.0). NULL = render-per-mac /
            // preseed.php résoudront via OsImage::latestForDistro au runtime.
            'version_full'          => trim((string)($_POST['version_full'] ?? '')) ?: null,
            'network_mode'          => $legacyNetwork['network_mode'] ?? (in_array($_POST['network_mode'] ?? '', Preset::NETWORK_MODES, true) ? $_POST['network_mode'] : 'dhcp'),
            'static_ip'             => $legacyNetwork !== null ? $legacyNetwork['static_ip'] : (trim($_POST['static_ip'] ?? '') ?: null),
            'static_cidr'           => $cidr,
            'static_netmask'        => $netmaskLegacy,
            'static_gateway'        => $legacyNetwork !== null ? $legacyNetwork['static_gateway'] : (trim($_POST['static_gateway'] ?? '') ?: null),
            'static_dns'            => json_encode($dns, JSON_UNESCAPED_SLASHES),
            'partition_mode'        => in_array($_POST['partition_mode'] ?? '', Preset::PARTITION_MODES, true) ? $_POST['partition_mode'] : 'default',
            'partition_custom_json' => $isCustom ? json_encode($plan, JSON_UNESCAPED_SLASHES) : null,
            'admin_user_login'      => trim($_POST['admin_user_login'] ?? ''),
            'admin_user_pwhash'     => $pwhash,
            'admin_user_password_cipher' => $pwcipher,
            'admin_user_groups'     => trim($_POST['admin_user_groups'] ?? '') ?: 'sudo',
            'admin_user_pubkeys'    => trim($_POST['admin_user_pubkeys'] ?? '') ?: null,
            'admin_user_fullname'   => $fullname,
            'root_login_mode'       => $rootMode,
            'advanced_config_json'  => $advancedJson,
            'ansible_role'          => $ansibleRole,
            'ansible_extra_vars'    => trim($_POST['ansible_extra_vars'] ?? '') ?: null,
            'domain'                => trim($_POST['domain'] ?? '') ?: null,
            'variant'                => $variant,
            'pre_wipe_mode'         => $preWipe,
            // Phase 2 chantier hardware-aware
            'based_on_machine_id'   => $basedOnId,
            'hostname_pattern'      => $hostnameValue,
            'hostname_random'       => $hostnameRandom,
            'pre_wipe_mode_json'    => $preWipeJson,
        ];
    }

    /**
     * Normalise et JSON-encode le groupe `adv[...]` du POST pour stockage SQL
     * dans presets.advanced_config_json. Délègue la normalisation à
     * Preset::normalizeAdvancedPayload (mutualisée avec InstallConfigBuilder
     * pour le wizard machine).
     */
    private function extractAdvancedConfig(mixed $adv): ?string
    {
        if (!is_array($adv)) return null;
        return json_encode(Preset::normalizeAdvancedPayload($adv), JSON_UNESCAPED_SLASHES);
    }

    private function validate(array $data): bool
    {
        $errors = $this->collectErrors($data);
        if (!empty($errors)) {
            foreach ($errors as $e) View::flash('error', $e);
            return false;
        }
        return true;
    }

    /**
     * Calcule les erreurs de validation d'un preset sans side-effect (pas de
     * flash). Utilisée par la voie formulaire (qui flush) et par les voies
     * non-HTTP (import JSON, CLI), qui collectent les erreurs autrement.
     *
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private function collectErrors(array $data): array
    {
        $errors = \Admin\Util\DiskIdentity::validateConstraints(Preset::toInstallConfig($data));
        if ($data['name'] === '')             $errors[] = 'Nom requis';
        if ($data['distro'] === '')           $errors[] = 'Distro requise';
        if ($data['admin_user_login'] === '') $errors[] = 'User admin requis';
        $ansibleRole = trim((string)($data['ansible_role'] ?? ''));
        if ($ansibleRole !== '' && !\Admin\Util\RepositoryProfile::allowedRole($ansibleRole)) {
            $errors[] = 'Rôle Ansible absent du profil de provisionnement PXE ou profil indisponible';
        }
        $allowed = self::allowedDistros();
        if ($data['distro'] !== '' && !in_array($data['distro'], $allowed, true)) {
            $errors[] = 'Distro invalide (autorisées: ' . implode(',', $allowed) . ')';
        }

        $variant = $data['variant'] ?? 'debian';
        if (!in_array($variant, ['debian', 'proxmox-ve'], true)) {
            $errors[] = 'Variante de modèle inconnue : aucun remplacement automatique ne sera effectué.';
        } elseif ($variant === 'proxmox-ve') {
            $errors[] = 'Les modèles Proxmox VE ne sont pas encore disponibles dans cette édition.';
        }
        if ($data['name'] !== '') {
            $existingId = Preset::findIdByName($data['name']);
            $existing = $existingId === null ? null : Preset::find($existingId);
            if (($existing['variant'] ?? 'debian') === 'proxmox-ve') {
                $errors[] = 'Ce modèle Proxmox VE historique est conservé en lecture seule pour le moment.';
            }
        }

        if ($data['network_mode'] === 'static') {
            if (empty($data['static_ip']) || !filter_var($data['static_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'Mode static : IP IPv4 valide requise';
            }
            if (!is_int($data['static_cidr']) || $data['static_cidr'] < 1 || $data['static_cidr'] > 32) {
                $errors[] = 'Mode static : préfixe CIDR (1-32) requis';
            }
            if (empty($data['static_gateway']) || !filter_var($data['static_gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'Mode static : gateway IPv4 valide requise';
            }
            $dns = json_decode($data['static_dns'] ?? '[]', true) ?: [];
            $dnsValid = array_filter($dns, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
            if (count($dnsValid) === 0) {
                $errors[] = 'Mode static : au moins un DNS IPv4 valide requis';
            }
        }

        if ($data['partition_mode'] === 'custom') {
            $plan = Preset::parsePartitioningPlan($data['partition_custom_json'] ?? null);

            // Generic recipes must be structurally valid too. Capacity checks
            // additionally use the reference inventory when one is available.
            $diskCapacitiesMb = [];
            $strict = false;
            $basedOnId = isset($data['based_on_machine_id']) ? (int)$data['based_on_machine_id'] : 0;
            if ($basedOnId > 0) {
                $refMachine = Machine::find($basedOnId);
                if ($refMachine) {
                    $diskCapacitiesMb = Machine::diskCapacitiesMbFromInventory($refMachine);
                    $strict = true;
                }
                // A deleted reference removes capacity checks; structural
                // validation still applies to the recipe.
            }
            $errors = array_merge(
                $errors,
                InstallConfigBuilder::validatePartitioningPlan($plan, $diskCapacitiesMb, $strict)
            );
        }

        // root_login_mode='disabled' : le user admin doit avoir au moins un accès
        // (password OU pubkeys), sinon le compte sera inaccessible après install.
        if (($data['root_login_mode'] ?? 'enabled') === 'disabled') {
            $hasPw = !empty($data['admin_user_pwhash']);
            $hasPubkeys = !empty(trim($data['admin_user_pubkeys'] ?? ''));
            if (!$hasPw && !$hasPubkeys) {
                $errors[] = 'Compte root désactivé : le user admin doit avoir un mot de passe OU au moins une clé SSH (sinon aucun accès)';
            }
        }

        // Validation light des champs avancés (le formatage strict est fait à l'extraction)
        $adv = $data['advanced_config_json'] !== null ? json_decode($data['advanced_config_json'], true) : null;
        if ($data['advanced_config_json'] !== null && (!is_array($adv) || (array_is_list($adv) && trim($data['advanced_config_json']) !== '{}'))) {
            $errors[] = 'Configuration avancée : objet JSON valide requis';
        }
        if (is_array($adv)) {
            if (isset($adv['network'])) {
                if (!is_array($adv['network'])) $errors[] = 'Réseau : objet de configuration requis';
                else {
                    $nics = [];
                    $ref = !empty($data['based_on_machine_id']) ? Machine::find((int)$data['based_on_machine_id']) : null;
                    if ($ref) $nics = HardwareContext::fromMachine($ref)['nics'];
                    $errors = array_merge($errors, InstallConfigBuilder::validateNetworkPlan($adv['network'], $nics));
                }
            }
            $zone = $adv['timezone']['zone'] ?? '';
            if ($zone !== '' && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                $errors[] = "Timezone invalide : '$zone' n'est pas un identifiant IANA";
            }
            $bootdev = $adv['bootloader']['bootdev'] ?? '';
            if ($bootdev !== '' && $bootdev !== 'default' && !preg_match('#^/dev/[A-Za-z0-9/_-]+$#', $bootdev)) {
                $errors[] = "Bootdev invalide : doit être 'default' ou /dev/...";
            }
            $mirHost = $adv['mirror']['hostname'] ?? '';
            if ($mirHost !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $mirHost)) {
                $errors[] = "Hostname miroir invalide : '$mirHost'";
            }
            // NTP server : IPv4 OU FQDN (RFC 1123 simplifié : labels alphanum/hyphen,
            // séparés par points, pas de hyphen en bord, longueur 1-63 par label).
            $ntpServer = $adv['timezone']['ntp_server'] ?? null;
            if (is_string($ntpServer) && $ntpServer !== '') {
                $isIp   = filter_var($ntpServer, FILTER_VALIDATE_IP) !== false;
                $isFqdn = preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $ntpServer) === 1;
                if (!$isIp && !$isFqdn) {
                    $errors[] = "Serveur NTP invalide : '$ntpServer' (attendu : FQDN ou IPv4/IPv6)";
                }
            }
            // Format locale POSIX : lang[_TERRITORY][.encoding][@modifier]
            $locale = $adv['localization']['locale'] ?? '';
            if ($locale !== '' && !preg_match('/^[a-zA-Z]+(_[a-zA-Z]+)?(\.[a-zA-Z0-9-]+)?(@[a-zA-Z]+)?$/', $locale)) {
                $errors[] = "Locale invalide : '$locale' (format attendu : fr_FR.UTF-8)";
            }
        }
        if (!empty($data['ansible_role']) && !preg_match('/^[A-Za-z0-9_-]+$/D', $data['ansible_role'])) {
            $errors[] = 'Rôle Ansible invalide : nom de playbook sans chemin ni extension requis';
        }

        return $errors;
    }

    /** Noms d'OS autorisés (modèle distro-name) : catalog_entries DISTINCT distro.
     *  Fallback ['debian'] si catalogue vide (avant le 1er refresh). */
    private static function allowedDistros(): array
    {
        $names = \Admin\Models\CatalogEntry::distinctDistros();
        return !empty($names) ? $names : ['debian'];
    }
}
