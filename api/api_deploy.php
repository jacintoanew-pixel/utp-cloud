<?php
/**
 * Endpoint de Despliegue y Sincronización Real del Espacio de Trabajo Multi-Proyecto
 * UTP Cloud - Producción Ubuntu 24.04
 */

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    if ($isHttps) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// 1. Verificación obligatoria de autenticación y 2FA
if (!isset($_SESSION['usuario']) || empty($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Control de tiempo de inactividad de sesión (2 horas máx - SEC-30)
$maxIdleTime = 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $maxIdleTime)) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión expirada por inactividad']);
    exit;
}
$_SESSION['last_activity'] = time();

$usuario = strtolower(preg_replace('/[^a-z0-9_-]/', '', $_SESSION['usuario']));
$userDir = "/var/www/{$usuario}";

if (!is_dir($userDir)) {
    mkdir($userDir, 0755, true);
}

// Clasificación de extensiones
function classifyExt($ext) {
    $ext = strtolower($ext);
    if (in_array($ext, ['html', 'htm'])) return 'HTML';
    if ($ext === 'css') return 'CSS';
    if ($ext === 'php') return 'PHP';
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'])) return 'IMG';
    if (in_array($ext, ['mp4', 'webm', 'ogv'])) return 'VIDEO';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) return 'AUDIO';
    if (in_array($ext, ['pdf', 'doc', 'docx', 'txt', 'json'])) return 'DOC';
    return 'FILE';
}

function getWorkspaceMetaFile($userDir) {
    return $userDir . '/.workspace_meta.json';
}

function getWorkspaceMeta($userDir, $usuario) {
    $metaFile = getWorkspaceMetaFile($userDir);
    if (file_exists($metaFile)) {
        $content = @file_get_contents($metaFile);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data) && !empty($data['projects']) && is_array($data['projects'])) {
                return $data;
            }
        }
    }

    return [
        'activeProjectId' => 'p1',
        'projects' => [
            [
                'id'        => 'p1',
                'name'      => 'Proyecto 1',
                'slug'      => '',
                'subdomain' => "{$usuario}-p1",
                'url'       => "https://{$usuario}.example.com",
                'storage'   => '5.0'
            ]
        ]
    ];
}

function saveWorkspaceMeta($userDir, $meta) {
    $metaFile = getWorkspaceMetaFile($userDir);
    @file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($metaFile, 0644);
}

function getKnownProjectSlugs($userDir, $usuario) {
    $meta = getWorkspaceMeta($userDir, $usuario);
    $slugs = [];
    if (!empty($meta['projects']) && is_array($meta['projects'])) {
        foreach ($meta['projects'] as $p) {
            $s = trim($p['slug'] ?? '');
            if (!empty($s) && $s !== 'p1' && $s !== 'root') {
                $slugs[strtolower($s)] = true;
            }
        }
    }
    if (is_dir($userDir)) {
        $dirs = @scandir($userDir);
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..') continue;
                if (substr($d, 0, 1) === '.') continue;
                $full = $userDir . '/' . $d;
                if (is_dir($full)) {
                    if (strpos($d, 'proyecto-') === 0 || file_exists($full . '/index.html') || file_exists($full . '/index.php')) {
                        $slugs[strtolower($d)] = true;
                    }
                }
            }
        }
    }
    return array_keys($slugs);
}

