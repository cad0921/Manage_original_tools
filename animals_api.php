<?php
// animals_api.php — Animals CRUD + mirror to Items as category 'animal' (生物). PHP 7.4+

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
date_default_timezone_set('UTC');
umask(0);

$animalsDir = __DIR__ . '/Animals';
$animalsJson = $animalsDir . '/animals.json';

$itemsDir = __DIR__ . '/Items';
$itemsJson = __DIR__ . '/Items/items.json';

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function respond(int $code, array $payload): void {
    http_response_code($code);
    $json = json_encode($payload, JSON_FLAGS);
    if ($json === false) {
        http_response_code(500);
        $fallback = json_encode([
            'status' => 'error',
            'message' => 'JSON encode failed: ' . json_last_error_msg(),
        ], JSON_FLAGS);
        echo $fallback === false ? '{"status":"error","message":"JSON encode failed"}' : $fallback;
    } else {
        echo $json;
    }
    exit;
}

function ensure_dir(string $path, bool $fatal = true): bool {
    if (is_dir($path)) {
        @chmod($path, 0777);
        return true;
    }
    if (@mkdir($path, 0777, true) || is_dir($path)) {
        @chmod($path, 0777);
        return true;
    }
    if ($fatal) {
        respond(500, ['status' => 'error', 'message' => '無法建立目錄', 'path' => $path]);
    }
    return false;
}

function prepare_metadata(array &$data): void {
    if (!isset($data['metadata']) || !is_array($data['metadata'])) {
        $data['metadata'] = [];
    }
    if (!array_key_exists('lastUpdated', $data['metadata'])) {
        $data['metadata']['lastUpdated'] = null;
    }
    $data['metadata']['lastUpdated'] = gmdate('c');
}

function try_save(string $path, array $data): void {
    ensure_dir(dirname($path));
    prepare_metadata($data);
    $json = json_encode($data, JSON_FLAGS);
    if ($json === false) {
        respond(500, ['status' => 'error', 'message' => 'JSON encode failed: ' . json_last_error_msg()]);
    }
    $tmp = $path . '.tmp_' . substr(sha1((string) microtime(true)), 0, 6);
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false) {
        respond(500, ['status' => 'error', 'message' => '無法建立暫存檔', 'path' => $tmp]);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        respond(500, ['status' => 'error', 'message' => '無法寫入檔案', 'path' => $path]);
    }
    @chmod($path, 0666);
}

function load_json(string $path, string $rootKey): array {
    ensure_dir(dirname($path), false);
    if (!file_exists($path)) {
        $seed = [
            $rootKey => [],
            'metadata' => ['lastUpdated' => null],
        ];
        $encoded = json_encode($seed, JSON_FLAGS);
        if ($encoded !== false) {
            @file_put_contents($path, $encoded);
        }
        return $seed;
    }
    $txt = @file_get_contents($path);
    if ($txt === false) {
        respond(500, ['status' => 'error', 'message' => "無法讀取 $path"]);
    }
    $data = json_decode($txt, true);
    if (!is_array($data)) {
        $data = [$rootKey => []];
    }
    if (!isset($data[$rootKey]) || !is_array($data[$rootKey])) {
        $data[$rootKey] = [];
    }
    if (!isset($data['metadata']) || !is_array($data['metadata'])) {
        $data['metadata'] = ['lastUpdated' => null];
    } elseif (!array_key_exists('lastUpdated', $data['metadata'])) {
        $data['metadata']['lastUpdated'] = null;
    }
    return $data;
}

function slugify(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/', '-', $value) ?? '';
    return $value !== '' ? $value : 'animal';
}

function parse_array_field(string $key): array {
    if (isset($_POST[$key])) {
        $raw = $_POST[$key];
        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw), static fn($v) => $v !== ''));
        }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded), static fn($v) => $v !== ''));
        }
        $parts = array_map('trim', explode(',', (string) $raw));
        return array_values(array_filter($parts, static fn($v) => $v !== ''));
    }

    $out = [];
    $keyWithBrackets = $key . '[]';
    foreach ($_POST as $k => $v) {
        if ($k !== $keyWithBrackets) {
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $entry) {
                $entry = trim((string) $entry);
                if ($entry !== '') {
                    $out[] = $entry;
                }
            }
        } else {
            $entry = trim((string) $v);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }
    }
    return $out;
}

