(function () {
  function initSummaryHeroSettings() {
    var homepageModes = document.querySelectorAll('input[name="homepage_mode"]');
    var homepageSelect = document.getElementById('homepage_podcast_id');
    var podcastSettings = document.querySelector('[data-homepage-podcast-settings]');
    var heroSettings = document.querySelector('[data-summary-hero-settings]');

    if (!homepageModes.length || !homepageSelect || !podcastSettings || !heroSettings) {
      return;
    }

    function updateVisibility() {
      var selectedMode = document.querySelector('input[name="homepage_mode"]:checked');
      var showsPodcast = selectedMode && selectedMode.value === 'podcast';
      podcastSettings.hidden = !showsPodcast;
      homepageSelect.required = Boolean(showsPodcast);
      heroSettings.hidden = Boolean(showsPodcast);
    }

    homepageModes.forEach(function (mode) {
      mode.addEventListener('change', updateVisibility);
    });
    updateVisibility();
  }

  function initMultipodcastWarning() {
    var enabledCheckbox = document.getElementById('multipodcast_enabled');
    var warning = document.querySelector('[data-multipodcast-warning]');
    var enabledWarning = document.querySelector('[data-multipodcast-enabled-warning]');
    var disabledWarning = document.querySelector('[data-multipodcast-disabled-warning]');
    var enableSettings = document.querySelector('[data-multipodcast-enable-settings]');
    var disableSettings = document.querySelector('[data-multipodcast-disable-settings]');
    var conversionSlug = document.getElementById('conversion_slug');
    var disableCheckbox = document.querySelector('[data-disable-confirm-checkbox]');
    var disableTitle = document.querySelector('[data-disable-confirm-title]');

    if (!enabledCheckbox || !warning || !enabledWarning || !disabledWarning) {
      return;
    }
    var initiallyEnabled = enabledCheckbox.dataset.initialEnabled === '1';

    function updateWarning() {
      warning.hidden = false;
      enabledWarning.hidden = !enabledCheckbox.checked;
      disabledWarning.hidden = enabledCheckbox.checked;
      if (enableSettings) {
        enableSettings.hidden = initiallyEnabled || !enabledCheckbox.checked;
      }
      if (disableSettings) {
        disableSettings.hidden = !initiallyEnabled || enabledCheckbox.checked;
      }
      if (conversionSlug) {
        conversionSlug.required = !initiallyEnabled && enabledCheckbox.checked;
      }
      if (disableCheckbox) {
        disableCheckbox.required = initiallyEnabled && !enabledCheckbox.checked;
      }
      if (disableTitle) {
        disableTitle.required = initiallyEnabled && !enabledCheckbox.checked;
      }
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