function scanServerFiles($baseDir, $usuario, $projectSlug = '', $knownSlugs = []) {
    $result = [];
    if (!is_dir($baseDir)) return $result;

    $isRoot = empty($projectSlug);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $id = 1;
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relPath = str_replace('\\', '/', substr($path, strlen($baseDir) + 1));
        
        $parts = explode('/', $relPath);
        $hasDotPart = false;
        foreach ($parts as $part) {
            if (substr($part, 0, 1) === '.') {
                $hasDotPart = true;
                break;
            }
        }
        if ($hasDotPart) continue;

        if ($isRoot && !empty($knownSlugs)) {
            $firstSeg = strtolower($parts[0]);
            if (in_array($firstSeg, $knownSlugs, true)) {
                continue;
            }
        }

        if ($item->isFile()) {
            $bytes = $item->getSize();

            if ($bytes < 10000) {
                $snippet = @file_get_contents($path);
                if ($snippet !== false) {
                    if (strpos($snippet, 'Nada aquí todavía') !== false || strpos($snippet, 'ESPACIO VACÍO') !== false) {
                        continue;
                    }
                    if (strpos($snippet, '¡Bienvenido a la web de') !== false || strpos($snippet, 'Espacio Web de') !== false) {
                        continue;
                    }
                }
            }

            $ext = strtolower($item->getExtension());
            $type = classifyExt($ext);

            $sizeStr = ($bytes < 1024) ? "{$bytes} B" : round($bytes / 1024, 1) . ' KB';
            if ($bytes >= 1024 * 1024) {
                $sizeStr = round($bytes / (1024 * 1024), 2) . ' MB';
            }

            $dateStr = date('d/m/Y H:i', filemtime($path));
            if ($isRoot) {
                $mediaUrl = "https://{$usuario}.example.com/{$relPath}";
            } else {
                $mediaUrl = "https://{$usuario}.example.com/{$projectSlug}/{$relPath}";
            }

            $code = '';
            if (in_array($type, ['HTML', 'CSS', 'PHP', 'DOC']) && $bytes <= 512 * 1024) {
                $code = @file_get_contents($path) ?: '';
            }

            $result[] = [
                'id'       => $id++,
                'name'     => $relPath,
                'type'     => $type,
                'size'     => $sizeStr,
                'bytes'    => $bytes,
                'date'     => $dateStr,
                'mediaUrl' => $mediaUrl,
                'code'     => $code
            ];
        }
    }
    return $result;
}

$rawPayload = file_get_contents('php://input');
if (!empty($rawPayload)) {
    $jsonBody = json_decode($rawPayload, true) ?: [];
} elseif (!isset($jsonBody) || !is_array($jsonBody)) {
    $jsonBody = [];
}
$action = $_POST['action'] ?? ($_GET['action'] ?? ($jsonBody['action'] ?? 'get_workspace'));

// 2. Validación Anti-CSRF para acciones mutantes
if ($action !== 'get_workspace') {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ($jsonBody['csrf_token'] ?? ''));
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if (empty($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Seguridad: Token CSRF inválido o ausente']);
        exit;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if (!empty($origin)) {
        $parsedHost = parse_url($origin, PHP_URL_HOST);
        if (!empty($parsedHost) && strpos($parsedHost, 'example.com') === false && strpos($parsedHost, 'localhost') === false && strpos($parsedHost, '127.0.0.1') === false) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Seguridad: Origen de solicitud no autorizado']);
            exit;
        }
    }
}