function parse_drops_from_request(string $key = 'drops'): array {
    $raw = $_POST[$key] ?? '';
    if ($raw === '' || $raw === null) {
        return [];
    }
    if (is_array($raw)) {
        $data = $raw;
    } else {
        $data = json_decode((string) $raw, true);
    }
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $chance = isset($entry['chance']) ? (float) $entry['chance'] : 0.0;
        if ($chance < 0) {
            $chance = 0.0;
        } elseif ($chance > 1) {
            $chance = 1.0;
        }
        $min = isset($entry['min']) ? (int) $entry['min'] : 1;
        if ($min < 0) {
            $min = 0;
        }
        $max = isset($entry['max']) ? (int) $entry['max'] : $min;
        if ($max < $min) {
            $max = $min;
        }
        $drop = ['chance' => $chance, 'min' => $min, 'max' => $max];
        if (isset($entry['itemId'])) {
            $drop['itemId'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $entry['itemId']);
        }
        $out[] = $drop;
    }
    return $out;
}

function ensure_item_categories(array &$data): void {
    if (!isset($data['categories']) || !is_array($data['categories'])) {
        $data['categories'] = [];
    }
    $have = [];
    foreach ($data['categories'] as &$category) {
        if (!is_array($category)) {
            continue;
        }
        $have[$category['id'] ?? ''] = true;
        if (!isset($category['label']) || $category['label'] === '') {
            $category['label'] = $category['name'] ?? ($category['id'] ?? '');
        }
    }
    unset($category);
    if (empty($have['animal'])) {
        $data['categories'][] = ['id' => 'animal', 'name' => '生物', 'label' => '生物'];
    }
}

function find_index_by_id(array $list, string $id): int {
    foreach ($list as $index => $entry) {
        if (is_array($entry) && ($entry['id'] ?? '') === $id) {
            return (int) $index;
        }
    }
    return -1;
}

