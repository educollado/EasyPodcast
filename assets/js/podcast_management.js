(function () {
  function initCategoryPicker() {
    var hidden = document.getElementById('category-hidden');
    var chipsEl = document.getElementById('category-chips');
    var select = document.getElementById('category-select');
    var max = 3;

    if (!hidden || !chipsEl || !select) {
      return;
    }

    var selected = hidden.value
      ? hidden.value.split(',').map(function (value) { return value.trim(); }).filter(Boolean)
      : [];

    function removeCategory(category) {
      selected = selected.filter(function (item) {
        return item !== category;
      });
      render();
    }

    function render() {
      chipsEl.innerHTML = '';

      selected.forEach(function (category) {
        var chip = document.createElement('span');
        chip.className = 'chip';
        chip.appendChild(document.createTextNode(category + ' '));

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'chip-remove';
        button.textContent = '×';
        button.setAttribute('aria-label', 'Eliminar ' + category);
        button.addEventListener('click', function () {
          removeCategory(category);
        });

        chip.appendChild(button);
        chipsEl.appendChild(chip);
      });

      hidden.value = selected.join(', ');
      select.disabled = selected.length >= max;
      select.value = '';
    }

    select.addEventListener('change', function () {
      if (!select.value || selected.length >= max) {
        select.value = '';
        return;
      }

      if (selected.indexOf(select.value) === -1) {
        selected.push(select.value);
      }

      render();
    });

    render();
  }

  function initImagePreviews() {
    function showPreview(preview, source) {
      var placeholder = document.getElementById(preview.id.replace('-preview', '-placeholder'));

      if (source === '') {
        preview.removeAttribute('src');
      } else {
        preview.src = source;
      }
      preview.hidden = source === '';
      if (placeholder) {
        placeholder.hidden = source !== '';
      }
    }

    document.querySelectorAll('[data-image-preview]').forEach(function (input) {
      var preview = document.getElementById(input.dataset.imagePreview);
      if (!preview) {
        return;
      }

      if (preview.dataset.errorHandler !== 'true') {
        preview.dataset.errorHandler = 'true';
        preview.addEventListener('error', function () {
          showPreview(preview, '');
        });
      }

      if (input.type === 'file') {
        input.addEventListener('change', function () {
          var file = input.files && input.files[0];
          if (!file || !file.type.startsWith('image/')) {
            return;
          }

          var reader = new FileReader();
          reader.addEventListener('load', function () {
            showPreview(preview, typeof reader.result === 'string' ? reader.result : '');
          });
          reader.readAsDataURL(file);
        });
        return;
      }

      input.addEventListener('change', function () {
        showPreview(preview, input.value.trim());
      });
    });
  }

  function initPage() {
    initCategoryPicker();
    initImagePreviews();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPage);
  } else {
    initPage();
  }
}());
