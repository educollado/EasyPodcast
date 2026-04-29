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
      if (field.form) {
        field.form.submit();
      }
    }
  }

  function initAdminUi() {
    document.addEventListener('submit', handleConfirmForms);
    document.addEventListener('change', handleAutoSubmit);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminUi);
  } else {
    initAdminUi();
  }
}());
