(function () {
  function trackAudioPlays() {
    document.querySelectorAll('audio.player[data-episode-id]').forEach(function (audio) {
      var tracked = false;

      audio.addEventListener('play', function () {
        if (tracked) {
          return;
        }

        var episodeId = parseInt(audio.dataset.episodeId || '0', 10);
        if (!episodeId) {
          return;
        }

        tracked = true;

        var trackUrl = audio.getAttribute('data-track-url') || '/track.php';
        fetch(trackUrl + '?episode_id=' + encodeURIComponent(episodeId) + '&action=play', {
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        }).catch(function () {});
      });

      audio.addEventListener('loadedmetadata', function () {
        tracked = false;
      });
    });
  }

  function initRevealCards() {
    if (!('IntersectionObserver' in window)) {
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) {
          return;
        }

        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.06 });

    document.querySelectorAll('.reveal').forEach(function (element) {
      element.classList.add('animate-in');
      observer.observe(element);
    });
  }

  function initPublicUi() {
    trackAudioPlays();
    initRevealCards();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPublicUi);
  } else {
    initPublicUi();
  }
}());
