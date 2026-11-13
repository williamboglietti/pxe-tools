<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\InitGuard;
use Admin\Models\Setting;
use Admin\View;

/**
 * Spec H — Mode iPXE chainload (HTTP boot natif).
 *
 * Routes :
 *   GET  /settings/ipxe       → form (toggle activer)
 *   POST /settings/ipxe       → sauve
 *   GET  /boot.ipxe           → script iPXE MAC-aware (sera ajouté en H.3)
 *
 * Quand `ipxe_enabled = on` :
 *   - pxelinux-default + grub.cfg ajoutent un menuentry "Chainload iPXE"
 *     qui charge ipxe.lkrn (BIOS) / ipxe.efi (UEFI) depuis TFTP
 *   - ipxe exécute /boot.ipxe?mac=... qui retourne un script iPXE servant
 *     les bons kernel/initrd (lus depuis os_images) en HTTP natif (rapide
 *     vs TFTP, scripting flexible, hardware support large)
 *
 * Off par défaut pour ne pas casser les setups existants.
 */
final class IpxeController
{
    public function form(array $params): void
    {
        View::render('settings/ipxe/form', [
            'jobs' => \Admin\Util\ApplianceJobs::tracked('ipxe_job_ids'),
            'enabled' => Setting::get(Setting::KEY_IPXE_ENABLED, 'off') === 'on',
        ], 'iPXE chainload');
    }

    public function update(array $params): void
    {
        $enabled = isset($_POST['ipxe_enabled']);
        $ids = \Admin\Util\ApplianceJobs::enqueue([
            'bootstrap:render_templates' => 'Régénération des menus PXE (chainload iPXE)',
        ], [Setting::KEY_IPXE_ENABLED => $enabled ? 'on' : 'off'], 'ipxe_job_ids');
        InitGuard::resetCache();
        View::flash('info', 'Choix iPXE enregistré. Application des menus demandée.', '/init?jobs=' . implode(',', $ids), 'Suivre les traitements');
        View::redirect('/settings/ipxe');
    }
}