function safe_unlink(?string $path): void {
    if ($path === null || $path === '') {
        return;
    }
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// ---------- ROUTES ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$animals = load_json($animalsJson, 'animals');

if ($method === 'GET') {
    respond(200, ['status' => 'ok', 'animals' => $animals['animals']]);
}
if ($method !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
$action = $_POST['action'] ?? '';

// Load items for mirroring
$items = load_json($itemsJson, 'items');
ensure_item_categories($items);

if ($action === 'create') {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'create', 'message' => 'name is required']);
    }
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $dropSetIds = parse_array_field('dropSetIds');
    $drops = parse_drops_from_request('drops');

    $slug = slugify($name);
    $id = $slug . '_' . substr(sha1(uniqid('', true)), 0, 6);
    $dir = $animalsDir . '/' . $id;
    ensure_dir($dir);

    $imageMeta = null;
    if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            respond(400, ['status' => 'error', 'message' => "image upload error: $err"]);
        }
        $size = (int) ($_FILES['image']['size'] ?? 0);
        if ($size > MAX_UPLOAD_BYTES) {
            respond(400, ['status' => 'error', 'message' => 'image too large']);
        }
        $nameOrig = (string) ($_FILES['image']['name'] ?? 'image');
        $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS, true)) {
            respond(400, ['status' => 'error', 'message' => 'invalid image ext']);
        }
        $safe = 'image.' . $ext;
        $dest = $dir . '/' . $safe;
        if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            respond(500, ['status' => 'error', 'message' => 'failed to save image']);
        }
        @chmod($dest, 0666);
        $imageMeta = [
            'filename' => $safe,
            'path' => "Animals/$id/$safe",
            'label' => trim((string) ($_POST['imageLabel'] ?? '')),
            'uploadedAt' => gmdate('c'),
        ];
    }

    $animal = [
        'id' => $id,
        'name' => $name,
        'notes' => $notes,
        'image' => $imageMeta,
        'dropSetIds' => $dropSetIds,
        'drops' => $drops,
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
    ];
    $animals['animals'][] = $animal;
    try_save($animalsJson, $animals);

    // Mirror to Items
    $itemId = 'animal-' . $id;
    $itemDir = $itemsDir . '/' . $itemId;

    $itemImage = null;
    if ($imageMeta && isset($imageMeta['filename'])) {
        $useItemSubdir = ensure_dir($itemDir, false);
        if (!$useItemSubdir) {
            ensure_dir($itemsDir);
        }
        $src = $animalsDir . '/' . $id . '/' . $imageMeta['filename'];
        if (is_file($src)) {
            $ext = pathinfo($src, PATHINFO_EXTENSION);
            $filename = $useItemSubdir
                ? ('image.' . $ext)
                : ($itemId . '_' . substr(sha1((string) microtime(true)), 0, 6) . '.' . $ext);
            $destDir = $useItemSubdir ? $itemDir : $itemsDir;
            $dst = $destDir . '/' . $filename;
            if (@copy($src, $dst)) {
                @chmod($dst, 0666);
                $itemImage = [
                    'filename' => $filename,
                    'path' => 'Items/' . ($useItemSubdir ? ($itemId . '/' . $filename) : $filename),
                    'label' => $imageMeta['label'] ?? '',
                    'uploadedAt' => gmdate('c'),
                ];
            }
        }
    }

    $idx = find_index_by_id($items['items'], $itemId);
    $mirrored = [
        'id' => $itemId,
        'linkedAnimalId' => $id,
        'name' => $name,
        'categoryId' => 'animal',
        'notes' => $notes,
        'image' => $itemImage,
        'dropSetIds' => $dropSetIds,
        'drops' => $drops,
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
    ];
    if ($idx === -1) {
        $items['items'][] = $mirrored;
    } else {
        $items['items'][$idx] = $mirrored;
    }
    try_save($itemsJson, $items);

    respond(200, ['status' => 'ok', 'handledAction' => 'create', 'animal' => $animal]);
}

