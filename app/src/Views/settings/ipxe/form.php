<?php
use Admin\View;
/** @var bool $enabled */
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2>iPXE chainload</h2>
    <p class="muted">
      Charge iPXE depuis pxelinux/GRUB pour bénéficier du HTTP boot natif :
      kernel/initrd téléchargés en HTTP (bien plus rapide qu'en TFTP),
      scripting flexible, hardware support large. Pattern netboot.xyz.
      Optionnel — désactivé par défaut.
    </p>
  </hgroup>
</header>

<?php View::partial('settings/_application_status', ['jobs' => $jobs ?? []]); ?>
<form method="post" action="/settings/ipxe">
  <fieldset>
    <legend><strong>Activation</strong></legend>
    <label for="ipxe-enabled">
      <input type="checkbox" name="ipxe_enabled" id="ipxe-enabled" role="switch"
             <?= $enabled ? 'checked' : '' ?>>
      Activer le chainload iPXE
    </label>
    <small class="muted">
      <?php if ($enabled): ?>
        Activé. Les menus pxelinux et GRUB exposent une entrée <strong>"Chainload iPXE"</strong>.
        L'admin peut sélectionner cette entrée pour booter via iPXE → /boot.ipxe (HTTP).
      <?php else: ?>
        Désactivé (par défaut). Les machines bootent uniquement via pxelinux (BIOS)
        ou GRUB EFI (UEFI), avec kernel/initrd en TFTP. Pas d'option iPXE proposée
        dans les menus.
      <?php endif; ?>
    </small>
  </fieldset>

  <article class="flash flash-warning" data-persistent style="margin-top:1rem;">
    <strong><?= View::icon('warning') ?> Note Secure Boot UEFI</strong> : ipxe.efi n'est pas signé Microsoft.
    Sur une machine UEFI avec Secure Boot activé, l'option chainload iPXE échouera
    (sauf si l'image iPXE est signée avec votre MOK custom — ce qui n'est pas
    encore implémenté). Sur BIOS legacy ou UEFI sans SB, ça marche directement.
  </article>

  <div class="actions" style="margin-top:1rem;">
    <a href="/settings" class="btn btn-secondary">Retour</a>
    <button type="submit" class="btn btn-primary">Enregistrer</button>
  </div>
</form>

<p class="hint-row muted text-small" style="margin-top:1rem;">
  <?= View::icon('lightbulb') ?>
  <span>Les menus PXE sont régénérés automatiquement à l'enregistrement&nbsp;: le
  changement s'applique au prochain démarrage réseau des machines.</span>
</p>
