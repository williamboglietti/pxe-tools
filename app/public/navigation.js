// Navigation owns the drawer and desktop rail; theme.js only owns the theme.
(() => {
  'use strict';

  function initNavigation() {
    const shell = document.getElementById('app-shell');
    const sidebar = document.getElementById('app-sidebar');
    if (!shell || !sidebar || shell.dataset.navigationReady === 'true') return;
    shell.dataset.navigationReady = 'true';

    const backdrop = document.getElementById('app-backdrop');
    const content = shell.querySelector('.app-content');
    const mobile = window.matchMedia('(max-width: 920px)');
    const hoverCapable = window.matchMedia('(hover: hover)');
    const collapsedKey = 'pxe.sidebar.collapsed';
    const initialRole = sidebar.getAttribute('role');
    const focusableSelector = 'a[href],button,input,select,textarea,summary,[tabindex]';
    let drawerOpen = false;
    let pointerPeek = false;
    let focusPeek = false;
    let hoverSuppressed = false;
    let keyboardInput = true;
    let returnFocus = null;
    let drawerLock = null;

    function readPreference(key) {
      try { return localStorage.getItem(key); } catch (_) { return null; }
    }

    function savePreference(key, value) {
      try { localStorage.setItem(key, String(value)); } catch (_) { /* Optional persistence. */ }
    }

    const storedCollapsed = readPreference(collapsedKey);
    let collapsed = storedCollapsed === null
      ? shell.dataset.sidebarCollapsed === 'true'
      : storedCollapsed === 'true';

    function isVisible(element) {
      return element instanceof HTMLElement && element.isConnected
        && !element.closest('[hidden],[inert]')
        && element.getClientRects().length > 0
        && window.getComputedStyle(element).visibility !== 'hidden';
    }

    function focusableControls() {
      return [...sidebar.querySelectorAll(focusableSelector)]
        .filter(element => !element.matches(':disabled') && element.tabIndex >= 0 && isVisible(element));
    }

    function focusElement(element) {
      if (!isVisible(element)) return false;
      element.focus({ preventScroll: true });
      return document.activeElement === element;
    }

    function focusDrawer() {
      const closeButton = sidebar.querySelector('[data-sidebar-close]');
      if (focusElement(closeButton)) return;
      if (focusElement(focusableControls()[0])) return;
      focusElement(sidebar);
    }

    function focusOpener() {
      const opener = [...document.querySelectorAll('[data-sidebar-open]')].find(isVisible);
      if (!focusElement(opener)) focusElement(document.getElementById('main-content'));
    }

    function restoreFocus() {
      if (!focusElement(returnFocus)) focusOpener();
      returnFocus = null;
    }

    // Preserve locks that already existed before this drawer was opened.
    function lockDrawerBackground() {
      if (drawerLock) return;
      drawerLock = {
        overflow: document.body.style.overflow,
        menuOpen: document.body.classList.contains('menu-open'),
        contentInert: content ? content.inert : false,
      };
      document.body.style.overflow = 'hidden';
      document.body.classList.add('menu-open');
      if (content) content.inert = true;
    }

    function releaseDrawerBackground() {
      if (!drawerLock) return;
      document.body.style.overflow = drawerLock.overflow;
      document.body.classList.toggle('menu-open', drawerLock.menuOpen);
      if (content) content.inert = drawerLock.contentInert;
      drawerLock = null;
    }

    function render() {
      const modalOpen = mobile.matches && drawerOpen;
      const peek = !mobile.matches && collapsed && (pointerPeek || focusPeek);
      shell.dataset.sidebarCollapsed = String(collapsed);
      shell.dataset.sidebarHover = String(peek);
      sidebar.classList.toggle('is-open', modalOpen);
      sidebar.inert = mobile.matches && !modalOpen;
      sidebar.setAttribute('aria-hidden', String(sidebar.inert));
      backdrop?.classList.toggle('is-open', modalOpen);
      if (backdrop) backdrop.setAttribute('aria-hidden', 'true');

      if (modalOpen) {
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
      } else {
        sidebar.removeAttribute('aria-modal');
        if (initialRole === null) sidebar.removeAttribute('role');
        else sidebar.setAttribute('role', initialRole);
      }

      document.querySelectorAll('[data-sidebar-open]').forEach(button => {
        button.setAttribute('aria-expanded', String(modalOpen));
        button.setAttribute('aria-controls', sidebar.id);
      });
      sidebar.querySelectorAll('[data-sidebar-collapse]').forEach(button => {
        const label = collapsed ? 'Déplier le panneau' : 'Replier le panneau';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        const visibleLabel = button.querySelector('.sidebar-label');
        if (visibleLabel) visibleLabel.textContent = label;
        button.setAttribute('aria-expanded', String(!collapsed || peek));
        button.setAttribute('aria-controls', sidebar.id);
      });
    }

    function openDrawer() {
      if (!mobile.matches || drawerOpen) return;
      returnFocus = document.activeElement;
      drawerOpen = true;
      pointerPeek = false;
      focusPeek = false;
      lockDrawerBackground();
      render();
      focusDrawer();
    }

    function closeDrawer(restore = true) {
      const wasOpen = drawerOpen;
      drawerOpen = false;
      releaseDrawerBackground();
      render();
      if (wasOpen && restore) restoreFocus();
      else if (wasOpen) returnFocus = null;
    }

    function setCollapsed(value) {
      collapsed = value;
      pointerPeek = false;
      focusPeek = false;
      savePreference(collapsedKey, collapsed);
      render();
    }

    // A stored closed group must never hide the active route on page load.
    sidebar.querySelectorAll('details[data-nav-group]').forEach((group, index) => {
      const name = group.dataset.navGroup || group.id || String(index);
      const key = 'pxe.sidebar.group.' + name;
      const storedOpen = readPreference(key);
      const active = group.querySelector('[aria-current]:not([aria-current="false"])');
      if (active) group.open = true;
      else if (storedOpen !== null) group.open = storedOpen === 'true';
      const summary = group.querySelector(':scope > summary');
      const syncSummary = () => summary?.setAttribute('aria-expanded', String(group.open));
      let observedOpen = group.open;
      syncSummary();
      group.addEventListener('toggle', () => {
        syncSummary();
        // Initial native toggle events can arrive after listeners are attached.
        if (group.open === observedOpen) return;
        observedOpen = group.open;
        savePreference(key, group.open);
      });
    });

    function maybePeek(event) {
      if (mobile.matches || !collapsed || hoverSuppressed || !hoverCapable.matches) return;
      if (event.pointerType === 'touch') return;
      const footer = sidebar.querySelector('.sidebar-footer');
      if (footer && event.clientY >= footer.getBoundingClientRect().top - 6) return;
      pointerPeek = true;
      render();
    }

    sidebar.addEventListener('pointerenter', maybePeek);
    sidebar.addEventListener('pointermove', maybePeek);
    sidebar.addEventListener('pointerleave', () => {
      hoverSuppressed = false;
      pointerPeek = false;
      if (!keyboardInput) focusPeek = false;
      render();
    });

    document.addEventListener('pointerdown', event => {
      keyboardInput = false;
      if (!sidebar.contains(event.target) && focusPeek) {
        focusPeek = false;
        render();
      }
    }, true);

    document.addEventListener('focusin', event => {
      if (mobile.matches && drawerOpen) {
        if (!sidebar.contains(event.target)) focusDrawer();
        return;
      }
      if (!mobile.matches && collapsed && keyboardInput && sidebar.contains(event.target)) {
        focusPeek = !event.target.closest('[data-sidebar-collapse]');
        render();
      }
    });

    sidebar.addEventListener('focusout', event => {
      if (!sidebar.contains(event.relatedTarget)) {
        focusPeek = false;
        render();
      }
    });

    document.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      if (target.closest('[data-sidebar-open]')) {
        openDrawer();
      } else if (target.closest('[data-sidebar-close]') || target === backdrop) {
        closeDrawer();
      } else if (sidebar.contains(target) && target.closest('[data-sidebar-collapse]')) {
        if (mobile.matches) return;
        hoverSuppressed = true;
        setCollapsed(!collapsed);
      } else if (sidebar.contains(target) && target.closest('a.sidebar-item,a.sidebar-subitem')) {
        closeDrawer(false);
      } else if (!mobile.matches && collapsed && !pointerPeek && !focusPeek) {
        const summary = target.closest('details[data-nav-group] > summary');
        if (!summary || !sidebar.contains(summary)) return;
        // Clicking an icon-only group also works on desktops without hover.
        event.preventDefault();
        setCollapsed(false);
        summary.parentElement.open = true;
      }
    });

    document.addEventListener('keydown', event => {
      if (event.key === 'Tab') keyboardInput = true;
      if (!mobile.matches || !drawerOpen) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeDrawer();
        return;
      }
      if (event.key !== 'Tab') return;
      const controls = focusableControls();
      const first = controls[0];
      const last = controls[controls.length - 1];
      const active = document.activeElement;
      if (!controls.length) {
        event.preventDefault();
        focusElement(sidebar);
      } else if (!controls.includes(active)) {
        event.preventDefault();
        focusElement(event.shiftKey ? last : first);
      } else if (event.shiftKey && active === first) {
        event.preventDefault();
        focusElement(last);
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        focusElement(first);
      }
    });

    // Native search dialogs must own focus instead of the mobile drawer.
    document.addEventListener('pxe:search-open', () => closeDrawer(false));

    function breakpointChanged() {
      const active = document.activeElement;
      const wasOpen = drawerOpen;
      closeDrawer(false);
      pointerPeek = false;
      focusPeek = false;
      hoverSuppressed = false;
      render();
      if (mobile.matches && sidebar.contains(active)) {
        focusOpener();
      } else if (!mobile.matches && wasOpen && !isVisible(active)) {
        keyboardInput = true;
        const collapseButton = sidebar.querySelector('[data-sidebar-collapse]');
        if (!focusElement(collapseButton)) focusElement(focusableControls()[0]);
      }
    }

    function listenForChange(query, callback) {
      if (query.addEventListener) query.addEventListener('change', callback);
      else query.addListener(callback);
    }

    listenForChange(mobile, breakpointChanged);
    listenForChange(hoverCapable, () => {
      if (!hoverCapable.matches) {
        pointerPeek = false;
        render();
      }
    });

    if (!sidebar.hasAttribute('tabindex')) sidebar.setAttribute('tabindex', '-1');
    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNavigation, { once: true });
  } else {
    initNavigation();
  }
})();
