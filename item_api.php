<?php
// Item API — PHP 7.4+
// 修正：回傳帶 items+categories；分類補 label；穩健寫入（暫存檔→rename）；保留 drops 支援。

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

$itemsDir = __DIR__ . '/Items';
$jsonPath = $itemsDir . '/items.json';

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const ALLOWED_DROP_SOURCES = ['entity', 'material', 'weapon', 'armor', 'decor', 'interactive', 'building', 'resource', 'consumable', 'crop', 'mineral', 'tree', 'animal'];

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
        respond(500, ['status' => 'error', 'message' => '無法建立 Items 目錄（可能無權限）', 'path' => $path]);
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

function try_save_items(string $jsonPath, array $data): void {
    ensure_dir(dirname($jsonPath));
    prepare_metadata($data);
    $json = json_encode($data, JSON_FLAGS);
    if ($json === false) {
        respond(500, ['status' => 'error', 'message' => 'JSON encode failed: ' . json_last_error_msg()]);
    }
    $tmp = $jsonPath . '.tmp_' . substr(sha1((string) microtime(true)), 0, 6);
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false) {
        respond(500, ['status' => 'error', 'message' => '無法寫入 items.json（暫存檔建立失敗）', 'path' => $tmp]);
    }
    if (!@rename($tmp, $jsonPath)) {
        @unlink($tmp);
        respond(500, ['status' => 'error', 'message' => '無法寫入 items.json（rename 失敗，可能權限/鎖定）', 'path' => $jsonPath]);
    }
    @chmod($jsonPath, 0666);
}

function slugify(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/', '-', $value) ?? '';
    return $value !== '' ? $value : 'item';
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
        if (isset($entry['sourceType']) && isset($entry['sourceId'])) {
            $stype = strtolower(trim((string) $entry['sourceType']));
            if (in_array($stype, ALLOWED_DROP_SOURCES, true)) {
                $drop['sourceType'] = $stype;
                $drop['sourceId'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $entry['sourceId']);
            }
        }
        if (isset($entry['itemId'])) {
            $drop['itemId'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $entry['itemId']);
        }
        $out[] = $drop;
    }
    return $out;
}

function default_creature_payload(): array {
    return ['disposition' => 'neutral', 'animations' => [], 'skills' => []];
}

function parse_bool_like($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
    }
    if (is_numeric($value)) {
        return ((int) $value) !== 0;
    }
    return false;
}

function sanitize_creature_array($raw): array {
    if (!is_array($raw)) {
        $raw = [];
    }
    $allowed = ['friendly', 'neutral', 'hostile'];
    $disposition = '';
    if (isset($raw['disposition'])) {
        $disposition = strtolower(trim((string) $raw['disposition']));
    }
    if (!in_array($disposition, $allowed, true)) {
        $disposition = 'neutral';
    }

    $animations = [];
    if (isset($raw['animations']) && is_array($raw['animations'])) {
        foreach ($raw['animations'] as $anim) {
            if (!is_array($anim)) {
                continue;
            }
            $animalId = trim((string) ($anim['animalId'] ?? ''));
            $clipName = trim((string) ($anim['clipName'] ?? ''));
            if ($animalId === '' && $clipName === '') {
                continue;
            }
            $chanceRaw = $anim['triggerChance'] ?? 1.0;
            $chance = is_numeric($chanceRaw) ? (float) $chanceRaw : 1.0;
            if ($chance < 0) {
                $chance = 0.0;
            }
            if ($chance > 1) {
                $chance = 1.0;
            }
            $isIdle = parse_bool_like($anim['isIdle'] ?? false);
            $animations[] = [
                'animalId' => $animalId,
                'clipName' => $clipName,
                'triggerChance' => $chance,
                'isIdle' => $isIdle,
            ];
        }
    }

    $skills = [];
    if (isset($raw['skills']) && is_array($raw['skills'])) {
        foreach ($raw['skills'] as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $name = trim((string) ($skill['name'] ?? ''));
            $description = trim((string) ($skill['description'] ?? ''));
            if ($name === '' && $description === '') {
                continue;
            }
            $skills[] = ['name' => $name, 'description' => $description];
        }
    }

    return ['disposition' => $disposition, 'animations' => $animations, 'skills' => $skills];
}

function parse_creature_from_request(string $key = 'creature'): ?array {
    if (!isset($_POST[$key])) {
        return null;
    }
    $raw = $_POST[$key];
    if (is_array($raw)) {
        return sanitize_creature_array($raw);
    }
    $rawStr = trim((string) $raw);
    if ($rawStr === '' || strtolower($rawStr) === 'null') {
        return null;
    }
    $decoded = json_decode($rawStr, true);
    if (!is_array($decoded)) {
        return null;
    }
    return sanitize_creature_array($decoded);
}