if ($action === 'update') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'update', 'message' => 'id is required']);
    }
    $aidx = find_index_by_id($animals['animals'], $id);
    if ($aidx === -1) {
        respond(404, ['status' => 'error', 'handledAction' => 'update', 'message' => 'animal not found']);
    }

    $changed = false;
    if (isset($_POST['name'])) {
        $animals['animals'][$aidx]['name'] = trim((string) $_POST['name']);
        $changed = true;
    }
    if (isset($_POST['notes'])) {
        $animals['animals'][$aidx]['notes'] = trim((string) $_POST['notes']);
        $changed = true;
    }
    if (isset($_POST['dropSetIds'])) {
        $animals['animals'][$aidx]['dropSetIds'] = parse_array_field('dropSetIds');
        $changed = true;
    }
    if (isset($_POST['drops'])) {
        $animals['animals'][$aidx]['drops'] = parse_drops_from_request('drops');
        $changed = true;
    }

    $dir = $animalsDir . '/' . $id;
    ensure_dir($dir);
    if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            respond(400, ['status' => 'error', 'handledAction' => 'update', 'message' => "image upload error: $err"]);
        }
        $size = (int) ($_FILES['image']['size'] ?? 0);
        if ($size > MAX_UPLOAD_BYTES) {
            respond(400, ['status' => 'error', 'handledAction' => 'update', 'message' => 'image too large']);
        }
        $nameOrig = (string) ($_FILES['image']['name'] ?? 'image');
        $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS, true)) {
            respond(400, ['status' => 'error', 'handledAction' => 'update', 'message' => 'invalid image ext']);
        }
        if (isset($animals['animals'][$aidx]['image']['filename'])) {
            @unlink($dir . '/' . $animals['animals'][$aidx]['image']['filename']);
        }
        $safe = 'image.' . $ext;
        $dest = $dir . '/' . $safe;
        if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            respond(500, ['status' => 'error', 'handledAction' => 'update', 'message' => 'failed to save image']);
        }
        @chmod($dest, 0666);
        $animals['animals'][$aidx]['image'] = [
            'filename' => $safe,
            'path' => "Animals/$id/$safe",
            'label' => trim((string) ($_POST['imageLabel'] ?? '')),
            'uploadedAt' => gmdate('c'),
        ];
        $changed = true;
    }

    if ($changed) {
        $animals['animals'][$aidx]['updatedAt'] = gmdate('c');
        try_save($animalsJson, $animals);
    }

    // Mirror update to Items
    $itemId = 'animal-' . $id;
    $iidx = find_index_by_id($items['items'], $itemId);
    $itemDir = $itemsDir . '/' . $itemId;
    $itemImage = $iidx !== -1 ? ($items['items'][$iidx]['image'] ?? null) : null;
    $hasNewUpload = !empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
    if ($hasNewUpload && isset($animals['animals'][$aidx]['image']['filename'])) {
        $src = $animalsDir . '/' . $id . '/' . $animals['animals'][$aidx]['image']['filename'];
        if (is_file($src)) {
            $useItemSubdir = ensure_dir($itemDir, false);
            if (!$useItemSubdir) {
                ensure_dir($itemsDir);
            }
            $ext = pathinfo($src, PATHINFO_EXTENSION);
            $filename = $useItemSubdir
                ? ('image.' . $ext)
                : ($itemId . '_' . substr(sha1((string) microtime(true)), 0, 6) . '.' . $ext);
            $destDir = $useItemSubdir ? $itemDir : $itemsDir;
            $dst = $destDir . '/' . $filename;
            if (@copy($src, $dst)) {
                @chmod($dst, 0666);
                if ($itemImage && isset($itemImage['path'])) {
                    safe_unlink($itemImage['path']);
                }
                $itemImage = [
                    'filename' => $filename,
                    'path' => 'Items/' . ($useItemSubdir ? ($itemId . '/' . $filename) : $filename),
                    'label' => $animals['animals'][$aidx]['image']['label'] ?? '',
                    'uploadedAt' => gmdate('c'),
                ];
            }
        }
    }
    $mirrored = [
        'id' => $itemId,
        'linkedAnimalId' => $id,
        'name' => $animals['animals'][$aidx]['name'],
        'categoryId' => 'animal',
        'notes' => $animals['animals'][$aidx]['notes'],
        'image' => $itemImage,
        'dropSetIds' => $animals['animals'][$aidx]['dropSetIds'] ?? [],
        'drops' => $animals['animals'][$aidx]['drops'] ?? [],
        'createdAt' => $iidx !== -1 ? ($items['items'][$iidx]['createdAt'] ?? gmdate('c')) : gmdate('c'),
        'updatedAt' => gmdate('c'),
    ];
    if ($iidx === -1) {
        $items['items'][] = $mirrored;
    } else {
        $items['items'][$iidx] = $mirrored;
    }
    try_save($itemsJson, $items);

    respond(200, ['status' => 'ok', 'handledAction' => 'update', 'animal' => $animals['animals'][$aidx]]);
}

if ($action === 'delete') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'delete', 'message' => 'id is required']);
    }
    $aidx = find_index_by_id($animals['animals'], $id);
    if ($aidx === -1) {
        respond(404, ['status' => 'error', 'handledAction' => 'delete', 'message' => 'animal not found']);
    }

    $dir = $animalsDir . '/' . $id;
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($dir);
    }

    array_splice($animals['animals'], $aidx, 1);
    try_save($animalsJson, $animals);

    $itemId = 'animal-' . $id;
    $iidx = find_index_by_id($items['items'], $itemId);
    if ($iidx !== -1) {
        if (isset($items['items'][$iidx]['image']['path'])) {
            safe_unlink($items['items'][$iidx]['image']['path']);
        }
        $itemDir = $itemsDir . '/' . $itemId;
        if (is_dir($itemDir)) {
            $files = glob($itemDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($itemDir);
        }
        array_splice($items['items'], $iidx, 1);
        try_save($itemsJson, $items);
    }

    respond(200, ['status' => 'ok', 'handledAction' => 'delete', 'deleted' => $id]);
}

respond(400, ['status' => 'error', 'message' => 'Unknown action']);
