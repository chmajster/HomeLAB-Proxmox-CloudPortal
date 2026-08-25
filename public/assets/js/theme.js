(() => {
  'use strict';

  const storageKey = 'portal-theme';
  const root = document.documentElement;

  function storedTheme() {
    try {
      const value = localStorage.getItem(storageKey);
      return value === 'light' || value === 'dark' ? value : null;
    } catch {
      return null;
    }
  }

  function applyTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') return;
    root.dataset.bsTheme = theme;
  }

  // Apply the saved theme immediately, before the stylesheet is loaded.
  // This prevents detail pages that do not load app.js from falling back to dark.
  applyTheme(storedTheme());

  document.addEventListener('DOMContentLoaded', () => {
    const page = document.body?.dataset.page || '';

    // app.js owns the theme button on normal pages and settings.js owns it on
    // settings. Detail pages intentionally skip app.js, so bind the control here.
    if (!['vm-details', 'project-details', 'admin-resource-details'].includes(page)) return;

    document.getElementById('themeButton')?.addEventListener('click', () => {
      const next = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      try {
        localStorage.setItem(storageKey, next);
      } catch {
        // Theme switching still works for the current page when storage is unavailable.
      }
    });
  });
})();
