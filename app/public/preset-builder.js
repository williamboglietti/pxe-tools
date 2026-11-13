/**
 * Bootstrap des builders (DNS rows + partitions) pour /presets/edit.
 * Toute la logique est dans builder-shared.js — ce fichier ne fait que l'init.
 */
(() => {
  'use strict';
  if (!window.builderShared) {
    console.warn('preset-builder.js: builder-shared.js doit être chargé avant');
    return;
  }
  window.builderShared.initBuilders(document);
})();
