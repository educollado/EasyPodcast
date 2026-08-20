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
    var warning = document.querySelector('[data-multipodcast-warning]');
    var enabledWarning = document.querySelector('[data-multipodcast-enabled-warning]');
    var disabledWarning = document.querySelector('[data-multipodcast-disabled-warning]');

    if (!enabledCheckbox || !warning || !enabledWarning || !disabledWarning) {
      return;
    }

    function updateWarning() {
      warning.hidden = false;
      enabledWarning.hidden = !enabledCheckbox.checked;
      disabledWarning.hidden = enabledCheckbox.checked;
    }

    enabledCheckbox.addEventListener('change', updateWarning);
  }

  function initSummaryThemePreview() {
    var themeSelect = document.querySelector('[data-summary-theme-selector]');
    if (!themeSelect) {
      return;
    }

    function updateTheme() {
      document.documentElement.dataset.theme = themeSelect.value || 'easypodcast';
    }

    themeSelect.addEventListener('change', updateTheme);
    updateTheme();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initSummaryHeroSettings();
      initMultipodcastWarning();
      initSummaryThemePreview();
    });
  } else {
    initSummaryHeroSettings();
    initMultipodcastWarning();
    initSummaryThemePreview();
  }
}());