function seed_categories(): array {
    return [
        ['id' => 'material', 'name' => '素材', 'label' => '素材'],
        ['id' => 'weapon', 'name' => '武器', 'label' => '武器'],
        ['id' => 'armor', 'name' => '防具', 'label' => '防具'],
        ['id' => 'decor', 'name' => '裝飾', 'label' => '裝飾'],
        ['id' => 'consumable', 'name' => '消耗品', 'label' => '消耗品'],
        ['id' => 'crop', 'name' => '農作物', 'label' => '農作物'],
        ['id' => 'mineral', 'name' => '礦物', 'label' => '礦物'],
        ['id' => 'tree', 'name' => '樹木', 'label' => '樹木'],
        ['id' => 'animal', 'name' => '生物', 'label' => '生物'],
    ];
}

function load_items_json(string $jsonPath): array {
    if (!file_exists($jsonPath)) {
        ensure_dir(dirname($jsonPath));
        $seed = ['categories' => seed_categories(), 'items' => [], 'metadata' => ['lastUpdated' => null]];
        $encoded = json_encode($seed, JSON_FLAGS);
        if ($encoded !== false) {
            @file_put_contents($jsonPath, $encoded);
        }
        return $seed;
    }
    $txt = @file_get_contents($jsonPath);
    if ($txt === false) {
        respond(500, ['status' => 'error', 'message' => '無法讀取 items.json']);
    }
    $data = json_decode($txt, true);
    if (!is_array($data)) {
        $data = ['categories' => [], 'items' => []];
    }
    if (!isset($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
    }
    if (!isset($data['categories']) || !is_array($data['categories'])) {
        $data['categories'] = [];
    }
    if (!isset($data['metadata']) || !is_array($data['metadata'])) {
        $data['metadata'] = ['lastUpdated' => null];
    } elseif (!array_key_exists('lastUpdated', $data['metadata'])) {
        $data['metadata']['lastUpdated'] = null;
    }

    $need = ['crop' => '農作物', 'mineral' => '礦物', 'tree' => '樹木', 'animal' => '生物'];
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
    foreach ($need as $cid => $name) {
        if (empty($have[$cid])) {
            $data['categories'][] = ['id' => $cid, 'name' => $name, 'label' => $name];
        }
    }

    foreach ($data['items'] as &$item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['categoryId'] ?? '') === 'animal') {
            $item['creature'] = sanitize_creature_array($item['creature'] ?? []);
        }
    }
    unset($item);

    return $data;
}

function find_index_by_id(array $items, string $id): int {
    foreach ($items as $index => $item) {
        if (is_array($item) && ($item['id'] ?? '') === $id) {
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

// ---------- Routes ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $data = load_items_json($jsonPath);
    respond(200, ['status' => 'ok', 'items' => $data['items'], 'categories' => $data['categories']]);
}
if ($method !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'Method not allowed']);
}
$action = $_POST['action'] ?? '';

$data = load_items_json($jsonPath);
$items = $data['items'];

if ($action === 'create') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $categoryId = trim((string) ($_POST['categoryId'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $terrains = parse_array_field('terrains');
    $creatureData = parse_creature_from_request('creature');

    if ($name === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'create', 'message' => 'name is required']);
    }
    if ($categoryId === '') {
        $categoryId = 'material';
    }

    $slug = slugify($name);
    $id = $slug . '_' . substr(sha1(uniqid('', true)), 0, 6);
    $itemDir = $itemsDir . '/' . $id;

    $imageMeta = null;
    if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        $err = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            respond(400, ['status' => 'error', 'handledAction' => 'create', 'message' => "image upload error: $err"]);
        }
        $size = (int) ($_FILES['image']['size'] ?? 0);
        if ($size > MAX_UPLOAD_BYTES) {
            respond(400, ['status' => 'error', 'handledAction' => 'create', 'message' => 'image too large']);
        }
        $nameOrig = (string) ($_FILES['image']['name'] ?? 'image');
        $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGE_EXTENSIONS, true)) {
            respond(400, ['status' => 'error', 'handledAction' => 'create', 'message' => 'invalid image ext']);
        }
        $useSubdir = ensure_dir($itemDir, false);
        if (!$useSubdir) {
            ensure_dir($itemsDir);
        }
        $safe = $useSubdir ? ('image.' . $ext) : ($id . '_' . substr(sha1((string) microtime(true)), 0, 6) . '.' . $ext);
        $destDir = $useSubdir ? $itemDir : $itemsDir;
        $dest = $destDir . '/' . $safe;
        if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            respond(500, ['status' => 'error', 'handledAction' => 'create', 'message' => 'failed to save image']);
        }
        @chmod($dest, 0666);
        $pathRel = 'Items/' . ($useSubdir ? ($id . '/' . $safe) : $safe);
        $imageMeta = ['filename' => $safe, 'path' => $pathRel, 'label' => trim((string) ($_POST['imageLabel'] ?? '')), 'uploadedAt' => gmdate('c')];
    }

    $item = [
        'id' => $id,
        'name' => $name,
        'categoryId' => $categoryId,
        'notes' => $notes,
        'terrains' => $terrains,
        'image' => $imageMeta,
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
        'drops' => parse_drops_from_request('drops'),
    ];
    if ($categoryId === 'animal') {
        $item['creature'] = $creatureData ?? default_creature_payload();
    } elseif ($creatureData !== null) {
        $item['creature'] = $creatureData;
    }
    $items[] = $item;
    $data['items'] = $items;
    try_save_items($jsonPath, $data);

    respond(200, ['status' => 'ok', 'handledAction' => 'create', 'item' => $item, 'items' => $data['items'], 'categories' => $data['categories']]);
}

