<?php if (\Admin\Util\PxeHttpReadiness::unavailable()): ?>
<article class="flash flash-error" role="alert" data-persistent>
  Le service HTTP PXE n’est pas encore disponible sur le port 80. Si un autre service utilise ce port, il faut résoudre le conflit avant de lancer une installation.
  <?php if (getenv('PXE_SERVICE_MANAGER') === 'systemd' && \Admin\Auth::user() !== null): ?>
    <a href="/init/port-80">Examiner le conflit avec nginx</a>
  <?php endif; ?>
</article>
<?php endif; ?>
