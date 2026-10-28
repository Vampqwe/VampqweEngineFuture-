/* Системный JS админ-панели (SCOPE_CORE). Раздаётся через /assets/js/admin.js. */
(function () {
  'use strict';
  // Подтверждение опасных действий: <button data-confirm="Текст?">
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      ev.preventDefault();
      ev.stopPropagation();
    }
  });
})();
