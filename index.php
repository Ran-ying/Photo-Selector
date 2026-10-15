<?php
declare(strict_types=1);

/*
 * 模特选片台 — 单文件 PHP 应用
 *
 * 部署：把本文件放到支持 PHP、PDO_SQLite 和 fileinfo 的网站目录。
 * 首次打开时设置管理员密码。运行数据默认写入本文件上一级目录的
 * private_model_photo_selector；也可用 PHOTO_SELECTOR_DATA_DIR 环境变量指定。
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// High-resolution camera JPEGs can temporarily require several times their
// compressed file size while GD applies EXIF orientation or a manual rotation.
ini_set('memory_limit', '512M');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('model_photo_selector');
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

const APP_NAME = '模特选片台';
const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;
const MAX_UPLOAD_FILES = 200;
const DERIVATIVE_VERSION = 5;

$configuredDataDir = getenv('PHOTO_SELECTOR_DATA_DIR');
$dataDir = $configuredDataDir !== false && trim($configuredDataDir) !== ''
    ? rtrim($configuredDataDir, DIRECTORY_SEPARATOR)
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_model_photo_selector';
$originalDir = $dataDir . DIRECTORY_SEPARATOR . 'originals';
$previewDir = $dataDir . DIRECTORY_SEPARATOR . 'previews';
$thumbDir = $dataDir . DIRECTORY_SEPARATOR . 'thumbs';

foreach ([$dataDir, $originalDir, $previewDir, $thumbDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('无法创建数据目录，请检查 PHP 写入权限。');
    }
}

try {
    $db = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'selector.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    shoot_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS models (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    cn_name TEXT NOT NULL,
    character_name TEXT NOT NULL DEFAULT '',
    access_code TEXT NOT NULL UNIQUE,
    allow_download INTEGER NOT NULL DEFAULT 1,
    session_version INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'draft',
    submitted_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    model_id INTEGER NOT NULL REFERENCES models(id) ON DELETE CASCADE,
    stored_name TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    width INTEGER,
    height INTEGER,
    rotation INTEGER NOT NULL DEFAULT 0,
    rating INTEGER NOT NULL DEFAULT 0 CHECK(rating BETWEEN 0 AND 5),
    note TEXT NOT NULL DEFAULT '',
    reviewed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_models_event ON models(event_id);
CREATE INDEX IF NOT EXISTS idx_photos_model ON photos(model_id);
CREATE TABLE IF NOT EXISTS access_attempts (
    ip_hash TEXT PRIMARY KEY,
    tries INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
);
SQL);
    $photoColumns = array_column($db->query('PRAGMA table_info(photos)')->fetchAll(), 'name');
    if (!in_array('rotation', $photoColumns, true)) {
        $db->exec('ALTER TABLE photos ADD COLUMN rotation INTEGER NOT NULL DEFAULT 0');
    }
    $modelColumns = array_column($db->query('PRAGMA table_info(models)')->fetchAll(), 'name');
    if (!in_array('session_version', $modelColumns, true)) {
        // Existing model sessions are invalidated after this compatible migration.
        $db->exec('ALTER TABLE models ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0');
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('数据库初始化失败，请确认已启用 PDO_SQLite。');
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    static $value;
    if ($value !== null) return $value;
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $value = is_array($decoded) ? $decoded : [];
    } else {
        $value = $_POST;
    }
    return $value;
}

function setting(PDO $db, string $key): ?string
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function setSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)');
    $stmt->execute([$key, $value]);
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (input()['csrf'] ?? '');
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        jsonResponse(['ok' => false, 'error' => '页面已过期，请刷新后重试。'], 419);
    }
}

function requireAdmin(): void
{
    if (empty($_SESSION['admin'])) jsonResponse(['ok' => false, 'error' => '请先登录管理员。'], 401);
}

function activeModelId(PDO $db): int
{
    $id = (int)($_SESSION['model_id'] ?? 0);
    if ($id < 1) return 0;
    $stmt = $db->prepare('SELECT session_version FROM models WHERE id = ?');
    $stmt->execute([$id]);
    $version = $stmt->fetchColumn();
    if ($version === false || !isset($_SESSION['model_auth_version'])
        || (int)$_SESSION['model_auth_version'] !== (int)$version) {
        unset($_SESSION['model_id'], $_SESSION['model_auth_version']);
        return 0;
    }
    return $id;
}

function requireModel(PDO $db): int
{
    $id = activeModelId($db);
    if ($id < 1) jsonResponse(['ok' => false, 'error' => '访问已失效，请重新输入访问码。'], 401);
    return $id;
}

function textValue(mixed $value, int $max): string
{
    $text = trim((string)$value);
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max);
    return substr($text, 0, $max);
}

function generateCode(PDO $db, int $length = 6): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $db->prepare('SELECT 1 FROM models WHERE access_code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

function clientIpHash(): string
{
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function enforceAttemptLimit(PDO $db): void
{
    $hash = clientIpHash();
    $now = time();
    $stmt = $db->prepare('SELECT tries, window_start FROM access_attempts WHERE ip_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if ($row && $now - (int)$row['window_start'] < 900 && (int)$row['tries'] >= 12) {
        jsonResponse(['ok' => false, 'error' => '尝试次数过多，请15分钟后再试。'], 429);
    }
    if (!$row || $now - (int)$row['window_start'] >= 900) {
        $stmt = $db->prepare('INSERT OR REPLACE INTO access_attempts(ip_hash, tries, window_start) VALUES(?, 0, ?)');
        $stmt->execute([$hash, $now]);
    }
}

function recordFailedAttempt(PDO $db): void
{
    $stmt = $db->prepare('UPDATE access_attempts SET tries = tries + 1 WHERE ip_hash = ?');
    $stmt->execute([clientIpHash()]);
}

function clearAttempts(PDO $db): void
{
    $stmt = $db->prepare('DELETE FROM access_attempts WHERE ip_hash = ?');
    $stmt->execute([clientIpHash()]);
}

function extensionForMime(string $mime): ?string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => null,
    };
}

function jpegOrientation(string $path): int
{
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($path, 'IFD0');
        $orientation = (int)($exif['Orientation'] ?? 0);
        if ($orientation >= 1 && $orientation <= 8) return $orientation;
    }

    $data = @file_get_contents($path, false, null, 0, 131072);
    if (!is_string($data) || strlen($data) < 18) return 1;
    $exifAt = strpos($data, "Exif\0\0");
    if ($exifAt === false) return 1;
    $tiff = $exifAt + 6;
    $byteOrder = substr($data, $tiff, 2);
    if ($byteOrder !== 'II' && $byteOrder !== 'MM') return 1;
    $little = $byteOrder === 'II';
    $u16 = static function (string $buffer, int $offset, bool $isLittle): int {
        if ($offset < 0 || $offset + 2 > strlen($buffer)) return 0;
        $a = ord($buffer[$offset]);
        $b = ord($buffer[$offset + 1]);
        return $isLittle ? $a | ($b << 8) : ($a << 8) | $b;
    };
    $u32 = static function (string $buffer, int $offset, bool $isLittle): int {
        if ($offset < 0 || $offset + 4 > strlen($buffer)) return 0;
        $a = ord($buffer[$offset]);
        $b = ord($buffer[$offset + 1]);
        $c = ord($buffer[$offset + 2]);
        $d = ord($buffer[$offset + 3]);
        return $isLittle
            ? $a | ($b << 8) | ($c << 16) | ($d << 24)
            : ($a << 24) | ($b << 16) | ($c << 8) | $d;
    };
    if ($u16($data, $tiff + 2, $little) !== 42) return 1;
    $ifd = $tiff + $u32($data, $tiff + 4, $little);
    $entryCount = $u16($data, $ifd, $little);
    if ($entryCount < 1 || $entryCount > 512) return 1;
    for ($i = 0; $i < $entryCount; $i++) {
        $entry = $ifd + 2 + ($i * 12);
        if ($entry + 12 > strlen($data)) break;
        if ($u16($data, $entry, $little) !== 0x0112) continue;
        $orientation = $u16($data, $entry + 8, $little);
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }
    return 1;
}

function loadImage(string $path, string $mime): mixed
{
    if (!extension_loaded('gd')) return null;
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function orientImage(mixed $image, int $orientation): mixed
{
    if ($orientation === 2) imageflip($image, IMG_FLIP_HORIZONTAL);
    if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
    if (in_array($orientation, [3, 5, 6, 7, 8], true)) {
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
        };
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($image);
            $image = $rotated;
        }
    }
    if (in_array($orientation, [5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
    return $image;
}

function rotateImage(mixed $image, int $rotation): mixed
{
    $rotation = (($rotation % 360) + 360) % 360;
    if (!in_array($rotation, [90, 180, 270], true)) return $image;
    $rotated = imagerotate($image, -$rotation, 0);
    if ($rotated === false) return $image;
    imagedestroy($image);
    return $rotated;
}

function makeJpegDerivative(string $source, string $target, string $mime, int $maxEdge, int $quality, int $rotation = 0): bool
{
    $convert = '/usr/bin/convert';
    if (function_exists('exec') && is_executable($convert)) {
        $normalizedRotation = (($rotation % 360) + 360) % 360;
        $parts = [
            escapeshellarg($convert),
            escapeshellarg($source),
            '-auto-orient',
            '-thumbnail',
            escapeshellarg($maxEdge . 'x' . $maxEdge . '>'),
        ];
        if (in_array($normalizedRotation, [90, 180, 270], true)) {
            $parts[] = '-rotate';
            $parts[] = escapeshellarg((string)$normalizedRotation);
        }
        array_push(
            $parts,
            '-background', 'white',
            '-alpha', 'remove',
            '-colorspace', 'sRGB',
            '-strip',
            '-quality', escapeshellarg((string)$quality),
            escapeshellarg($target)
        );
        $output = [];
        $status = 1;
        @exec(implode(' ', $parts), $output, $status);
        if ($status === 0 && is_file($target) && filesize($target) > 0) return true;
        @unlink($target);
    }

    $image = loadImage($source, $mime);
    if (!$image) return false;
    $width = imagesx($image);
    $height = imagesy($image);
    // Keep the GD fallback below a 128 MB PHP memory limit for 24 MP camera JPEGs.
    $gdMaxEdge = min($maxEdge, 1400);
    $scale = min(1, $gdMaxEdge / max($width, $height));
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($image);
    if ($mime === 'image/jpeg') $canvas = orientImage($canvas, jpegOrientation($source));
    $canvas = rotateImage($canvas, $rotation);
    $ok = imagejpeg($canvas, $target, $quality);
    imagedestroy($canvas);
    return $ok;
}

function adminData(PDO $db): array
{
    $events = $db->query('SELECT * FROM events ORDER BY shoot_date DESC, id DESC')->fetchAll();
    $modelStmt = $db->prepare(<<<'SQL'
SELECT m.*,
       COUNT(p.id) AS photo_count,
       SUM(CASE WHEN p.rating > 0 OR p.note <> '' THEN 1 ELSE 0 END) AS reviewed_count,
       SUM(CASE WHEN p.rating >= 4 THEN 1 ELSE 0 END) AS selected_count
FROM models m
LEFT JOIN photos p ON p.model_id = m.id
WHERE m.event_id = ?
GROUP BY m.id
ORDER BY m.id DESC
SQL);
    $photoStmt = $db->prepare('SELECT id, model_id, original_name, file_size, width, height, rotation, rating, note, reviewed_at, created_at FROM photos WHERE model_id = ? ORDER BY id');
    foreach ($events as &$event) {
        $modelStmt->execute([(int)$event['id']]);
        $event['models'] = $modelStmt->fetchAll();
        foreach ($event['models'] as &$model) {
            $photoStmt->execute([(int)$model['id']]);
            $model['photos'] = $photoStmt->fetchAll();
            foreach ($model['photos'] as &$photo) {
                $photo['thumb_url'] = '?api=photo&id=' . $photo['id'] . '&size=thumb&v=' . DERIVATIVE_VERSION . '&r=' . (int)$photo['rotation'];
                $photo['preview_url'] = '?api=photo&id=' . $photo['id'] . '&size=preview&v=' . DERIVATIVE_VERSION . '&r=' . (int)$photo['rotation'];
            }
        }
    }
    return $events;
}

function modelData(PDO $db, int $modelId): ?array
{
    $stmt = $db->prepare(<<<'SQL'
SELECT m.id, m.cn_name, m.character_name, m.allow_download, m.review_status, m.submitted_at,
       e.name AS event_name, e.shoot_date
FROM models m JOIN events e ON e.id = m.event_id
WHERE m.id = ?
SQL);
    $stmt->execute([$modelId]);
    $model = $stmt->fetch();
    if (!$model) return null;
    $photos = $db->prepare('SELECT id, original_name, width, height, rotation, rating, note, reviewed_at FROM photos WHERE model_id = ? ORDER BY id');
    $photos->execute([$modelId]);
    $model['photos'] = $photos->fetchAll();
    foreach ($model['photos'] as &$photo) {
        $photo['thumb_url'] = '?api=photo&id=' . $photo['id'] . '&size=thumb&v=' . DERIVATIVE_VERSION . '&r=' . (int)$photo['rotation'];
        $photo['preview_url'] = '?api=photo&id=' . $photo['id'] . '&size=preview&v=' . DERIVATIVE_VERSION . '&r=' . (int)$photo['rotation'];
        $photo['download_url'] = '?api=photo&id=' . $photo['id'] . '&size=original&download=1';
    }
    return $model;
}

function servePhoto(PDO $db, string $originalDir, string $previewDir, string $thumbDir): void
{
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM photos WHERE id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetch();
    if (!$photo) {
        http_response_code(404);
        exit;
    }

    $isAdmin = !empty($_SESSION['admin']);
    $sessionModel = activeModelId($db);
    if (!$isAdmin && $sessionModel !== (int)$photo['model_id']) {
        http_response_code(403);
        exit;
    }

    $size = (string)($_GET['size'] ?? 'preview');
    if (!in_array($size, ['thumb', 'preview', 'original'], true)) {
        http_response_code(400);
        exit;
    }
    $download = !empty($_GET['download']);
    if ($download && $size !== 'original') {
        http_response_code(400);
        exit;
    }
    // The download flag is a presentation detail, not an authorization
    // boundary. Any original-byte request must respect allow_download.
    if ($size === 'original' && !$isAdmin) {
        $allow = $db->prepare('SELECT allow_download FROM models WHERE id = ?');
        $allow->execute([$sessionModel]);
        if (!(int)$allow->fetchColumn()) {
            http_response_code(403);
            exit;
        }
    }

    $stored = basename((string)$photo['stored_name']);
    $path = $originalDir . DIRECTORY_SEPARATOR . $stored;
    $originalPath = $path;
    $mime = (string)$photo['mime'];
    $rotation = ((int)($photo['rotation'] ?? 0) % 360 + 360) % 360;
    if (is_file($originalPath)) {
        $dimensions = @getimagesize($originalPath);
        if (is_array($dimensions)) {
            $displayWidth = (int)$dimensions[0];
            $displayHeight = (int)$dimensions[1];
            if ($mime === 'image/jpeg' && in_array(jpegOrientation($originalPath), [5, 6, 7, 8], true)) {
                [$displayWidth, $displayHeight] = [$displayHeight, $displayWidth];
            }
            if (in_array($rotation, [90, 270], true)) {
                [$displayWidth, $displayHeight] = [$displayHeight, $displayWidth];
            }
            if ($displayWidth !== (int)$photo['width'] || $displayHeight !== (int)$photo['height']) {
                $db->prepare('UPDATE photos SET width = ?, height = ? WHERE id = ?')->execute([$displayWidth, $displayHeight, (int)$photo['id']]);
            }
        }
    }
    if ($size === 'thumb') {
        $candidate = $thumbDir . DIRECTORY_SEPARATOR . pathinfo($stored, PATHINFO_FILENAME) . '.jpg';
        if (!is_file($candidate) || ((int)($_GET['v'] ?? 0) >= DERIVATIVE_VERSION && filemtime($candidate) < filemtime(__FILE__))) {
            makeJpegDerivative($originalPath, $candidate, (string)$photo['mime'], 560, 78, $rotation);
        }
        if (is_file($candidate)) { $path = $candidate; $mime = 'image/jpeg'; }
    } elseif ($size === 'preview') {
        $candidate = $previewDir . DIRECTORY_SEPARATOR . pathinfo($stored, PATHINFO_FILENAME) . '.jpg';
        if (!is_file($candidate) || ((int)($_GET['v'] ?? 0) >= DERIVATIVE_VERSION && filemtime($candidate) < filemtime(__FILE__))) {
            makeJpegDerivative($originalPath, $candidate, (string)$photo['mime'], 2200, 86, $rotation);
        }
        if (is_file($candidate)) { $path = $candidate; $mime = 'image/jpeg'; }
    }
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    if ($download) {
        // RFC 5987 UTF-8 filename with an ASCII fallback; strip unsafe header controls.
        $originalName = basename(str_replace('\\', '/', (string)$photo['original_name']));
        $safe = preg_replace('/[\\x00-\\x1F\\x7F]/u', '_', $originalName) ?: 'photo.jpg';
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe) ?: 'photo.jpg';
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($safe));
    } else {
        header('Content-Disposition: inline');
    }
    readfile($path);
    exit;
}

$api = (string)($_GET['api'] ?? '');
if ($api !== '') {
    try {
        if ($api === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $installed = setting($db, 'admin_password') !== null;
            $payload = [
                'ok' => true,
                'installed' => $installed,
                'admin' => !empty($_SESSION['admin']),
                'model' => !empty($_SESSION['model_id']),
                'csrf' => csrfToken(),
            ];
            if (!empty($_SESSION['admin'])) $payload['events'] = adminData($db);
            $modelId = activeModelId($db);
            $payload['model'] = $modelId > 0;
            if ($modelId > 0) $payload['model_data'] = modelData($db, $modelId);
            jsonResponse($payload);
        }

        if ($api === 'photo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            servePhoto($db, $originalDir, $previewDir, $thumbDir);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok' => false, 'error' => '请求方法不受支持。'], 405);
        requireCsrf();
        $body = input();

        if ($api === 'setup') {
            if (setting($db, 'admin_password') !== null) jsonResponse(['ok' => false, 'error' => '已完成初始设置。'], 409);
            $password = (string)($body['password'] ?? '');
            if (strlen($password) < 8) jsonResponse(['ok' => false, 'error' => '管理员密码至少8位。'], 422);
            setSetting($db, 'admin_password', password_hash($password, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            jsonResponse(['ok' => true]);
        }

        if ($api === 'admin_login') {
            enforceAttemptLimit($db);
            $hash = setting($db, 'admin_password');
            if (!$hash || !password_verify((string)($body['password'] ?? ''), $hash)) {
                recordFailedAttempt($db);
                jsonResponse(['ok' => false, 'error' => '密码不正确。'], 401);
            }
            clearAttempts($db);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            unset($_SESSION['model_id'], $_SESSION['model_auth_version']);
            jsonResponse(['ok' => true]);
        }

        if ($api === 'model_login') {
            enforceAttemptLimit($db);
            $code = strtoupper(preg_replace('/\s+/', '', (string)($body['code'] ?? '')));
            $stmt = $db->prepare('SELECT id, session_version FROM models WHERE access_code = ?');
            $stmt->execute([$code]);
            $model = $stmt->fetch();
            $id = $model ? (int)$model['id'] : 0;
            if ($id < 1) {
                recordFailedAttempt($db);
                jsonResponse(['ok' => false, 'error' => '访问码不正确。'], 401);
            }
            clearAttempts($db);
            session_regenerate_id(true);
            $_SESSION['model_id'] = $id;
            $_SESSION['model_auth_version'] = (int)$model['session_version'];
            unset($_SESSION['admin']);
            jsonResponse(['ok' => true]);
        }

        if ($api === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            jsonResponse(['ok' => true, 'csrf' => csrfToken()]);
        }

        if ($api === 'create_event') {
            requireAdmin();
            $name = textValue($body['name'] ?? '', 80);
            $date = textValue($body['shoot_date'] ?? '', 10);
            if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonResponse(['ok' => false, 'error' => '请填写活动名称和拍摄日期。'], 422);
            }
            $stmt = $db->prepare('INSERT INTO events(name, shoot_date) VALUES(?, ?)');
            $stmt->execute([$name, $date]);
            jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        }

        if ($api === 'delete_event') {
            requireAdmin();
            $eventId = (int)($body['event_id'] ?? 0);
            if ($eventId < 1) jsonResponse(['ok' => false, 'error' => '活动参数无效。'], 422);

            $photos = $db->prepare('SELECT p.stored_name FROM photos p JOIN models m ON m.id = p.model_id WHERE m.event_id = ?');
            $photos->execute([$eventId]);
            $storedNames = $photos->fetchAll(PDO::FETCH_COLUMN);

            $delete = $db->prepare('DELETE FROM events WHERE id = ?');
            $delete->execute([$eventId]);
            if ($delete->rowCount() < 1) jsonResponse(['ok' => false, 'error' => '活动不存在或已被删除。'], 404);

            foreach ($storedNames as $storedName) {
                $storedName = basename((string)$storedName);
                $baseName = pathinfo($storedName, PATHINFO_FILENAME);
                @unlink($originalDir . DIRECTORY_SEPARATOR . $storedName);
                @unlink($previewDir . DIRECTORY_SEPARATOR . $baseName . '.jpg');
                @unlink($thumbDir . DIRECTORY_SEPARATOR . $baseName . '.jpg');
            }
            jsonResponse(['ok' => true]);
        }

        if ($api === 'create_model') {
            requireAdmin();
            $eventId = (int)($body['event_id'] ?? 0);
            $cn = textValue($body['cn_name'] ?? '', 60);
            $character = textValue($body['character_name'] ?? '', 80);
            if ($eventId < 1 || $cn === '') jsonResponse(['ok' => false, 'error' => '请选择活动并填写模特CN。'], 422);
            $exists = $db->prepare('SELECT 1 FROM events WHERE id = ?');
            $exists->execute([$eventId]);
            if (!$exists->fetchColumn()) jsonResponse(['ok' => false, 'error' => '活动不存在。'], 404);
            $code = generateCode($db);
            $stmt = $db->prepare('INSERT INTO models(event_id, cn_name, character_name, access_code, allow_download) VALUES(?, ?, ?, ?, ?)');
            $stmt->execute([$eventId, $cn, $character, $code, !empty($body['allow_download']) ? 1 : 0]);
            jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId(), 'code' => $code]);
        }

        if ($api === 'update_model') {
            requireAdmin();
            $modelId = (int)($body['model_id'] ?? 0);
            $cn = textValue($body['cn_name'] ?? '', 60);
            $character = textValue($body['character_name'] ?? '', 80);
            if ($modelId < 1 || $cn === '') jsonResponse(['ok' => false, 'error' => '请填写模特CN。'], 422);
            $exists = $db->prepare('SELECT 1 FROM models WHERE id = ?');
            $exists->execute([$modelId]);
            if (!$exists->fetchColumn()) jsonResponse(['ok' => false, 'error' => '模特不存在或已被删除。'], 404);
            $db->prepare('UPDATE models SET cn_name = ?, character_name = ? WHERE id = ?')->execute([$cn, $character, $modelId]);
            jsonResponse(['ok' => true]);
        }

        if ($api === 'delete_model') {
            requireAdmin();
            $modelId = (int)($body['model_id'] ?? 0);
            if ($modelId < 1) jsonResponse(['ok' => false, 'error' => '模特参数无效。'], 422);

            $photos = $db->prepare('SELECT stored_name FROM photos WHERE model_id = ?');
            $photos->execute([$modelId]);
            $storedNames = $photos->fetchAll(PDO::FETCH_COLUMN);
            $delete = $db->prepare('DELETE FROM models WHERE id = ?');
            $delete->execute([$modelId]);
            if ($delete->rowCount() < 1) jsonResponse(['ok' => false, 'error' => '模特不存在或已被删除。'], 404);

            foreach ($storedNames as $storedName) {
                $storedName = basename((string)$storedName);
                $baseName = pathinfo($storedName, PATHINFO_FILENAME);
                @unlink($originalDir . DIRECTORY_SEPARATOR . $storedName);
                @unlink($previewDir . DIRECTORY_SEPARATOR . $baseName . '.jpg');
                @unlink($thumbDir . DIRECTORY_SEPARATOR . $baseName . '.jpg');
            }
            jsonResponse(['ok' => true]);
        }

        if ($api === 'regenerate_code') {
            requireAdmin();
            $modelId = (int)($body['model_id'] ?? 0);
            $code = generateCode($db);
            $stmt = $db->prepare('UPDATE models SET access_code = ?, session_version = session_version + 1 WHERE id = ?');
            $stmt->execute([$code, $modelId]);
            if ($stmt->rowCount() < 1) jsonResponse(['ok' => false, 'error' => '模特不存在。'], 404);
            jsonResponse(['ok' => true, 'code' => $code]);
        }

        if ($api === 'toggle_download') {
            requireAdmin();
            $stmt = $db->prepare('UPDATE models SET allow_download = ? WHERE id = ?');
            $stmt->execute([!empty($body['allow_download']) ? 1 : 0, (int)($body['model_id'] ?? 0)]);
            jsonResponse(['ok' => true]);
        }

        if ($api === 'rotate_photo') {
            requireAdmin();
            $photoId = (int)($body['photo_id'] ?? 0);
            $direction = (string)($body['direction'] ?? '');
            if ($photoId < 1 || !in_array($direction, ['left', 'right'], true)) {
                jsonResponse(['ok' => false, 'error' => '旋转参数无效。'], 422);
            }
            $stmt = $db->prepare('SELECT stored_name, mime, width, height, rotation FROM photos WHERE id = ?');
            $stmt->execute([$photoId]);
            $photo = $stmt->fetch();
            if (!$photo) jsonResponse(['ok' => false, 'error' => '照片不存在。'], 404);
            $step = $direction === 'right' ? 90 : -90;
            $rotation = (((int)$photo['rotation'] + $step) % 360 + 360) % 360;
            $stored = basename((string)$photo['stored_name']);
            $originalPath = $originalDir . DIRECTORY_SEPARATOR . $stored;
            $dimensions = @getimagesize($originalPath);
            $width = is_array($dimensions) ? (int)$dimensions[0] : (int)$photo['width'];
            $height = is_array($dimensions) ? (int)$dimensions[1] : (int)$photo['height'];
            if ((string)$photo['mime'] === 'image/jpeg' && in_array(jpegOrientation($originalPath), [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
            if (in_array($rotation, [90, 270], true)) [$width, $height] = [$height, $width];
            $db->prepare('UPDATE photos SET rotation = ?, width = ?, height = ? WHERE id = ?')
                ->execute([$rotation, $width, $height, $photoId]);
            $base = pathinfo($stored, PATHINFO_FILENAME);
            @unlink($previewDir . DIRECTORY_SEPARATOR . $base . '.jpg');
            @unlink($thumbDir . DIRECTORY_SEPARATOR . $base . '.jpg');
            jsonResponse(['ok' => true, 'rotation' => $rotation]);
        }

        if ($api === 'upload') {
            requireAdmin();
            $modelId = (int)($_POST['model_id'] ?? 0);
            $exists = $db->prepare('SELECT 1 FROM models WHERE id = ?');
            $exists->execute([$modelId]);
            if (!$exists->fetchColumn()) jsonResponse(['ok' => false, 'error' => '请先选择模特。'], 422);
            if (empty($_FILES['photos'])) jsonResponse(['ok' => false, 'error' => '请选择照片。'], 422);

            $files = $_FILES['photos'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            if ($count > MAX_UPLOAD_FILES) jsonResponse(['ok' => false, 'error' => '单次最多上传200张照片。'], 422);
            $saved = 0;
            $errors = [];

            $savePhoto = static function (string $tmp, string $name, int $size, bool $uploadedFile) use ($db, $modelId, $originalDir, $previewDir, $thumbDir): ?string {
                if ($size < 1 || $size > MAX_UPLOAD_BYTES) return $name . '：文件为空或超过30MB';
                $dimensions = @getimagesize($tmp);
                $mime = is_array($dimensions) ? (string)($dimensions['mime'] ?? '') : '';
                $ext = extensionForMime($mime);
                if ($ext === null || !$dimensions) return $name . '：仅支持JPEG、PNG和WebP';

                $base = bin2hex(random_bytes(16));
                $stored = $base . '.' . $ext;
                $destination = $originalDir . DIRECTORY_SEPARATOR . $stored;
                $storedOk = $uploadedFile ? move_uploaded_file($tmp, $destination) : copy($tmp, $destination);
                if (!$storedOk) return $name . '：保存失败';

                @chmod($destination, 0640);
                makeJpegDerivative($destination, $previewDir . DIRECTORY_SEPARATOR . $base . '.jpg', $mime, 2200, 86);
                makeJpegDerivative($destination, $thumbDir . DIRECTORY_SEPARATOR . $base . '.jpg', $mime, 560, 78);
                $displayWidth = (int)$dimensions[0];
                $displayHeight = (int)$dimensions[1];
                if ($mime === 'image/jpeg') {
                    if (in_array(jpegOrientation($destination), [5, 6, 7, 8], true)) {
                        [$displayWidth, $displayHeight] = [$displayHeight, $displayWidth];
                    }
                }
                $stmt = $db->prepare('INSERT INTO photos(model_id, stored_name, original_name, mime, file_size, width, height) VALUES(?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$modelId, $stored, textValue(basename($name), 180), $mime, $size, $displayWidth, $displayHeight]);
                return null;
            };

            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? (string)$files['name'][$i] : (string)$files['name'];
                $tmp = is_array($files['tmp_name']) ? (string)$files['tmp_name'][$i] : (string)$files['tmp_name'];
                $error = is_array($files['error']) ? (int)$files['error'][$i] : (int)$files['error'];
                $size = is_array($files['size']) ? (int)$files['size'][$i] : (int)$files['size'];
                if ($error !== UPLOAD_ERR_OK || $size < 1) {
                    $errors[] = $name . '：上传失败';
                    continue;
                }

                $photoError = $savePhoto($tmp, $name, $size, true);
                if ($photoError === null) $saved++; else $errors[] = $photoError;
            }
            jsonResponse(['ok' => true, 'saved' => $saved, 'errors' => $errors]);
        }

        if ($api === 'review') {
            $modelId = requireModel($db);
            $photoId = (int)($body['photo_id'] ?? 0);
            $rating = max(0, min(5, (int)($body['rating'] ?? 0)));
            $note = textValue($body['note'] ?? '', 1000);
            $stmt = $db->prepare('UPDATE photos SET rating = ?, note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND model_id = ?');
            $stmt->execute([$rating, $note, $photoId, $modelId]);
            if ($stmt->rowCount() < 1) jsonResponse(['ok' => false, 'error' => '照片不存在。'], 404);
            $db->prepare("UPDATE models SET review_status = 'draft', submitted_at = NULL WHERE id = ? AND review_status = 'submitted'")->execute([$modelId]);
            jsonResponse(['ok' => true, 'saved_at' => date('H:i')]);
        }

        if ($api === 'submit_review') {
            $modelId = requireModel($db);
            $db->prepare("UPDATE models SET review_status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$modelId]);
            jsonResponse(['ok' => true]);
        }

        jsonResponse(['ok' => false, 'error' => '未知请求。'], 404);
    } catch (Throwable $e) {
        error_log(APP_NAME . ': ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => '服务器处理失败，请稍后重试。'], 500);
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#171714">
<title>模特选片台</title>
<meta name="description" content="约拍照片分发、选片、评级与备注。">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23171714'/%3E%3Cpath d='M17 22h9l4-5h8l4 5h5a5 5 0 015 5v19a5 5 0 01-5 5H17a5 5 0 01-5-5V27a5 5 0 015-5z' fill='%23f4d64e'/%3E%3Ccircle cx='32' cy='36' r='9' fill='%23171714'/%3E%3Ccircle cx='32' cy='36' r='4' fill='%23fff'/%3E%3C/svg%3E">
<style>
:root{--ink:#171714;--muted:#73736c;--paper:#f6f6f1;--card:#fff;--line:#deded6;--accent:#f4d64e;--accent2:#debb12;--danger:#b83535;--ok:#237753;--shadow:0 18px 50px rgba(23,23,20,.10);--r:18px}
*{box-sizing:border-box}html{font-size:16px}body{margin:0;background:var(--paper);color:var(--ink);font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.5}button,input,textarea{font:inherit}button{cursor:pointer}a{color:inherit}.hidden{display:none!important}.app{min-height:100dvh}.topbar{height:68px;background:var(--ink);color:#fff;display:flex;align-items:center;padding:0 clamp(18px,4vw,54px);gap:18px;position:sticky;top:0;z-index:20}.brand{font-weight:760;letter-spacing:.04em;display:flex;align-items:center;gap:11px}.brand-mark{width:31px;height:31px;border-radius:9px;background:var(--accent);display:grid;place-items:center;color:var(--ink);font-size:17px}.topbar .spacer{flex:1}.topbar button{color:#fff;border:1px solid #494943;background:transparent;border-radius:10px;padding:8px 13px}.container{width:min(1180px,calc(100% - 36px));margin:0 auto;padding:42px 0 70px}.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:0 1px 0 rgba(0,0,0,.02)}.auth-wrap{min-height:100dvh;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(390px,.9fr);background:var(--ink)}.auth-art{padding:clamp(38px,7vw,92px);display:flex;flex-direction:column;justify-content:space-between;color:#fff;position:relative;overflow:hidden}.auth-art:after{content:"";position:absolute;width:55vw;height:55vw;max-width:740px;max-height:740px;border:1px solid #41413c;border-radius:50%;right:-22%;bottom:-45%}.auth-art h1{font-size:clamp(3rem,7vw,6.7rem);line-height:.94;margin:0;letter-spacing:-.06em;max-width:8ch}.auth-art p{font-size:1.05rem;color:#bbbbae;max-width:33rem}.auth-panel{background:var(--paper);padding:clamp(28px,6vw,82px);display:flex;align-items:center}.auth-box{width:min(440px,100%);margin:auto}.eyebrow{font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:750}.auth-box h2{font-size:2rem;letter-spacing:-.04em;margin:10px 0 8px}.auth-box>p{color:var(--muted);margin:0 0 28px}.field{display:grid;gap:8px;margin:16px 0}.field label{font-size:.88rem;font-weight:700}.field input,.field textarea{width:100%;border:1px solid #cacac1;background:#fff;border-radius:12px;padding:12px 13px;outline:none;transition:.2s}.field input:focus,.field textarea:focus{border-color:var(--ink);box-shadow:0 0 0 3px rgba(23,23,20,.08)}.code-input{text-transform:uppercase;letter-spacing:.28em;font-size:1.4rem;font-weight:800;text-align:center}.btn{border:0;border-radius:12px;padding:11px 16px;font-weight:730;background:var(--ink);color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:8px}.btn:hover{filter:brightness(1.12)}.btn.primary{background:var(--accent);color:var(--ink)}.btn.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}.btn.danger{background:#fff0f0;color:var(--danger);border:1px solid #f0caca}.btn.block{width:100%}.link-btn{border:0;background:transparent;padding:8px 0;color:var(--muted);text-decoration:underline;text-underline-offset:3px}.auth-switch{margin-top:20px;text-align:center}.message{min-height:24px;margin:12px 0;color:var(--danger);font-size:.9rem}.dashboard-grid{display:grid;grid-template-columns:270px minmax(0,1fr);gap:24px}.sidebar{padding:18px;align-self:start;position:sticky;top:92px}.section-title{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:16px}.section-title h2,.section-title h3{margin:0;letter-spacing:-.035em}.section-title h2{font-size:1.7rem}.event-list{display:grid;gap:8px}.event-button{border:1px solid transparent;background:transparent;border-radius:12px;text-align:left;padding:11px 12px}.event-button:hover{background:#f3f3ed}.event-button.active{background:var(--ink);color:#fff}.event-button small{display:block;opacity:.65;margin-top:2px}.divider{height:1px;background:var(--line);margin:18px 0}.compact-form{display:grid;gap:10px}.compact-form input{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 11px}.main-stack{display:grid;gap:22px}.hero-row{padding:26px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px}.hero-row h1{font-size:2rem;margin:3px 0 0;letter-spacing:-.04em}.hero-row p{margin:4px 0 0;color:var(--muted)}.stats{display:flex;gap:8px;flex-wrap:wrap}.stat{background:#f1f1eb;border-radius:12px;padding:9px 13px;min-width:82px}.stat strong{display:block;font-size:1.2rem}.stat span{font-size:.75rem;color:var(--muted)}.panel{padding:24px}.model-form{display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end}.model-form .field{margin:0}.check{display:flex;align-items:center;gap:8px;padding-bottom:12px;white-space:nowrap}.model-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.model-card{padding:17px;border:1px solid var(--line);border-radius:14px;background:#fff;display:grid;grid-template-columns:1fr auto;gap:12px;cursor:pointer}.model-card:hover,.model-card.active{border-color:#8c8c82;box-shadow:0 6px 22px rgba(0,0,0,.06)}.model-card h4{margin:0;font-size:1.05rem}.model-card p{margin:3px 0;color:var(--muted);font-size:.88rem}.code-chip{align-self:start;background:var(--accent);border-radius:9px;padding:7px 9px;font-weight:850;letter-spacing:.12em;font-size:.86rem}.progress-line{height:5px;background:#e9e9e2;border-radius:99px;overflow:hidden;margin-top:12px}.progress-line i{display:block;height:100%;background:var(--ink)}.model-meta{display:flex;gap:10px;color:var(--muted);font-size:.78rem;margin-top:8px}.upload-zone{border:1.5px dashed #b6b6ad;border-radius:15px;padding:26px;text-align:center;background:#fafaf7}.upload-zone.drag{border-color:var(--ink);background:#fffbe7}.upload-zone input{display:none}.upload-zone p{margin:7px 0;color:var(--muted)}.upload-progress{height:7px;background:#e5e5df;border-radius:99px;overflow:hidden;margin-top:15px}.upload-progress i{display:block;height:100%;width:0;background:var(--accent2);transition:width .2s}.admin-photos{display:grid;gap:9px;margin-top:18px}.admin-photo{display:grid;grid-template-columns:68px minmax(130px,1fr) 120px minmax(150px,1fr);align-items:center;gap:12px;padding:9px;border:1px solid var(--line);border-radius:12px}.admin-photo img{width:68px;height:52px;object-fit:cover;border-radius:8px;background:#eee}.admin-photo .note{font-size:.86rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.stars-text{color:#9d7f00;letter-spacing:.06em}.gallery-shell{width:min(1420px,calc(100% - 32px));margin:auto;padding:28px 0 80px}.gallery-head{display:flex;align-items:flex-end;justify-content:space-between;gap:22px;margin-bottom:28px}.gallery-head h1{font-size:clamp(2rem,4vw,3.5rem);letter-spacing:-.055em;line-height:1;margin:8px 0}.gallery-head p{margin:0;color:var(--muted)}.gallery-actions{display:flex;align-items:center;gap:12px}.gallery-progress{min-width:210px}.gallery-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px}.thumb{position:relative;border:0;padding:0;background:#ddd;aspect-ratio:4/5;overflow:hidden;border-radius:12px}.thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s}.thumb:hover img{transform:scale(1.025)}.thumb-badge{position:absolute;left:8px;bottom:8px;background:rgba(23,23,20,.82);color:#fff;border-radius:8px;padding:5px 7px;font-size:.74rem;backdrop-filter:blur(6px)}.thumb.reviewed:after{content:"\2713";position:absolute;top:8px;right:8px;width:25px;height:25px;background:var(--accent);display:grid;place-items:center;border-radius:50%;font-weight:900}.empty{padding:54px 25px;text-align:center;color:var(--muted)}.viewer{position:fixed;inset:0;background:rgba(16,16,14,.88);z-index:50;display:grid;place-items:center;padding:22px}.viewer-card{width:min(1180px,100%);height:min(780px,calc(100dvh - 44px));background:#fff;border-radius:20px;overflow:hidden;display:grid;grid-template-columns:minmax(0,1fr) 350px;box-shadow:var(--shadow)}.viewer-image{background:#10100f;display:grid;place-items:center;position:relative;overflow:hidden}.viewer-image img{width:100%;height:100%;object-fit:contain}.viewer-side{padding:24px;display:flex;flex-direction:column;overflow:auto}.viewer-side h2{font-size:1.05rem;margin:0;word-break:break-all}.viewer-side .index{color:var(--muted);font-size:.84rem;margin-top:4px}.close-viewer{position:absolute;top:14px;right:14px;width:42px;height:42px;border-radius:50%;border:1px solid #555;background:rgba(0,0,0,.45);color:#fff;font-size:1.3rem;z-index:2}.nav-arrow{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:58px;border:0;background:rgba(0,0,0,.42);color:#fff;font-size:1.5rem;border-radius:10px}.nav-arrow.prev{left:14px}.nav-arrow.next{right:14px}.rating-box{margin-top:26px}.rating-box label,.note-box label{display:block;font-size:.83rem;font-weight:750;margin-bottom:9px}.rating-buttons{display:flex;gap:7px}.rating-buttons button{width:45px;height:45px;border:1px solid var(--line);border-radius:11px;background:#fff;color:#aaa;font-size:1.45rem}.rating-buttons button.active,.rating-buttons button:hover{background:#fff8c8;border-color:#d3b72d;color:#ae8b00}.note-box{margin-top:22px}.note-box textarea{width:100%;min-height:150px;resize:vertical;border:1px solid var(--line);border-radius:12px;padding:12px;outline:none}.save-state{font-size:.78rem;color:var(--muted);height:22px;margin-top:6px}.viewer-actions{margin-top:auto;display:flex;gap:9px;padding-top:20px}.viewer-actions .btn{flex:1}.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);background:var(--ink);color:#fff;border-radius:11px;padding:10px 15px;z-index:100;opacity:0;pointer-events:none;transition:.25s}.toast.show{opacity:1;transform:translate(-50%,0)}
.upload-zone{display:block;width:100%}.model-card-actions{display:grid;gap:7px;align-content:start}.model-card-actions .btn{padding:6px 9px;border-radius:9px;font-size:.78rem}.hero-row>.btn{flex:none}
.upload-queue{margin-top:14px;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff}.upload-queue-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;background:#f5f5ef;font-size:.84rem}.upload-queue-head .btn{padding:5px 9px;border-radius:8px;font-size:.75rem}.upload-job{display:grid;grid-template-columns:minmax(0,1fr) 90px auto;gap:12px;align-items:center;padding:10px 13px;border-top:1px solid var(--line)}.upload-job-name{min-width:0}.upload-job-name strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.86rem}.upload-job-name small{display:block;color:var(--muted);margin-top:2px}.upload-job-status{font-size:.78rem;color:var(--muted);text-align:right}.upload-job-status.success{color:#257046}.upload-job-status.failed{color:var(--danger)}.upload-job .btn{padding:5px 9px;border-radius:8px;font-size:.75rem}.upload-job-progress{height:5px;background:#e8e8e1;border-radius:99px;overflow:hidden;margin-top:6px}.upload-job-progress i{display:block;height:100%;width:0;background:var(--accent2);transition:width .15s}.upload-job.failed .upload-job-progress i{background:var(--danger)}.upload-job.success .upload-job-progress i{background:#42a76b}.photo-list-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px}.photo-list-toolbar label{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:.84rem}.photo-list-toolbar select{border:1px solid var(--line);border-radius:9px;background:#fff;padding:7px 10px;color:var(--ink)}.viewer-download{margin-top:auto;padding-top:20px}.viewer-download .btn{width:100%}.viewer-actions{margin-top:9px;padding-top:0}.admin-photo{grid-template-columns:68px minmax(130px,1fr) 110px minmax(130px,1fr) auto}.photo-rotate-actions{display:flex;gap:5px}.photo-rotate-actions .btn{padding:6px 8px;border-radius:8px;font-size:.75rem;white-space:nowrap}
@media(max-width:920px){.admin-photo .photo-rotate-actions{grid-column:2/4;justify-content:flex-end}}
@media(max-width:680px){.admin-photo .photo-rotate-actions{grid-column:2;justify-content:flex-start}}
@media(max-width:920px){.auth-wrap{grid-template-columns:1fr}.auth-art{min-height:290px}.auth-art h1{font-size:3.6rem}.auth-panel{padding:42px 24px}.dashboard-grid{grid-template-columns:1fr}.sidebar{position:static}.event-list{display:flex;overflow:auto}.event-button{min-width:170px}.model-form{grid-template-columns:1fr 1fr}.model-cards{grid-template-columns:1fr}.gallery-grid{grid-template-columns:repeat(4,1fr)}.viewer-card{grid-template-columns:minmax(0,1fr) 320px}.admin-photo{grid-template-columns:60px 1fr 90px}.admin-photo .note{grid-column:2/4}}
@media(max-width:680px){.topbar{height:60px;padding:0 16px}.container{width:min(100% - 24px,1180px);padding-top:20px}.auth-art{padding:35px 24px;min-height:240px}.auth-art h1{font-size:2.9rem}.auth-art p{font-size:.92rem}.auth-panel{align-items:start}.hero-row{padding:21px;align-items:flex-start;flex-direction:column}.panel{padding:18px}.model-form{grid-template-columns:1fr}.check{padding:0}.model-cards{grid-template-columns:1fr}.gallery-shell{width:100%;padding:20px 12px 70px}.gallery-head{display:block}.gallery-actions{margin-top:18px;justify-content:space-between}.gallery-progress{flex:1;min-width:0}.gallery-grid{grid-template-columns:repeat(3,1fr);gap:6px}.thumb{border-radius:8px}.viewer{padding:0;background:#fff}.viewer-card{height:100svh;max-height:100svh;width:100%;border-radius:0;display:flex;flex-direction:column;overflow-x:hidden;overflow-y:auto}.viewer-image{width:100%;height:62svh;min-width:0;min-height:0;flex:0 0 62svh;touch-action:pan-y pinch-zoom}.viewer-image img{position:absolute;inset:0;display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain}.viewer-side{overflow:visible;padding:20px 18px 38px}.viewer-side h2{padding-right:46px}.close-viewer{position:fixed;top:max(10px,env(safe-area-inset-top));right:10px}.nav-arrow{display:none}.rating-buttons{justify-content:space-between}.rating-buttons button{width:18%;max-width:54px}.note-box textarea{min-height:120px}.viewer-actions{margin-top:12px}.admin-photo{grid-template-columns:54px 1fr}.admin-photo .stars-text{grid-column:2}.admin-photo .note{grid-column:2}.stats{width:100%}.stat{flex:1}.gallery-head h1{font-size:2.25rem}.upload-job{grid-template-columns:minmax(0,1fr) auto}.upload-job-status{grid-column:1;text-align:left}.upload-job .btn{grid-column:2;grid-row:1/3}}
</style>
</head>
<body>
<div id="loading" class="app" style="display:grid;place-items:center"><div>正在打开选片台…</div></div>

<section id="authView" class="auth-wrap hidden">
  <div class="auth-art">
    <div class="brand"><span class="brand-mark">◉</span> 模特选片台</div>
    <div><h1>照片，<br>由你来选。</h1><p>输入摄影师发给你的访问码，查看、评级和备注这次拍摄的照片。</p></div>
    <small style="color:#77776f">私人照片仅向持码访客展示</small>
  </div>
  <div class="auth-panel">
    <div class="auth-box">
      <div class="eyebrow" id="authEyebrow">访问照片</div>
      <h2 id="authTitle">输入访问码</h2>
      <p id="authHint">6位字母数字组合，不区分大小写。</p>
      <form id="modelLoginForm">
        <div class="field"><label for="accessCode">访问码</label><input id="accessCode" class="code-input" maxlength="6" autocomplete="one-time-code" required></div>
        <button class="btn primary block" type="submit">打开我的照片</button>
      </form>
      <form id="adminLoginForm" class="hidden">
        <div class="field"><label for="adminPassword">管理员密码</label><input id="adminPassword" type="password" autocomplete="current-password" required></div>
        <button class="btn block" type="submit">登录管理后台</button>
      </form>
      <form id="setupForm" class="hidden">
        <div class="field"><label for="setupPassword">设置管理员密码</label><input id="setupPassword" type="password" minlength="8" autocomplete="new-password" required></div>
        <div class="field"><label for="setupPassword2">再输入一次</label><input id="setupPassword2" type="password" minlength="8" autocomplete="new-password" required></div>
        <button class="btn primary block" type="submit">完成初始设置</button>
      </form>
      <div id="authMessage" class="message"></div>
      <div class="auth-switch"><button id="switchAuth" class="link-btn">摄影师管理入口</button></div>
    </div>
  </div>
</section>

<section id="adminView" class="app hidden">
  <header class="topbar"><div class="brand"><span class="brand-mark">◉</span> 模特选片台</div><div class="spacer"></div><button data-logout>退出管理</button></header>
  <main class="container"><div class="dashboard-grid">
    <aside class="sidebar card">
      <div class="section-title"><h3>拍摄活动</h3></div>
      <div id="eventList" class="event-list"></div>
      <div class="divider"></div>
      <form id="eventForm" class="compact-form">
        <strong>新建活动</strong>
        <input name="name" placeholder="活动名称" maxlength="80" required>
        <input name="shoot_date" type="date" required>
        <button class="btn primary" type="submit">创建活动</button>
      </form>
    </aside>
    <div id="adminMain" class="main-stack"></div>
  </div></main>
</section>

<section id="modelView" class="app hidden">
  <header class="topbar"><div class="brand"><span class="brand-mark">◉</span> 模特选片台</div><div class="spacer"></div><button data-logout>退出</button></header>
  <main class="gallery-shell">
    <div class="gallery-head">
      <div><div class="eyebrow" id="modelEvent"></div><h1 id="modelName"></h1><p id="modelRole"></p></div>
      <div class="gallery-actions"><div class="gallery-progress"><div id="progressText" style="font-size:.84rem;margin-bottom:6px"></div><div class="progress-line"><i id="galleryProgress"></i></div></div></div>
    </div>
    <div id="galleryGrid" class="gallery-grid"></div>
  </main>
</section>

<div id="viewer" class="viewer hidden" role="dialog" aria-modal="true" aria-label="照片评级">
  <div class="viewer-card">
    <div id="viewerImageArea" class="viewer-image"><button class="close-viewer" aria-label="关闭">×</button><button class="nav-arrow prev" aria-label="上一张">‹</button><img id="viewerImage" alt=""><button class="nav-arrow next" aria-label="下一张">›</button></div>
    <aside class="viewer-side"><h2 id="viewerFilename"></h2><div id="viewerIndex" class="index"></div><div class="rating-box"><label>评级</label><div id="ratingButtons" class="rating-buttons"></div></div><div class="note-box"><label for="photoNote">备注</label><textarea id="photoNote" maxlength="1000" placeholder="例如：喜欢这张的表情，希望精修时去掉背景杂物…"></textarea><div id="saveState" class="save-state"></div></div><div class="viewer-download"><a id="downloadPhoto" class="btn ghost" download>下载照片</a></div><div class="viewer-actions"><button id="prevPhoto" class="btn ghost">上一张</button><button id="nextPhoto" class="btn">下一张</button></div></aside>
  </div>
</div>
<div id="toast" class="toast"></div>

<script>
const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
let csrf='', mode='model', events=[], selectedEvent=0, selectedModel=0, modelData=null, currentPhoto=0, saveTimer=null, touchStartX=0, adminPhotoSort='default';
let uploadJobs=[], uploadJobId=0, activeUploads=0, uploadNeedsRefresh=false, uploadRefreshRunning=false;
const MAX_PARALLEL_UPLOADS=3, MAX_PHOTO_BYTES=30*1024*1024;
const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const fmtDate=s=>{if(!s)return '';const [y,m,d]=s.split('-');return `${y}年${Number(m)}月${Number(d)}日`};
const api=async(name,data=null,form=false)=>{const opt={method:data===null?'GET':'POST',headers:{}};if(data!==null){opt.headers['X-CSRF-Token']=csrf;if(form){opt.body=data}else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(data)}}const r=await fetch(`?api=${name}`,opt);const j=await r.json().catch(()=>({ok:false,error:'服务器返回异常'}));if(!r.ok||!j.ok)throw new Error(j.error||'操作失败');return j};
function show(id){['loading','authView','adminView','modelView'].forEach(x=>$('#'+x).classList.toggle('hidden',x!==id))}
function toast(msg){const t=$('#toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}
async function bootstrap(){try{const d=await api('bootstrap');csrf=d.csrf;if(!d.installed){show('authView');showSetup();return}if(d.admin){events=d.events||[];show('adminView');renderAdmin();return}if(d.model&&d.model_data){modelData=d.model_data;show('modelView');renderModel();return}show('authView')}catch(e){$('#loading').textContent=e.message}}
function showSetup(){$('#modelLoginForm').classList.add('hidden');$('#adminLoginForm').classList.add('hidden');$('#setupForm').classList.remove('hidden');$('#switchAuth').classList.add('hidden');$('#authEyebrow').textContent='首次使用';$('#authTitle').textContent='创建管理员';$('#authHint').textContent='设置一个至少8位的管理密码。'}
$('#switchAuth').addEventListener('click',()=>{mode=mode==='model'?'admin':'model';const admin=mode==='admin';$('#modelLoginForm').classList.toggle('hidden',admin);$('#adminLoginForm').classList.toggle('hidden',!admin);$('#authEyebrow').textContent=admin?'摄影师':'访问照片';$('#authTitle').textContent=admin?'管理员登录':'输入访问码';$('#authHint').textContent=admin?'管理活动、模特、照片与选片结果。':'6位字母数字组合，不区分大小写。';$('#switchAuth').textContent=admin?'返回模特访问':'摄影师管理入口';$('#authMessage').textContent=''});
$('#modelLoginForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('model_login',{code:$('#accessCode').value});location.reload()}catch(err){$('#authMessage').textContent=err.message}});
$('#adminLoginForm').addEventListener('submit',async e=>{e.preventDefault();try{await api('admin_login',{password:$('#adminPassword').value});location.reload()}catch(err){$('#authMessage').textContent=err.message}});
$('#setupForm').addEventListener('submit',async e=>{e.preventDefault();const a=$('#setupPassword').value,b=$('#setupPassword2').value;if(a!==b){$('#authMessage').textContent='两次密码不一致。';return}try{await api('setup',{password:a});location.reload()}catch(err){$('#authMessage').textContent=err.message}});
$$('[data-logout]').forEach(b=>b.addEventListener('click',async()=>{await api('logout',{});location.reload()}));
$('#eventForm').addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget,fd=new FormData(form);try{const r=await api('create_event',Object.fromEntries(fd));selectedEvent=r.id;form.reset();await reloadAdmin();toast('活动已创建')}catch(err){toast(err.message)}});
async function reloadAdmin(){const d=await api('bootstrap');csrf=d.csrf;events=d.events||[];renderAdmin()}
function renderAdmin(){if(events.length&&!events.some(e=>+e.id===+selectedEvent))selectedEvent=+events[0].id;$('#eventList').innerHTML=events.length?events.map(e=>`<button class="event-button ${+e.id===+selectedEvent?'active':''}" data-event="${e.id}">${esc(e.name)}<small>${fmtDate(e.shoot_date)} · ${e.models.length}人</small></button>`).join(''):'<div style="color:var(--muted);font-size:.9rem">还没有活动</div>';$$('[data-event]').forEach(b=>b.onclick=()=>{selectedEvent=+b.dataset.event;selectedModel=0;renderAdmin()});const event=events.find(e=>+e.id===+selectedEvent);if(!event){$('#adminMain').innerHTML='<div class="card empty">先在左侧创建一个拍摄活动。</div>';return}const total=event.models.reduce((n,m)=>n+(+m.photo_count||0),0);const reviewed=event.models.reduce((n,m)=>n+(+m.reviewed_count||0),0);if(!selectedModel&&event.models.length)selectedModel=+event.models[0].id;$('#adminMain').innerHTML=`<section class="hero-row card"><div><div class="eyebrow">${fmtDate(event.shoot_date)}</div><h1>${esc(event.name)}</h1><p>先创建模特，再把该模特的选片JPEG上传到对应记录。</p></div><div class="stats"><div class="stat"><strong>${event.models.length}</strong><span>模特</span></div><div class="stat"><strong>${total}</strong><span>照片</span></div><div class="stat"><strong>${reviewed}</strong><span>已评论</span></div></div></section><section class="panel card"><div class="section-title"><h2>模特与角色</h2></div><form id="modelForm" class="model-form"><div class="field"><label>模特CN</label><input name="cn_name" maxlength="60" required placeholder="例如：小羽"></div><div class="field"><label>出的角色</label><input name="character_name" maxlength="80" placeholder="例如：玛瑞嘉"></div><label class="check"><input type="checkbox" name="allow_download" checked> 允许下载</label><button class="btn primary" type="submit">生成访问码</button></form><div class="model-cards">${event.models.length?event.models.map(modelCard).join(''):'<div style="color:var(--muted)">还没有模特记录。</div>'}</div></section><section id="photoPanel" class="panel card">${renderPhotoPanel(event)}</section>`;$('#modelForm').onsubmit=createModel;$$('[data-model]').forEach(c=>c.onclick=e=>{if(e.target.closest('button,input'))return;selectedModel=+c.dataset.model;renderAdmin()});bindPhotoPanel(event)}
const renderAdminBase=renderAdmin;
renderAdmin=function(){renderAdminBase();const event=events.find(e=>+e.id===+selectedEvent),hero=$('#adminMain .hero-row');if(!event||!hero)return;const button=document.createElement('button');button.type='button';button.className='btn danger';button.textContent='删除活动';button.onclick=()=>deleteEvent(event);hero.appendChild(button);event.models.forEach(model=>{const edit=$(`[data-edit-model="${model.id}"]`),remove=$(`[data-delete-model="${model.id}"]`);if(edit)edit.onclick=()=>editModel(model);if(remove)remove.onclick=()=>deleteModel(model)})};
async function deleteEvent(event){if(!confirm(`确认删除活动“${event.name}”（${fmtDate(event.shoot_date)}）吗？\n\n该活动下的模特、照片、评级和备注都会永久删除，此操作不可恢复。`))return;try{await api('delete_event',{event_id:event.id});selectedEvent=0;selectedModel=0;await reloadAdmin();toast('活动已删除')}catch(err){toast(err.message)}}
async function editModel(model){const cn=prompt('修改模特CN：',model.cn_name);if(cn===null)return;const cleanCn=cn.trim();if(!cleanCn){toast('模特CN不能为空');return}const character=prompt('修改角色（可以留空）：',model.character_name||'');if(character===null)return;try{await api('update_model',{model_id:model.id,cn_name:cleanCn,character_name:character.trim()});selectedModel=+model.id;await reloadAdmin();toast('模特信息已更新')}catch(err){toast(err.message)}}
async function deleteModel(model){const count=+model.photo_count||0;if(!confirm(`确认删除模特“${model.cn_name}”吗？\n\n${count?`该模特的 ${count} 张照片、评级和备注也会永久删除。`:'该模特的访问码会立即失效。'}此操作不可恢复。`))return;try{await api('delete_model',{model_id:model.id});if(+selectedModel===+model.id)selectedModel=0;await reloadAdmin();toast('模特已删除')}catch(err){toast(err.message)}}
function modelCard(m){const total=+m.photo_count||0,reviewed=+m.reviewed_count||0,pct=total?Math.round(reviewed/total*100):0;return `<div class="model-card ${+m.id===+selectedModel?'active':''}" data-model="${m.id}"><div><h4>${esc(m.cn_name)}</h4><p>${esc(m.character_name||'未填写角色')}</p><div class="progress-line"><i style="width:${pct}%"></i></div><div class="model-meta"><span>${reviewed}/${total} 已处理</span><span>${m.review_status==='submitted'?'已完成':'选片中'}</span><span>${+m.selected_count||0}张高分</span></div></div><div class="model-card-actions"><button class="code-chip" data-copy="${esc(m.access_code)}" title="点击复制">${esc(m.access_code)}</button><button class="btn ghost" type="button" data-edit-model="${m.id}">修改</button><button class="btn danger" type="button" data-delete-model="${m.id}">删除</button></div></div>`}
async function createModel(e){e.preventDefault();const o=Object.fromEntries(new FormData(e.currentTarget));o.event_id=selectedEvent;o.allow_download=!!e.currentTarget.allow_download.checked;try{const r=await api('create_model',o);selectedModel=r.id;await reloadAdmin();toast(`访问码 ${r.code} 已生成`)}catch(err){toast(err.message)}}
function sortAdminPhotos(photos){const list=[...(photos||[])];if(adminPhotoSort==='rating-desc')list.sort((a,b)=>(+b.rating||0)-(+a.rating||0)||+a.id-+b.id);if(adminPhotoSort==='rating-asc')list.sort((a,b)=>(+a.rating||0)-(+b.rating||0)||+a.id-+b.id);if(adminPhotoSort==='unrated')list.sort((a,b)=>(+a.rating>0)-(+b.rating>0)||+a.id-+b.id);return list}
function renderPhotoPanel(event){const m=event.models.find(x=>+x.id===+selectedModel);if(!m)return '<div class="empty">创建或选择一位模特后上传照片。</div>';const photos=sortAdminPhotos(m.photos);return `<div class="section-title"><div><h2>${esc(m.cn_name)} 的照片</h2><div style="color:var(--muted);font-size:.88rem;margin-top:3px">访问码 <strong style="color:var(--ink);letter-spacing:.12em">${esc(m.access_code)}</strong></div></div><div style="display:flex;gap:8px"><button class="btn ghost" id="regenCode">换一个码</button><label class="check" style="padding:0 6px"><input id="allowDownload" type="checkbox" ${+m.allow_download?'checked':''}> 允许下载</label></div></div><label id="uploadZone" class="upload-zone"><input id="photoInput" type="file" accept="image/jpeg,image/png,image/webp" multiple><strong>点击或拖入照片</strong><p>逐张上传，最多同时上传3张；单张不超过30MB，失败后可单独重传。</p></label><div id="uploadQueue" class="upload-queue hidden"></div><div class="photo-list-toolbar"><strong>照片列表</strong><label>排序 <select id="photoSort"><option value="default" ${adminPhotoSort==='default'?'selected':''}>上传顺序</option><option value="rating-desc" ${adminPhotoSort==='rating-desc'?'selected':''}>星级高到低</option><option value="rating-asc" ${adminPhotoSort==='rating-asc'?'selected':''}>星级低到高</option><option value="unrated" ${adminPhotoSort==='unrated'?'selected':''}>未评级优先</option></select></label></div><div class="admin-photos">${photos.length?photos.map(p=>`<div class="admin-photo"><img loading="lazy" src="${p.thumb_url}" alt=""><div><strong>${esc(p.original_name)}</strong><div style="font-size:.75rem;color:var(--muted)">${p.width||'?'}×${p.height||'?'}</div></div><div class="stars-text">${p.rating?'★'.repeat(+p.rating):'未评级'}</div><div class="note" title="${esc(p.note)}">${esc(p.note||'暂无备注')}</div></div>`).join(''):'<div class="empty">还没有照片。</div>'}</div>`}
function bindPhotoPanel(event){const m=event.models.find(x=>+x.id===+selectedModel);if(!m)return;$$('[data-copy]').forEach(b=>b.onclick=e=>{e.stopPropagation();navigator.clipboard?.writeText(b.dataset.copy);toast('访问码已复制')});$('#regenCode').onclick=async()=>{if(!confirm('更换后，旧访问码不能再用于新登录。确定更换吗？'))return;try{const r=await api('regenerate_code',{model_id:m.id});await reloadAdmin();toast(`新访问码：${r.code}`)}catch(err){toast(err.message)}};$('#allowDownload').onchange=async e=>{try{await api('toggle_download',{model_id:m.id,allow_download:e.target.checked});toast('下载权限已更新')}catch(err){toast(err.message)}};$('#photoSort').onchange=e=>{adminPhotoSort=e.target.value;renderAdmin()};const input=$('#photoInput'),zone=$('#uploadZone');input.onchange=()=>{uploadPhotos(input.files,m.id);input.value=''};['dragenter','dragover'].forEach(n=>zone.addEventListener(n,e=>{e.preventDefault();zone.classList.add('drag')}));['dragleave','drop'].forEach(n=>zone.addEventListener(n,e=>{e.preventDefault();zone.classList.remove('drag')}));zone.addEventListener('drop',e=>uploadPhotos(e.dataTransfer.files,m.id));renderUploadQueue(m.id);kickUploadQueue()}
const bindPhotoPanelBase=bindPhotoPanel;
bindPhotoPanel=function(event){bindPhotoPanelBase(event);const m=event.models.find(x=>+x.id===+selectedModel);if(!m)return;const rows=$$('.admin-photos .admin-photo'),photos=sortAdminPhotos(m.photos);rows.forEach((row,index)=>{const photo=photos[index];if(!photo)return;const controls=document.createElement('div');controls.className='photo-rotate-actions';controls.innerHTML=`<button class="btn ghost" type="button" data-rotate-photo="${photo.id}" data-direction="left">↶ 左转</button><button class="btn ghost" type="button" data-rotate-photo="${photo.id}" data-direction="right">右转 ↷</button>`;row.appendChild(controls)});$$('[data-rotate-photo]').forEach(button=>button.onclick=async()=>{button.disabled=true;try{await api('rotate_photo',{photo_id:+button.dataset.rotatePhoto,direction:button.dataset.direction});await reloadAdmin();toast('照片方向已更新')}catch(err){button.disabled=false;toast(err.message)}})};
const formatBytes=n=>n<1024?`${n} B`:n<1048576?`${(n/1024).toFixed(1)} KB`:`${(n/1048576).toFixed(1)} MB`;
function uploadPhotos(files,modelId){
    const selected=[...(files||[])];
    if(!selected.length)return;
    if(selected.length>200)toast('一次最多选择200张，已加入前200张');
    selected.slice(0,200).forEach(file=>{
        const validType=['image/jpeg','image/png','image/webp'].includes(file.type)||/\.(?:jpe?g|png|webp)$/i.test(file.name);
        let status='queued',error='';
        if(!validType){status='failed';error='仅支持 JPEG、PNG 和 WebP'}
        else if(file.size<1||file.size>MAX_PHOTO_BYTES){status='failed';error='文件为空或超过30MB'}
        uploadJobs.push({id:++uploadJobId,file,modelId:+modelId,status,progress:0,error});
    });
    renderUploadQueue(modelId);
    kickUploadQueue();
}
function renderUploadQueue(modelId){
    const box=$('#uploadQueue');
    if(!box||+selectedModel!==+modelId)return;
    const jobs=uploadJobs.filter(j=>+j.modelId===+modelId);
    box.classList.toggle('hidden',!jobs.length);
    if(!jobs.length){box.innerHTML='';return}
    const done=jobs.filter(j=>j.status==='success').length,failed=jobs.filter(j=>j.status==='failed').length,working=jobs.filter(j=>j.status==='queued'||j.status==='uploading').length;
    box.innerHTML=`<div class="upload-queue-head"><span>${working?`正在处理 ${working} 张`:`本批上传完成`} · 成功 ${done}${failed?` · 失败 ${failed}`:''}</span>${done?'<button class="btn ghost" type="button" data-clear-uploads>清除已完成</button>':''}</div>${jobs.map(j=>{const label=j.status==='queued'?'等待中':j.status==='uploading'?`上传中 ${j.progress}%`:j.status==='success'?'已完成':`失败 · ${esc(j.error||'上传失败')}`;return `<div class="upload-job ${j.status}" data-upload-job="${j.id}"><div class="upload-job-name"><strong title="${esc(j.file.name)}">${esc(j.file.name)}</strong><small>${formatBytes(j.file.size)}</small><div class="upload-job-progress"><i style="width:${j.status==='success'?100:j.progress}%"></i></div></div><div class="upload-job-status ${j.status}">${label}</div>${j.status==='failed'?`<button class="btn ghost" type="button" data-retry-upload="${j.id}">重传</button>`:'<span></span>'}</div>`}).join('')}`;
    box.querySelector('[data-clear-uploads]')?.addEventListener('click',()=>{uploadJobs=uploadJobs.filter(j=>+j.modelId!==+modelId||j.status!=='success');renderUploadQueue(modelId)});
    box.querySelectorAll('[data-retry-upload]').forEach(button=>button.onclick=()=>{const job=uploadJobs.find(j=>j.id===+button.dataset.retryUpload);if(!job)return;job.status='queued';job.progress=0;job.error='';renderUploadQueue(modelId);kickUploadQueue()});
}
function updateUploadProgress(job,progress){job.progress=progress;const row=document.querySelector(`[data-upload-job="${job.id}"]`);if(!row)return;const bar=row.querySelector('.upload-job-progress i'),status=row.querySelector('.upload-job-status');if(bar)bar.style.width=progress+'%';if(status)status.textContent=`上传中 ${progress}%`}
function kickUploadQueue(){while(activeUploads<MAX_PARALLEL_UPLOADS){const job=uploadJobs.find(j=>j.status==='queued');if(!job)break;runUploadJob(job)}}
function runUploadJob(job){
    job.status='uploading';job.progress=0;activeUploads++;renderUploadQueue(job.modelId);
    const fd=new FormData();fd.append('model_id',job.modelId);fd.append('photos[]',job.file,job.file.name);
    const xhr=new XMLHttpRequest();xhr.open('POST','?api=upload');xhr.setRequestHeader('X-CSRF-Token',csrf);
    xhr.upload.onprogress=e=>{if(e.lengthComputable)updateUploadProgress(job,Math.min(99,Math.round(e.loaded/e.total*100)))};
    const finish=(success,error='')=>{activeUploads=Math.max(0,activeUploads-1);job.status=success?'success':'failed';job.progress=success?100:job.progress;job.error=error;if(success)uploadNeedsRefresh=true;renderUploadQueue(job.modelId);kickUploadQueue();refreshUploadsWhenSettled()};
    xhr.onload=()=>{let result=null;try{result=JSON.parse(xhr.responseText)}catch(e){}if(xhr.status>=200&&xhr.status<300&&result?.ok&&+result.saved===1)finish(true);else if(xhr.status===413)finish(false,'文件超过服务器上传限制');else finish(false,result?.errors?.[0]||result?.error||`服务器错误 ${xhr.status||''}`.trim())};
    xhr.onerror=()=>finish(false,'网络中断');xhr.ontimeout=()=>finish(false,'上传超时');xhr.timeout=10*60*1000;xhr.send(fd);
}
async function refreshUploadsWhenSettled(){
    if(activeUploads||uploadJobs.some(j=>j.status==='queued')||!uploadNeedsRefresh||uploadRefreshRunning)return;
    uploadNeedsRefresh=false;uploadRefreshRunning=true;
    try{await reloadAdmin();toast('照片列表已更新')}catch(e){uploadNeedsRefresh=true;toast(e.message||'照片列表刷新失败')}finally{uploadRefreshRunning=false;kickUploadQueue()}
}
function renderModel(){const m=modelData;$('#modelEvent').textContent=`${m.event_name} · ${fmtDate(m.shoot_date)}`;$('#modelName').textContent=m.cn_name;$('#modelRole').textContent=m.character_name?`角色：${m.character_name}`:'请点开缩略图进行评级和备注。';renderGallery()}
function renderGallery(){const p=modelData.photos||[],done=p.filter(x=>+x.rating>0||x.note).length,pct=p.length?Math.round(done/p.length*100):0;$('#progressText').textContent=`${done} / ${p.length} 张已处理`;$('#galleryProgress').style.width=pct+'%';$('#galleryGrid').innerHTML=p.length?p.map((x,i)=>`<button class="thumb ${+x.rating>0||x.note?'reviewed':''}" data-photo="${i}" aria-label="查看 ${esc(x.original_name)}"><img loading="lazy" src="${x.thumb_url}" alt=""><span class="thumb-badge">${x.rating?'★'.repeat(+x.rating):'待评级'}</span></button>`).join(''):'<div class="card empty" style="grid-column:1/-1">摄影师还没有上传照片。</div>';$$('[data-photo]').forEach(b=>b.onclick=()=>openViewer(+b.dataset.photo))}
function openViewer(i){currentPhoto=Math.max(0,Math.min(i,modelData.photos.length-1));const p=modelData.photos[currentPhoto];if(!p)return;$('#viewer').classList.remove('hidden');document.body.style.overflow='hidden';$('#viewerImage').src=p.preview_url;$('#viewerImage').alt=p.original_name;$('#viewerFilename').textContent=p.original_name;$('#viewerIndex').textContent=`${currentPhoto+1} / ${modelData.photos.length}`;$('#photoNote').value=p.note||'';$('#saveState').textContent='';$('#downloadPhoto').classList.toggle('hidden',!+modelData.allow_download);$('#downloadPhoto').href=p.download_url;$('#ratingButtons').innerHTML=[1,2,3,4,5].map(n=>`<button class="${n<=+p.rating?'active':''}" data-rate="${n}" aria-label="${n}星">★</button>`).join('');$$('[data-rate]').forEach(b=>b.onclick=()=>{p.rating=+b.dataset.rate;renderRating();saveReview()})}
function renderRating(){$$('[data-rate]').forEach(b=>b.classList.toggle('active',+b.dataset.rate<=+modelData.photos[currentPhoto].rating))}
function closeViewer(){$('#viewer').classList.add('hidden');document.body.style.overflow='';renderGallery()}
function movePhoto(delta){saveReview(true);openViewer((currentPhoto+delta+modelData.photos.length)%modelData.photos.length)}
async function saveReview(immediate=false){clearTimeout(saveTimer);const run=async()=>{const p=modelData.photos[currentPhoto];$('#saveState').textContent='保存中…';try{const r=await api('review',{photo_id:p.id,rating:+p.rating||0,note:p.note||''});modelData.review_status='draft';$('#saveState').textContent=`已自动保存 ${r.saved_at}`;renderGallery()}catch(e){$('#saveState').textContent=e.message}};if(immediate)return run();saveTimer=setTimeout(run,550)}
$('#photoNote').addEventListener('input',e=>{modelData.photos[currentPhoto].note=e.target.value;$('#saveState').textContent='待保存';saveReview()});$('.close-viewer').onclick=()=>{saveReview(true);closeViewer()};$('.nav-arrow.prev').onclick=()=>movePhoto(-1);$('.nav-arrow.next').onclick=()=>movePhoto(1);$('#prevPhoto').onclick=()=>movePhoto(-1);$('#nextPhoto').onclick=()=>movePhoto(1);$('#viewer').addEventListener('click',e=>{if(e.target===$('#viewer'))closeViewer()});document.addEventListener('keydown',e=>{if($('#viewer').classList.contains('hidden'))return;if(e.key==='Escape')closeViewer();if(e.key==='ArrowLeft')movePhoto(-1);if(e.key==='ArrowRight')movePhoto(1)});$('#viewerImageArea').addEventListener('touchstart',e=>touchStartX=e.changedTouches[0].clientX,{passive:true});$('#viewerImageArea').addEventListener('touchend',e=>{const d=e.changedTouches[0].clientX-touchStartX;if(Math.abs(d)>55)movePhoto(d>0?-1:1)},{passive:true});
bootstrap();
</script>
</body>
</html>
