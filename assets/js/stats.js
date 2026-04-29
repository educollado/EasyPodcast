(function () {
  function initStatsTabs() {
    var urlParams = new URLSearchParams(window.location.search);
    var activeTab = urlParams.get('tab') || 'diario';
    var activeTabElement = document.querySelector('.stats-tab[data-tab="' + activeTab + '"]');

    if (activeTabElement) {
      document.querySelectorAll('.stats-tab').forEach(function (tab) {
        tab.classList.remove('active');
      });
      document.querySelectorAll('.stats-panel').forEach(function (panel) {
        panel.classList.remove('active');
      });
      activeTabElement.classList.add('active');
      document.getElementById('tab-' + activeTab).classList.add('active');
    }

    document.querySelectorAll('.stats-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.stats-tab').forEach(function (item) {
          item.classList.remove('active');
        });
        document.querySelectorAll('.stats-panel').forEach(function (panel) {
          panel.classList.remove('active');
        });

        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');

        var nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('tab', tab.dataset.tab);
        window.history.replaceState({}, '', nextUrl);
      });
    });
  }

  function initStatsSorting() {
    document.querySelectorAll('th[data-sort]').forEach(function (header) {
      header.addEventListener('click', function () {
        var table = header.closest('table');
        var tbody = table ? table.querySelector('tbody') : null;
        if (!table || !tbody) {
          return;
        }

        var columnIndex = Array.from(header.parentNode.children).indexOf(header);
        var sortKey = header.dataset.sort || '';
        var currentDir = header.classList.contains('asc')
          ? 'asc'
          : (header.classList.contains('desc') ? 'desc' : '');
        var nextDir = currentDir === 'asc' ? 'desc' : 'asc';
        var direction = nextDir === 'asc' ? 1 : -1;
        var isNumeric = sortKey === 'count' || sortKey === 'year' || sortKey === 'total';
        var rows = Array.from(tbody.querySelectorAll('tr'));

        table.querySelectorAll('th[data-sort]').forEach(function (item) {
          item.classList.remove('asc', 'desc');
        });
        header.classList.add(nextDir);

        rows.sort(function (a, b) {
          var aCell = a.children[columnIndex];
          var bCell = b.children[columnIndex];
          var aValue = (aCell.dataset.sortValue || aCell.textContent || '').trim();
          var bValue = (bCell.dataset.sortValue || bCell.textContent || '').trim();

          if (isNumeric) {
            var aNumber = parseFloat(aValue.replace(/[^0-9.-]/g, '')) || 0;
            var bNumber = parseFloat(bValue.replace(/[^0-9.-]/g, '')) || 0;
            return (aNumber - bNumber) * direction;
          }

          return aValue.localeCompare(bValue, undefined, { numeric: true }) * direction;
        });

        rows.forEach(function (row) {
          tbody.appendChild(row);
        });
      });
    });
  }

  function initStats() {
    initStatsTabs();
    initStatsSorting();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStats);
  } else {
    initStats();
  }
}());
