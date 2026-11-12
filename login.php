<?php
// Configuración estricta de cookies de sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);

// DETECCIÓN Y FORZADO ESTRICTO DE HTTPS EN PRODUCCIÓN
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (!$isHttps && !empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false) {
    $redirectUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: $redirectUrl");
    exit;
}

if ($isHttps) {
    ini_set('session.cookie_secure', 1);
}

session_start();

// Si ya está autenticado completamente con 2FA, redirigir al dashboard
if (isset($_SESSION['usuario']) && !empty($_SESSION['2fa_verified']) && $_SESSION['2fa_verified'] === true) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/2fa_helper.php';

// Catálogo de usuarios para visualización en el login
$usuarios_meta = get_totp_catalog();

// Generación segura de Token Anti-CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error_msg = '';
$success_msg = '';

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'rate_limited') {
        $error_msg = 'Demasiados intentos fallidos. Por seguridad, espera 30 segundos.';
    } elseif ($_GET['error'] === 'csrf') {
        $error_msg = 'Solicitud no válida (token caducado). Intenta de nuevo.';
    } elseif ($_GET['error'] === 'unauthorized_2fa') {
        $error_msg = '🛡️ Acceso bloqueado: El uso de Google Authenticator es 100% obligatorio para ingresar al panel.';
    } elseif ($_GET['error'] === 'totp_replayed') {
        $error_msg = '⚠️ Ataque de repetición neutralizado: Este código ya fue usado. Espera al siguiente en tu aplicación.';
    } elseif ($_GET['error'] === 'totp_lockout') {
        $error_msg = '🚫 Bloqueo de seguridad: 3 intentos incorrectos de 2FA. Tu cuenta ha sido bloqueada temporalmente por 60 segundos.';
    } elseif ($_GET['error'] === 'totp_invalid') {
        $error_msg = 'Código de Google Authenticator incorrecto o expirado.';
    } elseif ($_GET['error'] === 'totp_format') {
        $error_msg = 'El código debe contener exactamente 6 dígitos numéricos.';
    } elseif ($_GET['error'] === 'session_expired') {
        $error_msg = 'Tu sesión ha expirado por inactividad. Por favor, ingresa de nuevo.';
    } else {
        $error_msg = 'Usuario o contraseña incorrectos.';
    }
} elseif (isset($_GET['logout'])) {
    $success_msg = 'Sesión cerrada exitosamente. Tokens de seguridad revocados.';
}

$pending_user = $_SESSION['2fa_pending_user'] ?? null;
$is_2fa_step = (!empty($pending_user) && isset($usuarios_meta[$pending_user]));
$active_meta = $is_2fa_step ? $usuarios_meta[$pending_user] : null;

