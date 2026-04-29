(function () {
  var cookiePrefix = 'easypodcast_theme_mode=';
  var value = 'normal';
  var parts = document.cookie ? document.cookie.split('; ') : [];

  for (var i = 0; i < parts.length; i += 1) {
    if (parts[i].indexOf(cookiePrefix) === 0) {
      value = decodeURIComponent(parts[i].slice(cookiePrefix.length));
      break;
    }
  }

  if (value !== 'auto') {
    value = 'normal';
  }

  document.documentElement.setAttribute('data-theme-mode', value);
}());
