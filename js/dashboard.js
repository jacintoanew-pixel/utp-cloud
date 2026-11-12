/**
 * UTP Cloud — Lógica Multi-Proyecto del Dashboard
 */
const sessionCfgEl = document.getElementById('utpSessionConfig');
let sessionCfg = {};
if (sessionCfgEl) {
  try {
    sessionCfg = JSON.parse(sessionCfgEl.textContent || '{}');
  } catch (e) {}
}
const serverActiveUser = typeof window.serverActiveUser !== 'undefined' ? window.serverActiveUser : (sessionCfg.activeUser || '');
const serverCsrfToken  = typeof window.serverCsrfToken !== 'undefined' ? window.serverCsrfToken : (sessionCfg.csrfToken || '');
window.serverActiveUser = serverActiveUser;
window.serverCsrfToken  = serverCsrfToken;
sessionStorage.setItem('utp_2fa_verified_user', serverActiveUser);
    sessionStorage.setItem('utp_2fa_token', 'session_active');
    sessionStorage.setItem('utp_2fa_time', Date.now().toString());

    function logoutUser(e) {
      if (e) e.preventDefault();
      sessionStorage.clear();
      window.location.replace('logout.php');
    }

    function open2FAModal() {
      openModal('modalUser2FA');
    }

    // ==========================================
    // UTILIDADES DE SEGURIDAD Y SANITIZACIÓN
    // ==========================================
    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function isValidFilename(name) {
      if (!name || typeof name !== 'string') return false;
      const trimmed = name.trim();
      // Prevenir Path Traversal (../, ..\), barras, caracteres nulos o nombres que empiezan con punto
      if (trimmed.includes('/') || trimmed.includes('\\') || trimmed.includes('..') || trimmed.startsWith('.')) {
        return false;
      }
      // Solo permitir caracteres seguros y alfanuméricos
      if (trimmed.length > 100 || !/^[a-zA-Z0-9_\-\.]+$/.test(trimmed)) {
        return false;
      }
      // Validar extensiones web seguras permitidas
      const parts = trimmed.split('.');
      if (parts.length < 2) return false;
      const ext = parts.pop().toLowerCase();
      const allowedExts = ['html', 'htm', 'css', 'php', 'js', 'json', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico'];
      return allowedExts.includes(ext);
    }

    function sanitizeProjectName(name, fallback = 'Proyecto') {
      if (!name || typeof name !== 'string') return fallback;
      // Eliminar etiquetas HTML, scripts, comillas y caracteres de control
      const cleaned = name.replace(/[<>"'&`\\/]/g, '').trim().slice(0, 50);
      return cleaned || fallback;
    }
    // Estructura Multi-Proyecto por cada usuario
    const userData = {
      tenant_a: {
        name: 'Tenant A',
        initials: 'TA',
        role: 'Propietario / Administrador',
        folder: '/var/www/tenant_a',
        activeProjectId: 'p1',
        projects: [
          {
            id: 'p1',
            name: 'Proyecto Principal',
            slug: '',
            subdomain: 'tenant_a-p1',
            url: 'https://tenant_a.example.com',
            storage: '0.0',
            files: [],
            trash: []
          }
        ]
      },
      tenant_b: {
        name: 'Tenant B',
        initials: 'TB',
        role: 'Desarrolladora Web',
        folder: '/var/www/tenant_b',
        activeProjectId: 'p1',
        projects: [
          {
            id: 'p1',
            name: 'Proyecto Principal',
            slug: '',
            subdomain: 'tenant_b-p1',
            url: 'https://tenant_b.example.com',
            storage: '0.0',
            files: [],
            trash: []
          }
        ]
      },
      tenant_c: {
        name: 'Tenant C',
        initials: 'TC',
        role: 'Desarrolladora Web',
        folder: '/var/www/tenant_c',
        activeProjectId: 'p1',
        projects: [
          {
            id: 'p1',
            name: 'Proyecto Principal',
            slug: '',
            subdomain: 'tenant_c-p1',
            url: 'https://tenant_c.example.com',
            storage: '0.0',
            files: [],
            trash: []
          }
        ]
      }
    };

    // Plantillas de código base por tipo
    const codeTemplates = {
      HTML: `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Sitio Web</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header>
    <h1>Bienvenido a Mi Sitio Web</h1>
    <p>Creado en mi espacio exclusivo de desarrollo.</p>
  </header>
  <main>
    <p>¡Edita este código desde tu panel y publica al instante!</p>
  </main>
</body>
</html>`,
      CSS: `/* Hoja de Estilos Personalizada */
body {
  margin: 0;
  padding: 20px;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background-color: #f8fafc;
  color: #1e293b;
}

h1 {
  color: #2563eb;
  font-weight: 800;
}

p {
  line-height: 1.6;
}`,
      PHP: `<?php
/**
 * Script de Backend PHP 8.3
 * Servidor Ubuntu 24.04
 */

$fecha_actual = date("d/m/Y H:i:s");
echo "<h2>Hola desde el servidor PHP</h2>";
echo "<p>Hora del servidor: " . $fecha_actual . "</p>";

// Escribe tu código aquí:
?>`
    };

    // Variables de estado
    let currentUserKey = 'tenant_a';
    let currentFilter = 'ALL';
    let targetDeleteFileId = null;
    let nextFileId = 500;
    let pendingUploadFiles = [];

    // Variables para modales personalizados de eliminación
    let pendingDeleteType = null;
    let pendingPermanentFileId = null;
    let selectedFileIds = new Set();

    // ==========================================
    // PERSISTENCIA Y SINCRONIZACIÓN MULTI-PROYECTO
    // ==========================================
    let pendingDeleteProjectId = null;

    function saveUserProjectsState() {
      const u = userData[currentUserKey];
      if (!u || !Array.isArray(u.projects)) return;

      const meta = {
        activeProjectId: u.activeProjectId,
        projects: u.projects.map(p => ({
          id: p.id,
          name: p.name,
          slug: p.slug !== undefined ? p.slug : (p.id === 'p1' ? '' : p.name.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')),
          subdomain: p.subdomain || `${currentUserKey}-${p.slug || 'p1'}`,
          url: p.url || (p.id === 'p1' ? `https://${currentUserKey}.example.com` : `https://${currentUserKey}.example.com/${p.slug}/`),
          storage: p.storage || '1.0'
        }))
      };

      try {
        localStorage.setItem(`utp_workspace_meta_${currentUserKey}`, JSON.stringify(meta));
      } catch (e) {}

      if (window.location.protocol !== 'file:' && window.location.pathname.endsWith('.php')) {
        const token = (typeof serverCsrfToken !== 'undefined') ? serverCsrfToken : '';
        fetch('api/api_deploy.php?action=save_workspace_state', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token
          },
          body: JSON.stringify({
            action: 'save_workspace_state',
            csrf_token: token,
            state: meta
          })
        }).catch(err => console.warn('Sync meta failed:', err));
      }
    }

    function initUserProjectsState() {
      const u = userData[currentUserKey];
      if (!u) return;

      try {
        const raw = localStorage.getItem(`utp_workspace_meta_${currentUserKey}`);
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed && Array.isArray(parsed.projects) && parsed.projects.length > 0) {
            syncProjectsFromMeta(parsed, true);
          }
        }
      } catch (e) {
        console.warn('Could not restore projects from localStorage:', e);
      }
    }

    function syncProjectsFromMeta(meta, forceActive = false) {
      const u = userData[currentUserKey];
      if (!u || !meta || !Array.isArray(meta.projects) || meta.projects.length === 0) return;

      const existingMap = new Map((u.projects || []).map(p => [p.id, p]));
      const merged = [];

      for (const pMeta of meta.projects) {
        const pId = pMeta.id;
        const slug = pMeta.slug !== undefined ? pMeta.slug : (pId === 'p1' ? '' : pMeta.name.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, ''));
        const publicUrl = (!slug || slug === 'p1' || pId === 'p1')
          ? `https://${currentUserKey}.example.com`
          : `https://${currentUserKey}.example.com/${slug}/`;

        if (existingMap.has(pId)) {
          const existing = existingMap.get(pId);
          existing.name = pMeta.name || existing.name;
          existing.slug = slug;
          existing.url = pMeta.url || publicUrl;
          if (pMeta.storage) existing.storage = pMeta.storage;
          merged.push(existing);
        } else {
          merged.push({
            id: pId,
            name: pMeta.name || 'Proyecto',
            slug: slug,
            subdomain: pMeta.subdomain || `${currentUserKey}-${slug || 'p1'}`,
            url: pMeta.url || publicUrl,
            storage: pMeta.storage || '0.0',
            files: [],
            trash: []
          });
        }
      }

      if (merged.length > 0) {
        u.projects = merged;
        saveUserProjectsState();
        renderProjectTabs();
      }

      if (meta.activeProjectId && u.projects.some(p => p.id === meta.activeProjectId)) {
        if (forceActive || !u.activeProjectId || !u.projects.some(p => p.id === u.activeProjectId)) {
          u.activeProjectId = meta.activeProjectId;
        }
      }
    }

    function promptDeleteProject(projectId, projectName) {
      if (projectId === 'p1') {
        showToast('No puedes eliminar el espacio principal (Proyecto 1)', 'error');
        return;
      }
      pendingDeleteProjectId = projectId;
      const elName = document.getElementById('deleteProjectTargetName');
      if (elName) elName.textContent = projectName || 'este proyecto';
      openModal('modalConfirmDeleteProject');
    }

    async function executeDeleteProject() {
      if (!pendingDeleteProjectId) return;
      const u = userData[currentUserKey];
      const target = u.projects.find(p => p.id === pendingDeleteProjectId);
      if (!target) return;

      const slug = target.slug || '';
      closeModal('modalConfirmDeleteProject');

      if (window.location.protocol !== 'file:' && window.location.pathname.endsWith('.php')) {
        const token = (typeof serverCsrfToken !== 'undefined') ? serverCsrfToken : '';
        try {
          await fetch('api/api_deploy.php?action=delete_project', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': token
            },
            body: JSON.stringify({
              action: 'delete_project',
              csrf_token: token,
              project_id: target.id,
              project_slug: slug
            })
          });
        } catch (e) {
          console.warn('Delete project server call failed:', e);
        }
      }

      u.projects = u.projects.filter(p => p.id !== pendingDeleteProjectId);
      if (u.activeProjectId === pendingDeleteProjectId) {
        u.activeProjectId = 'p1';
      }
      pendingDeleteProjectId = null;
      saveUserProjectsState();
      renderDashboard();
      parseTwemojis();
      await loadRealServerWorkspace();
      showToast(`Espacio <strong>${escapeHtml(target.name)}</strong> eliminado`, 'info');
    }

    // Obtener proyecto actualmente activo
    function getCurrentProject() {
      const u = userData[currentUserKey];
      if (!u || !u.projects || u.projects.length === 0) return null;
      let p = u.projects.find(proj => proj.id === u.activeProjectId);
      if (!p) {
        p = u.projects[0];
        u.activeProjectId = p.id;
      }
      return p;
    }

    // ==========================================
    // INICIALIZACIÓN
    // ==========================================
    
    // Renderizado universal de emojis estilo Apple Retina HD (Apple Color Emoji)
    const APPLE_FE0F_SET = new Set([
      '26a0', '1f6e1', '2600', '1f5d1', '2699', '2709', '270f', '260e', '2702', '2615', '2764', '26a1', '267b'
    ]);

    function parseAppleEmojis(target = document.body) {
      if (typeof twemoji !== 'undefined' && twemoji && twemoji.parse) {
        try {
          twemoji.parse(target, {
            callback: function(icon, options) {
              const finalIcon = APPLE_FE0F_SET.has(icon) ? (icon + '-fe0f') : icon;
              return 'https://cdn.jsdelivr.net/gh/iamcal/emoji-data@master/img-apple-160/' + finalIcon + '.png';
            },
            onerror: function() {
              if (this.src && !this.src.includes('-fe0f.png')) {
                this.src = this.src.replace('.png', '-fe0f.png');
              } else if (this.parentNode) {
                this.parentNode.replaceChild(document.createTextNode(this.alt), this);
              }
            }
          });
        } catch (e) {
          console.warn('[AppleEmoji] Error al procesar emojis:', e);
        }
      }
    }
    // Alias para compatibilidad total con todas las llamadas del sistema
    window.parseAppleEmojis = parseAppleEmojis;
    window.parseTwemojis = parseAppleEmojis;
    window.addEventListener('DOMContentLoaded', () => {
      const activeUser = serverActiveUser || sessionStorage.getItem('utp_2fa_verified_user') || 'tenant_a';
      if (activeUser && userData[activeUser]) {
        currentUserKey = activeUser;
      }
      initUserProjectsState();
      renderDashboard();
      parseTwemojis();
      loadRealServerWorkspace();
      refreshServerTelemetry(false);
      setInterval(() => refreshServerTelemetry(false), 1000);
      setupEditorScrollSync();

      // Reloj continuo de Uptime cada segundo
      setInterval(() => {
        if (currentUptimeSec > 0) {
          currentUptimeSec++;
          const d = Math.floor(currentUptimeSec / 86400);
          const h = Math.floor((currentUptimeSec % 86400) / 3600);
          const m = Math.floor((currentUptimeSec % 3600) / 60);
          const s = currentUptimeSec % 60;
          const fmt = `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;

          const el1 = document.getElementById('htopUptime');
          if (el1) el1.textContent = fmt;
          const el2 = document.getElementById('htopModalUptime');
          if (el2) el2.textContent = fmt;
        }
      }, 1000);
    });

    // =========================================================================
    // TELEMETRÍA REAL DEL SERVIDOR Y SINCRONIZACIÓN EN VIVO (PHP)
    // =========================================================================
    let currentUptimeSec = 0;

    let isFetchingTelemetry = false;
    async function refreshServerTelemetry(isManual = false) {
      if (isFetchingTelemetry) return;
      if (window.location.protocol === 'file:' && !window.location.pathname.endsWith('.php')) {
        return;
      }
      isFetchingTelemetry = true;

      try {
        const resp = await fetch('api/api_telemetry.php', { cache: 'no-store' });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.success && data.telemetry) {
          const t = data.telemetry;

          if (t.uptimeSec) {
            currentUptimeSec = t.uptimeSec;
          }

          // 1. Tarjeta Lateral: CPU con Load Average estilo htop
          const sCpuText = document.getElementById('sidebarCpuText');
          const sCpuBar = document.getElementById('sidebarCpuBar');
          const sCpuDetail = document.getElementById('sidebarCpuDetail');
          const cpuVal = typeof t.cpuPercent === 'number' ? Math.round(t.cpuPercent) : parseInt(t.cpuPercent) || 0;
          if (sCpuText) sCpuText.textContent = `${cpuVal}%`;
          if (sCpuBar) sCpuBar.style.width = `${Math.min(100, Math.max(3, cpuVal))}%`;
          if (sCpuDetail) sCpuDetail.textContent = `(${t.cpuCores || 1} Cores)`;

          // 2. Tarjeta Lateral: Memoria RAM
          const sRamText = document.getElementById('sidebarRamText');
          const sRamBar = document.getElementById('sidebarRamBar');
          const sRamDetail = document.getElementById('sidebarRamDetail');
          const ramMb = Math.round(t.ramUsedMB);
          const ramPct = Math.round(t.ramPercent);
          if (sRamText) sRamText.textContent = `${ramMb} MB`;
          if (sRamBar) sRamBar.style.width = `${Math.min(100, Math.max(4, ramPct))}%`;
          if (sRamDetail) sRamDetail.textContent = `/ ${(t.ramTotalMB / 1024).toFixed(2)} GB (${ramPct}%)`;

          // 3. Tarjeta Lateral: Almacenamiento SSD
          const sDiskText = document.getElementById('sidebarDiskText');
          const sDiskBar = document.getElementById('sidebarDiskBar');
          const sDiskDetail = document.getElementById('sidebarDiskDetail');
          const diskPct = Math.round(t.diskPercent);
          if (sDiskText) sDiskText.textContent = `${t.diskUsedGB} GB`;
          if (sDiskBar) sDiskBar.style.width = `${Math.min(100, Math.max(3, diskPct))}%`;
          if (sDiskDetail) sDiskDetail.textContent = `/ ${t.diskTotalGB} GB (${diskPct}%)`;

          // 4. Almacenamiento real de la carpeta del usuario
          const userStorageElem = document.getElementById('userStorageUsed');
          if (userStorageElem) userStorageElem.textContent = t.userStorageFormatted;

          // 5. Uptime & Tareas
          const uptimeElem = document.getElementById('htopUptime');
          if (uptimeElem && t.uptime) uptimeElem.textContent = t.uptime;
          const tasksElem = document.getElementById('htopTasks');
          if (tasksElem) tasksElem.textContent = t.tasks || 110;
          const swapElem = document.getElementById('htopSwap');
          if (swapElem) swapElem.textContent = `${t.swapPercent || 0}%`;

          // 6. Modal de Monitor HTOP (si está abierto o se va a abrir)
          const core1Bar = document.getElementById('htopCore1Bar');
          const core1Text = document.getElementById('htopCore1Text');
          const core2Bar = document.getElementById('htopCore2Bar');
          const core2Text = document.getElementById('htopCore2Text');
          if (t.coreLoads && t.coreLoads.length >= 2) {
            if (core1Bar) core1Bar.style.width = `${Math.max(2, t.coreLoads[0].percent)}%`;
            if (core1Text) core1Text.textContent = `${t.coreLoads[0].percent}%`;
            if (core2Bar) core2Bar.style.width = `${Math.max(2, t.coreLoads[1].percent)}%`;
            if (core2Text) core2Text.textContent = `${t.coreLoads[1].percent}%`;
          } else {
            if (core1Bar) core1Bar.style.width = `${Math.max(2, cpuVal)}%`;
            if (core1Text) core1Text.textContent = `${cpuVal}%`;
            if (core2Bar) core2Bar.style.width = `${Math.max(2, cpuVal)}%`;
            if (core2Text) core2Text.textContent = `${cpuVal}%`;
          }

          const modalRamBar = document.getElementById('htopModalRamBar');
          const modalRamText = document.getElementById('htopModalRamText');
          if (modalRamBar) modalRamBar.style.width = `${Math.max(4, ramPct)}%`;
          if (modalRamText) modalRamText.textContent = `${ramMb}M/${(t.ramTotalMB / 1024).toFixed(2)}G`;

          const modalTasks = document.getElementById('htopModalTasks');
          if (modalTasks) modalTasks.textContent = t.tasks;

          const modalLoad = document.getElementById('htopModalLoad');
          if (modalLoad) modalLoad.textContent = t.cpuLoadFormatted || t.cpuLoad;

          const modalUptime = document.getElementById('htopModalUptime');
          if (modalUptime && t.uptime) modalUptime.textContent = t.uptime;

          // Tabla de Procesos Estilo htop
          const procTbody = document.getElementById('htopProcessTableBody');
          if (procTbody && Array.isArray(t.processes) && t.processes.length > 0) {
            procTbody.innerHTML = t.processes.map((p, idx) => `
              <tr class="${idx % 2 === 0 ? 'bg-slate-900/60' : 'bg-slate-950'} hover:bg-slate-800 transition">
                <td class="py-1.5 px-3.5 text-cyan-400 font-bold">${escapeHtml(p.pid)}</td>
                <td class="py-1.5 px-3.5 text-slate-300">${escapeHtml(p.user)}</td>
                <td class="py-1.5 px-3.5 text-emerald-400 font-bold">${escapeHtml(p.cpu)}%</td>
                <td class="py-1.5 px-3.5 text-indigo-300 font-bold">${escapeHtml(p.mem)}%</td>
                <td class="py-1.5 px-3.5 text-white font-mono">${escapeHtml(p.command)}</td>
              </tr>
            `).join('');
          }

          if (isManual) {
            showToast('✓ Métricas sincronizadas en vivo', 'success');
          }
        }
      } catch (err) {
        console.error('Error al obtener telemetría real:', err);
      } finally {
        isFetchingTelemetry = false;
      }
    }

    function openHtopModal() {
      openModal('modalHtopMonitor');
      refreshServerTelemetry(false);
    }

    function closeHtopModal() {
      closeModal('modalHtopMonitor');
    }

    // Cargar archivos reales que existen en el disco físico del servidor
    async function loadRealServerWorkspace() {
      if (window.location.protocol === 'file:' || !window.location.pathname.endsWith('.php')) {
        return;
      }

      try {
        const u = userData[currentUserKey];
        const proj = getCurrentProject();
        const slug = proj ? (proj.slug || '') : '';
        const requestedProjId = proj ? proj.id : null;

        const resp = await fetch(`api/api_deploy.php?action=get_workspace&project_slug=${encodeURIComponent(slug)}`);
        if (!resp.ok) return;
        const data = await resp.json();
        if (data.success) {
          if (data.workspace_meta && Array.isArray(data.workspace_meta.projects) && data.workspace_meta.projects.length > 0) {
            syncProjectsFromMeta(data.workspace_meta, false);
          }
          if (Array.isArray(data.files)) {
            const curProj = (u && u.projects) ? u.projects.find(p => p.id === requestedProjId) : getCurrentProject();
            if (curProj) {
              curProj.files = data.files;
              if (data.publicUrl) curProj.url = data.publicUrl;
              if (u && curProj.id === u.activeProjectId) {
                renderFileList();
                renderProjectTabs();
              }
            }
          }
        }
      } catch (err) {
        console.error('Error al cargar espacio real del servidor:', err);
      }
    }

    // =========================================================================
    // BOTÓN DESTACADO: DESPLEGAR PÁGINA EN VIVO
    // =========================================================================
    async function deployCurrentProjectToLive() {
      const proj = getCurrentProject();
      if (!proj) return;

      const btn = document.getElementById('btnDeployLive');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = `
          <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>Desplegando en Servidor...</span>
        `;
      }

      // CASO VACÍO: Si el usuario borró todos los archivos del espacio
      if (proj.files.length === 0) {
        showToast('⚠️ No hay archivos en tu espacio. Limpiando servidor y activando página 404...', 'info');
      } else {
        showToast('🚀 Sincronizando archivos en tu servidor web...', 'info');
      }

      try {
        // Preparar payload de archivos activos
        const payloadFiles = [];
        for (const f of proj.files) {
          const item = { name: f.name };
          if (f.base64) {
            item.base64 = f.base64;
          } else if (f._rawBlob && f.category === 'MEDIA') {
            item.base64 = await new Promise((res) => {
              const r = new FileReader();
              r.onload = () => res(r.result);
              r.readAsDataURL(f._rawBlob);
            });
          } else {
            item.code = f.code || '';
          }
          payloadFiles.push(item);
        }

        const token = (typeof serverCsrfToken !== 'undefined') ? serverCsrfToken : '';
        const resp = await fetch('api/api_deploy.php?action=deploy_files', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token
          },
          body: JSON.stringify({
            action: 'deploy_files',
            csrf_token: token,
            project_id: proj.id,
            project_slug: proj.slug || '',
            files: payloadFiles
          })
        });

        const data = await resp.json();

        // Si el servidor detectó espacio vacío y activó 404
        if (data.isEmpty) {
          proj.files = [];
          if (data.publicUrl) proj.url = data.publicUrl;
          saveUserProjectsState();
          renderDashboard();
      parseTwemojis();
          await refreshServerTelemetry(false);
          highlightDeployUrlCard(data.publicUrl || proj.url);
          showToast('⚠️ No hay ningún archivo publicado en este espacio. Muestra 404 (Nada aquí todavía).', 'error');
          return;
        }

        if (data.success) {
          // Asignar estrictamente los archivos devueltos por el servidor
          proj.files = Array.isArray(data.files) ? data.files : [];
          if (data.publicUrl) proj.url = data.publicUrl;
          saveUserProjectsState();
          await refreshServerTelemetry(false);
          renderDashboard();
      parseTwemojis();
          highlightDeployUrlCard(data.publicUrl || proj.url);
          showToast(`✓ ¡${escapeHtml(proj.name)} desplegado! URL lista para visitar`, 'success');
        } else {
          showToast(`Error al desplegar: ${escapeHtml(data.error || 'Verifica los archivos')}`, 'error');
        }
      } catch (err) {
        showToast('✓ Sincronización completada localmente', 'info');
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = `
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>🚀 Desplegar Página</span>
          `;
          parseAppleEmojis(btn);
        }
      }
    }

    // ==========================================
    // RENDERIZADO DEL DASHBOARD
    // ==========================================
    function renderDashboard() {
      const u = userData[currentUserKey];
      if (!u) return;
      const proj = getCurrentProject();
      if (!proj) return;

      // Header y Textos Generales
      document.getElementById('headerUserName').textContent = u.name;
      document.getElementById('welcomeName').textContent = u.name;
      document.getElementById('userAvatar').textContent = u.initials;
      document.getElementById('userStorageUsed').textContent = `${proj.storage} MB`;
      
      // Renderizar Pestañas de Espacios de Trabajo (Proyectos)
      renderProjectTabs();

      // Configurar Apartado de URL Desplegada para el Proyecto Actual
      const effectiveUrl = proj.url || `https://${currentUserKey}.example.com`;
      const urlDisp = document.getElementById('deployedUrlDisplay');
      if (urlDisp) urlDisp.textContent = effectiveUrl;
      const btnVisit = document.getElementById('btnVisitDeployedUrl');
      if (btnVisit) btnVisit.href = effectiveUrl;
      const btnOpenExt = document.getElementById('btnOpenDeployedExternal');
      if (btnOpenExt) btnOpenExt.href = effectiveUrl;
      const navPub = document.getElementById('navPublicWeb');
      if (navPub) navPub.href = effectiveUrl;
      const btnVisitHeader = document.getElementById('btnVisitSite');
      if (btnVisitHeader) btnVisitHeader.href = effectiveUrl;
      const projBadge = document.getElementById('currentProjectBadge');
      if (projBadge) projBadge.textContent = proj.name;

      // Conteo de métricas por tipo en este proyecto
      const htmlCount = proj.files.filter(f => f.type === 'HTML').length;
      const cssCount = proj.files.filter(f => f.type === 'CSS').length;
      const phpCount = proj.files.filter(f => f.type === 'PHP').length;

      document.getElementById('statHTML').textContent = `${htmlCount} Archivo${htmlCount !== 1 ? 's' : ''}`;
      document.getElementById('statCSS').textContent = `${cssCount} Archivo${cssCount !== 1 ? 's' : ''}`;
      document.getElementById('statPHP').textContent = `${phpCount} Archivo${phpCount !== 1 ? 's' : ''}`;
      document.getElementById('trashCountBadge').textContent = proj.trash.length;

      // Renderizar Lista de Archivos
      renderFileList();
    }

    // ==========================================
    // RENDERIZADO DE PESTAÑAS DE PROYECTOS
    // ==========================================
    function renderProjectTabs() {
      const u = userData[currentUserKey];
      const container = document.getElementById('projectTabsContainer');
      if (!container) return;
      container.innerHTML = '';
      const isLight = !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');

      u.projects.forEach(p => {
        const isActive = p.id === u.activeProjectId;
        const isSubproject = (p.id !== 'p1');
        const btn = document.createElement('div');
        btn.addEventListener('click', (e) => { if (e.target.closest('[data-action="delete-project"]')) return; switchProject(p.id); });

        const safeProjName = escapeHtml(p.name);
        const delBtnHtml = isSubproject ? `
          <button type="button" title="Eliminar espacio" data-action="delete-project" data-project-id="${escapeHtml(p.id)}" data-project-name="${safeProjName}" class="ml-1 -mr-1 p-0.5 rounded-full ${isActive ? 'hover:bg-blue-700/60 text-blue-200 hover:text-white' : 'hover:bg-rose-500/20 text-slate-400 hover:text-rose-600'} font-bold transition text-[11px] leading-none">✕</button>
        ` : '';

        if (isActive) {
          btn.className = 'px-3.5 py-1.5 rounded-xl font-bold bg-blue-600 text-white shadow-sm text-xs transition flex items-center gap-1.5 cursor-pointer select-none';
          btn.innerHTML = `<span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span><span style="color:#ffffff !important;">${safeProjName}</span>${delBtnHtml}`;
        } else {
          btn.className = isLight
            ? 'px-3.5 py-1.5 rounded-xl font-semibold bg-slate-100 hover:bg-slate-200/80 text-slate-700 hover:text-slate-950 text-xs transition flex items-center gap-1.5 border border-slate-200/80 shadow-2xs cursor-pointer select-none'
            : 'px-3.5 py-1.5 rounded-xl font-semibold bg-slate-800/90 hover:bg-slate-700 text-slate-300 text-xs transition flex items-center gap-1.5 border border-slate-700/60 cursor-pointer select-none';
          btn.innerHTML = `<span>${safeProjName}</span>${delBtnHtml}`;
        }
        container.appendChild(btn);
      });
    }

    async function switchProject(projectId) {
      selectedFileIds.clear();
      const u = userData[currentUserKey];
      if (u.activeProjectId === projectId) return;

      u.activeProjectId = projectId;
      currentFilter = 'ALL';
      setFilter('ALL');
      saveUserProjectsState();
      renderDashboard();
      parseTwemojis();
      const cur = getCurrentProject();
      showToast(`Cambiado al espacio: <strong>${escapeHtml(cur ? cur.name : '')}</strong>`, 'info');
      await loadRealServerWorkspace();
    }

    // ==========================================
    // RENDERIZADO DE ARCHIVOS O PAPELERA
    // ==========================================
    function renderFileList() {
      const proj = getCurrentProject();
      if (!proj) return;
      const container = document.getElementById('fileListContainer');
      const trashNotice = document.getElementById('trashNoticeHeader');
      container.innerHTML = '';

      if (currentFilter === 'TRASH') {
        trashNotice.classList.remove('hidden');

        if (proj.trash.length === 0) {
          container.innerHTML = `
            <div class="empty-files-box text-center py-12 px-4 border border-dashed border-slate-200 rounded-3xl bg-slate-50/50">
              <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-500 mx-auto flex items-center justify-center mb-3 shadow-2xs">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </div>
              <p class="empty-files-title text-sm font-bold text-slate-700">La papelera de ${escapeHtml(proj.name)} está vacía</p>
              <p class="text-xs text-slate-400 mt-1">No tienes ningún archivo eliminado en este espacio.</p>
            </div>
          `;
          updateBulkActionBar();
          return;
        }

        const isLight = !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');
        proj.trash.forEach(f => {
          const isSelected = selectedFileIds.has(f.id);
          const rowCls = isLight
            ? (isSelected ? 'border-rose-400 ring-2 ring-rose-400/30 bg-rose-100/60' : 'border-rose-200 bg-rose-50/40 hover:bg-white hover:border-rose-300')
            : (isSelected ? 'border-rose-500/80 ring-2 ring-rose-500/40 bg-rose-950/50' : 'border-rose-900/60 bg-rose-950/30 hover:bg-rose-950/50 hover:border-rose-800/80');
          const badgeCls = isLight ? 'bg-rose-100 text-rose-700 font-bold border border-rose-200' : 'bg-rose-900/50 border border-rose-700/60 text-rose-200 font-bold';
          const restoreCls = isLight
            ? 'text-xs font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 border border-blue-200 px-3 py-1.5 rounded-xl transition flex items-center gap-1'
            : 'text-xs font-bold text-blue-300 bg-blue-950/70 hover:bg-blue-900/70 border border-blue-800/60 px-3 py-1.5 rounded-xl transition flex items-center gap-1.5';
          const delCls = isLight
            ? 'text-xs font-bold text-rose-600 hover:bg-rose-100/80 border border-rose-200 px-2.5 py-1.5 rounded-xl transition'
            : 'text-xs font-bold text-rose-300 hover:bg-rose-900/60 border border-rose-800/60 px-2.5 py-1.5 rounded-xl transition';
          const chkCls = isLight ? 'w-4 h-4 rounded text-rose-600 border-slate-300 bg-white focus:ring-rose-500 cursor-pointer' : 'w-4 h-4 rounded text-rose-500 border-slate-700 bg-slate-900 focus:ring-rose-500 cursor-pointer';

          container.innerHTML += `
            <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 sm:p-3.5 rounded-2xl border transition gap-2.5 sm:gap-3 ${rowCls}">
              <div class="flex items-center gap-2.5 sm:gap-3 min-w-0 flex-1">
                <input type="checkbox" data-change-action="toggle-select-file" data-file-id="${f.id}" ${isSelected ? 'checked' : ''} class="${chkCls} shrink-0">
                <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl ${badgeCls} text-xs flex items-center justify-center shrink-0">${f.type}</div>
                <div class="min-w-0 flex-1">
                  <span class="text-xs font-bold ${isLight ? 'text-slate-800' : 'text-slate-100'} line-through font-mono font-semibold block truncate">${escapeHtml(f.name)}</span>
                </div>
              </div>
              <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                <button data-action="restore-from-trash" data-file-id="${f.id}" class="${restoreCls}">
                  <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l6-6m-6 6l6 6"/></svg><span>Restaurar</span>
                </button>
                <button data-action="delete-permanent" data-file-id="${f.id}" class="${delCls}" title="Eliminar definitivamente">
                  ✕
                </button>
              </div>
            </div>
          `;
        });
        updateBulkActionBar();
        return;
      }

      trashNotice.classList.add('hidden');

      // Filtrar archivos activos
      let list = proj.files;
      if (currentFilter === 'MEDIA') {
        list = proj.files.filter(f => ['IMG', 'VIDEO', 'AUDIO'].includes(f.type) || f.category === 'MEDIA');
      } else if (currentFilter !== 'ALL') {
        list = proj.files.filter(f => f.type === currentFilter);
      }

      if (list.length === 0) {
        container.innerHTML = `
          <div class="empty-files-box text-center py-12 px-4 border border-dashed border-slate-200 rounded-3xl bg-slate-50/50">
            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-500 mx-auto flex items-center justify-center mb-3 shadow-2xs">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            </div>
            <p class="empty-files-title text-sm font-bold text-slate-700">No hay archivos en esta categoría de ${escapeHtml(proj.name)}</p>
            <p class="text-xs text-slate-400 mt-1">Crea un archivo nuevo o sube tu proyecto.</p>
            <button data-action="prompt-editor-choice" data-lang="${currentFilter !== 'ALL' ? currentFilter : 'HTML'}" class="mt-4 px-4 py-2 rounded-xl bg-blue-600 text-white text-xs font-bold shadow-xs">
              + Crear archivo ${currentFilter !== 'ALL' ? currentFilter : ''}
            </button>
          </div>
        `;
        updateBulkActionBar();
        return;
      }

      const isLight = !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');
      list.forEach(f => {
        const typeColors = isLight ? {
          HTML: 'bg-amber-100 text-amber-800 border border-amber-200',
          CSS: 'bg-blue-100 text-blue-800 border border-blue-200',
          PHP: 'bg-purple-100 text-purple-800 border border-purple-200',
          IMG: 'bg-emerald-100 text-emerald-800 border border-emerald-200',
          VIDEO: 'bg-rose-100 text-rose-800 border border-rose-200',
          AUDIO: 'bg-indigo-100 text-indigo-800 border border-indigo-200',
          JS: 'bg-yellow-100 text-yellow-800 border border-yellow-200',
          DOC: 'bg-slate-100 text-slate-800 border border-slate-200'
        } : {
          HTML: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
          CSS: 'bg-blue-500/15 text-blue-400 border border-blue-500/30',
          PHP: 'bg-purple-500/15 text-purple-400 border border-purple-500/30',
          IMG: 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30',
          VIDEO: 'bg-rose-500/15 text-rose-400 border border-rose-500/30',
          AUDIO: 'bg-indigo-500/15 text-indigo-400 border border-indigo-500/30',
          JS: 'bg-yellow-500/15 text-yellow-400 border border-yellow-500/30',
          DOC: 'bg-slate-800 text-slate-300 border border-slate-700'
        };
        const color = typeColors[f.type] || (isLight ? 'bg-slate-100 text-slate-700 border border-slate-200' : 'bg-slate-800 text-slate-300 border border-slate-700');
        const isSelected = selectedFileIds.has(f.id);

        const rowCls = isLight
          ? (isSelected ? 'border-blue-400 ring-2 ring-blue-500/40 bg-blue-50/70' : 'border-slate-200 bg-white hover:bg-slate-50 hover:border-slate-300 shadow-2xs')
          : (isSelected ? 'border-blue-500/80 ring-2 ring-blue-500/50 bg-blue-950/40' : 'border-slate-800/80 bg-slate-900/60 hover:bg-slate-850 hover:border-slate-700');

        const fileNameCls = isLight ? 'text-xs font-bold text-slate-800 font-mono' : 'text-xs font-bold text-slate-200 font-mono';
        const chkCls = isLight ? 'w-4 h-4 rounded text-blue-600 border-slate-300 bg-white focus:ring-blue-500 cursor-pointer' : 'w-4 h-4 rounded text-blue-500 border-slate-700 bg-slate-950 focus:ring-blue-500 cursor-pointer';

        const editBtnCls = isLight
          ? 'text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow-2xs'
          : 'text-xs font-semibold text-slate-200 bg-slate-800 border border-slate-700 hover:bg-slate-750 px-3 py-1.5 rounded-xl transition flex items-center gap-1';

        const viewMediaBtnCls = isLight
          ? 'text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow-2xs'
          : 'text-xs font-semibold text-emerald-400 bg-emerald-950/40 hover:bg-emerald-900/40 border border-emerald-800/60 px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow-2xs';

        const delBtnCls = isLight
          ? 'text-xs font-semibold text-rose-500 hover:text-rose-700 hover:bg-rose-50 p-2 rounded-xl transition'
          : 'text-xs font-semibold text-rose-400 hover:text-rose-300 hover:bg-rose-950/50 p-2 rounded-xl transition';

        container.innerHTML += `
          <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 sm:p-3.5 rounded-2xl border transition gap-2.5 sm:gap-3 ${rowCls}">
            <div class="flex items-center gap-2.5 sm:gap-3 min-w-0 flex-1">
              <input type="checkbox" data-change-action="toggle-select-file" data-file-id="${f.id}" ${isSelected ? 'checked' : ''} class="${chkCls} shrink-0">
              <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl ${color} font-bold text-xs flex items-center justify-center shrink-0">${f.type}</div>
              <div class="min-w-0 flex-1">
                <span class="${fileNameCls} block truncate">${escapeHtml(f.name)}</span>
              </div>
            </div>
            <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
              ${['IMG', 'VIDEO', 'AUDIO', 'DOC'].includes(f.type) ? `
                <button data-action="preview-media" data-file-id="${f.id}" class="${viewMediaBtnCls}">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  <span>Ver</span>
                </button>
              ` : `
                <button data-action="open-editor-file" data-file-id="${f.id}" class="${editBtnCls}">
                  <svg class="w-3.5 h-3.5 ${isLight ? 'text-slate-500' : 'text-slate-400'}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  <span>Editar</span>
                </button>
              `}
              <!-- BOTÓN DE ELIMINAR CON CONFIRMACIÓN -->
              <button data-action="confirm-delete-file" data-file-id="${f.id}" class="${delBtnCls}" title="Mover a papelera">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              </button>
            </div>
          </div>
        `;
      });

      updateBulkActionBar();
    }

    // ==========================================
    // FUNCIONES DE SELECCIÓN MÚLTIPLE Y ACCIONES POR LOTE
    // ==========================================
    function toggleSelectFile(fileId) {
      if (selectedFileIds.has(fileId)) {
        selectedFileIds.delete(fileId);
      } else {
        selectedFileIds.add(fileId);
      }
      renderFileList();
    }

    function toggleSelectAll(checked) {
      const proj = getCurrentProject();
      if (!proj) return;
      let visibleFiles = [];
      if (currentFilter === 'TRASH') {
        visibleFiles = proj.trash;
      } else if (currentFilter === 'ALL') {
        visibleFiles = proj.files;
      } else {
        visibleFiles = proj.files.filter(f => f.type === currentFilter);
      }

      if (checked) {
        visibleFiles.forEach(f => selectedFileIds.add(f.id));
      } else {
        visibleFiles.forEach(f => selectedFileIds.delete(f.id));
      }
      renderFileList();
    }

    function clearSelection() {
      selectedFileIds.clear();
      renderFileList();
    }

    function updateBulkActionBar() {
      const bar = document.getElementById('bulkActionBar');
      const countSpan = document.getElementById('bulkSelectedCount');
      const selectAllCb = document.getElementById('selectAllCheckbox');
      const activeActions = document.getElementById('bulkActiveActions');
      const trashActions = document.getElementById('bulkTrashActions');
      const btnBulkDeleteText = document.getElementById('btnBulkDeleteText');

      if (!bar) return;

      const count = selectedFileIds.size;
      if (count === 0) {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
        if (selectAllCb) selectAllCb.checked = false;
        return;
      }

      bar.classList.remove('hidden');
      bar.classList.add('flex');
      countSpan.textContent = `${count} seleccionado${count > 1 ? 's' : ''}`;

      if (currentFilter === 'TRASH') {
        activeActions.classList.add('hidden');
        trashActions.classList.remove('hidden');
      } else {
        activeActions.classList.remove('hidden');
        trashActions.classList.add('hidden');
        btnBulkDeleteText.textContent = `Mover a papelera (${count})`;
      }

      const proj = getCurrentProject();
      if (proj) {
        let visibleFiles = [];
        if (currentFilter === 'TRASH') {
          visibleFiles = proj.trash;
        } else if (currentFilter === 'ALL') {
          visibleFiles = proj.files;
        } else {
          visibleFiles = proj.files.filter(f => f.type === currentFilter);
        }
        if (selectAllCb) {
          selectAllCb.checked = visibleFiles.length > 0 && visibleFiles.every(f => selectedFileIds.has(f.id));
        }
      }
    }

    function confirmBulkDelete() {
      const proj = getCurrentProject();
      if (!proj || selectedFileIds.size === 0) return;

      const filesToDelete = proj.files.filter(f => selectedFileIds.has(f.id));
      if (filesToDelete.length === 0) return;

      document.getElementById('bulkDeleteCount').textContent = filesToDelete.length;
      document.getElementById('bulkDeleteNames').textContent = filesToDelete.map(f => f.name).join(', ');
      openModal('modalConfirmBulkDelete');
    }

    function executeBulkDelete() {
      const proj = getCurrentProject();
      if (!proj || selectedFileIds.size === 0) return;

      const count = selectedFileIds.size;
      proj.files = proj.files.filter(f => {
        if (selectedFileIds.has(f.id)) {
          proj.trash.push(f);
          return false;
        }
        return true;
      });

      closeModal('modalConfirmBulkDelete');
      selectedFileIds.clear();
      showToast(`Se movieron ${count} archivo${count > 1 ? 's' : ''} a la papelera`, 'info');
      renderDashboard();
      parseTwemojis();
    }

    function executeBulkRestore() {
      const proj = getCurrentProject();
      if (!proj || selectedFileIds.size === 0) return;

      let restoredCount = 0;
      proj.trash = proj.trash.filter(f => {
        if (selectedFileIds.has(f.id)) {
          proj.files.push(f);
          restoredCount++;
          return false;
        }
        return true;
      });

      selectedFileIds.clear();
      showToast(`Se restauraron ${restoredCount} archivo${restoredCount > 1 ? 's' : ''}`, 'success');
      renderDashboard();
      parseTwemojis();
    }

    function confirmBulkPermanentDelete() {
      const proj = getCurrentProject();
      if (!proj || selectedFileIds.size === 0) return;

      const filesToDelete = proj.trash.filter(f => selectedFileIds.has(f.id));
      if (filesToDelete.length === 0) return;

      document.getElementById('bulkPermanentCount').textContent = filesToDelete.length;
      document.getElementById('bulkPermanentNames').textContent = filesToDelete.map(f => f.name).join(', ');
      openModal('modalConfirmBulkPermanent');
    }

    function executeBulkPermanentDelete() {
      const proj = getCurrentProject();
      if (!proj || selectedFileIds.size === 0) return;

      const count = selectedFileIds.size;
      proj.trash = proj.trash.filter(f => !selectedFileIds.has(f.id));

      closeModal('modalConfirmBulkPermanent');
      selectedFileIds.clear();
      showToast(`Se eliminaron ${count} archivo${count > 1 ? 's' : ''} definitivamente`, 'error');
      renderDashboard();
      parseTwemojis();
    }

    // ==========================================
    // GESTIÓN DE FILTROS DE PESTAÑAS
    // ==========================================
    function setFilter(filter) {
      if (currentFilter !== filter) {
        selectedFileIds.clear();
      }
      currentFilter = filter;
      const isLight = !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');
      ['ALL', 'HTML', 'CSS', 'PHP', 'MEDIA', 'TRASH'].forEach(ft => {
        const btn = document.getElementById(`filter-${ft}`);
        if (!btn) return;
        if (ft === filter) {
          if (ft === 'TRASH') {
            btn.className = 'px-3 py-1.5 rounded-lg font-bold bg-rose-600 text-white shadow-xs transition flex items-center gap-1';
          } else {
            btn.className = isLight
              ? 'px-3 py-1.5 rounded-lg font-bold bg-white text-slate-800 shadow-xs transition border border-slate-200'
              : 'px-3 py-1.5 rounded-lg font-bold bg-slate-800 text-white shadow-xs transition border border-slate-700/60';
          }
        } else {
          if (ft === 'TRASH') {
            btn.className = isLight
              ? 'px-3 py-1.5 rounded-lg font-semibold text-rose-600 hover:bg-rose-50 transition flex items-center gap-1'
              : 'px-3 py-1.5 rounded-lg font-semibold text-rose-400 hover:bg-rose-950/50 transition flex items-center gap-1';
          } else {
            btn.className = isLight
              ? 'px-3 py-1.5 rounded-lg font-semibold text-slate-600 hover:text-slate-900 transition'
              : 'px-3 py-1.5 rounded-lg font-semibold text-slate-400 hover:text-white transition';
          }
        }
      });
      renderFileList();
    }

    // ==========================================
    // SUBIDAS INTELIGENTES: REEMPLAZAR O NUEVO ESPACIO
    // ==========================================
    function triggerUploadModal() {
      document.getElementById('fileInputDirect').click();
    }

    // Prevención estricta: evita que el navegador abra carpetas o archivos al soltarlos
    window.addEventListener('dragover', (e) => e.preventDefault(), false);
    window.addEventListener('drop', (e) => e.preventDefault(), false);

    // Lectura recursiva completa de carpetas y subcarpetas para Drag & Drop
    async function extractAllFilesFromDataTransfer(dataTransfer) {
      const results = [];
      const items = dataTransfer.items;

      if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
        const queue = [];
        for (let i = 0; i < items.length; i++) {
          const entry = items[i].webkitGetAsEntry();
          if (entry) queue.push(entry);
        }

        async function readDirectoryRecursively(entry, currentPath = '') {
          if (entry.isFile) {
            const file = await new Promise((res, rej) => entry.file(res, rej));
            file._customPath = currentPath ? `${currentPath}/${file.name}` : file.name;
            results.push(file);
          } else if (entry.isDirectory) {
            const reader = entry.createReader();
            const readEntriesBatch = async () => {
              const entries = await new Promise((res, rej) => reader.readEntries(res, rej));
              if (entries.length > 0) {
                for (const child of entries) {
                  await readDirectoryRecursively(child, currentPath ? `${currentPath}/${entry.name}` : entry.name);
                }
                await readEntriesBatch();
              }
            };
            await readEntriesBatch();
          }
        }

        for (const rootEntry of queue) {
          await readDirectoryRecursively(rootEntry);
        }
      } else if (dataTransfer.files && dataTransfer.files.length > 0) {
        for (let i = 0; i < dataTransfer.files.length; i++) {
          results.push(dataTransfer.files[i]);
        }
      }

      return results;
    }

    const dropzone = document.getElementById('dropzone');
    if (dropzone) {
      ['dragenter', 'dragover'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.add('dropzone-active');
        });
      });

      ['dragleave', 'drop'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
          e.preventDefault();
          e.stopPropagation();
          dropzone.classList.remove('dropzone-active');
        });
      });

      dropzone.addEventListener('drop', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dropzone-active');

        showToast('Leyendo archivos y carpetas...', 'info');
        const extracted = await extractAllFilesFromDataTransfer(e.dataTransfer);
        if (extracted && extracted.length > 0) {
          onFilesOrFolderPicked(extracted);
        } else {
          showToast('No se encontraron archivos para procesar', 'error');
        }
      });
    }

    // Inputs directos de Archivo y Carpeta con detección de cambio
    const fileInputDirect = document.getElementById('fileInputDirect');
    if (fileInputDirect) {
      fileInputDirect.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
          onFilesOrFolderPicked(Array.from(e.target.files));
          e.target.value = '';
        }
      });
    }

    const folderInputDirect = document.getElementById('folderInputDirect');
    if (folderInputDirect) {
      folderInputDirect.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
          onFilesOrFolderPicked(Array.from(e.target.files));
          e.target.value = '';
        }
      });
    }

    function onFilesOrFolderPicked(files) {
      if (!files || files.length === 0) return;
      
      pendingUploadFiles = Array.from(files);
      const proj = getCurrentProject();

      const hasExistingFiles = proj && proj.files && proj.files.length > 0;

      if (hasExistingFiles) {
        document.getElementById('conflictCurrentProjectName').textContent = proj.name;
        const u = userData[currentUserKey];
        document.getElementById('newProjectNameInput').value = `Proyecto ${u.projects.length + 1}`;
        openModal('modalUploadConflict');
      } else {
        proceedWithUploadToProject(proj, false);
      }
    }

    function cancelUploadConflictModal() {
      pendingUploadFiles = [];
      const f1 = document.getElementById('mainFileInput');
      if (f1) f1.value = '';
      const f2 = document.getElementById('mainFolderInput');
      if (f2) f2.value = '';
      closeModal('modalUploadConflict');
    }

    function chooseUploadAction(action) {
      closeModal('modalUploadConflict');
      const u = userData[currentUserKey];

      if (action === 'replace') {
        const proj = getCurrentProject();
        if (proj) {
          // Reemplazo limpio: Borra los archivos anteriores del proyecto y deja únicamente los nuevos subidos
          proj.files = [];
          proj.trash = [];
          saveUserProjectsState();
          proceedWithUploadToProject(proj, false);
        }
      } else if (action === 'new_space') {
        const rawInput = document.getElementById('newProjectNameInput').value;
        const rawName = sanitizeProjectName(rawInput, `Proyecto ${u.projects.length + 1}`);
        const newProjId = `p_${Date.now()}`;
        let baseSlug = rawName.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        if (!baseSlug) baseSlug = `proyecto-${u.projects.length + 1}`;
        let slug = baseSlug;
        let counter = 2;
        while (u.projects.some(p => (p.slug || '') === slug)) {
          slug = `${baseSlug}-${counter++}`;
        }

        const newProject = {
          id: newProjId,
          name: rawName,
          slug: slug,
          subdomain: `${currentUserKey}-${slug}`,
          url: `https://${currentUserKey}.example.com/${slug}/`,
          storage: '0.0',
          files: [],
          trash: []
        };

        u.projects.push(newProject);
        u.activeProjectId = newProjId;
        saveUserProjectsState();
        proceedWithUploadToProject(newProject, false);
      }
    }

    async function proceedWithUploadToProject(targetProject, isUpdateMerge = false) {
      const progressBox = document.getElementById('uploadProgressBox');
      const progressBar = document.getElementById('uploadProgressBar');
      const progressPercent = document.getElementById('uploadPercent');
      const progressName = document.getElementById('uploadFileName');

      progressBox.classList.remove('hidden');
      progressName.textContent = `Procesando ${pendingUploadFiles.length} archivo(s)...`;
      progressBar.style.width = '0%';
      progressPercent.textContent = '0%';

      let progress = 0;
      const interval = setInterval(() => {
        progress = Math.min(90, progress + 20);
        progressBar.style.width = `${progress}%`;
        progressPercent.textContent = `${progress}%`;
      }, 50);

      const imageExts = ['PNG', 'JPG', 'JPEG', 'GIF', 'WEBP', 'SVG', 'ICO'];
      const videoExts = ['MP4', 'WEBM', 'OGV'];
      const audioExts = ['MP3', 'WAV', 'OGG', 'M4A'];
      const docExts   = ['PDF', 'DOC', 'DOCX'];

      let updatedCount = 0;
      let addedCount = 0;
      let blockedFiles = 0;

      for (const f of pendingUploadFiles) {
        // Obtener ruta relativa limpia
        let rawPath = f._customPath || f.webkitRelativePath || f.name || 'archivo.html';
        const parts = rawPath.replace(/\\/g, '/').split('/');
        
        let relativePath = '';
        if (parts.length > 1) {
          // Si proviene de carpeta raíz, descartar la carpeta contenedora y preservar subcarpetas
          const subParts = parts.slice(1);
          const safeDirs = subParts.slice(0, -1).map(p => p.replace(/[^a-zA-Z0-9_\-]/g, '_'));
          const safeFile = subParts[subParts.length - 1].replace(/[^a-zA-Z0-9_\-\.]/g, '_');
          relativePath = safeDirs.length > 0 ? (safeDirs.join('/') + '/' + safeFile) : safeFile;
        } else {
          relativePath = parts[0].replace(/[^a-zA-Z0-9_\-\.]/g, '_');
        }

        if (!relativePath) relativePath = 'archivo.html';

        // Seguridad: bloquear dobles extensiones ejecutables
        const nameWithoutExt = relativePath.replace(/\.[^.]+$/, '');
        if (/\.(php|phtml|phar|sh|exe|cgi|bat|py|pl)$/i.test(nameWithoutExt)) {
          blockedFiles++;
          continue;
        }

        const ext = relativePath.split('.').pop().toUpperCase();
        let fileType = 'HTML';
        let category = 'CODE';
        let mediaUrl = null;
        let fileCode = '';
        let base64Data = null;

        if (imageExts.includes(ext)) {
          fileType = 'IMG';
          category = 'MEDIA';
          if (f instanceof Blob) {
            mediaUrl = URL.createObjectURL(f);
            base64Data = await new Promise((res) => {
              const r = new FileReader();
              r.onload = () => res(r.result);
              r.readAsDataURL(f);
            });
          }
        } else if (videoExts.includes(ext)) {
          fileType = 'VIDEO';
          category = 'MEDIA';
          if (f instanceof Blob) mediaUrl = URL.createObjectURL(f);
        } else if (audioExts.includes(ext)) {
          fileType = 'AUDIO';
          category = 'MEDIA';
          if (f instanceof Blob) mediaUrl = URL.createObjectURL(f);
        } else if (docExts.includes(ext)) {
          fileType = 'DOC';
          category = 'DOC';
          if (f instanceof Blob) mediaUrl = URL.createObjectURL(f);
        } else if (ext === 'CSS') {
          fileType = 'CSS';
        } else if (ext === 'PHP') {
          fileType = 'PHP';
        } else if (ext === 'JS' || ext === 'JSON') {
          fileType = 'JS';
        } else {
          fileType = 'HTML';
        }

        // Lectura de texto/código
        if (category === 'CODE' && f instanceof Blob && typeof f.text === 'function') {
          try {
            fileCode = await f.text();
          } catch (e) {
            fileCode = '';
          }
        }

        const formattedSize = `${((f.size || 1024) / 1024).toFixed(1)} KB`;
        const timeNow = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        // VERIFICAR SI YA EXISTE PARA ACTUALIZAR EN VEZ DE BORRAR
        const existingIdx = targetProject.files.findIndex(item => item.name.toLowerCase() === relativePath.toLowerCase());

        if (existingIdx !== -1) {
          // Reemplazo individual y seguro del archivo coincidente
          targetProject.files[existingIdx].size = formattedSize;
          targetProject.files[existingIdx].bytes = f.size || 1024;
          targetProject.files[existingIdx].date = `Actualizado ${timeNow}`;
          targetProject.files[existingIdx].type = fileType;
          targetProject.files[existingIdx].category = category;
          if (fileCode) targetProject.files[existingIdx].code = fileCode;
          if (mediaUrl) targetProject.files[existingIdx].mediaUrl = mediaUrl;
          if (base64Data) targetProject.files[existingIdx].base64 = base64Data;
          targetProject.files[existingIdx]._rawBlob = f;
          updatedCount++;
        } else {
          // Archivo nuevo en el proyecto
          targetProject.files.push({
            id: nextFileId++,
            name: relativePath,
            type: fileType,
            category: category,
            size: formattedSize,
            bytes: f.size || 1024,
            date: `Subido ${timeNow}`,
            code: fileCode,
            mediaUrl: mediaUrl,
            base64: base64Data,
            _rawBlob: f
          });
          addedCount++;
        }
      }

      clearInterval(interval);
      progressBar.style.width = '100%';
      progressPercent.textContent = '100%';

      setTimeout(() => {
        progressBox.classList.add('hidden');

        if (blockedFiles > 0) {
          showToast(`Seguridad: Se bloquearon ${blockedFiles} archivo(s) ejecutables`, 'error');
        }

        pendingUploadFiles = [];
        renderDashboard();
      parseTwemojis();

        let msg = '';
        if (updatedCount > 0 && addedCount > 0) {
          msg = `✓ Se actualizaron ${updatedCount} archivo(s) y se agregaron ${addedCount} nuevo(s).`;
        } else if (updatedCount > 0) {
          msg = `✓ Se actualizó ${updatedCount} archivo(s) exitosamente (los demás permanecen intactos).`;
        } else {
          msg = `✓ Se agregaron ${addedCount} archivo(s) al espacio.`;
        }

        showToast(msg, 'success');
        showToast('💡 Presiona "🚀 Desplegar Página" para publicar los cambios en vivo en la web.', 'info');
      }, 200);
    }

    function promptNewProjectModal() {
      const u = userData[currentUserKey];
      document.getElementById('manualProjectNameInput').value = `Proyecto ${u.projects.length + 1}`;
      openModal('modalNewProjectManual');
    }

    function confirmCreateManualProject() {
      const u = userData[currentUserKey];
      const rawInput = document.getElementById('manualProjectNameInput').value;
      const rawName = sanitizeProjectName(rawInput, `Proyecto ${u.projects.length + 1}`);
      const newProjId = `p_${Date.now()}`;
      
      let baseSlug = rawName.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
      if (!baseSlug) baseSlug = `proyecto-${u.projects.length + 1}`;
      let slug = baseSlug;
      let counter = 2;
      while (u.projects.some(p => (p.slug || '') === slug)) {
        slug = `${baseSlug}-${counter++}`;
      }

      const newProj = {
        id: newProjId,
        name: rawName,
        slug: slug,
        subdomain: `${currentUserKey}-${slug}`,
        url: `https://${currentUserKey}.example.com/${slug}/`,
        storage: '0.0',
        files: [],
        trash: []
      };

      u.projects.push(newProj);
      u.activeProjectId = newProjId;
      saveUserProjectsState();
      closeModal('modalNewProjectManual');
      renderDashboard();
      parseTwemojis();
      showToast(`¡Espacio de trabajo <strong>${escapeHtml(rawName)}</strong> creado!`, 'success');
    }

    // ==========================================
    // PERSONALIZAR Y GENERAR URL DE ESTE PROYECTO
    // ==========================================
    const randomWords = [
      'pixel', 'cyber', 'astro', 'hyper', 'matrix', 'cloud', 'turbo', 'quantum',
      'dev', 'code', 'space', 'sonic', 'blaze', 'alpha', 'craft', 'studio', 'nova'
    ];

    // Copiar URL del sitio desplegado
    function copyDeployedUrl() {
      const proj = getCurrentProject();
      const url = (proj && proj.url) ? proj.url : `https://${currentUserKey}.example.com`;
      
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
          onUrlCopiedSuccess(url);
        }).catch(() => {
          fallbackCopyText(url);
        });
      } else {
        fallbackCopyText(url);
      }
    }

    function fallbackCopyText(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        onUrlCopiedSuccess(text);
      } catch (e) {
        showToast(text, 'info');
      }
      document.body.removeChild(ta);
    }

    function onUrlCopiedSuccess(url) {
      const btn = document.getElementById('btnCopyDeployedUrl');
      const txt = document.getElementById('btnCopyDeployedUrlText');
      if (btn && txt) {
        const origTxt = txt.textContent;
        txt.textContent = '✓ ¡URL Copiada!';
        btn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');
        setTimeout(() => {
          txt.textContent = origTxt;
          btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
          btn.classList.add('bg-blue-600', 'hover:bg-blue-700');
        }, 2200);
      }
      showToast(`✓ Enlace web copiado: ${url}`, 'success');
    }

    // Alias para compatibilidad
    function copyCurrentURL() {
      copyDeployedUrl();
    }

    // Resaltar apartado de URL cada vez que el usuario pulse Desplegar Página
    function highlightDeployUrlCard(targetUrl) {
      const card = document.getElementById('cardDeployUrlSection');
      const btn = document.getElementById('btnCopyDeployedUrl');
      const disp = document.getElementById('deployedUrlDisplay');
      const visit = document.getElementById('btnVisitDeployedUrl');
      const ext = document.getElementById('btnOpenDeployedExternal');

      if (targetUrl) {
        if (disp) disp.textContent = targetUrl;
        if (visit) visit.href = targetUrl;
        if (ext) ext.href = targetUrl;
        const btnVisitHeader = document.getElementById('btnVisitSite');
        if (btnVisitHeader) btnVisitHeader.href = targetUrl;
        const navPub = document.getElementById('navPublicWeb');
        if (navPub) navPub.href = targetUrl;
      }

      if (card) {
        card.classList.add('ring-2', 'ring-blue-500', 'bg-blue-950/40');
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => {
          card.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-950/40');
        }, 2500);
      }

      if (btn) {
        btn.classList.add('animate-pulse');
        setTimeout(() => btn.classList.remove('animate-pulse'), 1800);
      }
    }

    // ==========================================
    // PREGUNTAR TIPO DE ARCHIVO O ARCHIVO EXISTENTE (HTML, CSS, PHP)
    // ==========================================
    let pendingPromptType = 'HTML';

    function promptEditorChoice(preselectedType) {
      if (preselectedType) {
        handleEditorLanguageSelected(preselectedType);
        return;
      }
      
      // Actualizar contadores de archivos disponibles en modalTypeChoice
      const proj = getCurrentProject();
      if (proj) {
        const hCount = proj.files.filter(f => f.type === 'HTML' || f.name.toLowerCase().endsWith('.html') || f.name.toLowerCase().endsWith('.htm')).length;
        const cCount = proj.files.filter(f => f.type === 'CSS' || f.name.toLowerCase().endsWith('.css')).length;
        const pCount = proj.files.filter(f => f.type === 'PHP' || f.name.toLowerCase().endsWith('.php')).length;

        const bH = document.getElementById('badgeModalCountHTML');
        if (bH) bH.textContent = hCount > 0 ? `${hCount} disponible${hCount !== 1 ? 's' : ''}` : '0 disponibles';

        const bC = document.getElementById('badgeModalCountCSS');
        if (bC) bC.textContent = cCount > 0 ? `${cCount} disponible${cCount !== 1 ? 's' : ''}` : '0 disponibles';

        const bP = document.getElementById('badgeModalCountPHP');
        if (bP) bP.textContent = pCount > 0 ? `${pCount} disponible${pCount !== 1 ? 's' : ''}` : '0 disponibles';
      }

      openModal('modalTypeChoice');
    }

    function handleEditorLanguageSelected(type) {
      closeModal('modalTypeChoice');
      pendingPromptType = type;

      const proj = getCurrentProject();
      if (!proj) return;

      const matchingFiles = proj.files.filter(f => {
        if (type === 'HTML') return f.type === 'HTML' || f.name.toLowerCase().endsWith('.html') || f.name.toLowerCase().endsWith('.htm');
        if (type === 'CSS') return f.type === 'CSS' || f.name.toLowerCase().endsWith('.css');
        if (type === 'PHP') return f.type === 'PHP' || f.name.toLowerCase().endsWith('.php');
        return f.type === type;
      });

      // Si hay archivos disponibles de este tipo, PREGUNTAR al usuario
      if (matchingFiles.length > 0) {
        openEditorActionPrompt(type, matchingFiles);
      } else {
        // Si no hay archivos existentes, ir directamente a crear uno nuevo
        selectTypeAndOpenEditor(type);
      }
    }

    function openEditorActionPrompt(type, files) {
      const titleElem = document.getElementById('actionPromptTitle');
      const subtitleElem = document.getElementById('actionPromptSubtitle');
      const badgeElem = document.getElementById('actionPromptBadgeIcon');
      const listContainer = document.getElementById('actionPromptFilesList');
      const btnCreateText = document.getElementById('btnActionCreateNewText');

      if (titleElem) titleElem.textContent = `Editor de Código (${type})`;
      if (subtitleElem) subtitleElem.textContent = `Tienes ${files.length} archivo(s) ${type} en este espacio.`;
      if (btnCreateText) btnCreateText.textContent = `Crear un Nuevo Archivo ${type}`;

      if (badgeElem) {
        badgeElem.textContent = type;
        badgeElem.className = `w-10 h-10 rounded-2xl flex items-center justify-center font-extrabold text-sm ${
          type === 'HTML' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' :
          (type === 'CSS' ? 'bg-blue-500/20 text-blue-400 border border-blue-500/30' : 'bg-purple-500/20 text-purple-400 border border-purple-500/30')
        }`;
      }

      if (listContainer) {
        const isLight = !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');
        listContainer.innerHTML = files.map(f => `
          <div data-action="prompt-open-file" data-file-id="${f.id}" class="p-3.5 rounded-2xl border transition cursor-pointer flex items-center justify-between group shadow-xs ${
            isLight
              ? 'border-slate-200 hover:border-blue-500 hover:bg-blue-50/40 bg-white'
              : 'border-slate-800 hover:border-blue-500/60 hover:bg-slate-850 bg-slate-900/80'
          }">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl font-mono font-bold text-xs flex items-center justify-center transition ${
                isLight
                  ? 'bg-slate-100 text-slate-800 group-hover:bg-blue-100 group-hover:text-blue-700'
                  : 'bg-slate-800 text-slate-300 group-hover:bg-blue-950/60 group-hover:text-blue-400'
              }">
                ${escapeHtml(f.type)}
              </div>
              <div class="text-left">
                <span class="block text-xs font-bold font-mono transition ${
                  isLight
                    ? 'text-slate-800 group-hover:text-blue-600'
                    : 'text-slate-200 group-hover:text-blue-400'
                }">${escapeHtml(f.name)}</span>
                <span class="block text-[10px] text-slate-400 mt-0.5">${escapeHtml(f.size || '0 KB')} &bull; ${escapeHtml(f.date || 'Reciente')}</span>
              </div>
            </div>
            <span class="text-xs font-bold ${isLight ? 'text-blue-600' : 'text-blue-400'} group-hover:translate-x-1 transition flex items-center gap-1 shrink-0 ml-2">
              <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg><span>Editar</span>
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
          </div>
        `).join('');
      }

      openModal('modalEditorActionPrompt');
    }

    function executeCreateNewFromPrompt() {
      closeModal('modalEditorActionPrompt');
      selectTypeAndOpenEditor(pendingPromptType);
    }

    function selectTypeAndOpenEditor(type) {
      closeModal('modalTypeChoice');
      
      const ext = type.toLowerCase();
      const defaultName = `nuevo_${Date.now().toString().slice(-4)}.${ext}`;
      const boilerplate = codeTemplates[type] || '';

      document.getElementById('editorBadgeType').textContent = type;
      document.getElementById('editorFileName').value = defaultName;
      document.getElementById('editorCodeArea').value = boilerplate;
      document.getElementById('editorStatus').textContent = `Creando archivo nuevo ${type}`;

      openModal('modalEditor');
      setTimeout(() => {
        const area = document.getElementById('editorCodeArea');
        if (area) {
          area.focus();
          updateEditorLineNumbers();
        }
      }, 60);
    }

    function openEditorWithFile(fileId) {
      const proj = getCurrentProject();
      if (!proj) return;
      const file = proj.files.find(f => f.id === fileId);
      if (!file) return;

      document.getElementById('editorBadgeType').textContent = file.type;
      document.getElementById('editorFileName').value = file.name;
      document.getElementById('editorCodeArea').value = file.code || codeTemplates[file.type] || '';
      document.getElementById('editorStatus').textContent = `Editando: ${file.name}`;

      openModal('modalEditor');
      setTimeout(() => {
        const area = document.getElementById('editorCodeArea');
        if (area) {
          area.focus();
          updateEditorLineNumbers();
        }
      }, 60);
    }

    function saveEditorCode() {
      const proj = getCurrentProject();
      if (!proj) return;

      const filename = document.getElementById('editorFileName').value.trim();
      const code = document.getElementById('editorCodeArea').value;
      const type = document.getElementById('editorBadgeType').textContent;

      if (!filename) {
        showToast('Debes ingresar un nombre para el archivo', 'error');
        return;
      }

      if (!isValidFilename(filename)) {
        showToast('Nombre inválido. Sin barras ni rutas relativas, y con extensión permitida (.html, .css, .php, etc.)', 'error');
        return;
      }

      let file = proj.files.find(f => f.name.toLowerCase() === filename.toLowerCase());
      if (file) {
        file.code = code;
        file.date = 'Justo ahora';
        showToast(`Archivo <strong>${escapeHtml(filename)}</strong> actualizado`, 'success');
      } else {
        proj.files.unshift({
          id: nextFileId++,
          name: filename,
          type: type,
          size: `${(code.length / 1024).toFixed(1)} KB`,
          date: 'Justo ahora',
          code: code
        });
        showToast(`Archivo <strong>${escapeHtml(filename)}</strong> creado exitosamente`, 'success');
      }

      closeModal('modalEditor');
      renderDashboard();
      parseTwemojis();
    }

    // ==========================================
    // VISUALIZACIÓN SEGURA DE ARCHIVOS MULTIMEDIA
    // ==========================================
    function previewMediaFile(fileId) {
      const proj = getCurrentProject();
      if (!proj) return;
      const file = proj.files.find(f => f.id === fileId) || proj.trash.find(f => f.id === fileId);
      if (!file) return;

      document.getElementById('mediaPreviewTitle').textContent = file.name;
      document.getElementById('mediaPreviewTypeBadge').textContent = file.type;
      document.getElementById('mediaPreviewSize').textContent = file.size || '0 KB';

      const container = document.getElementById('mediaPreviewContainer');
      container.innerHTML = '';

      const mediaSource = file.mediaUrl || (file.type === 'IMG' ? 'assets/logo.png' : '');

      if (file.type === 'IMG') {
        const img = document.createElement('img');
        img.src = mediaSource || 'assets/logo.png';
        img.alt = file.name;
        // Seguridad: Se visualiza en elemento <img> que nunca ejecuta scripts en SVG o imágenes
        img.className = 'max-h-80 max-w-full rounded-2xl mx-auto border border-slate-800 shadow-md object-contain bg-slate-950';
        container.appendChild(img);
      } else if (file.type === 'VIDEO') {
        if (mediaSource) {
          const vid = document.createElement('video');
          vid.src = mediaSource;
          vid.controls = true;
          vid.playsInline = true;
          vid.className = 'w-full max-h-80 rounded-2xl bg-black shadow-md';
          container.appendChild(vid);
        } else {
          container.innerHTML = `
            <div class="py-12 px-6 text-center bg-slate-900 text-slate-200 rounded-2xl w-full">
              <div class="w-14 h-14 rounded-2xl bg-blue-600/30 text-blue-400 mx-auto mb-3 flex items-center justify-center shadow-md"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg></div>
              <p class="font-bold text-xs font-mono">${escapeHtml(file.name)}</p>
              <p class="text-[11px] text-slate-400 mt-1">Archivo de video web compatible listo para reproducirse en el servidor.</p>
            </div>
          `;
        }
      } else if (file.type === 'AUDIO') {
        container.innerHTML = `
          <div class="p-6 text-center bg-gradient-to-br from-indigo-50 to-purple-50 rounded-2xl border border-indigo-100 w-full">
            <div class="w-14 h-14 rounded-2xl bg-indigo-600 text-white flex items-center justify-center mx-auto mb-3 shadow-md">
              <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/></svg>
            </div>
            <h4 class="text-xs font-bold text-slate-200 font-mono">${escapeHtml(file.name)}</h4>
            <p class="text-[10px] text-slate-500 mb-4">Pista de audio web compatible</p>
            <audio controls class="w-full" ${mediaSource ? `src="${mediaSource}"` : ''}></audio>
          </div>
        `;
      } else if (file.type === 'DOC') {
        container.innerHTML = `
          <div class="p-6 text-center bg-slate-900 rounded-2xl border border-slate-800 w-full">
            <div class="w-14 h-14 rounded-2xl bg-rose-600 text-white flex items-center justify-center mx-auto mb-3 shadow-md">
              <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <h4 class="text-xs font-bold text-slate-200 font-mono">${escapeHtml(file.name)}</h4>
            <p class="text-[10px] text-slate-500 mb-4">Documento PDF / Archivo adjunto del proyecto</p>
            ${mediaSource ? `<a href="${mediaSource}" target="_blank" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition"><span>Ver Documento en Nueva Pestaña ↗</span></a>` : ''}
          </div>
        `;
      }

      openModal('modalMediaPreview');
    }

    // ==========================================
    // SISTEMA DE ELIMINACIÓN Y PAPELERA
    // ==========================================
    function confirmDeleteFile(fileId) {
      const proj = getCurrentProject();
      if (!proj) return;
      const file = proj.files.find(f => f.id === fileId);
      if (!file) return;

      targetDeleteFileId = fileId;
      document.getElementById('deleteFileNameTarget').textContent = file.name;
      openModal('modalConfirmDelete');
    }

    function executeMoveToTrash() {
      if (!targetDeleteFileId) return;
      const proj = getCurrentProject();
      if (!proj) return;

      const fileIndex = proj.files.findIndex(f => f.id === targetDeleteFileId);
      if (fileIndex !== -1) {
        const [deleted] = proj.files.splice(fileIndex, 1);
        proj.trash.push(deleted);
        showToast(`"${escapeHtml(deleted.name)}" se movió a la papelera`, 'info');
      }

      targetDeleteFileId = null;
      closeModal('modalConfirmDelete');
      renderDashboard();
      parseTwemojis();
    }

    // Modal personalizado para eliminar por tipo (HTML, CSS o PHP)
    function confirmDeleteByType(type) {
      const proj = getCurrentProject();
      if (!proj) return;
      const matchingFiles = proj.files.filter(f => {
        if (type === 'MEDIA') return ['IMG', 'VIDEO', 'AUDIO'].includes(f.type) || f.category === 'MEDIA';
        return f.type === type;
      });
      
      if (matchingFiles.length === 0) {
        showToast(`No tienes archivos ${escapeHtml(type)} en este espacio`, 'info');
        return;
      }

      pendingDeleteType = type;
      document.getElementById('deleteTypeLabel').textContent = type;
      document.getElementById('deleteTypeCount').textContent = matchingFiles.length;
      openModal('modalConfirmDeleteByType');
    }

    function executeDeleteByType() {
      if (!pendingDeleteType) return;
      const proj = getCurrentProject();
      if (!proj) return;

      proj.files = proj.files.filter(f => {
        const isMatch = (pendingDeleteType === 'MEDIA') 
          ? (['IMG', 'VIDEO', 'AUDIO'].includes(f.type) || f.category === 'MEDIA')
          : (f.type === pendingDeleteType);

        if (isMatch) {
          proj.trash.push(f);
          return false;
        }
        return true;
      });

      closeModal('modalConfirmDeleteByType');
      showToast(`Se movieron los archivos ${escapeHtml(pendingDeleteType)} a la papelera`, 'info');
      pendingDeleteType = null;
      renderDashboard();
      parseTwemojis();
    }

    function restoreFromTrash(fileId) {
      const proj = getCurrentProject();
      if (!proj) return;
      const trashIndex = proj.trash.findIndex(f => f.id === fileId);
      if (trashIndex !== -1) {
        const [restored] = proj.trash.splice(trashIndex, 1);
        proj.files.push(restored);
        showToast(`"${escapeHtml(restored.name)}" restaurado`, 'success');
        renderDashboard();
      parseTwemojis();
      }
    }

    // Modal personalizado para eliminar permanentemente
    function deletePermanent(fileId) {
      const proj = getCurrentProject();
      if (!proj) return;
      const file = proj.trash.find(f => f.id === fileId);
      if (!file) return;

      pendingPermanentFileId = fileId;
      document.getElementById('deletePermanentFileName').textContent = file.name;
      openModal('modalConfirmDeletePermanent');
    }

    function executeDeletePermanent() {
      if (!pendingPermanentFileId) return;
      const proj = getCurrentProject();
      if (!proj) return;

      proj.trash = proj.trash.filter(f => f.id !== pendingPermanentFileId);
      closeModal('modalConfirmDeletePermanent');
      showToast('Archivo eliminado permanentemente', 'error');
      pendingPermanentFileId = null;
      renderDashboard();
      parseTwemojis();
    }

    function emptyTrashConfirm() {
      openModal('modalConfirmEmptyTrash');
    }

    function executeEmptyTrash() {
      const proj = getCurrentProject();
      if (!proj) return;
      proj.trash = [];
      closeModal('modalConfirmEmptyTrash');
      showToast('Papelera vaciada completamente', 'info');
      renderDashboard();
      parseTwemojis();
    }

    // ==========================================
    // UTILIDADES DE MODALES, CONTROL DE SCROLL Y TOASTS
    // ==========================================
    const activeModalsSet = new Set();

    function openModal(id) {
      const modal = document.getElementById(id);
      if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        activeModalsSet.add(id);

        // Bloquear estrictamente el scroll de la página de fondo
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';
        document.body.classList.add('overflow-hidden');
      }
    }

    function closeModal(id) {
      const modal = document.getElementById(id);
      if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        activeModalsSet.delete(id);

        // Restaurar scroll de la página únicamente si ya no quedan modales abiertos
        if (activeModalsSet.size === 0) {
          document.body.style.overflow = '';
          document.documentElement.style.overflow = '';
          document.body.classList.remove('overflow-hidden');
        }
      }
    }

    // Actualizar numeración de líneas sincronizada en el editor
    function updateEditorLineNumbers() {
      const area = document.getElementById('editorCodeArea');
      const container = document.getElementById('editorLineNumbers');
      if (!area || !container) return;
      const lines = area.value.split('\n');
      const count = Math.max(1, lines.length);
      let html = '';
      for (let i = 1; i <= count; i++) {
        html += `<div class="h-5 leading-5">${i}</div>`;
      }
      container.innerHTML = html;
      container.scrollTop = area.scrollTop;
    }

    // Inicializar sincronización y captura universal de scroll en el editor
    function setupEditorScrollSync() {
      const area = document.getElementById('editorCodeArea');
      const linesContainer = document.getElementById('editorLineNumbers');
      const modalEditor = document.getElementById('modalEditor');

      if (area) {
        // Sincronizar scroll del textarea con los números de línea
        area.addEventListener('scroll', () => {
          if (linesContainer) {
            linesContainer.scrollTop = area.scrollTop;
          }
        });

        // Actualizar números al escribir o pegar contenido
        area.addEventListener('input', updateEditorLineNumbers);

        // Permitir sangría suave con Tabulación
        area.addEventListener('keydown', (e) => {
          if (e.key === 'Tab') {
            e.preventDefault();
            const start = area.selectionStart;
            const end = area.selectionEnd;
            area.value = area.value.substring(0, start) + '  ' + area.value.substring(end);
            area.selectionStart = area.selectionEnd = start + 2;
            updateEditorLineNumbers();
          }
        });
      }

      // CAPTURA UNIVERSAL DE RUEDA: Si el usuario scrolea en cualquier parte de la ventana
      // del modal (cabecera, números, fondo o márgenes), scrolear el código directamente
      if (modalEditor) {
        modalEditor.addEventListener('wheel', (e) => {
          const codeArea = document.getElementById('editorCodeArea');
          if (codeArea) {
            if (e.target !== codeArea) {
              codeArea.scrollTop += e.deltaY;
              if (linesContainer) {
                linesContainer.scrollTop = codeArea.scrollTop;
              }
              e.preventDefault();
            }
          }
        }, { passive: false });
      }

      // Cerrar modales con tecla Escape
      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && activeModalsSet.size > 0) {
          const lastId = Array.from(activeModalsSet).pop();
          if (lastId) closeModal(lastId);
        }
      });
    }

    function showToast(msg, type = 'info') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      
      const bg = type === 'success' ? 'bg-emerald-600 text-white' : 
                 (type === 'error' ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
      
      toast.className = `${bg} px-4 py-3 rounded-2xl shadow-xl text-xs font-semibold flex items-center gap-2 transform transition-all duration-300 translate-y-2 opacity-0 pointer-events-auto`;
      toast.innerHTML = `<span>${msg}</span>`;
      parseAppleEmojis(toast);

      container.appendChild(toast);
      requestAnimationFrame(() => {
        toast.classList.remove('translate-y-2', 'opacity-0');
      });

      setTimeout(() => {
        toast.classList.add('translate-y-2', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
      }, 3200);
    }
  
    // ==========================================
    // SISTEMA UNIFICADO DE CONMUTACIÓN DE TEMA CLARO / OSCURO
    // ==========================================
    function isLightTheme() {
      return !document.documentElement.classList.contains('dark') || document.documentElement.classList.contains('theme-light');
    }

    function updateThemeButton(theme) {
      const btn = document.getElementById('themeToggleBtn');
      const icon = document.getElementById('themeToggleIcon');
      const text = document.getElementById('themeToggleText');
      if (!btn || !icon || !text) return;

      if (theme === 'dark') {
        icon.textContent = '🌙';
        text.textContent = 'Oscuro';
        btn.title = 'Cambiar a Tema Claro';
        btn.className = 'pill-toggle pill-btn flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border border-slate-700 bg-slate-800/80 hover:bg-slate-700 text-slate-200 font-bold text-xs transition shadow-xs cursor-pointer select-none';
      } else {
        icon.textContent = '☀️';
        text.textContent = 'Claro';
        btn.title = 'Cambiar a Tema Oscuro';
        btn.className = 'pill-toggle pill-btn flex items-center gap-1.5 px-3.5 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs transition shadow-xs cursor-pointer select-none';
      }
      parseAppleEmojis(icon);
    }

    function applyTheme(theme) {
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
      } catch(e) {}

      updateThemeButton(theme);

      // Re-renderizar pestañas y explorador de archivos para aplicar estilos del tema instantáneamente
      if (typeof renderProjectTabs === 'function') renderProjectTabs();
      if (typeof renderFileList === 'function') renderFileList();
    }

    function toggleTheme() {
      const current = isLightTheme() ? 'light' : 'dark';
      const newTheme = current === 'light' ? 'dark' : 'light';
      applyTheme(newTheme);
    }

    // Inicializar tema y sincronizar estado visual al cargar la página
    document.addEventListener('DOMContentLoaded', () => {
      let savedTheme = 'light';
      try {
        savedTheme = localStorage.getItem('panel_theme') || 'light';
      } catch(e) {}
      applyTheme(savedTheme);
    });


    // ==========================================
    // DELEGACIÓN DE EVENTOS (SIN HANDLERS INLINE / CSP ESTRICTO)
    // ==========================================
    document.querySelectorAll('[data-stop-propagation="1"]').forEach((el) => {
      el.addEventListener('click', (e) => e.stopPropagation());
    });

    document.addEventListener('click', (e) => {
      const backdrop = e.target.closest('[data-backdrop-action="cancel-upload-conflict"]');
      if (backdrop && e.target === backdrop) {
        cancelUploadConflictModal();
        return;
      }

      const el = e.target.closest('[data-action]');
      if (!el) return;

      const action = el.dataset.action;
      switch (action) {
        case 'prompt-editor-choice':
          promptEditorChoice(el.dataset.lang || undefined);
          break;
        case 'deploy-live':
          deployCurrentProjectToLive();
          break;
        case 'toggle-theme':
          toggleTheme();
          break;
        case 'open-2fa-modal':
          open2FAModal();
          break;
        case 'trigger-upload-modal':
          triggerUploadModal();
          break;
        case 'prompt-new-project':
          promptNewProjectModal();
          break;
        case 'copy-deployed-url':
          copyDeployedUrl();
          break;
        case 'click-target': {
          const tgt = document.getElementById(el.dataset.target);
          if (tgt) tgt.click();
          break;
        }
        case 'set-filter':
          setFilter(el.dataset.filter);
          break;
        case 'empty-trash-confirm':
          emptyTrashConfirm();
          break;
        case 'confirm-bulk-delete':
          confirmBulkDelete();
          break;
        case 'clear-selection':
          clearSelection();
          break;
        case 'execute-bulk-restore':
          executeBulkRestore();
          break;
        case 'confirm-bulk-permanent-delete':
          confirmBulkPermanentDelete();
          break;
        case 'close-htop-modal':
          closeHtopModal();
          break;
        case 'cancel-upload-conflict':
          cancelUploadConflictModal();
          break;
        case 'choose-upload-action':
          chooseUploadAction(el.dataset.choice);
          break;
        case 'confirm-create-manual-project':
          confirmCreateManualProject();
          break;
        case 'execute-create-new-from-prompt':
          executeCreateNewFromPrompt();
          break;
        case 'back-to-type-choice':
          closeModal('modalEditorActionPrompt');
          openModal('modalTypeChoice');
          break;
        case 'editor-lang':
          handleEditorLanguageSelected(el.dataset.lang);
          break;
        case 'save-editor-code':
          saveEditorCode();
          break;
        case 'execute-move-to-trash':
          executeMoveToTrash();
          break;
        case 'execute-delete-project':
          executeDeleteProject();
          break;
        case 'execute-empty-trash':
          executeEmptyTrash();
          break;
        case 'execute-delete-by-type':
          executeDeleteByType();
          break;
        case 'execute-delete-permanent':
          executeDeletePermanent();
          break;
        case 'execute-bulk-delete':
          executeBulkDelete();
          break;
        case 'execute-bulk-permanent-delete':
          executeBulkPermanentDelete();
          break;
        case 'close-modal':
          closeModal(el.dataset.modal);
          break;
        case 'delete-project':
          e.stopPropagation();
          promptDeleteProject(el.dataset.projectId, el.dataset.projectName);
          break;
        case 'restore-from-trash':
          restoreFromTrash(Number(el.dataset.fileId));
          break;
        case 'delete-permanent':
          deletePermanent(Number(el.dataset.fileId));
          break;
        case 'preview-media':
          previewMediaFile(Number(el.dataset.fileId));
          break;
        case 'open-editor-file':
          openEditorWithFile(Number(el.dataset.fileId));
          break;
        case 'confirm-delete-file':
          confirmDeleteFile(Number(el.dataset.fileId));
          break;
        case 'prompt-open-file':
          closeModal('modalEditorActionPrompt');
          openEditorWithFile(Number(el.dataset.fileId));
          break;
      }
    });

    document.addEventListener('change', (e) => {
      const el = e.target.closest('[data-change-action]');
      if (!el) return;
      const action = el.dataset.changeAction;
      if (action === 'toggle-select-all') {
        toggleSelectAll(el.checked);
      } else if (action === 'toggle-select-file') {
        toggleSelectFile(Number(el.dataset.fileId));
      }
    });