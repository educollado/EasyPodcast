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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCategoryPicker);
  } else {
    initCategoryPicker();
  }
}());
