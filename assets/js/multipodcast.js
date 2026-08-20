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

  function initMultipodcastWarning() {
    var enabledCheckbox = document.getElementById('multipodcast_enabled');
    var enabledWarning = document.querySelector('[data-multipodcast-enabled-warning]');
    var disabledWarning = document.querySelector('[data-multipodcast-disabled-warning]');

    if (!enabledCheckbox || !enabledWarning || !disabledWarning) {
      return;
    }

    function updateWarning() {
      enabledWarning.hidden = !enabledCheckbox.checked;
      disabledWarning.hidden = enabledCheckbox.checked;
    }

    enabledCheckbox.addEventListener('change', updateWarning);
    updateWarning();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initSummaryHeroSettings();
      initMultipodcastWarning();
    });
  } else {
    initSummaryHeroSettings();
    initMultipodcastWarning();
  }
}());
