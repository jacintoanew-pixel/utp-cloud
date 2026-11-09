<?php
// 2fa_helper.php - Gestion Centralizada de Estado y Configuracion de 2FA
// Soporta vinculacion inicial automatica y verificacion limpia post-enrolamiento

function get_2fa_status_file() {
    $securePath = '/etc/utp/2fa_status.json';
    if (file_exists($securePath) || is_dir('/etc/utp')) {
        return $securePath;
    }
    return __DIR__ . '/.2fa_status.json';
}

function get_2fa_defaults() {
    return [
        'tenant_a'    => ['enrolled' => true,  'enrolled_at' => '2026-09-07 00:00:00'],
        'tenant_c'    => ['enrolled' => false, 'enrolled_at' => null],
        'tenant_b' => ['enrolled' => true,  'enrolled_at' => '2026-09-07 16:15:36']
    ];
}

function get_all_2fa_statuses() {
    $file = get_2fa_status_file();
    $defaults = get_2fa_defaults();
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        $data = @json_decode($content, true);
        if (is_array($data)) {
            return array_merge($defaults, $data);
        }
    }
    return $defaults;
}

function is_user_2fa_enrolled($username) {
    $username = strtolower(trim((string)$username));
    $statuses = get_all_2fa_statuses();
    return !empty($statuses[$username]['enrolled']);
}

function set_user_2fa_enrolled($username, $enrolled = true) {
    $username = strtolower(trim((string)$username));
    $statuses = get_all_2fa_statuses();
    $statuses[$username] = [
        'enrolled'    => (bool)$enrolled,
        'enrolled_at' => $enrolled ? date('Y-m-d H:i:s') : null
    ];
    $file = get_2fa_status_file();
    $json = json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($file, $json, LOCK_EX);
    @chmod($file, 0664);
    return ($ok !== false);
}

function get_credentials_path() {
    $candidates = [
        '/etc/utp/credentials.json',
        dirname(__DIR__) . '/credentials.json',
        __DIR__ . '/config/credentials.json',
        __DIR__ . '/credentials.json'
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }
    return null;
}

function get_default_users() {
    // FAIL-CLOSED: Cero hashes ni secretos en código fuente público PHP (SEC-20)
    error_log("[SECURITY] Alerta: No se pudo cargar el archivo de credenciales seguro en /etc/utp/credentials.json.");
    return [];
}

function load_credentials_data() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $path = get_credentials_path();
    if ($path) {
        $content = @file_get_contents($path);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data) && !empty($data['users'])) {
                $cached = $data['users'];
                return $cached;
            }
        }
    }
    $cached = get_default_users();
    return $cached;
}

function get_totp_catalog() {
    return load_credentials_data();
}

function get_user_otp_uri($username) {
    $catalog = get_totp_catalog();
    $username = strtolower(trim((string)$username));
    if (!isset($catalog[$username])) return '';
    $u = $catalog[$username];
    return 'otpauth://totp/UTP%20Cloud:' . rawurlencode($u['nombre']) . '?secret=' . $u['secret'] . '&issuer=UTP%20Cloud';
}

function get_user_secret($username) {
    $catalog = get_totp_catalog();
    $username = strtolower(trim((string)$username));
    return $catalog[$username]['secret'] ?? '';
}
