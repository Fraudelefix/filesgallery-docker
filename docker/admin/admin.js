(() => {
  'use strict';

  // This Docker-image asset is loaded only for the canonical server-side admin
  // identity. It deliberately has no authority of its own.
  const insert = () => {
    if (document.getElementById('filesgallery-docker-admin')) return true;
    const settings = document.getElementById('user-settings');
    const topbar = document.getElementById('topbar-top');
    // The upstream UI creates Settings dynamically.  Wait for it so that the
    // project button is always placed immediately to its left.
    if (!topbar || !settings) return false;
    const link = document.createElement('a');
    link.id = 'filesgallery-docker-admin';
    link.className = 'button-icon';
    link.href = '/?action=admin';
    link.title = 'Administration (Docker version)';
    link.setAttribute('aria-label', 'Administration (Docker version)');
    link.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5Z"/></svg>';
    topbar.insertBefore(link, settings);
    return true;
  };

  if (insert()) return;
  const observer = new MutationObserver(() => { if (insert()) observer.disconnect(); });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.setTimeout(() => observer.disconnect(), 5000);
})();
