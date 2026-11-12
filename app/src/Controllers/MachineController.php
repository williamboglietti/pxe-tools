<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Auth;
use Admin\Db;
use Admin\Models\Action;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;
use Admin\Models\OsImage;
use Admin\Models\Preset;
use Admin\Models\Setting;
use Admin\Models\Installation;
use Admin\Models\Wipe;
use Admin\Models\DiskTest;
use Admin\Models\DiskReliabilityHistory;
use Admin\Util\AnsibleRedeployEnqueuer;
use Admin\Util\HardwareContext;
use Admin\Util\InstallConfigBuilder;
use Admin\Util\MachineOperation;
use Admin\Util\InstallPreflight;
use Admin\View;

final class MachineController
{
    public function index(array $params): void
    {
        $statusFilter = $_GET['status'] ?? 'all';
        $machines = Machine::all($statusFilter === 'all' ? null : $statusFilter);
        $activeTests = DiskTest::activeProgressByMachine();
        View::render('machines/index', [
            'machines'     => $machines,
            'statusFilter' => $statusFilter,
            'statuses'     => Machine::STATUSES,
            'presets'      => Preset::all(),
            'activeTests'  => $activeTests,
            'sig'          => Machine::listSignature($machines, $statusFilter, $activeTests),
        ], 'Machines');
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }
        $presets = Preset::all();
        $presetsGrouped = Preset::allGroupedForMachine($id);
        $latestInstall = Installation::latestForMachine($id);
        $latestWipe    = Wipe::latestForMachine($id);
        $latestTest    = DiskTest::latestForMachine($id);
        $latestTestPerDisk = DiskTest::latestResultPerDisk($id);
        $inventory = $machine['inventory_json'] ? json_decode($machine['inventory_json'], true) : null;
        $diskReliability = is_array($inventory) ? DiskReliabilityHistory::forInventory($inventory) : [];

        // Données wizard (consommées par le partial _install/wizard-form.php intégré dans
        // l'onglet "Installer"). On charge la config existante OU les défauts pour
        // pré-remplir le wizard.
        $existing = Machine::installConfig($machine);
        $wizardConfig = $existing ?? self::defaultInstallConfig($machine);

