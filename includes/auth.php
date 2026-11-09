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

require_once __DIR__ . '/2fa_helper.php';

// Credenciales registradas: cargadas de forma desacoplada y segura
$usuarios = load_credentials_data();

// =========================================================================
// Funciones Criptográficas RFC 6238 (TOTP)
// =========================================================================
function base32_decode_rfc($b32) {
    $lut = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = 0;
    $val = 0;
    $output = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($lut, $b32[$i]);
        if ($pos === false) continue;
        $val = ($val << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $output .= chr(($val >> ($bits - 8)) & 0xff);
            $bits -= 8;
        }
    }
    return $output;
}

/**
 * Verifica el código TOTP retornando el slice temporal validado
 * para prevenir ataques de repetición (Replay Attacks).
 */
function verify_totp($secret, $inputCode, $window = 1) {
    $key = base32_decode_rfc($secret);
    $time = floor(time() / 30);
    $cleanCode = trim((string)$inputCode);
    for ($i = -$window; $i <= $window; $i++) {
        $t = $time + $i;
        $data = pack('N*', 0) . pack('N*', $t);
        $hash = hash_hmac('sha1', $data, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $part = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        $expected = str_pad($part % 1000000, 6, '0', STR_PAD_LEFT);
        if (hash_equals($expected, $cleanCode)) {
            return $t; // Retorna el time slice validado
        }
    }
    return false;
}

// =========================================================================
// Rate Limiting Persistente a nivel de IP (Protección anti Fuerza Bruta)
// =========================================================================
function get_client_ip() {
    // Al ser Nginx el servidor perimetral directo, REMOTE_ADDR es la IP real y no puede ser suplantada
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function get_ip_ratelimit_file($ip) {
    $dir = sys_get_temp_dir() . '/utp_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $hash = hash('sha256', 'utp_salt_' . $ip);
    return $dir . '/' . $hash . '.json';
}

function check_ip_rate_limit($ip) {
    $file = get_ip_ratelimit_file($ip);
    if (!file_exists($file)) return true;
    
    $data = @json_decode(@file_get_contents($file), true);
    if (!is_array($data)) return true;
    
    $now = time();
    if (!empty($data['blocked_until']) && $data['blocked_until'] > $now) {
        return false;
    }
    
    return true;
}

function record_ip_failure($ip) {
    $file = get_ip_ratelimit_file($ip);
    $data = ['attempts' => 0, 'first_attempt' => time(), 'blocked_until' => 0];
    if (file_exists($file)) {
        $parsed = @json_decode(@file_get_contents($file), true);
        if (is_array($parsed)) $data = $parsed;
    }
    
    $now = time();
    // Si ya pasaron más de 120 segundos después de expirar un bloqueo previo, reiniciar ciclo
    if (!empty($data['blocked_until']) && ($now - $data['blocked_until']) > 120) {
        $data['attempts'] = 0;
        $data['first_attempt'] = $now;
        $data['blocked_until'] = 0;
    } elseif ($now - ($data['first_attempt'] ?? $now) > 120 && empty($data['blocked_until'])) {
        $data['attempts'] = 0;
        $data['first_attempt'] = $now;
        $data['blocked_until'] = 0;
    }
    
    $data['attempts']++;
    $attempts = $data['attempts'];

    // Escalado Progresivo Anti-Fuerza Bruta (SEC-09)
    if ($attempts >= 15) {
        $data['blocked_until'] = $now + 3600; // 1 hora de bloqueo
    } elseif ($attempts >= 10) {
        $data['blocked_until'] = $now + 900;  // 15 minutos de bloqueo
    } elseif ($attempts >= 5) {
        $data['blocked_until'] = $now + 60;   // 1 minuto de bloqueo
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clear_ip_failures($ip) {
    $file = get_ip_ratelimit_file($ip);
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Registra y valida slices consumidos de TOTP a nivel de servidor (Global Anti-Replay - SEC-07)
 * Impide que un código interceptado sea reusado en otra sesión durante su vigencia.
 */
function check_and_mark_totp_used_globally($user, $slice) {
    $dir = sys_get_temp_dir() . '/utp_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/totp_' . hash('sha256', 'utp_totp_salt_' . strtolower($user)) . '.json';
    $now = time();
    $usedSlices = [];

    $fp = @fopen($file, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            if (!empty($content)) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    // Retener únicamente slices de los últimos 120 segundos
                    foreach ($decoded as $s => $ts) {
                        if (($now - $ts) < 120) {
                            $usedSlices[$s] = $ts;
                        }
                    }
                }
            }

            if (isset($usedSlices[$slice])) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false; // Código ya utilizado globalmente en el servidor
            }

            $usedSlices[$slice] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($usedSlices));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    return true;
}

// 1. Verificación de Rate Limiting Persistente por IP y por Sesión
$clientIp = get_client_ip();
if (!check_ip_rate_limit($clientIp)) {
    header('Location: /login.php?error=rate_limited');
    exit;
}

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if ($_SESSION['login_attempts'] >= 5) {
    $timePassed = time() - $_SESSION['last_attempt_time'];
    if ($timePassed < 30) {
        header('Location: /login.php?error=rate_limited');
        exit;
    } else {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

// 2. Validación de Token Anti-CSRF
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if (empty($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
        header('Location: /login.php?error=csrf');
        exit;
    }

    $action = $_POST['action'] ?? 'credentials';

    // Cancelar paso 2FA y regresar a credenciales
    if ($action === 'cancel_2fa') {
        unset($_SESSION['2fa_pending_user']);
        unset($_SESSION['totp_fails']);
        header('Location: /login.php');
        exit;
    }

    // =====================================================================
    // PASO 1: Validación de Credenciales (Usuario y Password Hasheado)
    // =====================================================================
    if ($action === 'credentials') {
        $rawUser = trim((string)($_POST['usuario'] ?? ''));
        $rawPass = (string)($_POST['password'] ?? '');

        if (strlen($rawUser) > 32 || strlen($rawPass) > 128) {
            record_ip_failure($clientIp);
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            header('Location: /login.php?error=1');
            exit;
        }

        $usuario = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawUser));

        $auth_ok = false;
        if (isset($usuarios[$usuario])) {
            $target = $usuarios[$usuario];
            $hashes = isset($target['password_hashes']) ? (array)$target['password_hashes'] : (array)($target['password_hash'] ?? []);
            foreach ($hashes as $h) {
                if (password_verify($rawPass, $h)) {
                    $auth_ok = true;
                    break;
                }
            }
        } else {
            // Mitigación estricta contra enumeración de usuarios por temporización
            password_verify($rawPass, '$2y$10$cf2E97Pj1Vb.o1a1Gf1e0uH0p8j1Wv7h0a4B3f6c8d0e2f4g6h8i0');
        }

        if ($auth_ok) {
            // Guardar usuario autenticado temporalmente para el paso 2 (2FA OBLIGATORIO)
            $_SESSION['2fa_pending_user'] = $usuario;
            $_SESSION['totp_fails'] = 0;
            header('Location: /login.php?step=2fa');
            exit;
        } else {
            record_ip_failure($clientIp);
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            header('Location: /login.php?error=1');
            exit;
        }
    }

    // =====================================================================
    // PASO 2: Validación Obligatoria de Google Authenticator (TOTP 6 Dígitos)
    // =====================================================================
    if ($action === 'totp') {
        $pendingUser = $_SESSION['2fa_pending_user'] ?? '';
        if (empty($pendingUser) || !isset($usuarios[$pendingUser])) {
            // Intento de bypass de 2FA detectado
            header('Location: /login.php?error=unauthorized_2fa');
            exit;
        }

        $totpCode = trim((string)($_POST['totp_code'] ?? ''));
        if (strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
            $_SESSION['totp_fails'] = ($_SESSION['totp_fails'] ?? 0) + 1;
            header('Location: /login.php?step=2fa&error=totp_format');
            exit;
        }

        $secret = $usuarios[$pendingUser]['secret'];
        $matchedSlice = verify_totp($secret, $totpCode, 1);

        if ($matchedSlice !== false) {
            // PREVENCIÓN DE ATAQUES DE REPETICIÓN (ANTI-REPLAY GLOBAL Y LOCAL - SEC-07)
            if (!isset($_SESSION['used_totp_slices'][$pendingUser])) {
                $_SESSION['used_totp_slices'][$pendingUser] = [];
            }

            if (in_array($matchedSlice, $_SESSION['used_totp_slices'][$pendingUser], true) || !check_and_mark_totp_used_globally($pendingUser, $matchedSlice)) {
                // Código ya utilizado en esta ventana de tiempo por esta o cualquier otra sesión
                header('Location: /login.php?step=2fa&error=totp_replayed');
                exit;
            }

            // Registrar el slice consumido en la sesión
            $_SESSION['used_totp_slices'][$pendingUser][] = $matchedSlice;
            if (count($_SESSION['used_totp_slices'][$pendingUser]) > 10) {
                array_shift($_SESSION['used_totp_slices'][$pendingUser]);
            }

            // AUTENTICACIÓN 2FA EXITOSA Y COMPLETA
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // REGISTRO PERMANENTE DE ENROLAMIENTO 2FA
            if (!is_user_2fa_enrolled($pendingUser)) {
                set_user_2fa_enrolled($pendingUser, true);
            }

            $_SESSION['usuario']       = $pendingUser;
            $_SESSION['nombre']        = $usuarios[$pendingUser]['nombre'];
            $_SESSION['rol']           = $usuarios[$pendingUser]['rol'];
            $_SESSION['carpeta']       = $usuarios[$pendingUser]['carpeta'];
            $_SESSION['url']           = $usuarios[$pendingUser]['url'];
            $_SESSION['2fa_verified']  = true; // BANDERA ESTRICTA DE SEGURIDAD 2FA
            $_SESSION['auth_time']     = time();
            $_SESSION['login_attempts'] = 0;
            $_SESSION['totp_fails']     = 0;
            clear_ip_failures($clientIp);
            unset($_SESSION['2fa_pending_user']);

            header('Location: /dashboard.php');
            exit;
        } else {
            // Código incorrecto o expirado
            record_ip_failure($clientIp);
            $_SESSION['totp_fails'] = ($_SESSION['totp_fails'] ?? 0) + 1;
            
            // Protección contra Fuerza Bruta en 2FA:
            // Si falla 3 veces, expulsar al paso 1 e imponer bloqueo de 60 segundos
            if ($_SESSION['totp_fails'] >= 3) {
                unset($_SESSION['2fa_pending_user']);
                $_SESSION['totp_fails'] = 0;
                $_SESSION['login_attempts'] = 5;
                $_SESSION['last_attempt_time'] = time() + 30; // Bloqueo de 60 segundos
                header('Location: /login.php?error=totp_lockout');
                exit;
            }

            header('Location: /login.php?step=2fa&error=totp_invalid');
            exit;
        }
    }

