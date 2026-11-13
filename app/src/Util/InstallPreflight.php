<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Models\Machine;
use Admin\Models\Preset;
use Admin\Models\Setting;
use Admin\Models\OsImage;
use Admin\Models\CatalogEntry;
use Admin\Db;

/** Read-only target review shared by individual and bulk installations. */
final class InstallPreflight
{
    public static function wipeDisks(mixed $raw, array $machine): ?array
    {
        $inv=json_decode($machine['inventory_json']??'null',true)?:[];
        $known=array_values(array_filter(array_column($inv['disks']??[],'name')));
        $dict=Preset::parseWipeMode($raw,$known);
        if (!$known) return array_filter($dict,static fn($mode)=>$mode!=='aucun') ? null : [];
        $out=[];
        foreach ($known as $name) {
            $path=str_starts_with($name,'/dev/')?$name:'/dev/'.ltrim($name,'/');
            $mode=$dict[$name]??$dict[$path]??$dict['*']??'aucun';
            if ($mode!=='aucun') {
                $identity=DiskIdentity::forPath($inv,$path);
                if ($identity['identity']===null) return null;
                $out[]=['name'=>$name,'mode'=>$mode,'identity'=>$identity['identity']];
            }
        }
        return $out;
    }

    public static function target(array $machine, array $config, mixed $preWipe, array $distros, bool $allowDeferred = false): array
    {
        // A generic preset carries only a partition recipe. Bind physical IDs to
        // the current target machine's fresh inventory before reviewing it.
        $config = DiskIdentity::bindConfig($config, $machine);
        $config = InstallConfigBuilder::bindNetworkConfig($config, $machine);
        $hw=HardwareContext::fromMachine($machine);
        $wipe=self::wipeDisks($preWipe,$machine);
        $operation=$allowDeferred && $wipe===[] ? 'install_deferred' : 'install';
        $rule=MachineOperation::eligibility($machine,$operation);
        $capacities = Machine::diskCapacitiesMbFromInventory($machine);
        $errors=InstallConfigBuilder::validate($config,$distros,$capacities,true,$hw['nics']);
        if (PxeHttpReadiness::unavailable()) {
            $errors[] = 'Le service HTTP PXE est indisponible sur le port 80 : résolvez le conflit avant l’installation.';
        }
        $serverIp = Setting::get(Setting::KEY_PXE_IP, '') ?? '';
        $accessSubnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || NetworkDetector::parseCidr($accessSubnet) === null) {
            $errors[] = 'Configurez l’adresse du serveur et le sous-réseau autorisé dans la configuration réseau avant l’installation.';
        }
        $ansibleRole = trim((string)($config['ansible_role'] ?? ''));
        if ($ansibleRole !== '') {
            if (Setting::get(Setting::KEY_ANSIBLE_ENABLED, 'off') !== 'on') {
                $errors[] = 'Un rôle Ansible est demandé mais le module est désactivé : retirez le rôle ou activez le module.';
            } else {
                if (in_array(AnsibleRuntimeStatus::current(), ['not_configured', 'unavailable'], true)) {
                    $errors[] = 'Le runtime Ansible demandé est indisponible : préparez le module avant l’installation.';
                }
                if (!RepositoryProfile::allowedRole($ansibleRole)) {
                    $errors[] = 'Playbook absent du profil de provisionnement PXE ou profil indisponible.';
                }
            }
        }
        $inventory = json_decode((string)($machine['inventory_json'] ?? ''), true) ?: [];
        $observedBootMode = $inventory['inventory']['boot_mode'] ?? null;
        $errors = array_merge($errors, InstallConfigBuilder::validateFirmwarePlan($config, is_string($observedBootMode) ? $observedBootMode : null));
        if (!$hw['disks']) $errors[]='Inventaire disque absent : effectuez une découverte avant de confirmer la portée de l’installation.';
        $errors = array_merge($errors, DiskIdentity::validateTargets($config, $machine));
        // Any non-custom mode uses the renderer’s automatic disk choice. Never guess among several devices.
        if (($config['partition_mode'] ?? 'default') !== 'custom' && count($hw['disks']) > 1) {
            $errors[]='Plusieurs disques sont inventoriés : choisissez explicitement le disque cible dans Plan personnalisé, puis utilisez Schéma auto si souhaité.';
        }
        if ($wipe===null) $errors[]='Pré-effacement bloqué : relancez un discovery et vérifiez les numéros de série des disques sélectionnés.';
        if (is_array($config['disk_target_constraints'] ?? null) && $config['disk_target_constraints'] !== []) {
            $intendedSerial = DiskIdentity::normalize(reset($config['disk_target_constraints']));
            foreach ($wipe ?? [] as $disk) if (($disk['identity']['value'] ?? null) !== $intendedSerial) {
                $errors[] = 'Contrainte disque : le pré-effacement inclut un autre disque que celui imposé par le relevé ; aucun effacement ne sera programmé.';
            }
        }
        // A named wipe disk that disappeared must never silently fall out of the plan.
        $dict=Preset::parseWipeMode($preWipe,[]);
        $paths=array_map(static fn($d)=>'/dev/'.basename($d['name']),$hw['disks']);
        foreach ($dict as $name=>$mode) if ($name!=='*' && $mode!=='aucun' && !in_array('/dev/'.basename((string)$name),$paths,true)) $errors[]='Disque de pré-effacement absent : '.$name;
        if (($config['variant']??'debian')==='proxmox-ve') {
            // The inherited path depends on a private integration. Block it
            // until a generic, independently tested installation exists.
            $errors[]='L’installation générique de Proxmox VE n’est pas encore disponible.';
        }
        $plan=Preset::parsePartitioningPlan($config['partition_custom_json']??null);
        $targetPaths = ($config['partition_mode'] ?? 'default') === 'custom' ? $plan['target_disks'] : [(string)($config['system_disk_path'] ?? '')];
        foreach ($targetPaths as $path) if (!isset($capacities[$path]) || $capacities[$path] <= 0) $errors[] = 'Capacité du disque cible absente : '.$path.' ; relancez un discovery.';
        $wipePaths = array_map(static fn($disk) => '/dev/'.basename($disk['name']), $wipe ?? []);
        $preserved = array_values(array_filter($paths, static fn($path) => !in_array($path, $targetPaths, true) && !in_array($path, $wipePaths, true)));
        if (($config['partition_mode']??'default')==='custom') {
            $rawPlan=json_decode((string)($config['partition_custom_json']??''),true);
            $identities=is_array($rawPlan['target_disk_identities']??null)?$rawPlan['target_disk_identities']:[];
            $disks=[];
            foreach (($plan['target_disks']??[]) as $diskPath) {
                $serial=(string)($identities[$diskPath]['value']??'');
                $disks[]=$serial!=='' ? $diskPath.(($identities[$diskPath]['kind']??'')==='wwid' ? ' · WWID ' : ' · numéro de série ').$serial : $diskPath;
            }
        } else {
            $serial=(string)($config['system_disk_identity']['value']??'');
            $diskPath=(string)($config['system_disk_path']??'');
            $disks=[$serial!=='' ? $diskPath.(($config['system_disk_identity']['kind']??'')==='wwid' ? ' · WWID ' : ' · numéro de série ').$serial : 'Choix automatique de l’installateur parmi : '.implode(', ',$paths)];
        }
        $network=[];
        foreach ($config['network']['interfaces']??[] as $i=>$nic) {
            $matchBy=$nic['match_by']??'primary';
            $resolved=null;
            foreach ($hw['nics'] as $index=>$candidate) {
                if (($matchBy==='mac' && strtolower($nic['match']['mac']??'')===strtolower($candidate['mac']))
                    || ($matchBy==='index' && (int)($nic['index']??$nic['match']['index']??0)===$index)
                    || ($matchBy==='primary' && strtolower($candidate['mac'])===strtolower($hw['boot_mac']))) { $resolved=$candidate; break; }
            }
            $match=$resolved ? $resolved['name'].' · '.$resolved['mac'] : ($nic['match']['mac']??$nic['index']??$nic['match']['index']??$matchBy);
            if (!$resolved && ($nic['mode']??'dhcp')!=='disabled') $errors[]='Carte réseau #'.($i+1).' non résolue dans l’inventaire de cette cible.';
            $line='Carte '.($i+1).' ('.$match.')'.((int)($config['network']['primary']??0)===$i?' · primaire':'').' : '.($nic['mode']??'dhcp');
            if (($nic['mode']??'')==='static') $line.=' '.$nic['static']['ip'].'/'.($nic['static']['cidr']??'').' · passerelle '.($nic['static']['gateway']??'').' · DNS '.implode(', ',$nic['static']['dns']??[]);
            if (isset($nic['vlan']['id'])) $line.=' · VLAN '.$nic['vlan']['id'];
            if (!empty($nic['bond']['role'])) $line.=' · bond '.($nic['bond']['name']??'').' '.($nic['bond']['role']??'').' '.($nic['bond']['mode']??'');
            $network[]=$line;
        }
        if (!$network) $network[]='Carte PXE : '.(($config['network_mode']??'dhcp')==='static' ? ($config['static_ip']??'').'/'.($config['static_cidr']??'').' · passerelle '.($config['static_gateway']??'').' · DNS '.implode(', ',$config['static_dns']??[]) : 'DHCP');
        $sql='SELECT * FROM os_images WHERE distro=? AND arch=?';
        $args=[$config['distro']??'debian',$machine['boot_arch']?:'amd64'];
        if (!empty($config['version_full'])) { $sql.=' AND version_full=?'; $args[]=$config['version_full']; }
        elseif (!empty($config['codename'])) { $sql.=' AND codename=?'; $args[]=$config['codename']; }
        $stmt=Db::get()->prepare($sql.' ORDER BY downloaded_at DESC,id DESC'); $stmt->execute($args);
        $available=false;
        $images=$stmt->fetchAll();
        if (!empty($config['version_full']) && !$images && CatalogEntry::find($config['distro'],$config['version_full'],$machine['boot_arch']?:'amd64')===null) $errors[]='Version système inconnue du catalogue pour cette architecture.';
        foreach ($images as $image) if ($image['status']==='downloaded' && (int)$image['enabled']===1 && OsImage::filesAvailable($image)) { $available=true; break; }
        $imageSummary=$available?'Image téléchargée ; fichiers de démarrage présents.':'Image à préparer ou à télécharger. Vérifiez Images avant le démarrage PXE ; la programmation ne confirme pas sa disponibilité.';
        return ['id'=>(int)$machine['id'],'label'=>Machine::displayLabel($machine),'state'=>!$rule['allowed']?'blocked':($errors?'adapt':'ready'),
            'reasons'=>array_values(array_unique(array_merge($rule['allowed']?[]:[$rule['reason']],$errors))), 'action_id'=>$rule['action_id'],
            'network'=>$network,'disks'=>$disks,'preserved_disks'=>$preserved,'boot_mode'=>$observedBootMode??'Inconnu','pre_wipe'=>$wipe??[],'image'=>$imageSummary,
            'fingerprint'=>hash('sha256',json_encode([$machine['id'],$machine['mac'],$machine['hostname'],$machine['domain'],$machine['status'],$machine['rescue_mode'],$machine['inventory_json'],$config,$wipe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
    }
}
