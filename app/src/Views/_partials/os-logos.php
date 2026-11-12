<?php
/**
 * Logos de marque par distro, rendus inline dans `.os-logo` (46px) par les deux
 * pickers d'OS (install single-select _install/wizard-form.php + wizard init
 * multi-select init/wizard/images.php).
 *
 * Source UNIQUE = les SVG de public/img/distros/<code>.svg, dont les tracés
 * correspondent à Simple Icons ; chaque logo peut avoir ses propres conditions
 * de licence et de marque. Ils peuvent être (re)téléchargés par
 * scripts/download-distro-logos.sh. Les mêmes fichiers alimentent
 * View::distroLogo() (badges /settings/images, via <img>). On les inline ici
 * parce que les tuiles stylent `.os-logo svg` (taille/centrage) ;
 * file_get_contents garde une source unique sans dupliquer le markup.
 *
 * @return array<string,string> code distro => markup SVG (vide si fichier absent)
 */

$dir   = __DIR__ . '/../../../public/img/distros/';
$logos = [];
foreach (['debian', 'ubuntu', 'fedora', 'centos', 'almalinux', 'rocky', 'proxmox'] as $code) {
    $path = $dir . $code . '.svg';
    $logos[$code] = is_file($path) ? (string) file_get_contents($path) : '';
}
return $logos;
