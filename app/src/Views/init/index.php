<?php
use Admin\View;
?>
<div class="init-screen-wrapper">
<section class="init-screen">
    <header class="init-header">
        <h1>Activité de l’appliance</h1>
        <p class="muted">Suivez les traitements de préparation et de maintenance. Les opérations continuent si vous quittez cette page.</p>
        <div class="actions"><a href="/settings" class="btn btn-secondary">Retour aux paramètres</a><a href="/activity" class="btn btn-secondary">Toute l’activité</a></div>
    </header>

    <?php View::partial('init/_progress', [
        'jobs' => $jobs, 'progress' => $progress, 'hasFailure' => $hasFailure,
        'downloads' => $downloads ?? [], 'jobIds' => $jobIds ?? [],
    ]); ?>
</section>
</div>
