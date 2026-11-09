<?php
// Configuración de sesión
session_start();

// Protección Anti-CSRF / Cross-Site Navigation (SEC-10)
if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] === 'cross-site') {
    http_response_code(403);
    exit('Solicitud no autorizada.');
}

// Si se recibe cabecera Referer u Origin, validar que pertenezca al dominio legítimo
$ref = $_SERVER['HTTP_REFERER'] ?? ($_SERVER['HTTP_ORIGIN'] ?? '');
if (!empty($ref)) {
    $host = parse_url($ref, PHP_URL_HOST);
    if (!empty($host) && strpos($host, 'example.com') === false && strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
        http_response_code(403);
        exit('Origen de cierre de sesión no autorizado.');
    }
}

$_SESSION = [];

// Eliminar cookie de sesión en el cliente
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header('Location: login.php?logout=1');
exit;