if ($action === 'update') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'update', 'message' => 'id is required']);
    }
    $idx = find_index_by_id($items, $id);
    if ($idx === -1) {
        respond(404, ['status' => 'error', 'handledAction' => 'update', 'message' => 'item not found']);
    }

    $changed = false;
    if (isset($_POST['name'])) {
        $items[$idx]['name'] = trim((string) $_POST['name']);
        $changed = true;
    }
    if (isset($_POST['categoryId'])) {
        $items[$idx]['categoryId'] = trim((string) $_POST['categoryId']);
        $changed = true;
    }
    if (isset($_POST['notes'])) {
        $items[$idx]['notes'] = trim((string) $_POST['notes']);
        $changed = true;
    }
    if (isset($_POST['terrains'])) {
        $items[$idx]['terrains'] = parse_array_field('terrains');
        $changed = true;
    }
    if (isset($_POST['drops'])) {
        $items[$idx]['drops'] = parse_drops_from_request('drops');
        $changed = true;
    }

    $finalCategoryId = trim((string) ($items[$idx]['categoryId'] ?? ''));

    if (isset($_POST['creature'])) {
        $creature = parse_creature_from_request('creature');
        if ($finalCategoryId === 'animal') {
            $items[$idx]['creature'] = $creature ?? default_creature_payload();
        } elseif ($creature !== null) {
            $items[$idx]['creature'] = $creature;
        } else {
            unset($items[$idx]['creature']);
        }
        $changed = true;
    } else {
        if ($finalCategoryId === 'animal') {
            if (!isset($items[$idx]['creature']) || !is_array($items[$idx]['creature'])) {
                $items[$idx]['creature'] = default_creature_payload();
                $changed = true;
            }
        } elseif (isset($items[$idx]['creature'])) {
            unset($items[$idx]['creature']);
            $changed = true;
        }
    }

    $dir = $itemsDir . '/' . $id;
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
        if (isset($items[$idx]['image']['path'])) {
            safe_unlink($items[$idx]['image']['path']);
        }
        $useSubdir = ensure_dir($dir, false);
        if (!$useSubdir) {
            ensure_dir($itemsDir);
        }
        $safe = $useSubdir ? ('image.' . $ext) : ($id . '_' . substr(sha1((string) microtime(true)), 0, 6) . '.' . $ext);
        $destDir = $useSubdir ? $dir : $itemsDir;
        $dest = $destDir . '/' . $safe;
        if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            respond(500, ['status' => 'error', 'handledAction' => 'update', 'message' => 'failed to save image']);
        }
        @chmod($dest, 0666);
        $pathRel = 'Items/' . ($useSubdir ? ($id . '/' . $safe) : $safe);
        $items[$idx]['image'] = ['filename' => $safe, 'path' => $pathRel, 'label' => trim((string) ($_POST['imageLabel'] ?? ($items[$idx]['image']['label'] ?? ''))), 'uploadedAt' => gmdate('c')];
        $changed = true;
    }

    if (isset($_POST['removeImage']) && parse_bool_like($_POST['removeImage'])) {
        if (isset($items[$idx]['image']['path'])) {
            safe_unlink($items[$idx]['image']['path']);
        }
        $items[$idx]['image'] = null;
        $changed = true;
    }

    if ($changed) {
        $items[$idx]['updatedAt'] = gmdate('c');
        $data['items'] = $items;
        try_save_items($jsonPath, $data);
    }

    respond(200, ['status' => 'ok', 'handledAction' => 'update', 'item' => $items[$idx], 'items' => $data['items'], 'categories' => $data['categories']]);
}

if ($action === 'delete') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        respond(400, ['status' => 'error', 'handledAction' => 'delete', 'message' => 'id is required']);
    }
    $idx = find_index_by_id($items, $id);
    if ($idx === -1) {
        respond(404, ['status' => 'error', 'handledAction' => 'delete', 'message' => 'item not found']);
    }

    if (isset($items[$idx]['image']['path'])) {
        safe_unlink($items[$idx]['image']['path']);
    }
    $dir = $itemsDir . '/' . $id;
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

    array_splice($items, $idx, 1);
    $data['items'] = $items;
    try_save_items($jsonPath, $data);
    respond(200, ['status' => 'ok', 'handledAction' => 'delete', 'deleted' => $id, 'items' => $data['items'], 'categories' => $data['categories']]);
}

respond(400, ['status' => 'error', 'message' => 'Unknown action']);
