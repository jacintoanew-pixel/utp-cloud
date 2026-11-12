<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);

// DETECCIÓN Y FORZADO ESTRICTO DE HTTPS EN PRODUCCIÓN
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (!$isHttps && !empty($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') === false && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false) {
    header("HTTP/1.1 301 Moved Permanently");
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

if ($isHttps) {
    ini_set('session.cookie_secure', 1);
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Content-Security-Policy: upgrade-insecure-requests;');
}

session_start();

// BARRERA DE SEGURIDAD ESTRICTA: 2FA OBLIGATORIO (ANTI-BYPASS)
if (!isset($_SESSION['usuario']) || empty($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    unset($_SESSION['usuario']);
    unset($_SESSION['2fa_verified']);
    header('Location: login.php?error=unauthorized_2fa');
    exit;
}

if (isset($_SESSION['2fa_pending_user'])) {
    header('Location: login.php?step=2fa');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Control de tiempo de inactividad (2 horas máx)
$maxIdleTime = 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleTime)) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=session_expired');
    exit;
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/includes/2fa_helper.php';

$active_session_user = strtolower(preg_replace('/[^a-z0-9_-]/', '', $_SESSION['usuario']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <script src="js/theme-init.js"></script>

  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <title>Dashboard - Espacio de Trabajo Personal</title>
  <link rel="icon" type="image/png" href="assets/logo.png">
  
  <script src="js/tailwindcss.min.js"></script>
  <script src="js/tailwind-config.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=cabinet-grotesk@700,800,900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Martian+Mono:wght@400;500;600;700&family=Sora:wght@300;400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <script src="js/twemoji.min.js"></script>
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body class="bg-[#eef4fe] text-slate-800 p-2 sm:p-6 min-h-screen antialiased flex flex-col justify-between overflow-x-hidden">

  <!-- Contenedor Principal Estilo App SaaS -->
  <div class="saas-container max-w-[1400px] w-full mx-auto bg-[#f8fbff] border border-blue-100/70 rounded-2xl sm:rounded-[32px] p-3.5 sm:p-7 shadow-xl shadow-blue-500/5 transition-colors duration-200">

    <!-- CABECERA DE NAVEGACIÓN -->
    <header class="flex flex-col lg:flex-row items-center justify-between gap-4 pb-6 mb-6 border-b border-slate-200/60">
      
      <!-- Logo y Botones tipo "Pill" -->
      <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
        <div class="logo-box w-11 h-11 rounded-2xl bg-white border border-slate-200 p-1.5 flex items-center justify-center shadow-md shadow-blue-500/5 mr-2">
          <img src="assets/logo.png" alt="Logo" class="w-full h-full object-contain">
        </div>

        <nav class="flex flex-wrap items-center gap-2">
          <button class="pill-btn flex items-center gap-2 px-4 py-2 rounded-full border border-blue-500/40 bg-blue-500/10 text-blue-400 font-semibold text-xs shadow-sm">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Mi Espacio
          </button>

          <!-- BOTÓN EDITOR DE CÓDIGO -->
          <button data-action="prompt-editor-choice" class="pill-btn flex items-center gap-2 px-4 py-2 rounded-full border border-slate-700 bg-slate-800/80 hover:bg-slate-700 text-slate-200 font-semibold text-xs transition shadow-sm">
            <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
            Editor de Código
          </button>

          <!-- BOTÓN DESTACADO: DESPLEGAR PÁGINA EN VIVO -->
          <button id="btnDeployLive" data-action="deploy-live" class="pill-btn flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-700 hover:from-blue-700 hover:to-indigo-800 text-white font-semibold text-xs shadow-md shadow-blue-500/25 transition">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>🚀 Desplegar Página</span>
          </button>
        </nav>
      </div>

      <!-- Usuario y Logout a la Derecha -->
      <div class="flex flex-wrap items-center justify-between sm:justify-end gap-2 sm:gap-3 w-full lg:w-auto">
                <!-- SELECTOR DE TEMA CLARO / OSCURO -->
        <button id="themeToggleBtn" data-action="toggle-theme" class="pill-toggle pill-btn flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs transition shadow-xs cursor-pointer select-none" title="Cambiar tema claro/oscuro">
          <span id="themeToggleIcon" class="flex items-center justify-center">☀️</span>
          <span id="themeToggleText">Claro</span>
        </button>

        <!-- Tarjeta de Usuario Activo con acceso a Seguridad 2FA -->
        <div data-action="open-2fa-modal" class="user-pill flex items-center gap-2.5 bg-white border border-slate-200 pl-2 pr-3.5 py-1.5 rounded-full shadow-sm cursor-pointer hover:border-blue-300 transition" title="Configuración y Clave de Seguridad 2FA">
          <div id="userAvatar" class="w-8 h-8 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 text-white font-bold text-xs flex items-center justify-center">
            JS
          </div>
          <div class="text-left">
            <span id="headerUserName" class="block text-xs font-bold text-slate-800 leading-none">Tenant A</span>
            <span id="headerUserRole" class="text-[10px] text-emerald-600 font-bold leading-none flex items-center gap-1 mt-0.5">
              <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
              2FA Activo
            </span>
          </div>
        </div>

        <!-- Botón Cerrar Sesión -->
        <a href="logout.php" class="text-xs font-bold text-slate-400 hover:text-red-600 px-3 py-2 rounded-full hover:bg-red-50 transition flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Salir
        </a>
      </div>

    </header>

    <!-- CUERPO PRINCIPAL DEL DASHBOARD -->
    <div class="space-y-6">

      <!-- Saludo y Acciones Principales -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <span class="text-slate-400 text-xs font-semibold">Panel de Desarrollo Personal</span>
          <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            Hola, <span id="welcomeName" class="text-blue-600">Tenant A</span> 👋
          </h1>
          <p class="text-slate-500 text-xs sm:text-sm mt-1">Este es tu espacio exclusivo de trabajo. Solo tú tienes acceso a tus archivos y proyectos.</p>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 sm:gap-3 w-full sm:w-auto">
          <button data-action="trigger-upload-modal" class="w-full sm:w-auto justify-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-2xl text-xs font-semibold shadow-md hover:shadow-lg transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            <span>Subir Archivos / Carpeta</span>
          </button>
          <a id="btnVisitSite" href="https://<?php echo htmlspecialchars($active_session_user); ?>.example.com" target="_blank" class="w-full sm:w-auto justify-center bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 px-4 py-2.5 rounded-2xl text-xs font-semibold shadow-xs hover:shadow-md transition flex items-center gap-2">
            <span>Visitar Mi Web</span>
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
          </a>
        </div>
      </div>

      <!-- BARRA DE GESTIÓN DE ESPACIOS DE TRABAJO / PROYECTOS -->
      <div class="projects-bar card-soft p-3 sm:p-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0 overflow-hidden flex-1">
          <span class="text-slate-500 dark:text-slate-400 text-xs font-semibold uppercase tracking-wider flex items-center gap-1.5 pl-1 shrink-0">
            <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            <span class="hidden sm:inline">Espacios:</span>
          </span>
          <!-- Contenedor dinámico de pestañas de proyectos con scroll horizontal táctil -->
          <div id="projectTabsContainer" class="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 flex-1"></div>
        </div>

        <button data-action="prompt-new-project" class="btn-new-project text-xs font-semibold px-3.5 py-1.5 rounded-xl transition flex items-center justify-center gap-1.5 shadow-2xs shrink-0 cursor-pointer">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>+ Agregar Espacio Nuevo</span>
        </button>
      </div>

      <!-- SECCIÓN DE MÉTRICAS E INSTRUMENTOS -->
      <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
        
        <!-- COLUMNA IZQUIERDA: Almacenamiento, Personalizar URL y Subidas -->
        <div class="md:col-span-4 space-y-5">

          <!-- 1. TARJETA: Métricas del Servidor en Vivo -->
          <div class="card-soft p-6">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-2">
                <div class="metric-server-icon w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                </div>
                <div>
                  <h3 class="text-sm font-semibold text-slate-900">Métricas del Servidor</h3>
                </div>
              </div>
              <span class="metric-server-status text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Activo</span>
              </span>
            </div>

            <!-- 3 Métricas en tiempo real -->
            <div class="space-y-4">
              
              <!-- CPU / Procesador -->
              <div>
                <div class="flex justify-between items-center text-xs mb-1.5">
                  <span class="metric-label font-semibold text-slate-700 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M3 9h2m-2 6h2m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                    Procesador (CPU)
                  </span>
                  <span class="font-medium text-blue-600 font-mono text-xs"><span id="sidebarCpuText">--%</span> <span class="text-[10px] text-slate-400 font-normal" id="sidebarCpuDetail">(1 Cores)</span></span>
                </div>
                <div class="progress-track w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div id="sidebarCpuBar" class="bg-blue-500 h-full rounded-full transition-all duration-500" style="width: 10%"></div>
                </div>
              </div>

              <!-- Memoria RAM -->
              <div>
                <div class="flex justify-between items-center text-xs mb-1.5">
                  <span class="metric-label font-semibold text-slate-700 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Memoria RAM
                  </span>
                  <span class="font-medium text-purple-600 font-mono text-xs"><span id="sidebarRamText">-- MB</span> <span class="text-[10px] text-slate-400 font-normal" id="sidebarRamDetail">/ 3.82 GB (11%)</span></span>
                </div>
                <div class="progress-track w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div id="sidebarRamBar" class="bg-purple-500 h-full rounded-full transition-all duration-500" style="width: 11%"></div>
                </div>
              </div>

              <!-- Almacenamiento SSD -->
              <div>
                <div class="flex justify-between items-center text-xs mb-1.5">
                  <span class="metric-label font-semibold text-slate-700 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    Almacenamiento (SSD)
                  </span>
                  <span class="font-medium text-emerald-600 font-mono text-xs"><span id="sidebarDiskText">-- GB</span> <span class="text-[10px] text-slate-400 font-normal" id="sidebarDiskDetail">/ 37.7 GB (6%)</span></span>
                </div>
                <div class="progress-track w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div id="sidebarDiskBar" class="bg-emerald-500 h-full rounded-full transition-all duration-500" style="width: 6%"></div>
                </div>
              </div>

            </div>

            <!-- Footer de la tarjeta con espacio web del usuario -->
            <div class="metric-divider mt-5 pt-3.5 border-t border-slate-100 flex flex-wrap items-center justify-between text-[11px] gap-2">
              <span class="text-slate-400 font-medium">Uso en este proyecto: <strong id="userStorageUsed" class="text-slate-800">-- KB</strong></span>
              <span class="version-pill text-slate-600 font-bold bg-slate-100 px-2 py-0.5 rounded-md">HTML5 • CSS3 • PHP 8.3</span>
            </div>
          </div>

          <!-- 2. TARJETA: Apartado de Enlace Web y Copiar URL al Desplegar -->
          <div class="card-soft p-6 transition-all duration-300" id="cardDeployUrlSection">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs" id="deployCardIcon">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                </div>
                <div>
                  <h3 class="text-sm font-semibold text-slate-900">Enlace del Sitio Web</h3>
                  <span class="text-[10px] text-slate-400 font-medium">Acceso público</span>
                </div>
              </div>
            </div>

            <!-- Caja de URL activa -->
            <div id="deployedUrlBox" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 mb-3 flex items-center justify-between font-mono text-xs text-slate-700 select-all overflow-hidden group hover:border-blue-300 transition">
              <span id="deployedUrlDisplay" class="truncate font-medium text-blue-600">https://tenant_a.example.com</span>
            </div>

            <!-- Botones de Acción: Copiar URL & Abrir Web -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <button data-action="copy-deployed-url" id="btnCopyDeployedUrl" class="w-full py-2.5 px-3 rounded-xl bg-blue-600 hover:bg-blue-700 active:scale-[0.98] text-white text-xs font-semibold flex items-center justify-center gap-1.5 transition shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                <span id="btnCopyDeployedUrlText">Copiar URL</span>
              </button>
              <a id="btnOpenDeployedExternal" href="https://<?php echo htmlspecialchars($active_session_user); ?>.example.com" target="_blank" class="w-full py-2.5 px-3 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-700 active:scale-[0.98] text-slate-200 text-xs font-semibold flex items-center justify-center gap-1.5 transition shadow-2xs">
                <span>Abrir Web ↗</span>
              </a>
            </div>
          </div>

          <!-- 3. TARJETA: Apartado de Subidas (Archivos o Carpeta Completa) -->
          <div class="card-soft p-6">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                Subir a tu Espacio
              </h3>
              <span class="text-[10px] text-slate-500 font-semibold">Arrastra y suelta</span>
            </div>

            <!-- Zona Dropzone -->
            <div id="dropzone" class="border-2 border-dashed border-slate-200 hover:border-blue-400 bg-slate-50/70 hover:bg-blue-50/30 rounded-2xl p-4 text-center cursor-pointer transition flex flex-col items-center justify-center group">
              <div class="dropzone-icon-box w-10 h-10 rounded-2xl bg-white border border-slate-200 group-hover:scale-105 group-hover:border-blue-300 flex items-center justify-center text-blue-600 mb-2 transition shadow-xs">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
              </div>
              <p class="dropzone-title text-xs font-bold text-slate-700">Arrastra tu proyecto o archivos aquí</p>
              <p class="text-[11px] text-slate-500 mt-0.5 font-medium">Soporta carpetas completas y archivos individuales</p>
              
              <!-- Botones de selección -->
              <div class="flex items-center gap-2 mt-3" data-stop-propagation="1">
                <button data-action="click-target" data-target="fileInputDirect" class="dropzone-btn text-[11px] font-semibold text-blue-600 bg-white border border-slate-200 hover:bg-slate-50 px-2.5 py-1 rounded-lg transition shadow-2xs">
                  Subir Archivos
                </button>
                <button data-action="click-target" data-target="folderInputDirect" class="dropzone-btn text-[11px] font-semibold text-purple-600 bg-white border border-slate-200 hover:bg-slate-50 px-2.5 py-1 rounded-lg transition shadow-2xs">
                  Subir Carpeta
                </button>
              </div>

              <!-- Inputs ocultos para archivos y carpetas (con compatibilidad de subcarpetas) -->
              <input type="file" id="fileInputDirect" multiple class="hidden">
              <input type="file" id="folderInputDirect" webkitdirectory directory mozdirectory multiple class="hidden">
            </div>

            <!-- Progreso de subida animado -->
            <div id="uploadProgressBox" class="hidden mt-3 pt-3 border-t border-slate-100">
              <div class="flex justify-between text-xs font-semibold text-slate-600 mb-1">
                <span id="uploadFileName">Procesando subida...</span>
                <span id="uploadPercent">0%</span>
              </div>
              <div class="progress-track w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                <div id="uploadProgressBar" class="bg-blue-600 h-full w-0 transition-all duration-300"></div>
              </div>
            </div>
          </div>

        </div>

        <!-- COLUMNA DERECHA: Métricas & Explorador de Archivos / Papelera -->
        <div class="md:col-span-8 space-y-5">
          
          <!-- 3 Tarjetas de Métricas de Archivos del Proyecto Activo -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            
            <!-- Card 1: Páginas HTML -->
            <div class="card-soft p-5 flex flex-col justify-between">
              <div class="flex justify-between items-start">
                <div class="metric-icon-html w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-xs">HTML</div>
                <button data-action="prompt-editor-choice" data-lang="HTML" class="metric-btn-html text-xs font-semibold text-amber-600 bg-amber-50 hover:bg-amber-100 px-2.5 py-1 rounded-full transition">Editar / Crear +</button>
              </div>
              <div class="mt-4">
                <span id="statHTML" class="text-2xl font-medium text-slate-900 block">0 Archivos</span>
              </div>
            </div>

            <!-- Card 2: Estilos CSS -->
            <div class="card-soft p-5 flex flex-col justify-between">
              <div class="flex justify-between items-start">
                <div class="metric-icon-css w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-xs">CSS</div>
                <button data-action="prompt-editor-choice" data-lang="CSS" class="metric-btn-css text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 px-2.5 py-1 rounded-full transition">Editar / Crear +</button>
              </div>
              <div class="mt-4">
                <span id="statCSS" class="text-2xl font-medium text-slate-900 block">0 Archivos</span>
              </div>
            </div>

            <!-- Card 3: Backend PHP -->
            <div class="card-soft p-5 flex flex-col justify-between">
              <div class="flex justify-between items-start">
                <div class="metric-icon-php w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold text-xs">PHP</div>
                <button data-action="prompt-editor-choice" data-lang="PHP" class="metric-btn-php text-xs font-semibold text-purple-600 bg-purple-50 hover:bg-purple-100 px-2.5 py-1 rounded-full transition">Editar / Crear +</button>
              </div>
              <div class="mt-4">
                <span id="statPHP" class="text-2xl font-medium text-slate-900 block">0 Archivos</span>
              </div>
            </div>

          </div>

          <!-- SECCIÓN: EXPLORADOR DE ARCHIVOS, ELIMINACIÓN Y PAPELERA -->
          <div class="card-soft p-6">
            
            <!-- Barra Superior de Archivos -->
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-3 mb-5">
              <div>
                <h3 class="text-base font-semibold text-slate-900 flex items-center gap-2">
                  <span>Mis Archivos Web</span>
                  <span id="currentProjectBadge" class="text-xs bg-blue-50 text-blue-700 font-semibold px-2.5 py-0.5 rounded-full border border-blue-200/80">Proyecto 1</span>
                </h3>
                <p class="text-xs text-slate-500 font-medium">Gestiona, edita o elimina los archivos de este espacio de trabajo</p>
              </div>

              <!-- Botones de Acción Rápida -->
              <div class="flex flex-wrap items-center gap-2">
                <button data-action="prompt-editor-choice" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-3.5 py-2 rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                  <span>Editor de Código</span>
                </button>
              </div>
            </div>

            <!-- Pestañas de Filtro y Papelera -->
            <div class="file-list-divider flex flex-col lg:flex-row lg:items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100">
              
              <!-- Filtro de Categoría -->
              <div class="filter-bar-wrap flex items-center gap-1.5 bg-slate-100/80 p-1 rounded-xl text-xs overflow-x-auto no-scrollbar max-w-full">
                <button data-action="set-filter" data-filter="ALL" id="filter-ALL" class="px-3 py-1.5 rounded-lg font-bold bg-white text-slate-800 shadow-xs transition shrink-0">Todos</button>
                <button data-action="set-filter" data-filter="HTML" id="filter-HTML" class="filter-btn px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-950 transition shrink-0">HTML</button>
                <button data-action="set-filter" data-filter="CSS" id="filter-CSS" class="filter-btn px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-950 transition shrink-0">CSS</button>
                <button data-action="set-filter" data-filter="PHP" id="filter-PHP" class="filter-btn px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-950 transition shrink-0">PHP</button>
                <button data-action="set-filter" data-filter="MEDIA" id="filter-MEDIA" class="filter-btn px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-950 transition flex items-center gap-1.5 shrink-0">
                  <svg class="w-3.5 h-3.5 text-purple-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                  <span>Media</span>
                </button>
                <button data-action="set-filter" data-filter="TRASH" id="filter-TRASH" class="filter-trash-btn px-3 py-1.5 rounded-lg font-semibold text-rose-600 hover:bg-rose-100/70 transition flex items-center gap-1.5 shrink-0">
                  <svg class="w-3.5 h-3.5 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  <span>Papelera</span>
                  <span id="trashCountBadge" class="trash-badge bg-rose-100 text-rose-700 border border-rose-200 text-[10px] font-bold px-1.5 py-0.2 rounded-full">0</span>
                </button>
              </div>

            </div>

            <!-- Cabecera de Papelera si está activa -->
            <div id="trashNoticeHeader" class="hidden mb-4 bg-rose-50 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-900/60 rounded-2xl p-3.5 flex items-center justify-between shadow-xs">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-rose-100 text-rose-600 dark:bg-rose-900/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60 flex items-center justify-center shrink-0 shadow-2xs">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div>
                  <h4 class="text-xs font-bold text-rose-950 dark:text-rose-200">Papelera de Reciclaje</h4>
                  <p class="text-[11px] text-rose-800 dark:text-rose-300/90 mt-0.5">Los archivos aquí pueden ser restaurados o eliminados permanentemente.</p>
                </div>
              </div>
              <button data-action="empty-trash-confirm" class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-xs transition shrink-0">
                Vaciar Papelera
              </button>
            </div>

            <!-- BARRA DE ACCIONES POR LOTE (SELECCIÓN MÚLTIPLE) -->
            <div id="bulkActionBar" class="hidden mb-3 p-3 rounded-2xl bg-slate-900 text-white shadow-xl items-center justify-between gap-3 transition">
              <div class="flex items-center gap-2.5">
                <input type="checkbox" id="selectAllCheckbox" data-change-action="toggle-select-all" class="w-4 h-4 rounded text-blue-600 bg-slate-800 border-slate-700 cursor-pointer focus:ring-0">
                <label for="selectAllCheckbox" class="text-xs font-bold text-slate-200 cursor-pointer select-none">
                  <span id="bulkSelectedCount">0 seleccionados</span>
                </label>
              </div>

              <!-- Acciones cuando estamos en archivos activos -->
              <div id="bulkActiveActions" class="flex items-center gap-2">
                <button data-action="confirm-bulk-delete" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-3.5 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  <span id="btnBulkDeleteText">Mover a papelera</span>
                </button>
                <button data-action="clear-selection" class="text-slate-400 hover:text-white px-2.5 py-1.5 text-xs font-semibold transition">
                  Cancelar
                </button>
              </div>

              <!-- Acciones cuando estamos en la papelera -->
              <div id="bulkTrashActions" class="hidden flex items-center gap-2">
                <button data-action="execute-bulk-restore" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-3.5 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
                  <span>↩ Restaurar seleccionados</span>
                </button>
                <button data-action="confirm-bulk-permanent-delete" class="bg-rose-600 hover:bg-rose-700 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
                  <span>✕ Eliminar permanentemente</span>
                </button>
                <button data-action="clear-selection" class="text-slate-400 hover:text-white px-2.5 py-1.5 text-xs font-semibold transition">
                  Cancelar
                </button>
              </div>
            </div>

            <!-- Tabla / Lista de Archivos Exclusivos -->
            <div id="fileListContainer" class="space-y-2.5">
              <!-- Se rellena dinámicamente con JS -->
            </div>

          </div>

        </div>

      </div>

    </div>

    <!-- Pie de página -->
    <footer class="mt-8 pt-6 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-400 gap-2">
      <span>Desarrollado por Admin User</span>
      <div class="flex items-center gap-4">
        <span class="text-emerald-600 font-semibold">● Servidor Activo</span>
      </div>
    </footer>

  </div>


  <!-- ============================================================= -->
  <!-- MODAL: DECISIÓN DE SUBIDA (REEMPLAZAR O CREAR ESPACIO NUEVO) -->
  <!-- ============================================================= -->
  <!-- MODAL: MONITOR EN VIVO ESTILO HTOP (24/7 LINUX TERMINAL) -->
  <div id="modalHtopMonitor" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-3 sm:p-6">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-4xl shadow-2xl overflow-hidden text-slate-200 font-mono text-xs animate-in fade-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
      
      <!-- Terminal Window Header Bar -->
      <div class="bg-slate-950 px-4 py-3 border-b border-slate-800 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2.5">
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-full bg-rose-500 cursor-pointer hover:opacity-80" data-action="close-htop-modal"></span>
            <span class="w-3 h-3 rounded-full bg-amber-500"></span>
            <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
          </div>
          <span class="text-slate-300 font-bold ml-2 text-xs truncate max-w-[150px] sm:max-w-none">htop - Ubuntu 24.04 LTS</span>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden sm:flex items-center gap-1.5 text-[11px] text-emerald-400 font-bold bg-emerald-950/60 border border-emerald-800/60 px-2.5 py-0.5 rounded-full">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
            <span>24/7 LIVE STREAM</span>
          </div>
          <button data-action="close-htop-modal" class="text-slate-400 hover:text-white px-2 py-0.5 rounded text-base leading-none">&times;</button>
        </div>
      </div>

      <!-- Terminal Body Content -->
      <div class="p-4 sm:p-5 overflow-y-auto space-y-4 font-mono text-[11px] leading-relaxed select-text">
        
        <!-- HTOP Gauges Bar -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 bg-slate-950/80 p-3.5 rounded-xl border border-slate-800">
          
          <!-- Columna 1: CPU Cores & Tasks -->
          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <span class="w-7 text-cyan-400 font-bold">1</span>
              <span class="text-slate-500">[</span>
              <div class="w-full bg-slate-800 rounded-xs h-3 overflow-hidden flex items-center px-0.5">
                <div id="htopCore1Bar" class="bg-gradient-to-r from-emerald-500 to-cyan-400 h-2 rounded-xs transition-all duration-300" style="width: 5%"></div>
              </div>
              <span class="text-slate-500">]</span>
              <span id="htopCore1Text" class="w-16 text-right text-emerald-400 font-bold">2.5%</span>
            </div>

            <div class="flex items-center gap-2">
              <span class="w-7 text-cyan-400 font-bold">2</span>
              <span class="text-slate-500">[</span>
              <div class="w-full bg-slate-800 rounded-xs h-3 overflow-hidden flex items-center px-0.5">
                <div id="htopCore2Bar" class="bg-gradient-to-r from-emerald-500 to-cyan-400 h-2 rounded-xs transition-all duration-300" style="width: 3%"></div>
              </div>
              <span class="text-slate-500">]</span>
              <span id="htopCore2Text" class="w-16 text-right text-emerald-400 font-bold">1.8%</span>
            </div>

            <div class="flex justify-between pt-1 text-slate-400 text-[10px]">
              <span>Tasks: <strong id="htopModalTasks" class="text-white">122</strong>, <span class="text-emerald-400 font-semibold">1 thr; 1 running</span></span>
            </div>
          </div>

          <!-- Columna 2: Memory, Swap & Load -->
          <div class="space-y-2">
            <div class="flex items-center gap-2">
              <span class="w-7 text-cyan-400 font-bold">Mem</span>
              <span class="text-slate-500">[</span>
              <div class="w-full bg-slate-800 rounded-xs h-3 overflow-hidden flex items-center px-0.5">
                <div id="htopModalRamBar" class="bg-gradient-to-r from-indigo-500 to-purple-500 h-2 rounded-xs transition-all duration-300" style="width: 11%"></div>
              </div>
              <span class="text-slate-500">]</span>
              <span id="htopModalRamText" class="w-24 text-right text-indigo-300 font-bold text-[10px]">433M/3.73G</span>
            </div>

            <div class="flex items-center gap-2">
              <span class="w-7 text-cyan-400 font-bold">Swp</span>
              <span class="text-slate-500">[</span>
              <div class="w-full bg-slate-800 rounded-xs h-3 overflow-hidden flex items-center px-0.5">
                <div class="bg-slate-700 h-2 rounded-xs" style="width: 0%"></div>
              </div>
              <span class="text-slate-500">]</span>
              <span class="w-24 text-right text-slate-400 text-[10px]">0K/0K</span>
            </div>

            <div class="flex justify-between pt-1 text-slate-400 text-[10px]">
              <span>Load: <strong id="htopModalLoad" class="text-amber-300">0.06 0.03 0.01</strong></span>
              <span>Uptime: <strong id="htopModalUptime" class="text-emerald-300">0d 03h 48m</strong></span>
            </div>
          </div>

        </div>

        <!-- Tabla de Procesos Reales -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <span class="text-slate-400 text-[11px] font-bold uppercase tracking-wider flex items-center gap-2">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Procesos Activos en el Servidor (Top CPU / RAM)
            </span>
            <span class="text-slate-500 text-[10px]">Actualización en vivo cada 2s</span>
          </div>

          <div class="border border-slate-800 rounded-xl overflow-x-auto bg-slate-950 shadow-inner no-scrollbar">
            <table class="w-full text-left border-collapse text-[11px]">
              <thead>
                <tr class="bg-slate-800/90 text-emerald-400 font-bold border-b border-slate-700">
                  <th class="py-2 px-3.5">PID</th>
                  <th class="py-2 px-3.5">USER</th>
                  <th class="py-2 px-3.5">CPU%</th>
                  <th class="py-2 px-3.5">MEM%</th>
                  <th class="py-2 px-3.5">COMMAND</th>
                </tr>
              </thead>
              <tbody id="htopProcessTableBody" class="divide-y divide-slate-800/60 font-mono">
                <tr>
                  <td colspan="5" class="py-4 text-center text-slate-500">Cargando procesos en vivo...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- Terminal Footer Bar (htop F-keys) -->
      <div class="bg-slate-950 px-4 py-2.5 border-t border-slate-800 flex flex-wrap items-center justify-between gap-2 text-[11px] shrink-0">
        <div class="flex flex-wrap items-center gap-2">
          <span class="bg-slate-800/80 px-2 py-0.5 rounded text-slate-300"><strong class="text-cyan-400">F1</strong> Help</span>
          <span class="bg-slate-800/80 px-2 py-0.5 rounded text-slate-300"><strong class="text-cyan-400">F2</strong> Setup</span>
          <span class="bg-slate-800/80 px-2 py-0.5 rounded text-slate-300"><strong class="text-cyan-400">F5</strong> Tree</span>
          <span class="bg-slate-800/80 px-2 py-0.5 rounded text-slate-300"><strong class="text-cyan-400">F10</strong> Quit</span>
        </div>
        <button data-action="close-htop-modal" class="px-4 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold transition shadow-sm">
          Cerrar Monitor
        </button>
      </div>

    </div>
  </div>

    <div id="modalUploadConflict" data-backdrop-action="cancel-upload-conflict" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 sm:p-7 max-w-lg w-full shadow-2xl relative text-slate-800">
      <button type="button" data-action="cancel-upload-conflict" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition cursor-pointer" title="Cerrar">✕</button>
      
      <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400 border border-amber-200 dark:border-amber-800/50 flex items-center justify-center font-bold text-lg mb-4 shadow-sm">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
      </div>

      <h3 class="text-lg font-extrabold text-slate-900 dark:text-white mb-1">Archivos Detectados en este Espacio</h3>
      <p class="text-xs text-slate-600 dark:text-slate-300 mb-5 leading-relaxed">
        Tu espacio actual ya contiene archivos web (<strong id="conflictCurrentProjectName" class="text-blue-600 dark:text-blue-400 font-extrabold">Proyecto 1</strong>). ¿Qué deseas hacer con los nuevos archivos que vas a subir?
      </p>

      <div class="space-y-3.5">
        
        <!-- Opción 1: Reemplazar Proyecto Actual (Adaptado al Tema Blanco y Oscuro) -->
        <div data-action="choose-upload-action" data-choice="replace" class="p-4 rounded-2xl border border-rose-200 hover:border-rose-400 bg-rose-50/80 hover:bg-rose-100/90 dark:border-rose-900/50 dark:bg-rose-950/30 dark:hover:bg-rose-950/50 cursor-pointer transition flex items-start gap-3.5 group shadow-xs">
          <div class="w-10 h-10 rounded-xl bg-white border border-rose-200 text-rose-600 dark:bg-rose-900/50 dark:border-rose-800/60 dark:text-rose-300 flex items-center justify-center font-bold text-base shadow-xs group-hover:scale-105 transition shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          </div>
          <div>
            <h4 class="text-xs font-bold text-rose-950 dark:text-rose-200 group-hover:text-rose-900 dark:group-hover:text-white transition">Reemplazar Proyecto Actual</h4>
            <p class="text-[11px] text-rose-900/80 dark:text-rose-300/80 mt-0.5 leading-snug">Borra los archivos antiguos del proyecto actual y muestra los nuevos archivos subidos.</p>
          </div>
        </div>

        <!-- Opción 2: Crear un Espacio Nuevo (Proyecto 2) -->
        <div class="p-4 rounded-2xl border border-blue-200 bg-blue-50/60 dark:border-blue-900/50 dark:bg-blue-950/30 text-slate-800 dark:text-slate-200 shadow-xs">
          <div class="flex items-start gap-3.5 mb-3">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold text-base shrink-0 shadow-sm">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </div>
            <div>
              <h4 class="text-xs font-bold text-blue-950 dark:text-blue-100">Agregar a un Espacio de Trabajo Nuevo</h4>
              <p class="text-[11px] text-slate-600 dark:text-slate-300 mt-0.5 leading-snug">Crea un proyecto independiente para no perder lo que ya tienes montado.</p>
            </div>
          </div>

          <div class="space-y-2 pt-1">
            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300">Nombre del nuevo espacio:</label>
            <div class="flex items-center gap-2">
              <input id="newProjectNameInput" type="text" value="Proyecto 2" class="modal-input w-full bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 dark:text-slate-100 focus:outline-none focus:border-blue-500 font-sans shadow-xs">
              <button data-action="choose-upload-action" data-choice="new_space" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2 rounded-xl transition shadow-sm shrink-0">
                Crear y Subir
              </button>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL: CREAR NUEVO ESPACIO DE TRABAJO (PROYECTO) MANUAL -->
  <!-- ============================================================= -->
  <div id="modalNewProjectManual" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-slate-800">
      <button data-action="close-modal" data-modal="modalNewProjectManual" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition">✕</button>
      
      <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-900/50 flex items-center justify-center font-bold text-lg mb-3 shadow-2xs">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.631 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.8m2.58-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">Nuevo Espacio de Trabajo</h3>
      <p class="text-xs text-slate-500 mb-4">Crea un nuevo proyecto independiente con sus propios archivos y su propia URL web.</p>

      <div class="space-y-3">
        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1">Nombre del Proyecto:</label>
          <input id="manualProjectNameInput" type="text" placeholder="Ej. Proyecto 2, Tienda, Portafolio..." class="modal-input w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs font-bold text-slate-800 focus:outline-none focus:border-blue-500">
        </div>

        <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2 pt-2">
          <button data-action="close-modal" data-modal="modalNewProjectManual" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
            Cancelar
          </button>
          <button data-action="confirm-create-manual-project" class="py-2.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-sm transition">
            Crear Espacio
          </button>
        </div>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL: DECISIÓN DE EDICIÓN (EDITAR ARCHIVO EXISTENTE O CREAR NUEVO) -->
  <!-- ============================================================= -->
  <div id="modalEditorActionPrompt" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 sm:p-7 max-w-lg w-full shadow-2xl relative animate-in fade-in zoom-in-95 duration-200">
      <button data-action="close-modal" data-modal="modalEditorActionPrompt" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition">✕</button>
      
      <div class="flex items-center gap-3 mb-2">
        <div id="actionPromptBadgeIcon" class="w-10 h-10 rounded-2xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-900/50 flex items-center justify-center font-extrabold text-sm">
          HTML
        </div>
        <div>
          <h3 id="actionPromptTitle" class="text-base font-extrabold text-slate-900 dark:text-white">Editor de Código</h3>
          <p id="actionPromptSubtitle" class="text-xs text-slate-500">¿Deseas editar un archivo existente o crear uno nuevo?</p>
        </div>
      </div>

      <p class="text-xs text-slate-500 mb-4">Selecciona qué acción deseas realizar en tu espacio de trabajo:</p>

      <!-- Opción 1: Lista de Archivos Existentes para Editar -->
      <div class="mb-4">
        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">Archivos disponibles en este espacio:</span>
        <div id="actionPromptFilesList" class="space-y-2 max-h-48 overflow-y-auto pr-1">
          <!-- Injected dynamically -->
        </div>
      </div>

      <!-- Opción 2: Crear un Nuevo Archivo -->
      <div class="pt-3 border-t border-slate-800">
        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">O crear un archivo nuevo:</span>
        <button id="btnActionCreateNew" data-action="execute-create-new-from-prompt" class="w-full py-3 px-4 rounded-2xl border-2 border-dashed border-slate-750 hover:border-blue-500 hover:bg-slate-850/60 transition flex items-center justify-center gap-2 text-xs font-bold text-slate-300 hover:text-blue-400 group">
          <span class="w-6 h-6 rounded-lg bg-slate-800 group-hover:bg-blue-600 text-slate-300 group-hover:text-white flex items-center justify-center text-sm font-bold transition">+</span>
          <span id="btnActionCreateNewText">Crear un Nuevo Archivo</span>
        </button>
      </div>

      <button data-action="back-to-type-choice" class="text-xs font-semibold text-slate-400 hover:text-white text-center w-full mt-4 transition">
        &larr; Volver a elegir lenguaje
      </button>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- MODAL 1: PREGUNTAR TIPO DE ARCHIVO (HTML, CSS o PHP) -->
  <!-- ============================================================= -->
  <div id="modalTypeChoice" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-lg w-full shadow-2xl relative">
      <button data-action="close-modal" data-modal="modalTypeChoice" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition">✕</button>
      
      <div class="flex items-center gap-3 mb-2">
        <div class="modal-icon-badge w-10 h-10 rounded-2xl flex items-center justify-center font-bold text-sm">
          &lt;/&gt;
        </div>
        <div>
          <h3 class="text-base font-extrabold text-slate-900">Editor de Código</h3>
          <p class="text-xs text-slate-500">¿Qué tipo de archivo deseas abrir o crear?</p>
        </div>
      </div>

      <p class="text-xs text-slate-500 mb-5">Selecciona el lenguaje en el que deseas programar en tu espacio personal:</p>

      <!-- 3 Opciones Clickeables -->
      <div class="space-y-3">
        <!-- Opción HTML -->
        <div data-action="editor-lang" data-lang="HTML" class="modal-choice-card p-4 rounded-2xl cursor-pointer transition flex items-center justify-between group shadow-xs">
          <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center font-extrabold text-sm group-hover:scale-105 transition">
              HTML
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h4 class="text-xs font-bold text-slate-900">Página Web (HTML)</h4>
                <span id="badgeModalCountHTML" class="text-[10px] font-semibold px-2 py-0.2 bg-amber-50 text-amber-700 border border-amber-200/60 rounded-full"></span>
              </div>
              <p class="text-[11px] text-slate-500 mt-0.5 font-medium">Estructura de páginas, textos, formularios e imágenes.</p>
            </div>
          </div>
          <span class="text-xs font-bold text-amber-600 group-hover:translate-x-1 transition flex items-center gap-1">
            <span>Seleccionar</span>
            <span>&rarr;</span>
          </span>
        </div>

        <!-- Opción CSS -->
        <div data-action="editor-lang" data-lang="CSS" class="modal-choice-card p-4 rounded-2xl cursor-pointer transition flex items-center justify-between group shadow-xs">
          <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center font-extrabold text-sm group-hover:scale-105 transition">
              CSS
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h4 class="text-xs font-bold text-slate-900">Hojas de Estilo (CSS)</h4>
                <span id="badgeModalCountCSS" class="text-[10px] font-semibold px-2 py-0.2 bg-blue-50 text-blue-700 border border-blue-200/60 rounded-full"></span>
              </div>
              <p class="text-[11px] text-slate-500 mt-0.5 font-medium">Diseño visual, colores, animaciones, fuentes y layout.</p>
            </div>
          </div>
          <span class="text-xs font-bold text-blue-600 group-hover:translate-x-1 transition flex items-center gap-1">
            <span>Seleccionar</span>
            <span>&rarr;</span>
          </span>
        </div>

        <!-- Opción PHP -->
        <div data-action="editor-lang" data-lang="PHP" class="modal-choice-card p-4 rounded-2xl cursor-pointer transition flex items-center justify-between group shadow-xs">
          <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center font-extrabold text-sm group-hover:scale-105 transition">
              PHP
            </div>
            <div>
              <div class="flex items-center gap-2">
                <h4 class="text-xs font-bold text-slate-900">Script de Servidor (PHP)</h4>
                <span id="badgeModalCountPHP" class="text-[10px] font-semibold px-2 py-0.2 bg-purple-50 text-purple-700 border border-purple-200/60 rounded-full"></span>
              </div>
              <p class="text-[11px] text-slate-500 mt-0.5 font-medium">Lógica dinámica, procesamiento backend y bases de datos.</p>
            </div>
          </div>
          <span class="text-xs font-bold text-purple-600 group-hover:translate-x-1 transition flex items-center gap-1">
            <span>Seleccionar</span>
            <span>&rarr;</span>
          </span>
        </div>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 2: EDITOR DE CÓDIGO EMBEBIDO -->
  <!-- ============================================================= -->
  <div id="modalEditor" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-3 sm:p-6 overflow-hidden overscroll-contain">
    <div class="editor-modal-container rounded-3xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden overscroll-contain">
      
      <!-- Barra superior del editor -->
      <div class="editor-header px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
            <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
            <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
          </div>
          <div class="h-4 w-px bg-slate-300"></div>
          <div class="flex items-center gap-2">
            <span id="editorBadgeType" class="px-2 py-0.5 rounded-md font-bold text-[10px] bg-purple-500/20 text-purple-700 border border-purple-300">PHP</span>
            <input id="editorFileName" type="text" value="index.php" class="editor-filename-input rounded-lg px-2.5 py-1 font-mono text-xs focus:outline-none focus:border-blue-500">
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button data-action="save-editor-code" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>Guardar Archivo</span>
          </button>
          <button data-action="close-modal" data-modal="modalEditor" class="editor-close-btn px-3 py-1.5 rounded-xl transition font-bold">
            Cerrar
          </button>
        </div>
      </div>

      <!-- Cuerpo del Editor con numeración de líneas dinámica sincronizada -->
      <div id="editorBodyWrapper" class="editor-body-wrapper flex-1 flex p-4 overflow-hidden text-xs relative select-none">
        <!-- Líneas dinámicas sincronizadas -->
        <div id="editorLineNumbers" class="editor-line-numbers select-none font-mono text-right pr-4 space-y-0 leading-5 text-[11px] hidden sm:block overflow-hidden pointer-events-none w-10 shrink-0 select-none">
          <div class="h-5 leading-5">1</div>
        </div>
        <!-- Textarea de Código -->
        <textarea id="editorCodeArea" spellcheck="false" class="editor-code-area w-full h-80 sm:h-[450px] bg-transparent font-mono text-xs leading-5 focus:outline-none resize-none overflow-y-auto pl-3 overscroll-contain"></textarea>
      </div>

      <!-- Pie del editor -->
      <div class="editor-footer px-5 py-2 text-[11px] flex justify-between items-center font-medium">
        <span>Codificación: UTF-8 • Listo para el servidor</span>
        <span id="editorStatus">Modo Edición</span>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 3: CONFIRMACIÓN DE ELIMINACIÓN INDIVIDUAL A PAPELERA -->
  <!-- ============================================================= -->
  <div id="modalConfirmDelete" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-14 h-14 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4 border border-rose-100 dark:border-rose-900/50 shadow-xs">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Mover a la papelera?</h3>
      <p id="deleteFileNameTarget" class="text-sm font-bold text-slate-800 dark:text-slate-200 font-mono mb-5">index.html</p>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmDelete" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-move-to-trash" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Sí, mover a papelera
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 4: CONFIRMACIÓN DE VACIAR PAPELERA -->
  <!-- ============================================================= -->
  
  <!-- ============================================================= -->
  <!-- MODAL: CONFIRMAR ELIMINACIÓN DE ESPACIO DE TRABAJO (PROYECTO) -->
  <!-- ============================================================= -->
  <div id="modalConfirmDeleteProject" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-sm w-full shadow-2xl relative text-center text-slate-800">
      <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-100 dark:border-rose-900/50 flex items-center justify-center mx-auto mb-3 shadow-xs">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      </div>
      <h3 class="text-base font-extrabold text-slate-900 mb-1">Eliminar Espacio</h3>
      <p class="text-xs text-slate-500 mb-5">
        ¿Estás seguro de que deseas eliminar permanentemente el espacio <strong id="deleteProjectTargetName" class="text-slate-800"></strong> y sus archivos en el servidor? Esta acción no se puede deshacer.
      </p>
      <div class="grid grid-cols-2 gap-2">
        <button data-action="close-modal" data-modal="modalConfirmDeleteProject" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-delete-project" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition">
          Sí, Eliminar
        </button>
      </div>
    </div>
  </div>

  <div id="modalConfirmEmptyTrash" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-14 h-14 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4 border border-rose-200 dark:border-rose-900/50 shadow-xs">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Vaciar toda la papelera?</h3>
      <p class="text-sm font-bold text-slate-700 mb-5">Se eliminarán definitivamente todos los archivos de la papelera</p>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmEmptyTrash" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-empty-trash" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Sí, vaciar definitivamente
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 5: CONFIRMACIÓN DE ELIMINAR POR TIPO (HTML, CSS o PHP) -->
  <!-- ============================================================= -->
  <div id="modalConfirmDeleteByType" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-14 h-14 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4 border border-rose-100 dark:border-rose-900/50 shadow-xs">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Mover a la papelera?</h3>
      <p class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-5">
        Archivos <span id="deleteTypeLabel" class="text-rose-600 dark:text-rose-400 font-bold">CSS</span> (<span id="deleteTypeCount">1</span>)
      </p>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmDeleteByType" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-delete-by-type" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Sí, mover a papelera
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 6: CONFIRMACIÓN DE ELIMINACIÓN PERMANENTE -->
  <!-- ============================================================= -->
  <div id="modalConfirmDeletePermanent" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-14 h-14 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4 border border-rose-200 dark:border-rose-900/50 shadow-xs">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Eliminar definitivamente?</h3>
      <p id="deletePermanentFileName" class="text-sm font-bold text-slate-800 dark:text-slate-200 font-mono mb-5">index.html</p>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmDeletePermanent" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-delete-permanent" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Sí, eliminar
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 7: CONFIRMACIÓN DE ELIMINAR POR LOTE A PAPELERA -->
  <!-- ============================================================= -->
  <div id="modalConfirmBulkDelete" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-sm w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-3 border border-rose-100 dark:border-rose-900/50 shadow-xs">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Mover a la papelera?</h3>
      <p class="text-xs text-slate-500 mb-2"><span id="bulkDeleteCount" class="font-bold">0</span> archivos seleccionados:</p>
      <div id="bulkDeleteNames" class="text-xs font-bold text-slate-300 font-mono bg-slate-950 p-2.5 rounded-xl border border-slate-800 break-words mb-5 max-h-24 overflow-y-auto text-left"></div>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmBulkDelete" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-bulk-delete" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Mover a papelera
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL 8: CONFIRMACIÓN DE ELIMINACIÓN PERMANENTE POR LOTE -->
  <!-- ============================================================= -->
  <div id="modalConfirmBulkPermanent" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-sm w-full shadow-2xl relative text-center text-slate-800">
      
      <div class="w-12 h-12 rounded-2xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-3 border border-rose-200 dark:border-rose-900/50 shadow-xs">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      </div>

      <h3 class="text-base font-extrabold text-slate-900 mb-1">¿Eliminar definitivamente?</h3>
      <p class="text-xs text-slate-500 mb-2"><span id="bulkPermanentCount" class="font-bold">0</span> archivos seleccionados:</p>
      <div id="bulkPermanentNames" class="text-xs font-bold text-slate-300 font-mono bg-slate-950 p-2.5 rounded-xl border border-slate-800 break-words mb-5 max-h-24 overflow-y-auto text-left"></div>

      <div class="flex flex-col-reverse sm:grid sm:grid-cols-2 gap-2.5 sm:gap-3">
        <button data-action="close-modal" data-modal="modalConfirmBulkPermanent" class="btn-modal-cancel py-2.5 px-4 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition">
          Cancelar
        </button>
        <button data-action="execute-bulk-permanent-delete" class="py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md transition">
          Eliminar
        </button>
      </div>

    </div>
  </div>


  <!-- ============================================================= -->
  <!-- MODAL: VISOR DE MEDIOS SEGURO (IMÁGENES, VIDEOS, AUDIO) -->
  <!-- ============================================================= -->
  <div id="modalMediaPreview" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-2xl w-full shadow-2xl relative text-slate-800">
      <button data-action="close-modal" data-modal="modalMediaPreview" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition">✕</button>
      
      <div class="flex items-center gap-3 mb-4">
        <span id="mediaPreviewTypeBadge" class="px-2.5 py-1 rounded-xl font-bold text-xs bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">IMG</span>
        <div>
          <h3 id="mediaPreviewTitle" class="text-sm font-extrabold text-slate-900 dark:text-white font-mono">archivo</h3>
          <p id="mediaPreviewSize" class="text-[11px] text-slate-400">0 KB</p>
        </div>
      </div>

      <!-- Contenedor dinámico de medios -->
      <div id="mediaPreviewContainer" class="my-4 flex items-center justify-center min-h-[180px] bg-slate-950/70 rounded-2xl p-3 border border-slate-800">
        <!-- Renderizado dinámico de imagen, video o audio -->
      </div>


      <div class="flex justify-end gap-2 pt-2 border-t border-slate-800">
        <button data-action="close-modal" data-modal="modalMediaPreview" class="btn-modal-cancel py-2.5 px-5 rounded-xl border border-slate-700 bg-slate-800 hover:bg-slate-750 text-slate-300 font-bold text-xs transition">
          Cerrar
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- MODAL: SEGURIDAD Y CLAVE 2FA DEL USUARIO -->
  <!-- ============================================================= -->
  <div id="modalUser2FA" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="modal-panel bg-white border border-slate-200 rounded-3xl p-6 max-w-md w-full shadow-2xl relative text-slate-800">
      <button data-action="close-modal" data-modal="modalUser2FA" class="btn-modal-close absolute top-5 right-5 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition cursor-pointer" title="Cerrar">✕</button>
      
      <div class="flex items-center gap-3 mb-4">
        <div class="w-10 h-10 rounded-2xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-base border border-blue-200 dark:border-blue-800/60 shadow-xs shrink-0">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </div>
        <div>
          <h3 class="text-base font-extrabold text-slate-900 dark:text-white">Seguridad de Dos Pasos (2FA)</h3>
          <p class="text-xs text-slate-500 dark:text-slate-400">Google Authenticator vinculado a tu cuenta</p>
        </div>
      </div>

      <div class="p-3.5 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200/80 dark:border-emerald-800/60 mb-4 flex items-center gap-3">
        <div class="w-7 h-7 rounded-full bg-emerald-500 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div class="text-left">
          <h4 class="text-xs font-bold text-emerald-950 dark:text-emerald-200">Protección Activa</h4>
          <p class="text-[11px] text-emerald-800 dark:text-emerald-300/90 mt-0.5">Tu cuenta requiere código TOTP de 6 dígitos para iniciar sesión.</p>
        </div>
      </div>

      <p class="text-xs text-slate-600 dark:text-slate-300 mb-3 text-left">Tu factor de doble autenticación (2FA) está vinculado y activo de forma segura con Google Authenticator. Por políticas de seguridad y defensa en profundidad, tu clave maestra se encuentra cifrada en el servidor y protegida contra exposición en pantalla.</p>

      <div class="flex flex-col items-center gap-3 p-4 bg-slate-50 dark:bg-slate-900/70 rounded-2xl border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between w-full bg-white dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
          <span class="font-mono text-xs font-bold text-slate-500 dark:text-slate-400 tracking-widest">•••• •••• •••• ••••</span>
          <span class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            Enlazado
          </span>
        </div>
      </div>

      <div class="mt-5 flex justify-end">
        <button type="button" data-action="close-modal" data-modal="modalUser2FA" class="w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold shadow-md transition cursor-pointer">
          Entendido
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CONTENEDOR FLOTANTE DE NOTIFICACIONES TOAST -->
  <!-- ============================================================= -->
  <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 pointer-events-none"></div>

  <script src="js/qrcode.min.js"></script>

  <!-- ============================================================= -->
  <!-- SCRIPT CON TODA LA LÓGICA MULTI-PROYECTO DEL DASHBOARD -->
  <!-- ============================================================= -->
  <script id="utpSessionConfig" type="application/json"><?php echo json_encode([
      'activeUser' => $active_session_user,
      'csrfToken'  => $csrf_token,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
  <script src="js/dashboard.js"></script>

</body>
</html>
