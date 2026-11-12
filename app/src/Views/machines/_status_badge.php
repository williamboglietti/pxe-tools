<?php
/**
 * Partial : contenu intérieur du badge statut sur la fiche machine.
 *
 * Rendu :
 * - Initialement par show.php (état initial du badge)
 * - En polling HTMX (hx-get="/machines/{id}/status" every 2s) → swap innerHTML
 *
 * @var array{class:string,label:string} $state
 */
use Admin\View;
?>
<span class="status-dot <?= View::e($state['class']) ?>" aria-hidden="true"></span>
<strong><?= View::e($state['label']) ?></strong>