// 404 HTML template minimalista y moderno
$page404Html = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Nada aquí todavía</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #090d16;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            overflow: hidden;
            position: relative;
        }
        .ambient-glow {
            position: absolute;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(99, 102, 241, 0.06) 45%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            filter: blur(50px);
            z-index: 1;
        }
        .container {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 2rem;
            user-select: none;
        }
        .code {
            font-size: clamp(6.5rem, 18vw, 11rem);
            font-weight: 900;
            line-height: 0.95;
            letter-spacing: -0.06em;
            background: linear-gradient(180deg, #ffffff 25%, #64748b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.7));
            margin-bottom: 0.75rem;
        }
        .title {
            font-size: clamp(1.25rem, 3.5vw, 1.75rem);
            font-weight: 500;
            letter-spacing: -0.02em;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>
    <div class="container">
        <div class="code">404</div>
        <div class="title">Nada aquí todavía</div>
    </div>
</body>
</html>
HTML;

// =========================================================================
// ACCIÓN 1: Obtener la lista real de archivos en el disco del servidor
// =========================================================================
if ($action === 'get_workspace') {
    $rawSlug = trim($_GET['project_slug'] ?? ($_POST['project_slug'] ?? ($jsonBody['project_slug'] ?? '')));
    $projectSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);
    if ($projectSlug === 'p1' || $projectSlug === 'root') $projectSlug = '';

    $isRoot = empty($projectSlug);
    $targetDir = $isRoot ? $userDir : $userDir . '/' . $projectSlug;
    $publicUrl = $isRoot ? "https://{$usuario}.example.com" : "https://{$usuario}.example.com/{$projectSlug}/";

    $knownSlugs = getKnownProjectSlugs($userDir, $usuario);
    $files = scanServerFiles($targetDir, $usuario, $projectSlug, $knownSlugs);
    $workspaceMeta = getWorkspaceMeta($userDir, $usuario);

    echo json_encode([
        'success'        => true,
        'user'           => $usuario,
        'project_slug'   => $projectSlug,
        'publicUrl'      => $publicUrl,
        'files'          => $files,
        'workspace_meta' => $workspaceMeta
    ]);
    exit;
}

// =========================================================================
// ACCIÓN 2: Guardar estado y lista de proyectos (Metadata)
// =========================================================================
if ($action === 'save_workspace_state') {
    $state = $jsonBody['state'] ?? [];
    if (!empty($state) && is_array($state)) {
        $currentMeta = getWorkspaceMeta($userDir, $usuario);
        
        if (isset($state['activeProjectId'])) {
            $currentMeta['activeProjectId'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $state['activeProjectId']);
        }
        if (isset($state['projects']) && is_array($state['projects'])) {
            // Límite estricto Anti-DoS de Proyectos (SEC-29)
            if (count($state['projects']) > 10) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Límite de proyectos excedido: Máximo 10 proyectos por inquilino.']);
                exit;
            }

            $cleanProjects = [];
            foreach ($state['projects'] as $p) {
                $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $p['id'] ?? '');
                $rawName = trim($p['name'] ?? 'Proyecto');
                $name = htmlspecialchars(mb_substr($rawName, 0, 60), ENT_QUOTES, 'UTF-8');
                $rawSlug = trim($p['slug'] ?? '');
                $slug = mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug), 0, 32);
                if ($id === 'p1') $slug = '';
                
                $url = empty($slug)
                    ? "https://{$usuario}.example.com"
                    : "https://{$usuario}.example.com/{$slug}/";

                if (!empty($id)) {
                    $cleanProjects[] = [
                        'id'        => $id,
                        'name'      => $name,
                        'slug'      => $slug,
                        'subdomain' => "{$usuario}-{$slug}",
                        'url'       => $url,
                        'storage'   => $p['storage'] ?? '1.0'
                    ];
                }
            }
            if (!empty($cleanProjects)) {
                $currentMeta['projects'] = $cleanProjects;
            }
        }
        saveWorkspaceMeta($userDir, $currentMeta);

        echo json_encode([
            'success'        => true,
            'workspace_meta' => $currentMeta
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

// =========================================================================
// ACCIÓN 3: Desplegar y sincronizar archivos en el servidor en vivo
// =========================================================================
if ($action === 'deploy_files') {
    $filesToDeploy = $jsonBody['files'] ?? [];
    $rawSlug = trim($_GET['project_slug'] ?? ($_POST['project_slug'] ?? ($jsonBody['project_slug'] ?? '')));
    $projectSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);
    if ($projectSlug === 'p1' || $projectSlug === 'root') $projectSlug = '';

    $isRoot = empty($projectSlug);
    $targetDir = $isRoot ? $userDir : $userDir . '/' . $projectSlug;
    $publicUrl = $isRoot ? "https://{$usuario}.example.com" : "https://{$usuario}.example.com/{$projectSlug}/";

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $knownSlugs = getKnownProjectSlugs($userDir, $usuario);

    // CASO A: NO HAY NINGÚN ARCHIVO EN EL ESPACIO (TODOS BORRADOS O VACÍO)
    if (empty($filesToDeploy)) {
        if ($isRoot) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $path = $item->getPathname();
                $rel = str_replace('\\', '/', substr($path, strlen($targetDir) + 1));
                $firstSeg = strtolower(explode('/', $rel)[0]);
                if (in_array($firstSeg, $knownSlugs, true)) {
                    continue; // Proteger subproyectos
                }
                if ($rel === '.workspace_meta.json') {
                    continue; // Proteger metadata
                }
                if ($item->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        } else {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        }

        file_put_contents($targetDir . '/index.html', $page404Html);
        chmod($targetDir . '/index.html', 0644);

        echo json_encode([
            'success'   => false,
            'isEmpty'   => true,
            'error'     => 'No hay ningún archivo en este espacio. Se ha limpiado el servidor y mostrado página 404 (Nada aquí todavía).',
            'publicUrl' => $publicUrl,
            'files'     => []
        ]);
        exit;
    }

    // CASO B: HAY ARCHIVOS ACTIVOS PARA DESPLEGAR
    // Controles Estrictos Anti-DoS: Límite de Archivos, Tamaño Individual y Cuota Total (SEC-21)
    if (count($filesToDeploy) > 150) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Límite de seguridad: No se permite desplegar más de 150 archivos por espacio.']);
        exit;
    }

    $totalProjectBytes = 0;
    $maxProjectQuotaBytes = 35 * 1024 * 1024; // 35 MB cuota total por proyecto
    $maxMediaFileBytes = 15 * 1024 * 1024;    // 15 MB máximo por archivo multimedia
    $maxCodeFileBytes = 2 * 1024 * 1024;      // 2 MB máximo por archivo de código o texto

    foreach ($filesToDeploy as $f) {
        $checkName = trim($f['name'] ?? '');
        if (empty($checkName)) continue;
        
        $ext = strtolower(pathinfo($checkName, PATHINFO_EXTENSION));
        $isMedia = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'mp4', 'webm', 'ogv', 'mp3', 'wav', 'ogg', 'm4a', 'pdf', 'doc', 'docx']);

        $fileBytes = 0;
        if (!empty($f['base64'])) {
            $cleanB64 = preg_replace('#^data:[^,]+,#i', '', $f['base64']);
            $fileBytes = (int)(strlen($cleanB64) * 0.75);
        } elseif (isset($f['code'])) {
            $fileBytes = strlen((string)$f['code']);
        }

        $limitForThisFile = $isMedia ? $maxMediaFileBytes : $maxCodeFileBytes;
        if ($fileBytes > $limitForThisFile) {
            http_response_code(413);
            $limitMB = round($limitForThisFile / (1024 * 1024), 1);
            echo json_encode(['success' => false, 'error' => "El archivo '{$checkName}' excede el límite permitido de {$limitMB} MB."]);
            exit;
        }

        $totalProjectBytes += $fileBytes;
    }

    if ($totalProjectBytes > $maxProjectQuotaBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Cuota de almacenamiento excedida: El tamaño total del proyecto supera el límite de 35 MB.']);
        exit;
    }

    $allowedRelativePaths = [];
    foreach ($filesToDeploy as $f) {
        $name = trim($f['name'] ?? '');
        if (!empty($name)) {
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '_', $name);
            $allowedRelativePaths[strtolower($cleanName)] = true;
        }
    }

    // Sincronización destructiva segura en $targetDir
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $path = $item->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($targetDir) + 1));
        
        if ($isRoot) {
            $firstSeg = strtolower(explode('/', $rel)[0]);
            if (in_array($firstSeg, $knownSlugs, true)) {
                continue; // Proteger subproyectos de raíz
            }
            if ($rel === '.workspace_meta.json') {
                continue;
            }
        }

        if ($item->isFile()) {
            if (!isset($allowedRelativePaths[strtolower($rel)])) {
                @unlink($path);
            }
        }
    }

    // Escribir los archivos en $targetDir
    $deployedCount = 0;
    foreach ($filesToDeploy as $f) {
        $name = trim($f['name'] ?? '');
        if (empty($name)) continue;

        // 1. Sanitización de ruta y prevención estricta de Path Traversal
        if (strpos($name, '..') !== false || substr($name, 0, 1) === '/' || strpos($name, '\\') !== false) {
            continue;
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '_', $name);
        
        // Evitar segmentos vacíos, directorios ocultos o navegación relativa
        $segments = explode('/', $cleanName);
        $hasInvalidSegment = false;
        foreach ($segments as $seg) {
            $segTrim = trim($seg);
            if ($segTrim === '' || $segTrim === '.' || $segTrim === '..' || substr($segTrim, 0, 1) === '.') {
                $hasInvalidSegment = true;
                break;
            }
        }
        if ($hasInvalidSegment) continue;

        // 2. Bloqueo de nombres de archivos críticos del sistema y dotfiles
        $baseFilename = strtolower(basename($cleanName));
        $blockedSystem = [
            '.htaccess', '.user.ini', '.bashrc', '.profile', '.bash_profile', '.bash_logout',
            '.env', 'web.config', 'composer.json', 'package.json', '.workspace_meta.json',
            '.2fa_status.json', '2fa_status.json', 'credentials.json'
        ];
        if (in_array($baseFilename, $blockedSystem, true) || substr($baseFilename, 0, 1) === '.') {
            continue;
        }

        // 3. Control estricto de dobles extensiones ejecutables
        $nameWithoutExt = pathinfo($cleanName, PATHINFO_FILENAME);
        if (preg_match('/\.(php|phtml|phar|php[34578]|sh|exe|cgi|bat|py|pl|inc|htaccess|user\.ini)$/i', $nameWithoutExt)) {
            continue;
        }

        // 4. Lista blanca estricta de extensiones permitidas (SEC-06)
        $fileExt = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        $allowedCode  = ['html', 'htm', 'css', 'php', 'js', 'json', 'txt'];
        $allowedImage = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];
        $allowedAudio = ['mp3', 'wav', 'ogg', 'm4a'];
        $allowedVideo = ['mp4', 'webm', 'ogv'];
        $allowedDoc   = ['pdf', 'doc', 'docx'];
        $allAllowed   = array_merge($allowedCode, $allowedImage, $allowedAudio, $allowedVideo, $allowedDoc);

        if (!in_array($fileExt, $allAllowed, true)) {
            continue;
        }

        $fullPath = $targetDir . '/' . $cleanName;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!empty($f['base64'])) {
            $rawBin = base64_decode(preg_replace('#^data:[^,]+,#i', '', $f['base64']));
            if ($rawBin !== false) {
                if ($fileExt === 'svg') {
                    $rawBin = preg_replace('/<script[\s\S]*?<\/script>/i', '', $rawBin);
                    $rawBin = preg_replace('/<foreignobject[\s\S]*?<\/foreignobject>/i', '', $rawBin);
                    $rawBin = preg_replace('/<animate[\s\S]*?>/i', '', $rawBin);
                    $rawBin = preg_replace('/<set[\s\S]*?>/i', '', $rawBin);
                    $rawBin = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $rawBin);
                    $rawBin = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href="#"', $rawBin);
                }
                file_put_contents($fullPath, $rawBin);
                chmod($fullPath, 0644);
                $deployedCount++;
            }
        } elseif (isset($f['code'])) {
            $codeContent = $f['code'];
            if ($fileExt === 'svg') {
                $codeContent = preg_replace('/<script[\s\S]*?<\/script>/i', '', $codeContent);
                $codeContent = preg_replace('/<foreignobject[\s\S]*?<\/foreignobject>/i', '', $codeContent);
                $codeContent = preg_replace('/<animate[\s\S]*?>/i', '', $codeContent);
                $codeContent = preg_replace('/<set[\s\S]*?>/i', '', $codeContent);
                $codeContent = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $codeContent);
                $codeContent = preg_replace('/href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href="#"', $codeContent);
            }
            file_put_contents($fullPath, $codeContent);
            chmod($fullPath, 0644);
            $deployedCount++;
        }
    }

    // Si se desplegó index.html y existe index.php por defecto de plantilla, purgarlo
    $hasIndexHtml = isset($allowedRelativePaths['index.html']) || file_exists($targetDir . '/index.html');
    if ($hasIndexHtml && file_exists($targetDir . '/index.php')) {
        $idxContent = @file_get_contents($targetDir . '/index.php');
        if ($idxContent && (strpos($idxContent, '¡Bienvenido a la web de') !== false || strpos($idxContent, 'Espacio Web de') !== false)) {
            @unlink($targetDir . '/index.php');
        }
    }

    // Auto-generar index.html si el usuario subió archivos HTML pero ninguno se llama 'index.html'
    if (!file_exists($targetDir . '/index.html') && !file_exists($targetDir . '/index.php')) {
        $candidateHtml = null;
        foreach ($allowedRelativePaths as $rel => $true) {
            $relExt = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (in_array($relExt, ['html', 'htm'], true) && file_exists($targetDir . '/' . $rel)) {
                $candidateHtml = $targetDir . '/' . $rel;
                break;
            }
        }
        if ($candidateHtml) {
            copy($candidateHtml, $targetDir . '/index.html');
            chmod($targetDir . '/index.html', 0644);
        }
    }

    $freshFiles = scanServerFiles($targetDir, $usuario, $projectSlug, $knownSlugs);

    echo json_encode([
        'success'       => true,
        'message'       => "Se desplegaron exitosamente {$deployedCount} archivo(s) en tu sitio web en vivo.",
        'deployedCount' => $deployedCount,
        'publicUrl'     => $publicUrl,
        'files'         => $freshFiles
    ]);
    exit;
}