        View::render('machines/show', [
            'machine'           => $machine,
            'presets'           => $presets,
            'latestInstall'     => $latestInstall,
            'latestWipe'        => $latestWipe,
            'latestTest'        => $latestTest,
            'latestTestPerDisk' => $latestTestPerDisk,
            'inventory'         => $inventory,
            'diskReliability'   => $diskReliability,
            'wizardConfig'      => $wizardConfig,
            'wizardAllowedDistros' => self::allowedDistros(),
            'wizardHasExistingConfig' => $existing !== null,
            'wizardMachineHw'   => self::extractHardwareHints($machine),
            'wizardAllPresets'  => $presets,
            'wizardPresetsGrouped' => $presetsGrouped,
        ], 'Machine ' . ($machine['hostname'] ?: $machine['mac']));
    }

    public function operationFragment(array $params): void
    {
        $machine = Machine::find((int)$params['id']);
        if (!$machine) { http_response_code(404); echo 'Machine introuvable'; return; }
        $summary = \Admin\Util\MachineOperationSummary::forMachine($machine);
        if (hash_equals($summary['signature'], (string)($_GET['sig'] ?? ''))) {
            http_response_code(204);
            return;
        }
        http_response_code(200);
        View::partial('machines/_operation_header', ['machine'=>$machine, 'summary'=>$summary]);
    }

    public function newForm(array $params): void
    {
        View::render('machines/new', [], 'Nouvelle machine');
    }

    public function create(array $params): void
    {
        $mac = Machine::normalizeMac($_POST['mac'] ?? '');
        if ($mac === '') {
            View::flash('error', 'MAC invalide');
            View::redirect('/machines/new');
        }
        try {
            $id = Machine::create([
                'mac'      => $mac,
                'hostname' => $_POST['hostname'] ?? null,
                'status'   => 'discovered',
            ]);
        } catch (\Exception $e) {
            View::flash('error', 'Erreur : ' . $e->getMessage());
            View::redirect('/machines/new');
            return;
        }
        View::flash('success', 'Machine créée');
        View::redirect('/machines/' . $id);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); return; }

        if (isset($_POST['status']) && !in_array($_POST['status'],Machine::STATUSES,true)) {
            View::flash('error','Statut invalide.'); View::redirect('/machines/'.$id.'#settings'); return;
        }

        // Update partial : seuls les champs présents dans le POST sont écrits.
        // Permet de scinder le formulaire en plusieurs sections (ex: Notes
        // éditables depuis l'onglet Aperçu, attributs depuis l'onglet
        // Paramètres) sans qu'une section écrase les autres.
        // Note 2026-05-21 : hostname et domain ne sont plus éditables depuis la
        // fiche machine (ils sont configurés via le preset ou le wizard d'install).
        // On les ignore défensivement si un POST forgé les contient.
        $data = [];
        $touched = [];

        if (array_key_exists('boot_mode', $_POST)) {
            $bootMode = $_POST['boot_mode'];
            if (!in_array($bootMode, ['http', 'tftp'], true)) $bootMode = 'http';
            $data['boot_mode'] = $bootMode;
            $touched[] = 'boot_mode';
        }
        if (array_key_exists('local_boot_source', $_POST)) {
            $requested = (string)$_POST['local_boot_source'];
            $current = (string)($machine['local_boot_source'] ?? 'none');
            // L'admin peut activer/désactiver manuellement. La provenance PXE
            // ne peut être créée que par /api/install-end après succès réel.
            if (in_array($requested, ['none', 'manual'], true)
                || ($requested === 'pxe' && $current === 'pxe')) {
                if ($requested !== $current) {
                    Machine::setLocalBootSource($id, $requested);
                    $touched[] = 'local_boot_source';
                }
            } else {
                View::flash('error', 'Choix de démarrage local invalide');
                View::redirect('/machines/' . $id . '#settings');
            }
        }
        if (array_key_exists('status', $_POST) && $_POST['status'] !== $machine['status']) {
            MachineOperation::correctStatus($id, (string)$_POST['status']);
            $touched[] = 'status';
        }
        if (array_key_exists('notes', $_POST)) {
            $data['notes'] = $_POST['notes'] !== '' ? $_POST['notes'] : null;
            $touched[] = 'notes';
        }

        if (!empty($data)) {
            Machine::update($id, $data);
        }

        $flashMsg = match (true) {
            $touched === ['notes']                                 => 'Notes enregistrées',
            count($touched) === 1 && $touched[0] === 'boot_mode'   => 'Mode de boot mis à jour',
            default                                                 => 'Machine mise à jour',
        };
        View::flash('success', $flashMsg);
        View::redirect('/machines/' . $id);
    }

    public function wipe(array $params): void
    {
        $_POST['status']='discovered';
        $this->setStatusManual($params);
    }


    /**
     * POST /machines/{id}/power — programme une action poweroff/reboot/cancel
     * que la machine consommera au prochain poll de discover.sh (≤5s).
     * Sert à éteindre/redémarrer une machine en attente après discovery sans
     * dépendre du WoL (souvent peu fiable).
     */
    public function power(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        $action = trim((string)($_POST['action'] ?? ''));
        if (!in_array($action, ['poweroff', 'reboot', 'cancel'], true)) {
            View::flash('error', 'Action invalide');
            View::redirect('/machines/' . $id);
            return;
        }
        try {
            MachineOperation::run($id,$action==='cancel'?null:'power',static function () use ($id,$action): void {
                Machine::setNextAction($id,$action==='cancel'?null:$action);
            });
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); View::redirect('/machines/'.$id); return; }
        $eventMsg = match ($action) {
            'cancel'   => 'Admin a annulé l\'action programmée',
            'poweroff' => 'Admin a programmé : extinction',
            'reboot'   => 'Admin a programmé : redémarrage',
        };
        MachineEvent::log($id, MachineEvent::LEVEL_ACTION, $eventMsg);
        $label = match ($action) {
            'cancel'   => 'Action programmée annulée',
            'poweroff' => 'Extinction programmée — sera appliquée au prochain poll de la machine (≤5s)',
            'reboot'   => 'Redémarrage programmé — sera appliqué au prochain poll de la machine (≤5s)',
        };
        View::flash('success', $label);
        View::redirect('/machines/' . $id);
    }

    /**
     * POST /machines/{id}/status — change manuellement le statut (fonction avancée debug).
     * Utile pour déverrouiller une machine bloquée (ex: stuck en `installing` après crash).
     * Statut validé contre Machine::STATUSES.
     *
     * REINST03-CASCADE : cascade les actions non-terminales en 'cancelled' avant
     * de changer le status machine. Sans cette cascade, les sous-jobs (wipes,
     * installations, disk_tests) restaient en 'running' orphelins indéfiniment
     * après un force-status admin.
     * La transaction englobe status machine + cascade actions pour garantir un
     * état cohérent même en cas d'erreur mi-chemin.
     */
    public function setStatusManual(array $params): void
    {
        $id=(int)$params['id'];
        try {
            $count=MachineOperation::correctStatus($id,trim((string)($_POST['status']??'')));
            View::flash('success', "Suivi corrigé ; $count action(s) clôturée(s). Aucun arrêt de processus distant n’est garanti.");
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); }
        View::redirect('/machines/'.$id.'#settings');
    }


    /**
     * Envoie un magic packet Wake-on-LAN à la machine.
     * Utilisé typiquement après assignation d'un preset (status=configured) :
     * la machine se réveille, boot PXE, voit le preset configuré, install auto.
     */
    public function wake(array $params): void
    {
        $this->reboot($params);
    }


    /**
     * Active le mode rescue : au prochain boot PXE, grub.php sert un menuentry
     * Debian d-i en récupération (rescue/enable=true) au lieu du flow normal.
     * WoL envoyé immédiatement pour réveiller la machine.
     */
    public function rescueStart(array $params): void
    {
        $id=(int)$params['id'];
        try {
            MachineOperation::run($id,'rescue',function () use ($id): void {
                Machine::setRescueMode($id,true); Machine::setNextAction($id,'reboot');
                $this->enqueueMachinePower($id,'reboot','Redémarrage en récupération machine '.$id);
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Mode récupération demandé par admin.');
            });
            View::flash('success','Récupération programmée au redémarrage.');
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); }
        View::redirect('/machines/'.$id);
    }


    /**
     * Re-collecte l'inventaire d'une machine déjà découverte. Effets :
     *   - status → 'inventorying' (grub.php re-sert le menuentry discovery)
     *   - next_action → 'reboot' (consommé au prochain poll discover.sh ≤5s,
     *     pour les machines actuellement en boucle de poll)
     *   - WoL envoyé pour réveiller les machines éteintes
     *
     * Utile après ajout de nouveaux champs d'inventaire (SMART, USB, etc.)
     * pour rafraîchir une fiche existante sans changer le reste de la config.
     */
    public function rediscover(array $params): void
    {
        $id=(int)$params['id'];
        try {
            MachineOperation::run($id,'discovery',function () use ($id): void {
                Machine::setStatus($id,'inventorying'); Machine::setNextAction($id,'reboot');
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Nouvel inventaire demandé par admin.');
                $this->enqueueMachinePower($id,'reboot','Redémarrage pour inventaire machine '.$id);
            });
            View::flash('success','Nouvel inventaire programmé. La machine va redémarrer.');
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); }
        View::redirect('/machines/'.$id);
    }


    /**
     * Désactive le mode rescue. Le prochain boot PXE servira le menuentry normal
     * selon le status de la machine.
     */
    public function rescueStop(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        Machine::setRescueMode($id, false);
        MachineEvent::log($id, MachineEvent::LEVEL_ACTION, 'Admin a désactivé le mode rescue');
        View::flash('info', 'Mode rescue désactivé. Le prochain boot servira le menu normal.');
        View::redirect('/machines/' . $id);
    }

    /**
     * GET /machines/{id}/events — fragment HTMX (auto-refresh 2s) pour le
     * terminal de l'onglet Événements. Affiche les N derniers événements de
     * la machine (info, action, warning, error) en ordre chronologique.
     */
    public function eventsFragment(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }
        $events = \Admin\Models\MachineEvent::recent($id, 100);
        View::partial('machines/_events_log', [
            'machine' => $machine,
            'events'  => $events,
        ]);
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        Machine::delete($id);
        View::flash('success', 'Machine supprimée');
        View::redirect('/');
    }

    /**
     * GET /machines/{id}/install — affiche le wizard 3 étapes.
     * Si la machine a déjà un install_config_json, le wizard est pré-rempli avec.
     */
    public function installWizard(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        $existing = Machine::installConfig($machine);
        $hasExistingConfig = $existing !== null;
        $config = $existing ?? self::defaultInstallConfig($machine);

        View::render('machines/install', [
            'machine'           => $machine,
            'config'            => $config,
            'allPresets'        => Preset::all(),
            'presetsGrouped'    => Preset::allGroupedForMachine((int)$machine['id']),
            'allowed_distros'   => self::allowedDistros(),
            'hasExistingConfig' => $hasExistingConfig,
            'machineHw'         => self::extractHardwareHints($machine),
        ], $hasExistingConfig ? 'Réinstaller ' . Machine::displayLabel($machine) : 'Installer ' . Machine::displayLabel($machine));
    }

    /**
     * GET /machines/{id}/hardware-context.json
     *
     * Expose le HardwareContext d'une machine (disques+NICs+RAM+UEFI+CPU) en
     * JSON. Consommé par le form preset (Phase 2 chantier hardware-aware) :
     * quand l'utilisateur change la machine de référence dans le <select>,
     * le JS fetch ce endpoint pour rafraîchir le partition-builder sans reload.
     */
    public function hardwareContextJson(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        header('Content-Type: application/json; charset=utf-8');
        if (!$machine) {
            http_response_code(404);
            echo json_encode(['error' => 'machine not found']);
            return;
        }
        $hw = HardwareContext::fromMachine($machine);
        // diskCapacitiesMb : utile au JS du partition-builder pour valider
        // overflow live (cohérent avec ce que collectErrors() utilisera server-side).
        $hw['disk_capacities_mb'] = Machine::diskCapacitiesMbFromInventory($machine);
        echo json_encode($hw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extrait du JSON inventory les indices utiles à l'auto-layout / jauge.
     * Format : ['is_uefi' => bool, 'ram_kb' => int,
     *          'disks' => [['name','size_bytes','is_ssd','vendor','model','transport'],...]]
     */
    private static function extractHardwareHints(array $machine): array
    {
        $inv = $machine['inventory_json'] ? (json_decode($machine['inventory_json'], true) ?: []) : [];
        $hw = $inv['inventory'] ?? [];
        $disks = [];
        foreach (($inv['disks'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $disks[] = [
                'name'       => (string)($d['name'] ?? ''),
                'size_bytes' => (int)($d['size_bytes'] ?? 0),
                'is_ssd'     => isset($d['rota']) ? ($d['rota'] === false) : null,
                'vendor'     => (string)($d['vendor'] ?? ''),
                'model'      => (string)($d['model'] ?? ''),
                'transport'  => (string)($d['transport'] ?? ''),
            ];
        }
        return [
            'is_uefi' => (($hw['boot_mode'] ?? null) === 'UEFI'),
            'ram_kb'  => (int)($hw['memory_kb'] ?? 0),
            'disks'   => $disks,
        ];
    }

    /**
     * POST /machines/{id}/install — valide et persiste la config + status='configured'.
     * Sauvegarde aussi hostname/domain (attributs machine, pas install).
     * WoL optionnel via checkbox.
     */
    public function installSubmit(array $params): void
    {
        $id=(int)$params['id'];
        $machine=Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine introuvable'; return; }
        // Building a preview never writes keys or config; validation precedes encryption.
        $config=$this->postedInstallConfig(true, $machine);
        $preWipe=$_POST['pre_wipe_mode']??'aucun';
        $review=InstallPreflight::target($machine,$config,$preWipe,self::allowedDistros(),true);
        $errors=$review['reasons'];
        if (!$errors) {
            try {
                $actionId=$this->scheduleInstall($id,$config,$preWipe,!empty($_POST['wake_now']),null,true);
                View::flash('success', !empty($_POST['wake_now'])
                    ? 'Installation programmée au prochain démarrage PXE. Aucun redémarrage automatique demandé.'
                    : 'Installation programmée. Suivez le démarrage et les étapes dans cette activité.');
                View::redirect('/tasks/'.$actionId);
            } catch (\Throwable $e) { $errors=[$e->getMessage()]; }
        }
        // Keep all submitted decisions after errors, including identity and deferred boot.
        $config['pre_wipe_mode']=$preWipe;
        $config['wake_now']=!empty($_POST['wake_now']);
        View::render('machines/install', [
            'machine'=>$machine, 'config'=>$config, 'allPresets'=>Preset::all(),
            'presetsGrouped'=>Preset::allGroupedForMachine($id), 'allowed_distros'=>self::allowedDistros(),
            'hasExistingConfig'=>Machine::installConfig($machine)!==null,
            'machineHw'=>self::extractHardwareHints($machine), 'errors'=>$errors,
        ], 'Installer');
    }

    private function postedInstallConfig(bool $preview, ?array $machine = null): array
    {
        $config=InstallConfigBuilder::buildFromPost($preview);
        if ($machine !== null) $config=\Admin\Util\DiskIdentity::bindConfig($config, $machine);
        if ($machine !== null) $config=InstallConfigBuilder::bindNetworkConfig($config, $machine);
        $config['hostname']=trim((string)($_POST['hostname']??'')) ?: null;
        $config['domain']=trim((string)($_POST['domain']??'')) ?: null;
        $config['pre_wipe_mode']=$_POST['pre_wipe_mode']??'aucun';
        $config['wake_now']=!empty($_POST['wake_now']);
        return $config;
    }

    public function installPreview(array $params): void
    {
        $machine=Machine::find((int)$params['id']);
        if (!$machine) { View::json(['error'=>'Machine introuvable.'],404); return; }
        $config=$this->postedInstallConfig(true, $machine);
        $target=InstallPreflight::target($machine,$config,$_POST['pre_wipe_mode']??'aucun',self::allowedDistros(),true);
        $config['review_disks']=$target['disks'];
        $config['review_pre_wipe']=$target['pre_wipe'];
        $config['review_preserved_disks']=$target['preserved_disks'];
        $config['review_boot_mode']=$target['boot_mode'];
        $review=InstallConfigBuilder::review($config,$target['reasons']);
        if ($machine['status']==='testing') $review[]=['label'=>'Démarrage après le test','value'=>!empty($config['wake_now'])
            ? 'Après réussite du test, installation au prochain démarrage PXE manuel. Le test se terminera en attente ou par une extinction, sans redémarrage.'
            : 'Le test continue. Après réussite, un redémarrage vers l’installation sera demandé. En cas d’échec, l’installation sera annulée.'];
        View::json(['review'=>$review, 'errors'=>InstallConfigBuilder::localizeErrors($target['reasons'])]);
    }

    /** Lock, revalidate and persist every part of the operation together. */
    private function scheduleInstall(int $id,array $config,mixed $preWipe,bool $deferred,?string $fingerprint=null,bool $individual=false,?int $presetId=null): int
    {
        return MachineOperation::run($id,null,function (array $machine) use ($id,$config,$preWipe,$deferred,$fingerprint,$individual,$presetId): int {
            if ($presetId!==null) {
                if (Db::driver()==='mysql') Db::get()->prepare('SELECT id FROM presets WHERE id=? FOR UPDATE')->execute([$presetId]);
                $preset=Preset::find($presetId);
                if (!$preset) throw new \RuntimeException('Le modèle a été supprimé.',409);
                $config=Preset::toInstallConfig($preset);
                $preWipe=$preset['pre_wipe_mode']??'aucun';
            }
            $config=\Admin\Util\DiskIdentity::bindConfig($config, $machine);
            $config=InstallConfigBuilder::bindNetworkConfig($config, $machine);
            if (Db::driver()==='mysql') {
                $lock=Db::get()->prepare('SELECT id FROM os_images WHERE distro=? AND arch=? FOR UPDATE');
                $lock->execute([$config['distro']??'debian',$machine['boot_arch']?:'amd64']);
                $lock->fetchAll();
            }
            $target=InstallPreflight::target($machine,$config,$preWipe,self::allowedDistros(),$individual);
            if ($target['state']!=='ready') throw new \RuntimeException(implode(' ',$target['reasons']),409);
            if ($fingerprint!==null && !hash_equals($target['fingerprint'],$fingerprint)) throw new \RuntimeException('La machine ou le modèle a changé. Relancez le contrôle préalable.',409);
            $hasWipe=$target['pre_wipe']!==[];
            if (!empty($config['version_full']) && !OsImage::ensureForDistroVersion($config['distro'],$config['version_full'],$machine['boot_arch']?:'amd64')) {
                throw new \RuntimeException('Version système inconnue du catalogue. Vérifiez les images disponibles.',422);
            }
            // Reserve the parent transactionally before scheduling work.
            $action=Action::create($id,$hasWipe?Action::TYPE_REINSTALL:Action::TYPE_INSTALL_ONLY,Auth::user()['username']??null);
            if ($individual) {
                $config=$this->postedInstallConfig(false, $machine);
                $rootCipher=InstallConfigBuilder::encryptRootPasswordFromPost();
                if ($rootCipher!==null) Machine::update($id,['root_password_cipher'=>$rootCipher]);
            }
            $hostname=$individual ? $config['hostname'] : ($machine['hostname']??null);
            $domain=$individual ? $config['domain'] : ($machine['domain']??$config['domain']??null);
            Machine::update($id,['hostname'=>$hostname,'domain'=>$domain]);
            if ($hasWipe) {
                $chain=json_encode(['install_config'=>$config,'hostname'=>$hostname,'domain'=>$domain],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                Wipe::start($id,$target['pre_wipe'],'reboot',$chain,(int)$action['id']);
                Machine::setStatus($id,'wiping');
            } else {
                $config['_pending_action_id']=(int)$action['id'];
                Machine::saveInstallConfig($id,$config);
                if ($machine['status']!=='testing') Machine::setStatus($id,'configured');
            }
            // A deferred launch must also clear the discovery poll instruction.
            // Tests keep their supported success → install chain without interrupting badblocks.
            $testing=$machine['status']==='testing';
            Machine::setNextAction($id,$deferred?null:'reboot');
            if ($testing && $deferred) {
                Db::get()->prepare("UPDATE disk_tests SET post_action=CASE WHEN in_session=1 THEN 'wait' ELSE 'poweroff' END WHERE machine_id=? AND job_status IN ('pending','running')")->execute([$id]);
            }
            if (!$deferred && !$testing) Action::enqueueReboot((int)$action['id'],$id);
            MachineEvent::log($id,MachineEvent::LEVEL_ACTION,
                $testing ? ($deferred ? 'Installation après réussite du test, au prochain démarrage PXE manuel. Aucun redémarrage automatique.' : 'Installation enchaînée automatiquement après réussite du test disque.') : ($deferred ? 'Installation programmée au prochain démarrage PXE, sans redémarrage automatique.' : 'Installation et redémarrage demandés.'),null,(int)$action['id']);
            return (int)$action['id'];
        });
    }


    /** Config par défaut quand la machine n'a pas encore d'install_config_json. */
    private static function defaultInstallConfig(array $machine): array
    {
        return [
            'distro'                => 'debian',
            'codename'              => null,
            'network_mode'          => 'dhcp',
            'static_ip'             => null,
            'static_cidr'           => 24,
            'static_gateway'        => null,
            'static_dns'            => Preset::defaultDns(),
            'partition_mode'        => 'default',
            'partition_custom_json' => null,
            'admin_user_login'      => '',
            'admin_user_pwhash'     => null,
            'admin_user_password_cipher' => null,
            'admin_user_groups'     => 'sudo',
            'admin_user_pubkeys'    => null,
            'ansible_role'          => null,
            'ansible_extra_vars'    => null,
            'advanced'              => Preset::defaultAdvancedConfig(),
        ];
    }

    /** Noms d'OS autorisés (modèle distro-name) : catalog_entries DISTINCT distro.
     *  Fallback ['debian'] si catalogue vide (avant le 1er refresh). */
    private static function allowedDistros(): array
    {
        $names = \Admin\Models\CatalogEntry::distinctDistros();
        return !empty($names) ? $names : ['debian'];
    }

    /** Applies reviewed snapshots per target, with independent transaction and result. */
    public function bulkInstall(array $params): void
    {
        [$preset,$config,$ids]=$this->bulkInput();
        if (!$preset || !$ids) { View::flash('error','Sélectionnez un modèle et au moins une machine.'); View::redirect('/machines'); return; }
        $results=[]; $done=0;
        foreach ($ids as $id) {
            $fingerprint=(string)($_POST['fingerprints'][$id]??'');
            try {
                if ($fingerprint==='') throw new \RuntimeException('Contrôle préalable requis avant le lancement.');
                $this->rejectSharedStaticAddress($config,count($ids));
                $actionId=$this->scheduleInstall($id,$config,$preset['pre_wipe_mode']??'aucun',!empty($_POST['wake_now']),$fingerprint,false,(int)$preset['id']);
                $results[]=['id'=>$id,'ok'=>true,'action_id'=>$actionId]; $done++;
            } catch (\Throwable $e) { $results[]=['id'=>$id,'ok'=>false,'reason'=>$e->getMessage()]; }
        }
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) View::json(['results'=>$results,'started_count'=>$done]);
        $errors=array_map(static fn($r)=>'#'.$r['id'].' : '.($r['reason']??''),array_filter($results,static fn($r)=>!$r['ok']));
        View::flash($errors?'warning':'success',"$done installation(s) programmée(s). ".implode(' ',$errors));
        View::redirect('/machines');
    }

    public function bulkPreflight(array $params): void
    {
        [$preset,$config,$ids]=$this->bulkInput();
        if (!$preset || !$ids) { View::json(['error'=>'Sélectionnez un modèle et au moins une machine.'],422); return; }
        $targets=[];
        foreach ($ids as $id) {
            $machine=Machine::find($id);
            if (!$machine) { $targets[]=['id'=>$id,'label'=>'Machine #'.$id,'state'=>'blocked','reasons'=>['Machine introuvable.'],'network'=>[],'disks'=>[],'pre_wipe'=>[]]; continue; }
            $target=InstallPreflight::target($machine,$config,$preset['pre_wipe_mode']??'aucun',self::allowedDistros());
            try { $this->rejectSharedStaticAddress($config,count($ids)); } catch (\RuntimeException $e) { if ($target['state']!=='blocked') $target['state']='adapt'; $target['reasons'][]=$e->getMessage(); }
            $targets[]=$target;
        }
        View::json(['targets'=>$targets,'ready_count'=>count(array_filter($targets,static fn($t)=>$t['state']==='ready')),'total'=>count($ids)]);
    }

    private function bulkInput(): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['machine_ids']??[])),static fn($id)=>$id>0)));
        $preset=Preset::find((int)($_POST['preset_id']??0));
        $config=$preset?Preset::toInstallConfig($preset):[];
        return [$preset,$config,$ids];
    }

    private function rejectSharedStaticAddress(array $config,int $count): void
    {
        $static=($config['network_mode']??'dhcp')==='static';
        if (!empty($config['network']['interfaces'])) $static=(bool)array_filter($config['network']['interfaces'],static fn($nic)=>($nic['mode']??'dhcp')==='static');
        if ($count>1 && $static) throw new \RuntimeException('Ce modèle attribuerait la même IP statique à plusieurs machines. Adaptez le réseau individuellement.');
    }


    /**
     * POST /machines/{id}/reveal-password — déchiffre un mot de passe stocké
     * en clair (chiffré via master.key Crypto). Auth admin suffit, plus de
     * passphrase opérateur. POST body : type=root (défaut) ou type=admin_user.
     *
     * Fallback rétrocompat : si la machine a encore l'ancien format SecretBox
     * (passphrase) pour le root, on exige passphrase et on décrypte via
     * SecretBox. Pas de migration auto (lecture seule).
     *
     * Retourne JSON {password: "..."} ou {error: "..."}.
     */
    public function revealPassword(array $params): void
    {
        $id = (int)$params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); View::json(['error' => 'Machine introuvable']); return; }

        $type = (string)($_POST['type'] ?? 'root');
        if (!in_array($type, ['root', 'admin_user'], true)) {
            View::json(['error' => 'Type invalide']);
            return;
        }

        // Cipher (nouveau format Crypto master.key)
        $cipher = null;
        if ($type === 'root') {
            $cipher = $machine['root_password_cipher'] ?? null;
        } else {
            $config = json_decode((string)($machine['install_config_json'] ?? ''), true);
            $cipher = is_array($config) ? ($config['admin_user_password_cipher'] ?? null) : null;
        }

        if (!empty($cipher)) {
            try {
                $clear = \Admin\Util\Crypto::decrypt($cipher);
            } catch (\Throwable $e) {
                error_log('[revealPassword] decrypt failed: ' . $e->getMessage());
                View::json(['error' => 'Échec du déchiffrement (master.key invalide ?)']);
                return;
            }
            MachineEvent::log(
                $id,
                MachineEvent::LEVEL_INFO,
                'Mot de passe ' . ($type === 'root' ? 'root' : 'utilisateur admin') . ' révélé par ' . (Auth::user()['username'] ?? 'admin')
            );
            View::json(['password' => $clear]);
            return;
        }

        // Fallback rétrocompat : ancien format SecretBox (passphrase) sur root
        if ($type === 'root' && !empty($machine['root_password_encrypted'])) {
            $passphrase = (string)($_POST['passphrase'] ?? '');
            if ($passphrase === '') {
                View::json(['error' => 'Passphrase requise (ancien format)', 'legacy' => true]);
                return;
            }
            $clear = \Admin\Util\SecretBox::decrypt(
                $machine['root_password_encrypted'],
                $machine['root_password_kdf_salt'],
                $machine['root_password_nonce'],
                $passphrase
            );
            if ($clear === null) {
                View::json(['error' => 'Mauvaise passphrase', 'legacy' => true]);
                return;
            }
            MachineEvent::log($id, MachineEvent::LEVEL_INFO, 'Mot de passe root révélé (legacy passphrase) par ' . (Auth::user()['username'] ?? 'admin'));
            View::json(['password' => $clear]);
            return;
        }

        View::json(['error' => 'Aucun mot de passe stocké pour cette machine']);
    }

    /**
     * GET /machines/list-fragment — rend toute la section dynamique du dashboard
     * (compteur "Machines (N)", bulk-bar, empty-state OR table). Polling HTMX 5s
     * depuis #machines-section. Le polling sur le wrapper (vs uniquement <tbody>)
     * couvre aussi l'état vide : sans ça, la 1ère machine n'apparaît jamais en
     * live tant qu'on ne refresh pas manuellement. Respecte le filtre statut.
     */
    public function listFragment(array $params): void
    {
        $statusFilter = $_GET['status'] ?? 'all';
        $machines = Machine::all($statusFilter === 'all' ? null : $statusFilter);
        $activeTests = DiskTest::activeProgressByMachine();
        $sig = Machine::listSignature($machines, $statusFilter, $activeTests);
        // Anti-clignotement : si le client affiche déjà cette signature (rien n'a
        // changé depuis son dernier rendu), on renvoie 204 → htmx ne swappe pas →
        // le tableau (et les cases cochées) n'est pas recréé. Le polling continue
        // (l'élément + son hx-trigger restent en place). Cf. bulk-install.js qui
        // renvoie data-sig via htmx:configRequest.
        if (isset($_SERVER['HTTP_HX_REQUEST']) && ($_GET['sig'] ?? null) === $sig) {
            http_response_code(204);
            return;
        }
        View::partial('machines/_section', ['machines' => $machines, 'sig' => $sig, 'activeTests' => $activeTests]);
    }

    /**
     * GET /machines/{id}/power-fragment — rend uniquement le panel "Contrôle
     * alimentation à distance" (état awaiting + countdown + boutons). Polling
     * HTMX every 5s pour matérialiser l'action consommée par la machine sans
     * recharger toute la page.
     */
    public function powerFragment(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }
        View::partial('machines/_power_panel', ['machine' => $machine]);
    }

    /** GET /machines/{id}/boot-fragment — état du menu GRUB et historique. */
    public function bootFragment(array $params): void
    {
        $machine = Machine::find((int)$params['id']);
        if (!$machine) { http_response_code(404); return; }
        View::partial('machines/_boot_history', ['machine' => $machine]);
    }

    /**
     * GET /machines/{id}/status — rend uniquement le contenu du badge statut,
     * pour polling HTMX 2s sur la fiche machine quand l'état est "live"
     * (inventorying / installing / wiping / testing / etc.).
     */
    public function statusFragment(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); return; }
        View::partial('machines/_status_badge', [
            'state' => View::stateLabel($machine),
        ]);
    }

    /**
     * POST /machines/{id}/ansible/retry — re-enqueue un init_job ansible bootstrap
     * isolé (sans wipe/install) pour relancer uniquement la phase post-installation.
     *
     * Garde-fous :
     *   - Machine doit exister.
     *   - Pas de job ansible_retry déjà en pending/running pour cette action.
     *   - Pas d'autre init_job actif (wipe/install en running bloque le retry).
     * Redirige vers /tasks/{action_id} après enqueue.
     */
    public function ansibleRetry(array $params): void
    {
        $id=(int)$params['id'];
        try {
            $actionId=MachineOperation::run($id,'ansible',static function () use ($id): int {
                $pdo=Db::get();
                $stmt=$pdo->prepare("SELECT a.id AS action_id,i.id AS install_id FROM actions a JOIN installations i ON i.action_id=a.id WHERE a.machine_id=? AND i.ansible_status='failed' AND a.status!='cancelled' ORDER BY a.id DESC LIMIT 1");
                $stmt->execute([$id]); $target=$stmt->fetch();
                if (!$target) throw new \RuntimeException('Aucune phase Ansible en échec à réessayer.');
                $actionId=(int)$target['action_id'];
                $pdo->prepare("INSERT INTO init_jobs (job_type,status,message) VALUES (?,'pending',?)")->execute(['action:ansible_retry:'.$actionId,'Réessai Ansible machine '.$id]);
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Réessai Ansible demandé.',null,$actionId);
                return $actionId;
            });
            View::flash('success','Réessai Ansible programmé.'); View::redirect('/tasks/'.$actionId);
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); View::redirect('/machines/'.$id.'#events'); }
    }

    /**
     * La machine est-elle dans une fenêtre d'attente ACTIVE (écran d'attente
     * discovery/post-test) ? Elle poll alors /api/discovery-action toutes les
     * 5 s : next_action est LE canal fiable — pas de SSH dans l'environnement
     * d'installation, et le WoL n'agit pas sur une machine déjà allumée.
     * Sinon : canal worker (SSH machine installée, repli WoL).
     */
    private static function machineIsAwaiting(array $machine): bool
    {
        $raw = $machine['awaiting_action_until'] ?? null;
        return $raw && strtotime((string)$raw . ' UTC') > time();
    }

    /**
     * POST /machines/{id}/reboot — point UNIQUE de redémarrage (menu Actions) :
     * machine en attente → next_action consommé au poll (≤ 5 s) ; sinon SSH via
     * worker root avec fallback WoL (init_job `machine:reboot:{id}`).
     */
    public function reboot(array $params): void
    {
        $id=(int)$params['id'];
        try {
            MachineOperation::run($id,'power',function (array $machine) use ($id): void {
                if (self::machineIsAwaiting($machine)) Machine::setNextAction($id,'reboot');
                else $this->enqueueMachinePower($id,'reboot', 'Demande administrateur pour machine '.$id);
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Redémarrage demandé par admin.');
            });
            View::flash('success','Demande transmise. Consultez l’activité pour le résultat.');
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); }
        View::redirect('/machines/'.$id);
    }


    /**
     * POST /machines/{id}/shutdown — point UNIQUE d'arrêt (menu Actions) :
     * machine en attente → next_action consommé au poll (≤ 5 s) ; sinon SSH via
     * worker root (init_job `machine:shutdown:{id}`, pas de fallback WoL —
     * le WoL allume, n'éteint pas).
     */
    public function shutdown(array $params): void
    {
        $id=(int)$params['id'];
        try {
            MachineOperation::run($id,'power',function (array $machine) use ($id): void {
                if (self::machineIsAwaiting($machine)) Machine::setNextAction($id,'poweroff');
                else $this->enqueueMachinePower($id,'shutdown', 'Demande administrateur pour machine '.$id);
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Arrêt demandé par admin.');
            });
            View::flash('success','Demande transmise. Consultez l’activité pour le résultat.');
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); }
        View::redirect('/machines/'.$id);
    }


    /**
     * POST /machines/{id}/auto-deploy — active ou désactive le redéploiement
     * Ansible automatique pour cette machine (déclenché par webhook GitHub
     * sur push qui touche la config de la machine).
     */
    public function autoDeployToggle(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        $enabled = !empty($_POST['auto_deploy']) ? 1 : 0;
        Machine::update($id, ['auto_deploy' => $enabled]);
        MachineEvent::log($id, MachineEvent::LEVEL_ACTION,
            $enabled ? 'Auto-déploiement Ansible activé' : 'Auto-déploiement Ansible désactivé');
        View::flash('success',
            $enabled
                ? 'Auto-déploiement Ansible activé pour cette machine.'
                : 'Auto-déploiement Ansible désactivé pour cette machine.');
        View::redirect('/machines/' . $id);
    }

    /**
     * POST /machines/{id}/ansible/redeploy — redéploie la config Ansible sur
     * cette machine SANS attendre un échec (à la différence d'ansibleRetry).
     * Use case : l'admin a mis à jour le repo Ansible sur GitHub et veut
     * appliquer la nouvelle config à chaud. Le worker fait `git pull` puis
     * `ansible-playbook playbooks/{role}.yml --limit {hostname}`.
     */
    public function ansibleRedeploy(array $params): void
    {
        $id = (int) $params['id'];
        $message = "Redéploiement manuel demandé par admin (machine $id)";
        $r = AnsibleRedeployEnqueuer::enqueue($id, $message);

        if ($r['ok']) {
            View::redirect('/jobs/' . (int)$r['job_id']);
            return;
        }

        // Si un job existe déjà → rediriger vers son log plutôt que d'afficher
        // une erreur muette : l'admin voit l'avancement du job en cours.
        if ($r['reason'] === 'already_pending' && isset($r['existing_job_id'])) {
            View::flash('info', 'Un redéploiement Ansible est déjà en cours — suivi affiché.');
            View::redirect('/jobs/' . (int)$r['existing_job_id']);
            return;
        }

        $msgByReason = [
            'machine_not_found'     => 'Machine introuvable.',
            'hostname_missing'      => 'Hostname manquant — la machine n\'a pas encore été inventoriée.',
            'ansible_role_missing'  => 'Aucun rôle Ansible configuré pour cette machine — installez-la d\'abord avec un preset incluant ansible_role.',
            'machine_busy'          => 'La machine est en cours d\'opération (installation, wipe ou test) — attendez la fin avant de redéployer.',
        ];
        View::flash('error', $msgByReason[$r['reason']] ?? 'Redéploiement Ansible refusé.');
        View::redirect('/machines/' . $id);
    }

    /**
     * POST /machines/{id}/ansible/pull — enqueue un init_job 'ansible_repo:pull'
     * qui exécute `git pull --ff-only` dans WORKDIR via le worker.py.
     * Use case : l'admin veut récupérer la dernière version du dépôt configuré
     * avant un redéploiement manuel, sans avoir à activer l'auto-deploy webhook.
     * Anti-doublon : refuse si un pull est déjà pending/running.
     */
    public function ansiblePull(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        $jobType = 'ansible_repo:pull';
        $pdo = Db::get();

        $existing = $pdo->prepare(
            "SELECT id FROM init_jobs
             WHERE job_type = ? AND status IN ('pending','running')
             LIMIT 1"
        );
        $existing->execute([$jobType]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            View::flash('info', 'Une mise à jour du dépôt Ansible est déjà en cours — suivi affiché.');
            View::redirect('/jobs/' . (int)$existingRow['id']);
            return;
        }

        $hostname = trim((string)($machine['hostname'] ?? '')) ?: 'machine #' . $id;
        $message = "Pull demandé depuis $hostname";
        $pdo->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
        )->execute([$jobType, $message]);
        $jobId = (int)$pdo->lastInsertId();

        MachineEvent::log($id, MachineEvent::LEVEL_ACTION, 'Mise à jour dépôt Ansible déclenchée');
        View::redirect('/jobs/' . $jobId);
    }

    /**
     * Enqueue un init_job `machine:reboot:{id}` ou `machine:shutdown:{id}`
     * consommé par worker.py (root, donc accès clé ansible_automation 0600).
     * Anti-double-enqueue : refuse si un job pending/running existe déjà
     * sur la même machine + même verbe.
     */
    private function enqueueMachinePower(int $machineId, string $verb, string $message): void
    {
        if (!in_array($verb, ['reboot', 'shutdown'], true)) {
            throw new \InvalidArgumentException('Commande d’alimentation invalide.');
        }
        $jobType = "machine:{$verb}:{$machineId}";
        $pdo = Db::get();
        $existing = $pdo->prepare(
            "SELECT id FROM init_jobs
             WHERE job_type = ? AND status IN ('pending', 'running')
             LIMIT 1"
        );
        $existing->execute([$jobType]);
        if ($existing->fetch()) {
            return;
        }
        $pdo->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
        )->execute([$jobType, $message]);
    }

    /**
     * POST /machines/bulk-delete — supprime les machines sélectionnées en une fois.
     *
     * Suppression toujours forcée : même les machines en cours d'opération
     * (wiping/installing/inventorying/testing) sont supprimées. Le JS UI
     * affiche un warning explicite avant de soumettre — ici on fait
     * confiance à la décision admin (cas d'usage : machine bloquée en
     * wiping zombie post-crash qu'il faut nettoyer manuellement).
     *
     * Conséquence : si une machine en wiping est supprimée puis re-poll
     * /api/discovery-action, le contrôleur (cf. discoveryAction) la recrée
     * automatiquement en status 'discovered' + window 1h pour décision
     * admin. La fiche recréée ne restaure pas les historiques supprimés.
     */
    public function bulkDelete(array $params): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['machine_ids'] ?? [])))));
        if (empty($ids)) {
            View::flash('error', 'Aucune machine sélectionnée');
            View::redirect('/machines');
            return;
        }

        $activeStatuses = ['wiping', 'installing', 'inventorying', 'testing'];
        $deleted     = 0;
        $notFound    = 0;
        $wasActive   = 0;
        foreach ($ids as $id) {
            $m = Machine::find($id);
            if (!$m) { $notFound++; continue; }
            if (in_array($m['status'] ?? '', $activeStatuses, true)) {
                $wasActive++;
            }
            Machine::delete($id);
            $deleted++;
        }

        $msg = "$deleted machine(s) supprimée(s)";
        if ($wasActive > 0) {
            $msg .= " · $wasActive en cours d'opération supprimée(s) sous warning";
        }
        if ($notFound > 0) {
            $msg .= " · $notFound introuvable(s) (déjà supprimée(s) ?)";
        }
        View::flash('success', $msg);
        View::redirect('/machines');
    }

    /**
     * Lance un wipe de disques pour cette machine.
     * - Whitelist : refuse tout disque non présent dans inventory_json.disks[].name
     * - Crée un wipe job pending, passe la machine en 'wiping', déclenche le reboot.
     */
    public function wipeDisks(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        // Format POST attendu (nouveau, mode-per-disk) :
        //   disks_modes['/dev/sda'] = 'partial' | 'full' | ''
        //   disks_modes['/dev/sdb'] = 'full' | 'partial' | ''
        //   post_action = 'reboot' | 'poweroff'
        // Disques avec mode='' = ignorés (non wipés).
        $disksModesIn = $_POST['disks_modes'] ?? [];
        $diskSerialsIn = is_array($_POST['disk_serials'] ?? null) ? $_POST['disk_serials'] : [];
        $postAction = $_POST['post_action'] ?? 'poweroff';
        if (!in_array($postAction, Wipe::POST_ACTIONS, true)) $postAction = 'poweroff';

        if (!is_array($disksModesIn) || empty($disksModesIn)) {
            View::flash('error', 'Aucun disque sélectionné');
            View::redirect('/machines/' . $id);
        }

        // Whitelist : disques connus de l'inventory
        $inv = $machine['inventory_json'] ? (json_decode($machine['inventory_json'], true) ?: []) : [];
        $knownDisks = array_filter(array_map(static fn($d) => $d['name'] ?? '', $inv['disks'] ?? []));

        $disksWithModes = [];
        foreach ($disksModesIn as $diskName => $mode) {
            $diskName = (string)$diskName;
            $mode = (string)$mode;
            if ($mode === '') continue;  // disque non sélectionné
            if (!in_array($diskName, $knownDisks, true)) continue;  // hors inventory
            if (!in_array($mode, Wipe::MODES, true)) continue;  // mode invalide
            $disksWithModes[] = ['name' => $diskName, 'mode' => $mode];
        }

        if (empty($disksWithModes)) {
            View::flash('error', 'Aucun disque valide à effacer (sélectionnez au moins un mode par disque).');
            View::redirect('/machines/' . $id);
        }

        try {
            $result=MachineOperation::run($id,'wipe',function (array $current) use ($id,$disksWithModes,$postAction,$diskSerialsIn): array {
                $inventory=json_decode($current['inventory_json']??'null',true)?:[];
                $known=array_column($inventory['disks']??[],'name');
                foreach ($disksWithModes as &$disk) {
                    if (!in_array($disk['name'],$known,true)) throw new \RuntimeException('L’inventaire a changé. Vérifiez à nouveau les disques.',409);
                    $identity=\Admin\Util\DiskIdentity::forPath($inventory,(string)$disk['name']);
                    if ($identity['identity']===null) throw new \RuntimeException($identity['error']??'Numéro de série requis pour effacer ce disque.',409);
                    $selectedSerial=\Admin\Util\DiskIdentity::normalize($diskSerialsIn[$disk['name']]??null);
                    if ($selectedSerial===null || $selectedSerial!==$identity['identity']['value']) throw new \RuntimeException('Le numéro de série a changé depuis l’affichage. Relancez un discovery et confirmez de nouveau le disque.',409);
                    $disk['identity']=$identity['identity'];
                }
                unset($disk);
                $action=Action::create($id,Action::TYPE_WIPE_ONLY,Auth::user()['username']??null);
                Wipe::start($id,$disksWithModes,$postAction,null,(int)$action['id']);
                Machine::setStatus($id,'wiping');
                if (self::machineIsAwaiting($current)) Machine::setNextAction($id,'reboot');
                else Action::enqueueReboot((int)$action['id'],$id);
                MachineEvent::log($id,MachineEvent::LEVEL_ACTION,'Effacement demandé : '.implode(', ',array_column($disksWithModes,'name')),null,(int)$action['id']);
                return $action;
            });
            $action=$result;
            $rebootMessage='Redémarrage programmé ; consultez la progression';
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); View::redirect('/machines/'.$id.'#storage'); return; }
        $summary = count($disksWithModes) . ' disque(s) : '
            . implode(', ', array_map(static fn($d) => $d['name'] . ' (' . $d['mode'] . ')', $disksWithModes));
        View::flash('success', "Effacement lancé sur $summary · $rebootMessage");
        View::redirect('/tasks/' . (int)$action['id']);
    }

    /**
     * Lance un job de tests de disque (SMART + badblocks).
     * Format POST attendu :
     *   disks[] = ['/dev/sda', '/dev/sdb', ...]   (cochés)
     *   modes[disk] = 'smart_short' | 'smart_long' | 'badblocks_*'
     *   disk_serials[disk] = numéro de série affiché (obligatoire : refus sinon)
     *   parallel = '1' (optionnel, défaut séquentiel)
     *   post_action = 'reboot' | 'poweroff' (défaut intelligent)
     *
     * Garde-fous :
     *   - refus si machine en wipe, en test, ou en mode rescue
     *   - whitelist disques contre inventory
     *   - tous les modes RW (destructifs) doivent être confirmés (côté UI : prompt 'DETRUIRE')
     */
    public function diskTestStart(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        // Conflits d'état
        if ($machine['status'] === 'wiping') {
            View::flash('error', 'Impossible : machine en cours d\'effacement');
            View::redirect('/machines/' . $id);
            return;
        }
        if ($machine['status'] === 'testing') {
            View::flash('error', 'Impossible : un test de disque est déjà en cours');
            View::redirect('/machines/' . $id);
            return;
        }
        if ((int)($machine['rescue_mode'] ?? 0) === 1) {
            View::flash('error', 'Opération impossible : mode rescue actif. Veuillez désactiver le mode rescue avant de lancer un test.');
            View::redirect('/machines/' . $id);
            return;
        }

        $disksRequested = $_POST['disks'] ?? [];
        $modesByDisk    = $_POST['modes'] ?? [];
        $parallel       = !empty($_POST['parallel']);
        $postAction     = $_POST['post_action'] ?? null;
        $diskSerialsIn  = is_array($_POST['disk_serials'] ?? null) ? $_POST['disk_serials'] : [];

        if (!is_array($disksRequested) || empty($disksRequested)) {
            View::flash('error', 'Aucun disque sélectionné');
            View::redirect('/machines/' . $id);
            return;
        }
        if (!is_array($modesByDisk)) {
            View::flash('error', 'Modes de test invalides');
            View::redirect('/machines/' . $id);
            return;
        }

        // Whitelist disques
        $inv = $machine['inventory_json'] ? (json_decode($machine['inventory_json'], true) ?: []) : [];
        $knownDisks = array_filter(array_map(static fn($d) => $d['name'] ?? '', $inv['disks'] ?? []));

        $jobs = [];
        $hasDestructive = false;
        $allReadOnly = true;
        foreach ($disksRequested as $d) {
            if (!in_array($d, $knownDisks, true)) continue;
            $mode = $modesByDisk[$d] ?? null;
            if (!is_string($mode) || !in_array($mode, DiskTest::MODES, true)) {
                View::flash('error', "Mode invalide pour le disque $d");
                View::redirect('/machines/' . $id);
                return;
            }
            $jobs[] = ['disk' => $d, 'mode' => $mode];
            if (in_array($mode, DiskTest::MODE_DESTRUCTIVE, true)) {
                $hasDestructive = true;
                $allReadOnly = false;
            }
        }
        if (empty($jobs)) {
            View::flash('error', 'Aucun disque valide (doit faire partie de l\'inventory de la machine)');
            View::redirect('/machines/' . $id);
            return;
        }

        // Chemin hybride : machine encore sur l'écran d'attente discovery → le
        // test s'enchaîne SANS reboot (le poll d'attente embarque l'action en
        // ≤ 5 s). Marge 60 s : si la fenêtre expire pendant le round-trip, la
        // machine est peut-être déjà en train de s'éteindre → cycle reboot sûr.
        $awaitRaw  = $machine['awaiting_action_until'] ?? null;
        $awaitTs   = $awaitRaw ? strtotime((string)$awaitRaw . ' UTC') : false;
        $inSession = $awaitTs !== false && $awaitTs > time() + 60;

        // Défaut post_action intelligent : in-session → retour à l'écran d'attente ;
        // sinon reboot si tous RO + OS installé, sinon poweroff.
        if ($postAction === null || $postAction === '' || !in_array($postAction, DiskTest::POST_ACTIONS, true)) {
            if ($inSession) {
                $postAction = 'wait';
            } else {
                $hasOs = in_array($machine['status'], ['installed', 'provisioned'], true);
                $postAction = ($allReadOnly && $hasOs) ? 'reboot' : 'poweroff';
            }
        }

        try {
            $action=MachineOperation::run($id,'disktest',function (array $current) use ($id,$jobs,$parallel,$postAction,$inSession,$diskSerialsIn): array {
                $inventory=json_decode($current['inventory_json']??'null',true)?:[];
                $known=array_column($inventory['disks']??[],'name');
                foreach ($jobs as $job) if (!in_array($job['disk'],$known,true)) throw new \RuntimeException('L’inventaire a changé. Vérifiez à nouveau les disques.',409);
                // Chaque disque est lié à son numéro de série ; le script du test le revérifie sur la machine.
                $bound=DiskTest::bindJobs($inventory,$jobs,$diskSerialsIn);
                $action=Action::create($id,Action::TYPE_DISKTEST,Auth::user()['username']??null);
                $testId=DiskTest::start($id,$bound,$parallel,$postAction,(int)$action['id']);
                if ($inSession) Db::get()->prepare('UPDATE disk_tests SET in_session=1 WHERE id=?')->execute([$testId]);
                Machine::setStatus($id,'testing');
                if (!$inSession) Action::enqueueReboot((int)$action['id'],$id);
                return $action;
            });
        } catch (\RuntimeException $e) { View::flash('error',$e->getMessage()); View::redirect('/machines/'.$id.'#storage'); return; }

        $count = count($jobs);
        $msg = "Tests de disque lancés sur $count disque(s)" . ($parallel ? ' (parallèle)' : ' (séquentiel)');
        if ($hasDestructive) $msg .= ' — modes destructifs inclus';
        $msg .= $inSession
            ? ' · La machine en attente démarre dans quelques secondes (sans redémarrage)'
            : ' · Redémarrage programmé via le worker';
        View::flash('success', $msg);
        View::redirect('/tasks/' . (int)$action['id']);
    }

    /**
     * POST /machines/{id}/disktest-cancel — annule le test de disque actif.
     * Serveur : test → aborted, action → cancelled, machine → discovered.
     * Agent : il découvre l'annulation à son prochain POST de progression
     * (réponse «ignored») → stoppe badblocks/smartctl et, en in-session,
     * revient à l'écran d'attente (fenêtre réarmée ici).
     */
    public function diskTestCancel(array $params): void
    {
        $id = (int)$params['id'];
        $test = MachineOperation::run($id, null, static function (array $machine) use ($id): ?array {
            $test = DiskTest::activeByMac((string)$machine['mac']);
            if ($test === null) return null;
            Db::get()->prepare("UPDATE disk_tests SET job_status='aborted',ended_at=CURRENT_TIMESTAMP WHERE id=? AND job_status IN ('pending','running')")->execute([(int)$test['id']]);
            if (!empty($test['action_id'])) Action::forceComplete((int)$test['action_id']);
            $config = Machine::installConfig($machine) ?? [];
            $pendingId = (int)($config['_pending_action_id'] ?? 0);
            $pending = $pendingId > 0 ? Action::findById($pendingId) : null;
            if ($pending && (int)$pending['machine_id'] === $id && $pending['type'] === Action::TYPE_INSTALL_ONLY && $pending['status'] === 'pending') {
                Action::forceComplete($pendingId);
            }
            Machine::setStatus($id, 'discovered');
            Machine::setNextAction($id, null);
            Machine::setAwaitingUntil($id, (int)($test['in_session'] ?? 0) === 1 ? time() + 3600 : null);
            MachineEvent::log($id, MachineEvent::LEVEL_ACTION,
                'Suivi du test et installation dépendante annulés ; arrêt demandé à l’agent au prochain contact.',
                null, !empty($test['action_id']) ? (int)$test['action_id'] : null);
            return $test;
        });
        View::flash($test ? 'success' : 'info', $test
            ? 'Suivi du test annulé et installation dépendante déprogrammée. L’agent recevra la demande d’arrêt à son prochain contact.'
            : 'Aucun test de disque en cours sur cette machine.');
        View::redirect('/machines/' . $id);
    }

    /**
     * POST /machines/{id}/disktest-post-action — modifie l'action de fin du test
     * EN COURS. L'agent applique la valeur fraîche renvoyée par disktest-end au
     * moment où il termine, pas celle du fetch initial.
     */
    public function diskTestPostAction(array $params): void
    {
        $id = (int) $params['id'];
        $machine = Machine::find($id);
        if (!$machine) { http_response_code(404); echo 'Machine not found'; return; }

        $pa = (string)($_POST['post_action'] ?? '');
        if (!in_array($pa, DiskTest::POST_ACTIONS, true)) {
            View::flash('error', 'Action de fin invalide.');
            View::redirect('/machines/' . $id);
            return;
        }
        $t = DiskTest::activeByMac((string)$machine['mac']);
        if ($t === null) {
            View::flash('info', 'Aucun test de disque en cours sur cette machine.');
            View::redirect('/machines/' . $id);
            return;
        }
        if ($pa === 'wait' && (int)($t['in_session'] ?? 0) !== 1) {
            View::flash('error', '« Retour à l\'écran d\'attente » n\'est possible que pour un test enchaîné depuis l\'attente (sans redémarrage).');
            View::redirect('/tasks/' . (int)($t['action_id'] ?? 0));
            return;
        }
        Db::get()->prepare(
            "UPDATE disk_tests SET post_action=? WHERE id=? AND job_status IN ('pending','running')"
        )->execute([$pa, (int)$t['id']]);
        $paLabel = match ($pa) {
            'wait'     => 'retour à l\'écran d\'attente',
            'reboot'   => 'redémarrer',
            'poweroff' => 'éteindre',
        };
        MachineEvent::log($id, MachineEvent::LEVEL_INFO,
            "Action de fin du test modifiée : $paLabel", null,
            !empty($t['action_id']) ? (int)$t['action_id'] : null);
        View::flash('success', 'Action de fin mise à jour : ' . $paLabel . '.');
        View::redirect('/tasks/' . (int)($t['action_id'] ?? 0));
    }
}
