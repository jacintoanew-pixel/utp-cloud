(function () {
  var APPLE_FE0F_SET = new Set([
    '26a0', '1f6e1', '2600', '1f5d1', '2699', '2709', '270f', '260e', '2702', '2615', '2764', '26a1', '267b'
  ]);

  function parseAppleEmojis(target) {
    if (typeof target === 'undefined') target = document.body;
    if (typeof twemoji !== 'undefined' && twemoji && twemoji.parse) {
      try {
        twemoji.parse(target, {
          callback: function (icon) {
            var finalIcon = APPLE_FE0F_SET.has(icon) ? (icon + '-fe0f') : icon;
            return 'https://cdn.jsdelivr.net/gh/iamcal/emoji-data@master/img-apple-160/' + finalIcon + '.png';
          },
          onerror: function () {
            if (this.src && !this.src.includes('-fe0f.png')) {
              this.src = this.src.replace('.png', '-fe0f.png');
            } else if (this.parentNode) {
              this.parentNode.replaceChild(document.createTextNode(this.alt), this);
            }
          }
        });
      } catch (e) {}
    }
  }

  window.parseAppleEmojis = parseAppleEmojis;
  window.parseTwemojis = parseAppleEmojis;

  function updateLoginThemeUI(theme) {
    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeToggleIcon');
    var text = document.getElementById('themeToggleText');
    if (!btn || !icon || !text) return;
    if (theme === 'dark') {
      icon.textContent = '🌙';
      text.textContent = 'Oscuro';
      btn.title = 'Cambiar a Tema Claro';
    } else {
      icon.textContent = '☀️';
      text.textContent = 'Claro';
      btn.title = 'Cambiar a Tema Oscuro';
    }
    parseAppleEmojis(icon);
  }

  function applyLoginTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.classList.add('dark');
      document.documentElement.classList.remove('theme-light');
      document.body.classList.add('dark');
      document.body.classList.remove('theme-light');
    } else {
      document.documentElement.classList.remove('dark');
      document.documentElement.classList.add('theme-light');
      document.body.classList.remove('dark');
      document.body.classList.add('theme-light');
    }
    try {
      localStorage.setItem('panel_theme', theme);
    } catch (e) {}
    updateLoginThemeUI(theme);
  }

  function toggleThemeLogin() {
    var isLight = document.documentElement.classList.contains('theme-light');
    applyLoginTheme(isLight ? 'dark' : 'light');
  }

  window.toggleThemeLogin = toggleThemeLogin;

  document.addEventListener('DOMContentLoaded', function () {
    var cfg = {};
    var cfgEl = document.getElementById('loginPageConfig');
    if (cfgEl) {
      try {
        cfg = JSON.parse(cfgEl.textContent || '{}');
      } catch (e) {}
    }

    var savedTheme = 'light';
    try {
      savedTheme = localStorage.getItem('panel_theme') || 'light';
    } catch (e) {}
    applyLoginTheme(savedTheme);
    parseAppleEmojis();

    var themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn) {
      themeBtn.addEventListener('click', toggleThemeLogin);
    }

    if (cfg.logoutRedirect) {
      var seconds = 5;
      var counter = document.getElementById('logoutTimerCount');
      var bar = document.getElementById('logoutProgressBar');
      var interval = setInterval(function () {
        seconds--;
        if (counter) counter.textContent = seconds;
        if (bar) bar.style.width = (seconds * 20) + '%';
        if (seconds <= 0) {
          clearInterval(interval);
          window.location.replace('login.php');
        }
      }, 1000);

      var cancelRedirect = function () { clearInterval(interval); };
      var userInp = document.getElementById('usernameInput') || document.querySelector('input[name="usuario"]');
      var passInp = document.getElementById('passwordInput') || document.querySelector('input[name="password"]');
      if (userInp) {
        userInp.addEventListener('focus', cancelRedirect);
        userInp.addEventListener('input', cancelRedirect);
      }
      if (passInp) {
        passInp.addEventListener('focus', cancelRedirect);
        passInp.addEventListener('input', cancelRedirect);
      }
    }

    if (cfg.enrollOtpUri) {
      var modal = document.getElementById('modal2FASetup');
      var openBtn = document.getElementById('openSetupModalBtn');
      var qrBox = document.getElementById('setupQrBox');
      var qrGenerated = false;

      var renderQrModal = function () {
        if (modal) modal.classList.add('active');
        if (!qrGenerated && qrBox && typeof QRCode !== 'undefined') {
          qrBox.innerHTML = '';
          new QRCode(qrBox, {
            text: cfg.enrollOtpUri,
            width: 180,
            height: 180,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
          });
          qrGenerated = true;
        }
      };

      if (openBtn) {
        openBtn.addEventListener('click', renderQrModal);
      }
      renderQrModal();
    }
  });
})();
