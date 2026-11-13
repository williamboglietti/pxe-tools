<?php
/**
 * Stepper de progression intégré dans l'<article> de chaque étape numérotée
 * du wizard d'init (étapes 1..5 — la welcome est hors compteur).
 *
 * @var int    $step       Numéro 1..5 (étape courante)
 * @var string $stepLabel  Nom court (utilisé seulement pour cohérence ; le
 *                         libellé visible vient de $allSteps ci-dessous)
 */
use Admin\View;

$totalSteps = 5;
$step       = (int)($step ?? 1);

$allSteps = [
    1 => 'Base de données',
    2 => 'Compte admin',
    3 => 'Réseau',
    4 => 'Systèmes',
    5 => 'Validation',
];
?>
<ol class="wizard-stepper">
  <?php foreach ($allSteps as $n => $label):
    $cls = $n < $step ? 'done' : ($n === $step ? 'active' : 'pending');
  ?>
    <li class="wizard-step <?= $cls ?>">
      <span class="dot"><?= $n < $step ? View::icon('check') : $n ?></span>
      <span class="label"><?= View::e($label) ?></span>
    </li>
  <?php endforeach; ?>
</ol>
