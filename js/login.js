/**
 * Interacciones y Validación de la Pantalla de Login con Google Authenticator (2FA)
 * UTP Cloud - Hardened Security (TOTP RFC 6238)
 * Compatible con vista local (.html) y backend de producción (.php)
 * Protecciones: Anti-Replay, Anti-Brute-Force (3 intentos), Anti-Timing, Barrera Anti-Bypass
 */

document.addEventListener('DOMContentLoaded', () => {
  // Elementos DOM
  const toggleBtn = document.getElementById('togglePassword');
  const passwordInput = document.getElementById('passwordInput');
  const usernameInput = document.getElementById('usernameInput');
  const loginForm = document.getElementById('loginForm');
  const alertBox = document.getElementById('alertBox');
  const submitBtn = document.getElementById('submitBtn');

  const step1Credentials = document.getElementById('step1Credentials');
  const step2TOTP = document.getElementById('step2TOTP');
  const panelTitle = document.getElementById('panelTitle');
  const panelSubtitle = document.getElementById('panelSubtitle');

  const previewAvatar = document.getElementById('previewAvatar');
  const previewName = document.getElementById('previewName');
  const previewRole = document.getElementById('previewRole');
  const totpInput = document.getElementById('totpInput');
  const verifyTotpBtn = document.getElementById('verifyTotpBtn');
  const backToStep1Btn = document.getElementById('backToStep1Btn');

  // Modal 2FA
  const modal2FASetup = document.getElementById('modal2FASetup');
  const openSetupModalBtn = document.getElementById('openSetupModalBtn');
  const closeModalBtn = document.getElementById('closeModalBtn');
  const doneModalBtn = document.getElementById('doneModalBtn');
  const qrImage = document.getElementById('qrImage');
  const secretDisplay = document.getElementById('secretDisplay');
  const copySecretBtn = document.getElementById('copySecretBtn');
  const copyText = document.getElementById('copyText');

  // Determinar si estamos en modo local (sin backend PHP activo)
  const isLocalMode = !loginForm || !loginForm.getAttribute('action') || window.location.protocol === 'file:';

  // Datos ficticios de demostración exclusivos para pruebas locales fuera de línea (sin backend PHP)
  // En producción, toda la autenticación la valida auth.php de forma criptográfica en el servidor
  const users = isLocalMode ? {
    'demo': {
      name: 'Usuario Demo',
      role: 'Desarrollador de Pruebas',
      initials: 'UD',
      password: 'DemoPassword2026!',
      secret: 'DEMO32SECRETTEST'
    }
  } : {};

  let currentUserKey = null;

  // Sanitización contra inyección de HTML / XSS
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Comparación en tiempo constante contra ataques de temporización (Timing Attacks)
  function constantTimeCompare(a, b) {
    if (typeof a !== 'string' || typeof b !== 'string') return false;
    if (a.length !== b.length) return false;
    let result = 0;
    for (let i = 0; i < a.length; i++) {
      result |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return result === 0;
  }

  // =========================================================================
  // Algoritmo Criptográfico TOTP RFC 6238 en JS Puro (Sin dependencias externas)
  // =========================================================================
  function sha1(bytes) {
    function rotl(n, s) { return (n << s) | (n >>> (32 - s)); }
    const len = bytes.length;
    const words = [];
    for (let i = 0; i < len; i++) {
      words[i >> 2] |= bytes[i] << (24 - (i % 4) * 8);
    }
    words[len >> 2] |= 0x80 << (24 - (len % 4) * 8);
    const wordCount = (((len + 8) >> 6) + 1) * 16;
    words[wordCount - 1] = (len * 8) & 0xffffffff;
    words[wordCount - 2] = Math.floor((len * 8) / 0x100000000);

    let h0 = 0x67452301, h1 = 0xefcdab89, h2 = 0x98badcfe, h3 = 0x10325476, h4 = 0xc3d2e1f0;
    const w = new Int32Array(80);

    for (let i = 0; i < wordCount; i += 16) {
      for (let j = 0; j < 16; j++) w[j] = words[i + j] | 0;
      for (let j = 16; j < 80; j++) w[j] = rotl(w[j - 3] ^ w[j - 8] ^ w[j - 14] ^ w[j - 16], 1);

      let a = h0, b = h1, c = h2, d = h3, e = h4;
      for (let j = 0; j < 80; j++) {
        let f, k;
        if (j < 20) { f = (b & c) | ((~b) & d); k = 0x5a827999; }
        else if (j < 40) { f = b ^ c ^ d; k = 0x6ed9eba1; }
        else if (j < 60) { f = (b & c) | (b & d) | (c & d); k = 0x8f1bbcdc; }
        else { f = b ^ c ^ d; k = 0xca62c1d6; }
        const temp = (rotl(a, 5) + f + e + k + w[j]) | 0;
        e = d; d = c; c = rotl(b, 30); b = a; a = temp;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0; h4 = (h4 + e) | 0;
    }
    const res = new Uint8Array(20);
    const hs = [h0, h1, h2, h3, h4];
    for (let i = 0; i < 5; i++) {
      res[i * 4] = (hs[i] >>> 24) & 0xff;
      res[i * 4 + 1] = (hs[i] >>> 16) & 0xff;
      res[i * 4 + 2] = (hs[i] >>> 8) & 0xff;
      res[i * 4 + 3] = hs[i] & 0xff;
    }
    return res;
  }

  function hmacSha1(key, message) {
    const blockSize = 64;
    let k = new Uint8Array(key);
    if (k.length > blockSize) k = sha1(k);
    const paddedKey = new Uint8Array(blockSize);
    paddedKey.set(k);

    const oKeyPad = new Uint8Array(blockSize);
    const iKeyPad = new Uint8Array(blockSize);
    for (let i = 0; i < blockSize; i++) {
      oKeyPad[i] = paddedKey[i] ^ 0x5c;
      iKeyPad[i] = paddedKey[i] ^ 0x36;
    }

    const innerMsg = new Uint8Array(blockSize + message.length);
    innerMsg.set(iKeyPad, 0);
    innerMsg.set(message, blockSize);
    const innerHash = sha1(innerMsg);

    const outerMsg = new Uint8Array(blockSize + 20);
    outerMsg.set(oKeyPad, 0);
    outerMsg.set(innerHash, blockSize);
    return sha1(outerMsg);
  }

  function base32Decode(base32) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let clean = base32.toUpperCase().replace(/=+$/, '');
    let bits = 0, value = 0;
    const bytes = [];
    for (let i = 0; i < clean.length; i++) {
      const val = alphabet.indexOf(clean[i]);
      if (val === -1) continue;
      value = (value << 5) | val;
      bits += 5;
      if (bits >= 8) {
        bytes.push((value >>> (bits - 8)) & 255);
        bits -= 8;
      }
    }
    return new Uint8Array(bytes);
  }

  function generateTOTP(secret, timeSec) {
    if (!timeSec) timeSec = Math.floor(Date.now() / 1000);
    const key = base32Decode(secret);
    const counter = Math.floor(timeSec / 30);
    const msg = new Uint8Array(8);
    const high = Math.floor(counter / 0x100000000);
    msg[0] = (high >>> 24) & 0xff; msg[1] = (high >>> 16) & 0xff;
    msg[2] = (high >>> 8) & 0xff;  msg[3] = high & 0xff;
    msg[4] = (counter >>> 24) & 0xff; msg[5] = (counter >>> 16) & 0xff;
    msg[6] = (counter >>> 8) & 0xff;  msg[7] = counter & 0xff;

    const h = hmacSha1(key, msg);
    const offset = h[19] & 0x0f;
    const binary = ((h[offset] & 0x7f) << 24) |
                   ((h[offset + 1] & 0xff) << 16) |
                   ((h[offset + 2] & 0xff) << 8) |
                   (h[offset + 3] & 0xff);
    return (binary % 1000000).toString().padStart(6, '0');
  }

  /**
   * Valida el código TOTP devolviendo el timeSlice para Anti-Replay
   */
  function verifyTOTP(secret, inputCode, windowSteps = 1) {
    const now = Math.floor(Date.now() / 1000);
    const step = 30;
    const cleanCode = (inputCode || '').trim();
    for (let i = -windowSteps; i <= windowSteps; i++) {
      const sliceTime = now + (i * step);
      const expected = generateTOTP(secret, sliceTime);
      if (constantTimeCompare(expected, cleanCode)) {
        return Math.floor(sliceTime / 30); // Retorna el timeSlice exacto validado
      }
    }
    return false;
  }

  // Alternar visualización de contraseña
  if (toggleBtn && passwordInput) {
    toggleBtn.addEventListener('click', () => {
      const isPass = passwordInput.type === 'password';
      passwordInput.type = isPass ? 'text' : 'password';
      toggleBtn.innerHTML = isPass
        ? `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>`
        : `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>`;
    });
  }

  // Rate Limiting local y gestión de bloqueos
  let failedAttempts = parseInt(sessionStorage.getItem('local_login_fails') || '0', 10);
  let totpFails = parseInt(sessionStorage.getItem('local_totp_fails') || '0', 10);
  let lockUntil = parseInt(sessionStorage.getItem('local_login_lock') || '0', 10);
  let usedTimeSlices = JSON.parse(sessionStorage.getItem('utp_used_totp_slices') || '[]');

  function checkLockout() {
    const now = Date.now();
    if (lockUntil > now) {
      const remainingSecs = Math.ceil((lockUntil - now) / 1000);
      if (submitBtn) submitBtn.disabled = true;
      if (verifyTotpBtn) verifyTotpBtn.disabled = true;
      showAlert(`Demasiados intentos fallidos. Acceso bloqueado por seguridad: espera ${remainingSecs}s.`, 'error');
      setTimeout(checkLockout, 1000);
      return true;
    } else {
      if (submitBtn) submitBtn.disabled = false;
      if (verifyTotpBtn) verifyTotpBtn.disabled = false;
      return false;
    }
  }

  if (isLocalMode) {
    checkLockout();
  }

  function showAlert(msg, type) {
    if (!alertBox) return;
    alertBox.style.display = 'block';
    alertBox.className = `alert-box alert-${type}`;
    alertBox.innerHTML = msg;
    if (window.parseAppleEmojis) {
      window.parseAppleEmojis(alertBox);
    }
  }

  function hideAlert() {
    if (alertBox) alertBox.style.display = 'none';
  }

  // Leer parámetros de URL para reportar bloqueos o logout seguro
  const urlParams = new URLSearchParams(window.location.search);
  const errorParam = urlParams.get('error');
  const logoutParam = urlParams.get('logout');

  if (errorParam === 'unauthorized_2fa') {
    showAlert('🛡️ <strong>Acceso bloqueado:</strong> El uso de Google Authenticator es 100% obligatorio para acceder al panel.', 'error');
  } else if (errorParam === 'totp_replayed') {
    showAlert('⚠️ <strong>Ataque de repetición bloqueado:</strong> Este código TOTP ya fue utilizado. Espera al siguiente código en tu app.', 'error');
  } else if (errorParam === 'totp_lockout') {
    showAlert('🚫 <strong>Bloqueo de seguridad:</strong> 3 intentos fallidos de 2FA. Tu cuenta ha sido bloqueada temporalmente.', 'error');
  } else if (logoutParam === '1') {
    showAlert(`
      <div style="display:flex; flex-direction:column; gap:5px; text-align:center;">
        <span class="alert-title">✓ Sesión cerrada exitosamente.</span>
        <span class="alert-desc">Redirigiendo al login principal en <strong id="logoutTimerCount" class="alert-counter">5</strong> segundos...</span>
        <div class="alert-progress-track" style="width:100%; height:4px; border-radius:99px; overflow:hidden; margin-top:3px;">
          <div id="logoutProgressBar" class="alert-progress-bar" style="width:100%; height:100%; transition:width 1s linear;"></div>
        </div>
      </div>
    `, 'success');

    let seconds = 5;
    const counter = document.getElementById('logoutTimerCount');
    const bar = document.getElementById('logoutProgressBar');
    const interval = setInterval(() => {
      seconds--;
      if (counter) counter.textContent = seconds;
      if (bar) bar.style.width = (seconds * 20) + '%';
      if (seconds <= 0) {
        clearInterval(interval);
        window.location.replace(window.location.pathname);
      }
    }, 1000);

    const cancelRedirect = () => clearInterval(interval);
    if (usernameInput) {
      usernameInput.addEventListener('focus', cancelRedirect);
      usernameInput.addEventListener('input', cancelRedirect);
    }
    if (passwordInput) {
      passwordInput.addEventListener('focus', cancelRedirect);
      passwordInput.addEventListener('input', cancelRedirect);
    }
  }

  // =========================================================================
  // PASO 1: Validación de Credenciales (Modo Prototipo Local)
  // =========================================================================
  if (loginForm && isLocalMode) {
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (checkLockout()) return;

      const rawUser = usernameInput ? usernameInput.value.trim() : '';
      const rawPass = passwordInput ? passwordInput.value : '';
      const userKey = rawUser.toLowerCase();

      if (submitBtn) {
        submitBtn.disabled = true;
        const originalSubmitText = submitBtn.innerHTML;
        submitBtn.innerHTML = `<span>Validando credenciales...</span>`;

        setTimeout(() => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalSubmitText;

          if (users[userKey] && constantTimeCompare(users[userKey].password, rawPass)) {
            // Credenciales válidas -> Transición al Paso 2 (2FA OBLIGATORIO)
            currentUserKey = userKey;
            totpFails = 0;
            sessionStorage.removeItem('local_totp_fails');
            const userObj = users[userKey];

            hideAlert();
            transitionToStep2(userObj);
          } else {
            // Credenciales incorrectas
            failedAttempts++;
            sessionStorage.setItem('local_login_fails', failedAttempts.toString());
            if (failedAttempts >= 5) {
              lockUntil = Date.now() + 30000;
              sessionStorage.setItem('local_login_lock', lockUntil.toString());
              checkLockout();
            } else {
              showAlert(`Usuario o contraseña incorrectos (${failedAttempts}/5 intentos).`, 'error');
            }
          }
        }, 300);
      }
    });
  }

  function transitionToStep2(userObj) {
    if (step1Credentials) step1Credentials.classList.add('step-hidden');
    if (step2TOTP) step2TOTP.classList.remove('step-hidden');

    if (panelTitle) panelTitle.textContent = 'Verificación 2FA';
    panelSubtitle.textContent = 'Introduce el código de 6 dígitos que muestra tu app Google Authenticator.';

    if (previewAvatar) previewAvatar.textContent = userObj.initials;
    if (previewName) previewName.textContent = userObj.name;
    if (previewRole) previewRole.textContent = userObj.role;

    // Configurar Modal QR local seguro para este usuario (sin peticiones a terceros)
    const otpUri = `otpauth://totp/UTP%20Cloud:${encodeURIComponent(userObj.name)}?secret=${userObj.secret}&issuer=UTP%20Cloud`;
    const qrBox = document.getElementById('qrLocalBox') || document.getElementById('qrImage');
    if (qrBox && typeof QRCode !== 'undefined') {
      qrBox.innerHTML = '';
      try {
        new QRCode(qrBox, {
          text: otpUri,
          width: 180,
          height: 180,
          colorDark: "#0f172a",
          colorLight: "#ffffff",
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {}
    }
    
    // Formatear clave secreta con espacios para legibilidad
    if (secretDisplay) {
      const formattedSecret = userObj.secret.match(/.{1,4}/g).join(' ');
      secretDisplay.textContent = formattedSecret;
      secretDisplay.setAttribute('data-raw', userObj.secret);
    }

    if (totpInput) {
      totpInput.value = '';
      totpInput.focus();
    }
  }

  // Regresar al Paso 1 (Modo Local)
  if (backToStep1Btn && isLocalMode) {
    backToStep1Btn.addEventListener('click', () => {
      if (step2TOTP) step2TOTP.classList.add('step-hidden');
      if (step1Credentials) step1Credentials.classList.remove('step-hidden');

      if (panelTitle) panelTitle.textContent = 'Iniciar Sesión';
      panelSubtitle.textContent = 'Ingresa tus credenciales para acceder a tus proyectos web.';

      currentUserKey = null;
      totpFails = 0;
      sessionStorage.removeItem('local_totp_fails');
      if (totpInput) totpInput.value = '';
      hideAlert();
      if (usernameInput) usernameInput.focus();
    });
  }

  // =========================================================================
  // PASO 2: Entrada y Verificación Obligatoria de Código TOTP de 6 Dígitos
  // =========================================================================
  if (totpInput) {
    // Formatear solo números y longitud 6
    totpInput.addEventListener('input', () => {
      totpInput.value = totpInput.value.replace(/\D/g, '').slice(0, 6);
      if (totpInput.value.length === 6) {
        if (isLocalMode) {
          verify2FACodeLocal();
        } else {
          const form = totpInput.closest('form');
          if (form) form.submit();
        }
      }
    });

    totpInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (isLocalMode) {
          verify2FACodeLocal();
        } else {
          const form = totpInput.closest('form');
          if (form) form.submit();
        }
      }
    });
  }

  if (verifyTotpBtn && isLocalMode) {
    verifyTotpBtn.addEventListener('click', verify2FACodeLocal);
  }

  function verify2FACodeLocal() {
    if (checkLockout()) return;
    if (!currentUserKey || !users[currentUserKey]) return;

    const code = totpInput.value.trim();
    if (code.length !== 6) {
      showAlert('Por favor ingresa los 6 dígitos de tu código de seguridad.', 'error');
      totpInput.focus();
      return;
    }

    verifyTotpBtn.disabled = true;
    const originalBtnText = verifyTotpBtn.innerHTML;
    verifyTotpBtn.innerHTML = `<span>Verificando código 2FA...</span>`;

    setTimeout(() => {
      verifyTotpBtn.disabled = false;
      verifyTotpBtn.innerHTML = originalBtnText;

      const userObj = users[currentUserKey];
      const matchedSlice = verifyTOTP(userObj.secret, code, 1);

      if (matchedSlice !== false) {
        // PREVENCIÓN DE ATAQUE DE REPETICIÓN (ANTI-REPLAY)
        if (usedTimeSlices.includes(matchedSlice)) {
          showAlert('⚠️ <strong>Ataque de repetición neutralizado:</strong> Este código TOTP ya fue utilizado. Espera a que Google Authenticator genere uno nuevo.', 'error');
          totpInput.select();
          return;
        }

        // Registrar timeSlice consumido
        usedTimeSlices.push(matchedSlice);
        if (usedTimeSlices.length > 10) usedTimeSlices.shift();
        sessionStorage.setItem('utp_used_totp_slices', JSON.stringify(usedTimeSlices));

        // Limpiar contadores de error
        sessionStorage.removeItem('local_login_fails');
        sessionStorage.removeItem('local_login_lock');
        sessionStorage.removeItem('local_totp_fails');

        // BARRERA DE SEGURIDAD 2FA: EXPEDICIÓN DE CREDENCIAL CRIPTOGRÁFICA
        sessionStorage.setItem('utp_2fa_verified_user', currentUserKey);
        sessionStorage.setItem('utp_2fa_token', crypto.randomUUID ? crypto.randomUUID() : (Math.random().toString(36).slice(2) + Date.now().toString(36)));
        sessionStorage.setItem('utp_2fa_time', Date.now().toString());

        showAlert(`✓ Doble factor verificado. ¡Bienvenido <strong>${escapeHtml(userObj.name)}</strong>!`, 'success');

        setTimeout(() => {
          if (window.location.protocol === 'file:' || window.location.pathname.endsWith('.html')) {
            window.location.href = `dashboard.html?user=${encodeURIComponent(currentUserKey)}`;
          } else {
            window.location.href = 'dashboard.php';
          }
        }, 500);
      } else {
        // Código incorrecto: Defensa contra Fuerza Bruta en 2FA (Máximo 3 fallos)
        totpFails++;
        sessionStorage.setItem('local_totp_fails', totpFails.toString());

        if (totpFails >= 3) {
          // Bloqueo estricto tras 3 intentos fallidos de 2FA
          sessionStorage.removeItem('local_totp_fails');
          lockUntil = Date.now() + 60000; // Bloqueo de 60 segundos
          sessionStorage.setItem('local_login_lock', lockUntil.toString());

          // Expulsar al Paso 1
          if (step2TOTP) step2TOTP.classList.add('step-hidden');
          if (step1Credentials) step1Credentials.classList.remove('step-hidden');
          currentUserKey = null;

          checkLockout();
          showAlert('🚫 <strong>Bloqueo de seguridad:</strong> 3 códigos 2FA incorrectos. Tu acceso ha sido bloqueado por 60 segundos.', 'error');
        } else {
          showAlert(`Código 2FA incorrecto o expirado (${totpFails}/3 intentos permitidos).`, 'error');
          totpInput.select();
        }
      }
    }, 300);
  }

  // =========================================================================
  // Control del Modal de Vinculación (QR y Clave Secreta)
  // =========================================================================
  if (openSetupModalBtn && modal2FASetup) {
    openSetupModalBtn.addEventListener('click', () => {
      modal2FASetup.classList.add('active');
    });
  }

  function closeModal() {
    if (modal2FASetup) modal2FASetup.classList.remove('active');
  }

  if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
  if (doneModalBtn) doneModalBtn.addEventListener('click', closeModal);

  if (modal2FASetup) {
    modal2FASetup.addEventListener('click', (e) => {
      if (e.target === modal2FASetup) closeModal();
    });
  }

  // Copiar clave secreta al portapapeles
  if (copySecretBtn && secretDisplay) {
    copySecretBtn.addEventListener('click', () => {
      const rawSecret = secretDisplay.getAttribute('data-raw') || secretDisplay.textContent.replace(/\s/g, '');
      navigator.clipboard.writeText(rawSecret).then(() => {
        if (copyText) copyText.textContent = '¡Copiado!';
        copySecretBtn.style.background = '#10b981';
        copySecretBtn.style.color = '#ffffff';
        copySecretBtn.style.borderColor = '#059669';

        setTimeout(() => {
          if (copyText) copyText.textContent = 'Copiar';
          copySecretBtn.style.background = '';
          copySecretBtn.style.color = '';
          copySecretBtn.style.borderColor = '';
        }, 2000);
      }).catch(() => {
        showAlert('No se pudo copiar automáticamente. Puedes copiar la clave manualmente.', 'error');
      });
    });
  }
});
