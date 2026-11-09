# UTP Cloud — Multi-Tenant Web Hosting Panel

English · [Español](#español)

A self-hosted multi-tenant web panel (**PHP 8.x + Vanilla JS + Tailwind CSS**) where developers and students log in with **password + mandatory RFC 6238 TOTP 2FA** and deploy static or PHP websites to their own isolated subdomain directly from the browser.

---

## Security & Architecture Highlights

- **Two-Step Authentication & Anti-Replay (`includes/auth.php`, `includes/2fa_helper.php`)**:
  - `password_verify()` with constant-time dummy hash verification to prevent username enumeration via timing attacks.
  - Mandatory **RFC 6238 TOTP (Google Authenticator)** with QR enrollment on first login (`js/qrcode.min.js`, generated locally in the browser with no third-party QR API calls).
  - **Global & Session TOTP Anti-Replay**: consumed 30-second time slices are locked on disk (`flock`) so an intercepted 6-digit code cannot be reused in another session during its validity window.
  - **Progressive IP & 2FA Rate Limiting**: automatic lockouts after repeated failed password or TOTP attempts (1 min $\rightarrow$ 15 min $\rightarrow$ 1 hour).
  - **Fail-Closed Credential Store**: zero password hashes or TOTP secrets in source code; credentials are loaded from `/etc/utp/credentials.json` outside the web root.
- **Hardened Deployment API (`api/api_deploy.php`)**:
  - Per-action CSRF token verification (`hash_equals`) and `Origin` / `Referer` host validation.
  - Strict path-traversal prevention, dotfile/system-file blocklist, double-extension filter (`.php.jpg`, `.phar`, etc.), and file-extension allow-list.
  - Inline SVG sanitizer (strips `<script>`, `<foreignObject>`, event handlers, and `javascript:` URIs on upload).
  - Anti-DoS quotas: max 10 projects per tenant, 150 files per project, 2 MB per code file, 15 MB per media file, 35 MB total project quota.
- **Role-Aware Live Telemetry (`api/api_telemetry.php`)**:
  - Real-time CPU per-core delta, RAM/Swap, network throughput, and process table read natively from `/proc` for administrators, while non-admin tenants receive only their isolated storage quota metrics (least privilege).
- **OS-Level Tenant Isolation**:
  - Designed to pair with per-tenant Linux users, dedicated PHP-FPM pools/sockets, and strict `open_basedir` jails per workspace.

---

## Repository Structure

```text
utp-cloud/
├── api/
│   ├── api_deploy.php          # Multi-project file sync, quotas & SVG/path sanitization
│   └── api_telemetry.php       # Native /proc live server & tenant storage telemetry
├── assets/                     # Brand logos and login animation
├── css/
│   ├── dashboard.css           # Workspace, code editor, htop monitor & theme styles
│   └── style.css               # Login and modal styles (light/dark themes)
├── includes/
│   ├── 2fa_helper.php          # Fail-closed credential loader & 2FA enrollment state
│   └── auth.php                # Step 1 (password) + Step 2 (TOTP), anti-replay & rate limit
├── js/
│   ├── dashboard.js            # Multi-project workspace, IndexedDB, editor & telemetry logic
│   ├── login.js                # Login UI interactions & offline demo mode
│   └── qrcode.min.js           # Client-side QR generator for TOTP enrollment
├── 404.html                    # Fallback 404 template
├── credentials.example.json    # Schema template for /etc/utp/credentials.json
├── dashboard.php               # Multi-project workspace editor & live htop monitor
├── login.php                   # Two-step login & 2FA QR enrollment modal
└── logout.php                  # Cross-site safe session termination
```

---

## Quick Start

1. **Provision Nginx + PHP-FPM 8.x** with a virtual host pointing to the repository root and per-tenant workspace directories under `/var/www/tenants/<tenant>`.
2. **Create the credential store outside the web root**:
   ```bash
   sudo mkdir -p /etc/utp
   sudo cp credentials.example.json /etc/utp/credentials.json
   sudo chown root:www-data /etc/utp/credentials.json
   sudo chmod 0640 /etc/utp/credentials.json
   ```
3. **Generate a bcrypt hash for each tenant**:
   ```bash
   php -r "echo password_hash('YourStrongPasswordHere', PASSWORD_DEFAULT) . PHP_EOL;"
   ```
   Paste the resulting hash into `/etc/utp/credentials.json` along with a 16-character Base32 TOTP secret.
4. **Configure your base domain**: replace `example.com` in `dashboard.php`, `logout.php`, and `api/api_deploy.php` with your domain.

---

## Español

Panel web autoalojado multi-inquilino (**PHP 8.x + JS Vanilla + Tailwind CSS**) donde cada desarrollador o estudiante inicia sesión con **contraseña + 2FA TOTP obligatorio (Google Authenticator)** y despliega sitios web en su propio subdominio aislado desde el navegador.

###Puntos clave de seguridad
- **Autenticación en 2 pasos y Anti-Replay (`includes/auth.php`)**: verificación `password_verify` resistente a ataques de temporización, enrolamiento QR local sin APIs externas, bloqueo global de códigos TOTP ya utilizados (`flock`) y rate-limiting progresivo por IP.
- **Cero secretos en el código (`includes/2fa_helper.php`)**: las credenciales se leen desde `/etc/utp/credentials.json` fuera del web root (modo *fail-closed*). Incluye [`credentials.example.json`](credentials.example.json) con la estructura exacta.
- **API de despliegue (`api/api_deploy.php`)**: validación CSRF (`hash_equals`) y de `Origin`, filtros contra *path traversal* y dobles extensiones, sanitización de archivos SVG en subida y cuotas Anti-DoS (máx. 10 proyectos, 150 archivos y 35 MB por proyecto).
- **Telemetría con mínimo privilegio (`api/api_telemetry.php`)**: lectura nativa desde `/proc` para administradores y métricas acotadas a la cuota del propio espacio para inquilinos.
