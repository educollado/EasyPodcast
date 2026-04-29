(function () {
  function initMediaCleanup() {
    var form = document.getElementById('cleanup-form');
    var toggleButton = document.getElementById('btn-select-all');

    if (!form || !toggleButton) {
      return;
    }

    var allSelected = false;

    toggleButton.addEventListener('click', function () {
      allSelected = !allSelected;
      form.querySelectorAll('input[name="files[]"]').forEach(function (checkbox) {
        checkbox.checked = allSelected;
      });

      toggleButton.textContent = allSelected
        ? (toggleButton.dataset.deselectLabel || toggleButton.textContent)
        : (toggleButton.dataset.selectLabel || toggleButton.textContent);
    });

    form.addEventListener('submit', function (event) {
      var checked = form.querySelectorAll('input[name="files[]"]:checked');
      if (checked.length === 0) {
        event.preventDefault();
        alert(form.dataset.emptySelectionMessage || '');
        return;
      }

      if (!window.confirm(form.dataset.confirmMessage || '')) {
        event.preventDefault();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMediaCleanup);
  } else {
    initMediaCleanup();
  }
}());
