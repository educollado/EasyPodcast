(function () {
  function handleConfirmForms(event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    var message = form.dataset.confirmMessage || '';
    if (message !== '' && !window.confirm(message)) {
      event.preventDefault();
      return;
    }

    if (form.dataset.submitLock !== '1') {
      return;
    }

    var selector = form.dataset.submitLockButton || 'button[type="submit"]';
    var button = form.querySelector(selector);
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    var lockText = form.dataset.submitLockText || '';
    button.disabled = true;
    if (lockText !== '') {
      button.textContent = lockText;
    }
  }

  function handleAutoSubmit(event) {
    var field = event.target;
    if (!(field instanceof HTMLElement) || field.dataset.submitOnChange !== '1') {
      return;
    }

    if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
      if (field instanceof HTMLSelectElement) {
        var selectedOption = field.options[field.selectedIndex];
        var navigationUrl = selectedOption ? selectedOption.dataset.navigationUrl || '' : '';
        if (navigationUrl !== '') {
          window.location.assign(navigationUrl);
          return;
        }
      }
      if (field.form) {
        field.form.submit();
      }
    }
  }

  function enhanceFileInput(input, labels) {
    if (input.dataset.translatedFileInput === '1') {
      return;
    }

    input.dataset.translatedFileInput = '1';

    var control = document.createElement('span');
    control.className = 'translated-file-control';
    input.parentNode.insertBefore(control, input);
    control.appendChild(input);

    input.classList.add('translated-file-native');

    var button = document.createElement('span');
    button.className = 'translated-file-button';
    button.tabIndex = 0;
    button.setAttribute('role', 'button');
    button.textContent = labels.select;

    var status = document.createElement('span');
    status.className = 'translated-file-status';
    status.setAttribute('aria-live', 'polite');

    function updateStatus() {
      var files = input.files;
      if (!files || files.length === 0) {
        status.textContent = labels.empty;
      } else if (files.length === 1) {
        status.textContent = files[0].name;
      } else {
        status.textContent = labels.multiple.replace('%d', String(files.length));
      }
    }

    function openPicker(event) {
      event.preventDefault();
      event.stopPropagation();
      input.click();
    }

    button.addEventListener('click', openPicker);
    button.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        openPicker(event);
      }
    });
    input.addEventListener('change', updateStatus);
    input.addEventListener('focus', function () {
      button.classList.add('is-focused');
    });
    input.addEventListener('blur', function () {
      button.classList.remove('is-focused');
    });

    control.appendChild(button);
    control.appendChild(status);
    updateStatus();
  }

  function initTranslatedFileInputs() {
    var nav = document.querySelector('.admin-nav');
    if (!nav) {
      return;
    }

    var labels = {
      select: nav.dataset.fileSelectLabel || 'Seleccionar archivo',
      empty: nav.dataset.fileEmptyLabel || 'No se ha seleccionado ningún archivo',
      multiple: nav.dataset.fileMultipleLabel || '%d archivos seleccionados'
    };

    document.querySelectorAll('input[type="file"]').forEach(function (input) {
      enhanceFileInput(input, labels);
    });

    document.addEventListener('reset', function (event) {
      if (!(event.target instanceof HTMLFormElement)) {
        return;
      }
      window.setTimeout(function () {
        event.target.querySelectorAll('input[type="file"]').forEach(function (input) {
          input.dispatchEvent(new Event('change'));
        });
      }, 0);
    });
  }

  function initAdminUi() {
    document.addEventListener('submit', handleConfirmForms);
    document.addEventListener('change', handleAutoSubmit);
    initTranslatedFileInputs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminUi);
  } else {
    initAdminUi();
  }
}());
