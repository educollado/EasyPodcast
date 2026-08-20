(function () {
  function initSummaryHeroSettings() {
    var homepageSelect = document.getElementById('homepage_podcast_id');
    var heroSettings = document.querySelector('[data-summary-hero-settings]');

    if (!homepageSelect || !heroSettings) {
      return;
    }

    function updateVisibility() {
      heroSettings.hidden = homepageSelect.value !== '';
    }

    homepageSelect.addEventListener('change', updateVisibility);
    updateVisibility();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSummaryHeroSettings);
  } else {
    initSummaryHeroSettings();
  }
}());
