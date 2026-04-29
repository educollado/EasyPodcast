(function () {
  function bindSelectAll(form, masterId, childName) {
    var master = document.getElementById(masterId);
    if (!master) {
      return;
    }

    function syncMaster() {
      var all = form.querySelectorAll('input[name="' + childName + '"]');
      var checked = form.querySelectorAll('input[name="' + childName + '"]:checked');
      master.checked = all.length > 0 && checked.length === all.length;
      master.indeterminate = checked.length > 0 && checked.length < all.length;
    }

    syncMaster();

    master.addEventListener('change', function () {
      form.querySelectorAll('input[name="' + childName + '"]').forEach(function (checkbox) {
        checkbox.checked = master.checked;
      });
    });

    form.addEventListener('change', function (event) {
      if (!(event.target instanceof HTMLInputElement) || event.target.name !== childName) {
        return;
      }

      syncMaster();
    });
  }

  function initImportFeed() {
    var form = document.getElementById('import-form');
    var button = document.getElementById('import-btn');

    if (!form || !button) {
      return;
    }

    bindSelectAll(form, 'select-all-meta', 'overwrite_fields[]');
    bindSelectAll(form, 'select-all-eps', 'selected_guids[]');

    form.addEventListener('submit', function (event) {
      var checked = form.querySelectorAll('input[name="selected_guids[]"]:checked');
      if (checked.length === 0) {
        event.preventDefault();
        alert(form.dataset.emptySelectionMessage || '');
        return;
      }

      button.disabled = true;
      button.textContent = form.dataset.importingLabel || button.textContent;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initImportFeed);
  } else {
    initImportFeed();
  }
}());