// =========================================================================
// ACCIÓN 4: Eliminar espacio de trabajo completo (Subproyecto)
// =========================================================================
if ($action === 'delete_project') {
    $rawSlug = trim($_POST['project_slug'] ?? ($jsonBody['project_slug'] ?? ''));
    $projectSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);
    $projectId = trim($_POST['project_id'] ?? ($jsonBody['project_id'] ?? ''));

    if (empty($projectSlug) || $projectSlug === 'p1' || $projectSlug === 'root' || $projectId === 'p1') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar el espacio de trabajo principal (Proyecto 1)']);
        exit;
    }

    $targetDir = $userDir . '/' . $projectSlug;
    if (is_dir($targetDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($targetDir);
    }

    $meta = getWorkspaceMeta($userDir, $usuario);
    $meta['projects'] = array_values(array_filter($meta['projects'] ?? [], function($p) use ($projectId, $projectSlug) {
        return (($p['id'] ?? '') !== $projectId && ($p['slug'] ?? '') !== $projectSlug);
    }));
    if (($meta['activeProjectId'] ?? '') === $projectId) {
        $meta['activeProjectId'] = 'p1';
    }
    saveWorkspaceMeta($userDir, $meta);

    echo json_encode([
        'success'        => true,
        'message'        => 'Espacio de trabajo eliminado exitosamente.',
        'workspace_meta' => $meta
    ]);
    exit;
}

