<?php
/**
 * Page autonome du wizard d'installation. Le wizard lui-même vit dans
 * `_install/wizard-form.php` et est aussi inclus inline dans show.php (onglet "Installer").
 *
 * Variables injectées par MachineController::installWizard via View::render() / extract() :
 * @var array              $machine
 * @var array              $config
 * @var array<int,array>   $allPresets
 * @var array{compatible: list<array<string,mixed>>, others: list<array<string,mixed>>} $presetsGrouped
 * @var array<int,string>  $allowed_distros
 * @var bool               $hasExistingConfig
 * @var array|null         $machineHw
 * @var array<int,string>  $errors
 */
use Admin\View;
use Admin\Models\Machine;

$machineLabel = Machine::displayLabel($machine);
$verb = $hasExistingConfig ? 'Réinstaller' : 'Installer';
?>

<header class="row-flex-spread mb-1">
  <hgroup>
    <h2><?= $verb ?> · <?= View::e($machineLabel) ?></h2>
    <p class="text-mono"><?= View::e($machine['mac']) ?></p>
  </hgroup>
  <a href="/machines/<?= (int)$machine['id'] ?>" class="btn btn-secondary">← Retour</a>
</header>

<?php View::partial('_install/wizard-form', [
    'machine'           => $machine,
    'config'            => $config,
    'allPresets'        => $allPresets,
    'presetsGrouped'    => $presetsGrouped ?? ['compatible' => [], 'others' => $allPresets],
    'allowed_distros'   => $allowed_distros,
    'hasExistingConfig' => $hasExistingConfig,
    'machineHw'         => $machineHw ?? null,
    'errors'            => $errors ?? [],
]); ?>
