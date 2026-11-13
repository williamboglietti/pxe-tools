// PxeToast — système de notifications toast vanilla (~85 lignes).
// Notifications en bas à droite, déduplication sur 2 s,
// auto-dismiss 6s, max 3 visibles, animation slide-in horizontale.
(() => {
  const MAX_VISIBLE = 3;
  const DEFAULT_AUTODISMISS_MS = 6000;
  const DEFAULT_DEDUP_MS = 2000;
  let container = null;
  const recent = new Map(); // dedup : `${type}|${msg}` → timestamp

  function ensureContainer() {
    if (container) return container;
    container = document.createElement('div');
    container.id = 'pxe-toast-stack';
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-atomic', 'false');
    document.body.appendChild(container);
    return container;
  }

  function dismiss(node) {
    if (!node || node.dataset.dismissing) return;
    node.dataset.dismissing = '1';
    node.classList.add('pxe-toast-leave');
    setTimeout(() => node.remove(), 280);
  }

  // Spinner SVG aligné sur celui des checkpoints (cf. tasks/_checklist.php) :
  // arc lucide 3/4 de cercle, stroke currentColor, animation 1.5s pour matcher
  // visuellement les autres pages de suivi de job. Style inline (pas de CSS
  // source — rebuild Tailwind pénible sur CPU sans AVX, cf. lessons 2026-05-13).
  function makeSpinner() {
    const NS = 'http://www.w3.org/2000/svg';
    const s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'pxe-toast-spinner');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS(NS, 'path');
    path.setAttribute('d', 'M21 12a9 9 0 1 1-6.219-8.56');
    s.appendChild(path);
    s.style.cssText = 'flex-shrink:0;align-self:center;width:1.1em;height:1.1em;animation:pxe-toast-spin 1.5s linear infinite;margin-right:0.4rem;';
    return s;
  }
  // Inject l'animation keyframe une seule fois (pas dans CSS source pour éviter rebuild).
  function injectSpinKeyframe() {
    if (document.getElementById('pxe-toast-spin-kf')) return;
    const st = document.createElement('style');
    st.id = 'pxe-toast-spin-kf';
    st.textContent = '@keyframes pxe-toast-spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }';
    document.head.appendChild(st);
  }

  // Poll /api/init-job-status?job_type=... toutes les 1s. Met à jour le toast
  // in-place selon les transitions du statut. Stop sur status terminal (success/
  // failed/skipped) ou timeout global 60s (worker mort).
  function pollJobStatus(toast, jobId) {
    const POLL_MS = 1000;
    const TIMEOUT_MS = 60000;
    const startedAt = Date.now();
    const intervalId = setInterval(async () => {
      if (Date.now() - startedAt > TIMEOUT_MS) {
        clearInterval(intervalId);
        setToastTerminal(toast, 'warning', 'Suivi automatique suspendu. Le résultat reste consultable dans l’activité.', false);
        return;
      }
      try {
        const r = await fetch('/jobs/' + encodeURIComponent(jobId) + '/status', { credentials: 'include' });
        if (!r.ok) return; // 4xx/5xx transient — on retentera au prochain tick
        const data = await r.json();
        if (!data.found) return; // job pas encore inséré → on attend

        const status = data.status;
        if (status === 'running' || status === 'pending') {
          // Update du message si le worker l'a changé (ex: "Pushing to git…")
          if (data.message) {
            const msgEl = toast.querySelector('.pxe-toast-msg');
            if (msgEl && msgEl.textContent !== data.message) msgEl.textContent = data.message;
          }
          return;
        }
        // Terminal
        clearInterval(intervalId);
        if (status === 'success' || status === 'skipped') {
          setToastTerminal(toast, 'success', '✅ ' + (data.message || 'Terminé'), true);
        } else if (status === 'failed') {
          setToastTerminal(toast, 'error', '❌ Échec : ' + (data.message || 'voir /init pour les détails'), false);
        } else {
          setToastTerminal(toast, 'warning', 'Statut inattendu : ' + status, false);
        }
      } catch (e) {
        // Erreur réseau passagère — on continue
      }
    }, POLL_MS);
  }

  // Swap classe + message + retire spinner + (re)programme dismiss si demandé.
  function setToastTerminal(toast, newType, newMsg, autoDismiss) {
    toast.classList.remove('pxe-toast-info', 'pxe-toast-warning', 'pxe-toast-success', 'pxe-toast-error');
    toast.classList.add('pxe-toast-' + newType);
    const spinner = toast.querySelector('.pxe-toast-spinner');
    if (spinner) spinner.remove();
    const msgEl = toast.querySelector('.pxe-toast-msg');
    if (msgEl) msgEl.textContent = newMsg;
    if (autoDismiss) setTimeout(() => dismiss(toast), 4000);
  }

  function show(type, message, opts = {}) {
    if (!message) return;
    const safeType = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
    const dedupMs = opts.dedupWindow ?? DEFAULT_DEDUP_MS;
    // Mode "live" (suivi de job init) : pas d'auto-dismiss tant que le job
    // n'est pas en état terminal. Le polling reprend la main.
    const live = Number.isInteger(Number(opts.jobId)) && Number(opts.jobId) > 0;
    const autoMs = live ? 0 : (opts.autoDismiss ?? (['error','warning'].includes(safeType) ? 0 : DEFAULT_AUTODISMISS_MS));

    // Dedup : skip si même type+message dans la fenêtre.
    const key = safeType + '|' + message;
    const now = Date.now();
    const last = recent.get(key);
    if (last && now - last < dedupMs) return;
    recent.set(key, now);
    // GC mémoire dedup (entrées > 30s)
    if (recent.size > 50) {
      for (const [k, t] of recent) if (now - t > 30000) recent.delete(k);
    }

    const root = ensureContainer();

    // Cap visible : drop oldest si > MAX_VISIBLE
    const visible = root.querySelectorAll('.pxe-toast:not(.pxe-toast-leave)');
    if (visible.length >= MAX_VISIBLE) dismiss(visible[0]);

    const toast = document.createElement('div');
    toast.className = 'pxe-toast pxe-toast-' + safeType;
    toast.setAttribute('role', safeType === 'error' || safeType === 'warning' ? 'alert' : 'status');

    if (live) {
      injectSpinKeyframe();
      toast.appendChild(makeSpinner());
    }

    const msgEl = document.createElement('div');
    msgEl.className = 'pxe-toast-msg';
    msgEl.textContent = message;
    toast.appendChild(msgEl);

    // Action optionnelle (lien-bouton). Style inline pour éviter de toucher
    // au CSS source Tailwind (rebuild pénible sur CPU sans AVX).
    if (opts.actionUrl) {
      const action = document.createElement('a');
      action.className = 'pxe-toast-action';
      action.href = opts.actionUrl;
      action.textContent = opts.actionLabel || 'Voir';
      action.style.cssText = 'flex-shrink:0;align-self:center;padding:0.25rem 0.6rem;border:1px solid currentColor;border-radius:6px;font-size:0.8rem;text-decoration:none;color:inherit;white-space:nowrap;line-height:1.2;';
      toast.appendChild(action);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'pxe-toast-close';
    close.setAttribute('aria-label', 'Fermer');
    close.innerHTML = '&times;';
    close.addEventListener('click', () => dismiss(toast));
    toast.appendChild(close);

    root.appendChild(toast);

    if (autoMs > 0) {
      setTimeout(() => dismiss(toast), autoMs);
    }
    if (live) {
      pollJobStatus(toast, opts.jobId);
    }
  }

  // Conversion auto des .flash PHP server-rendered → toasts JS.
  // Le HTML <article class="flash flash-info">…</article> est rendu pour
  // fallback no-JS. Au chargement, on les convertit puis on masque.
  function hijackPhpFlashes() {
    // Seuls les flashs ÉPHÉMÈRES (feedback d'action, rendus par layout.php) sont
    // convertis en toasts. Les bandeaux de statut PERSISTANTS inline portent
    // data-persistent et restent affichés tant que leur condition dure.
    const flashes = document.querySelectorAll('.flash[data-server-flash]:not([data-toast-bound])');
    flashes.forEach(f => {
      // type via flash-{type}
      const cls = [...f.classList].find(c => c.startsWith('flash-') && c !== 'flash');
      const type = cls ? cls.replace('flash-', '') : 'info';
      const txt = f.textContent.trim();
      const actionUrl = f.dataset.actionUrl || null;
      const actionLabel = f.dataset.actionLabel || null;
      const jobId = f.dataset.jobId || null;
      f.dataset.toastBound = "1";
      if (!jobId && f.hasAttribute("data-persistent")) return;
      if (txt) show(type, txt, { dedupWindow: 0, actionUrl, actionLabel, jobId });
      if (!f.hasAttribute("data-persistent")) f.remove();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hijackPhpFlashes);
  } else {
    hijackPhpFlashes();
  }

  // Rejoue le hijack après chaque swap HTMX : protège contre les .flash
  // résiduels (cas rare où un partial polled embarque un flash que le
  // backend a relâché — sans ce rebind, le .flash reste dans le DOM sans
  // être converti en toast, donc sans auto-dismiss → effet "sticky").
  // Le hijack lui-même est idempotent (querySelectorAll + remove), donc
  // un second appel sur un DOM déjà nettoyé est no-op.
  if (typeof document !== 'undefined') {
    document.body && document.body.addEventListener
      ? document.body.addEventListener('htmx:afterSwap', hijackPhpFlashes)
      : document.addEventListener('htmx:afterSwap', hijackPhpFlashes);
  }

  window.PxeToast = { show };
})();
