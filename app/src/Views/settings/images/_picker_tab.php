<?php
use Admin\View;
/** @var string $distro_code */
/** @var string $distro_label */
/** @var array<int,string> $archs */
/** @var array<int, array<string, array{codename:?string, channel:?string, archs:array<string,array<string,mixed>>}>> $majors */
/** @var ?string $empty_tab_hint Message custom si le catalogue de cette distro est vide (défaut : renvoi vers « Refresh catalogue »). */
$empty_tab_hint = $empty_tab_hint ?? null;

$channelLabel = fn(?string $c): string => match ($c) {
    'stable'    => 'stable',
    'oldstable' => 'oldstable',
    'testing'   => 'testing',
    'unstable'  => 'unstable',
    default     => '',
};

if (empty($majors)) {
    $hint = $empty_tab_hint ?? '';
    echo '<p class="muted picker-empty-tab">'
       . View::icon('package')
       . ' Aucune entrée connue pour ' . View::e($distro_label) . '. '
       . ($hint !== '' ? View::e($hint) : 'Lancez « ↻ Refresh catalogue » pour découvrir les versions disponibles.')
       . '</p>';
    return;
}

// $majors est array<int_major, array<version_full, meta>>. Le tri major DESC est
// déjà fait par CatalogEntry::groupedForPicker(). À l'intérieur d'une majeure,
// les mineures sont triées DESC par requête SQL ; on garde cet ordre.
?>

<?php foreach ($majors as $major => $versions):
    $first = reset($versions);
    $codename = $first['codename'] ?? null;
    $channel  = $first['channel'] ?? null;
    $channelDisplay = $channelLabel($channel);
    $title = $distro_label . ($major > 0 ? ' ' . $major : '');
    if ($codename) $title .= ' — ' . ucfirst($codename);
?>
  <section class="picker-major-section">
    <header class="picker-major-header">
      <h3 class="picker-major-title">
        <?= View::distroLogo($distro_code) ?>
        <?= View::e($title) ?>
      </h3>
      <?php if ($channelDisplay !== ''): ?>
        <span class="badge-channel badge-channel-<?= View::e($channel) ?>"><?= View::e($channelDisplay) ?></span>
      <?php endif; ?>
    </header>

    <table class="picker-table">
      <thead>
        <tr>
          <th class="picker-col-version">Version</th>
          <?php foreach ($archs as $arch):
              $masterId = "master_{$distro_code}_{$major}_{$arch}";
          ?>
            <th class="picker-col-arch">
              <label class="picker-master-toggle" for="<?= $masterId ?>">
                <input type="checkbox"
                       class="picker-master-check"
                       aria-label="<?= View::e('Toutes les versions de ' . $title . ' · ' . $arch) ?>"
                       id="<?= $masterId ?>"
                       data-distro="<?= View::e($distro_code) ?>"
                       data-major="<?= (int)$major ?>"
                       data-arch="<?= View::e($arch) ?>">
                Tout <?= View::e($arch) ?>
              </label>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($versions as $vf => $meta):
            $rowArchs = $meta['archs'];
        ?>
          <tr class="picker-row">
            <td class="picker-col-version"><?= View::e((string)$vf) ?></td>
            <?php foreach ($archs as $arch):
                $entry = $rowArchs[$arch] ?? null;
                $value = "$distro_code:$vf:$arch";
                $rowId = "row_{$distro_code}_" . md5("$vf|$arch");
            ?>
              <td class="picker-col-arch">
                <?php if ($entry === null): ?>
                  <span class="muted picker-arch-na" title="Non disponible pour cette version">—</span>
                <?php else: ?>
                  <label class="picker-row-toggle" for="<?= $rowId ?>">
                    <input type="checkbox"
                           class="picker-row-check"
                           aria-label="<?= View::e($distro_label . ' ' . $vf . ' · ' . $arch) ?>"
                           id="<?= $rowId ?>"
                           name="rows[]"
                           value="<?= View::e($value) ?>"
                           data-distro="<?= View::e($distro_code) ?>"
                           data-major="<?= (int)$major ?>"
                           data-arch="<?= View::e($arch) ?>">
                    <code class="picker-row-arch"><?= View::e($arch) ?></code>
                  </label>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>