// =========================================================================
// ACCIÓN 5: Eliminar archivo del disco del servidor
// =========================================================================
if ($action === 'delete_file') {
    $rawSlug = trim($_POST['project_slug'] ?? ($jsonBody['project_slug'] ?? ''));
    $projectSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);
    if ($projectSlug === 'p1' || $projectSlug === 'root') $projectSlug = '';

    $targetDir = empty($projectSlug) ? $userDir : $userDir . '/' . $projectSlug;

    $target = trim($_POST['name'] ?? ($jsonBody['name'] ?? ''));
    if (empty($target) || strpos($target, '..') !== false || substr($target, 0, 1) === '/' || strpos($target, '\\') !== false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nombre no válido']);
        exit;
    }

    $cleanTarget = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '_', $target);
    $baseFilename = strtolower(basename($cleanTarget));
    if (substr($baseFilename, 0, 1) === '.' || $baseFilename === '.workspace_meta.json' || $baseFilename === 'credentials.json' || $baseFilename === '2fa_status.json') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No se permite eliminar archivos del sistema']);
        exit;
    }

    $fullPath = $targetDir . '/' . $cleanTarget;
    $realTargetDir = realpath($targetDir);

    if (is_file($fullPath)) {
        $realTarget = realpath($fullPath);
        if ($realTarget && $realTargetDir && strpos($realTarget, $realTargetDir) === 0) {
            unlink($fullPath);
        }
    }

    $knownSlugs = getKnownProjectSlugs($userDir, $usuario);
    $freshFiles = scanServerFiles($targetDir, $usuario, $projectSlug, $knownSlugs);
    echo json_encode([
        'success' => true,
        'deleted' => $cleanTarget,
        'files'   => $freshFiles
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Acción no válida']);