// Detección de estado de enrolamiento:
// Si el usuario ya lo hizo (ej. Tenant A), no se le muestra QR, solo el código directo.
// Si nunca lo ha hecho (ej. Tenant B o Tenant C en primer acceso), se le muestra el QR para vincular.
$is_enrolled = ($is_2fa_step && is_user_2fa_enrolled($pending_user));
$enroll_otp_uri = (!$is_enrolled && $is_2fa_step) ? get_user_otp_uri($pending_user) : '';
$enroll_secret_raw = (!$is_enrolled && $is_2fa_step) ? get_user_secret($pending_user) : '';
$enroll_secret_formatted = (!$is_enrolled && $is_2fa_step) ? implode(' ', str_split($enroll_secret_raw, 4)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <script src="js/theme-init.js"></script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <link rel="icon" type="image/png" href="assets/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="stylesheet" href="css/style.css">
  <!-- Renderizador local de emojis -->
  <script src="js/twemoji.min.js"></script>
  <style>
    img.emoji {
      height: 1.2em;
      width: 1.2em;
      margin: 0 0.1em 0 0.1em;
      vertical-align: -0.18em;
      display: inline-block;
      object-fit: contain;
      image-rendering: -webkit-optimize-contrast;
      pointer-events: none;
      user-select: none;
    }
  </style>
</head>
<body>
  <!-- Selector de Tema en Login -->
  <button id="themeToggleBtn" style="position:fixed; top:20px; right:20px; z-index:100; border-radius:999px; padding:8px 14px; font-size:12px; font-weight:600; display:flex; align-items:center; gap:6px; cursor:pointer; transition:all 0.2s; border:1px solid var(--border-card); background:var(--bg-card); color:var(--text-dark); box-shadow:0 4px 14px rgba(0,0,0,0.18);" title="Cambiar tema claro/oscuro">
    <span id="themeToggleIcon">🌙</span>
    <span id="themeToggleText">Oscuro</span>
  </button>


  <!-- Luces ambientales de fondo -->
  <div class="ambient-orb orb-1"></div>
  <div class="ambient-orb orb-2"></div>

  <!-- Tarjeta Principal Dividida -->
  <div class="login-card">

    <!-- Sección Izquierda: Formulario -->
    <div class="form-section">
      <div>
        <div class="brand-badge">
          <img src="assets/logo.png" alt="Logo" class="brand-logo">
        </div>

        <?php if ($is_2fa_step): ?>
          <h1 class="form-title">Verificación 2FA</h1>
          <p class="form-subtitle">Introduce el código de 6 dígitos que muestra tu app Google Authenticator.</p>
        <?php else: ?>
          <h1 class="form-title">Iniciar Sesión</h1>
          <p class="form-subtitle">Ingresa tus credenciales para acceder a tus proyectos web.</p>
        <?php endif; ?>

        <!-- Cuadro de Alertas PHP perfectamente integrado con el flujo del encabezado -->
        <?php if (isset($_GET['logout'])): ?>
          <div class="alert-box alert-success" id="logoutAlertBox" style="display:flex; flex-direction:column; gap:5px; margin-top:14px;">
            <div class="alert-title" style="display:flex; align-items:center; justify-content:center; gap:6px;">
              <span>✓ Sesión cerrada exitosamente.</span>
            </div>
            <div class="alert-desc">
              Redirigiendo al login principal en <strong id="logoutTimerCount" class="alert-counter">5</strong> segundos...
            </div>
            <div class="alert-progress-track" style="width:100%; height:4px; border-radius:99px; overflow:hidden; margin-top:3px;">
              <div id="logoutProgressBar" class="alert-progress-bar" style="width:100%; height:100%; transition:width 1s linear;"></div>
            </div>
          </div>
        <?php elseif (!empty($error_msg)): ?>
          <div class="alert-box alert-error" style="display:block; margin-top:14px;">
            <span><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        <?php elseif (!empty($success_msg)): ?>
          <div class="alert-box alert-success" style="display:block; margin-top:14px;">
            <span class="alert-title"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($is_2fa_step): ?>
        <!-- ================= PASO 2: FORMULARIO GOOGLE AUTHENTICATOR (PHP) ================= -->
        <div class="user-preview-card">
          <div class="user-avatar-badge"><?php echo htmlspecialchars($active_meta['initials'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="user-info-text">
            <h4><?php echo htmlspecialchars($active_meta['nombre'], ENT_QUOTES, 'UTF-8'); ?></h4>
            <p><?php echo htmlspecialchars($active_meta['rol'], ENT_QUOTES, 'UTF-8'); ?></p>
          </div>
        </div>

        <form action="includes/auth.php" method="POST" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="totp">

          <div class="form-group">
            <label class="form-label" for="totpInput">Código de Google Authenticator</label>
            <div class="totp-input-wrapper">
              <input 
                type="text" 
                name="totp_code" 
                id="totpInput" 
                class="totp-input" 
                placeholder="••••••" 
                maxlength="6"
                inputmode="numeric"
                pattern="[0-9]{6}"
                required
                autofocus
                autocomplete="one-time-code"
              >
            </div>
          </div>

          <button type="submit" class="btn-primary">
            <span>Verificar e Ingresar al Panel</span>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </button>
        </form>

        <?php if (!$is_enrolled): ?>
          <!-- Botón/enlace discreto para abrir modal de QR ÚNICAMENTE si no ha completado su vinculación previa -->
          <div class="form-link-wrapper" style="text-align:center; margin-top:14px;">
            <button type="button" id="openSetupModalBtn" class="btn-link">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
              </svg>
              <span>¿Vincular Google Authenticator con QR?</span>
            </button>
          </div>
        <?php endif; ?>

        <!-- Formulario para cancelar 2FA y cambiar de usuario -->
        <form action="includes/auth.php" method="POST" style="margin-top: 10px;">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="cancel_2fa">
          <button type="submit" class="btn-secondary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Cambiar de cuenta</span>
          </button>
        </form>

      <?php else: ?>
        <!-- ================= PASO 1: FORMULARIO CREDENCIALES (PHP) ================= -->
        <form action="includes/auth.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="credentials">

          <!-- Campo Usuario -->
          <div class="form-group">
            <label class="form-label" for="usernameInput">Usuario</label>
            <div class="input-wrapper">
              <input 
                type="text" 
                name="usuario" 
                id="usernameInput" 
                class="form-input" 
                placeholder="Introduce tu usuario" 
                required 
                maxlength="32"
                autocomplete="username"
              >
              <div class="input-icon">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
            </div>
          </div>

          <!-- Campo Contraseña -->
          <div class="form-group">
            <label class="form-label" for="passwordInput">Contraseña</label>
            <div class="input-wrapper">
              <input 
                type="password" 
                name="password" 
                id="passwordInput" 
                class="form-input" 
                placeholder="••••••••••••" 
                required 
                maxlength="128"
                autocomplete="current-password"
              >
              <button type="button" id="togglePassword" class="toggle-pass-btn" title="Mostrar/Ocultar contraseña">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Opciones extras -->
          <div class="form-options">
            <label class="remember-me">
              <input type="checkbox" name="recordar" checked class="checkbox-custom">
              <span>Recordar sesión</span>
            </label>
          </div>

          <!-- Botón de Envío -->
          <button type="submit" id="submitBtn" class="btn-primary">
            <span>Continuar a Verificación</span>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
          </button>
        </form>
      <?php endif; ?>

      <!-- Pie de página -->
      <div class="form-footer" style="justify-content: center; text-align: center;">
        <span>Hecho por: Admin User</span>
      </div>
    </div>

    <!-- Sección Derecha: GIF Animado -->
    <div class="media-section">
      <div class="media-container">
        <img src="assets/gif.gif" alt="Animación" class="media-gif">
      </div>
    </div>

  </div>

  <script src="js/login.js"></script>

  <?php if ($is_2fa_step && !$is_enrolled): ?>
  <!-- ================= MODAL VINCULACIÓN GOOGLE AUTHENTICATOR ================= -->
  <div id="modal2FASetup" class="modal-overlay">
    <div class="modal-dialog">
      <div class="modal-header">
        <div class="modal-title">
          <svg width="22" height="22" fill="none" stroke="#2563eb" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
          </svg>
          <span>Vincular Google Authenticator</span>
        </div>
        <button type="button" id="closeModalBtn" class="modal-close-btn" title="Cerrar">✕</button>
      </div>

      <ol class="setup-steps">
        <li>Abre la aplicación <strong>Google Authenticator</strong> en tu teléfono.</li>
        <li>Toca el botón <strong>+</strong> y pulsa <strong>Escanear código QR</strong>.</li>
        <li>O ingresa la clave secreta manualmente si no puedes usar la cámara.</li>
      </ol>

      <div class="qr-wrapper">
        <div id="setupQrBox" style="background:#ffffff; padding:10px; border-radius:12px; display:flex; justify-content:center; align-items:center; min-width:180px; min-height:180px; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>
        <div class="secret-box">
          <span id="secretDisplay" data-raw="<?php echo htmlspecialchars($enroll_secret_raw, ENT_QUOTES, 'UTF-8'); ?>" class="secret-code"><?php echo htmlspecialchars($enroll_secret_formatted, ENT_QUOTES, 'UTF-8'); ?></span>
          <button type="button" id="copySecretBtn" class="btn-copy" title="Copiar clave secreta">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
            </svg>
            <span id="copyText">Copiar</span>
          </button>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="doneModalBtn" class="btn-primary">
          <span>Listo, tengo mi código</span>
        </button>
      </div>
    </div>
  </div>

  <script src="js/qrcode.min.js"></script>
  <?php endif; ?>

  <script id="loginPageConfig" type="application/json"><?php echo json_encode([
      'logoutRedirect' => isset($_GET['logout']),
      'enrollOtpUri'   => ($is_2fa_step && !$is_enrolled) ? $enroll_otp_uri : null,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
  <script src="js/login-page.js"></script>

</body>
</html>
