(function () {
  try {
    var cs = document.currentScript;
    var key = (cs && cs.getAttribute('data-theme-key')) || 'panel_theme';
    var def = (cs && cs.getAttribute('data-theme-default')) || 'light';
    var t = localStorage.getItem(key) || def;
    if (t === 'dark') {
      document.documentElement.classList.add('dark');
      document.documentElement.classList.remove('theme-light');
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.classList.add('theme-light');
    }
  } catch (e) {}
})();
