<?php
// Terrain management API — PHP 7.4+ safe; GET 永不因寫入權限 500；POST 採暫存檔寫入，錯誤更清楚。

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

umask(0);

$baseDir  = __DIR__ . '/Terrains';
$jsonPath = $baseDir . '/terrains.json';

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function ensureDirectory(string $path): void {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            respond(500, ['status'=>'error','message'=>'無法建立地形資料夾（可能無權限）']);
        }
    }
    @chmod($path, 0777); // best-effort
}

function loadData(string $jsonPath): array {
    // 檔案不存在 → GET 回空資料，不 500
    if (!file_exists($jsonPath)) {
        return [
            'terrains' => [],
            'metadata' => ['notes'=>'Managed via terrain_api.php','lastUpdated'=>null],
        ];
    }
    $raw = @file_get_contents($jsonPath);
    if ($raw === false) {
        respond(500, ['status'=>'error','message'=>'Failed to read terrains.json']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = ['terrains'=>[], 'metadata'=>['notes'=>'Recovered after invalid JSON','lastUpdated'=>null]];
    }
    if (!isset($data['terrains']) || !is_array($data['terrains'])) $data['terrains'] = [];
    if (!isset($data['metadata']) || !is_array($data['metadata'])) $data['metadata'] = ['notes'=>'Managed via terrain_api.php','lastUpdated'=>null];
    return $data;
}

function trySave(string $jsonPath, array $data): void {
    $data['metadata']['lastUpdated'] = date(DATE_ATOM);
    ensureDirectory(dirname($jsonPath));

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp  = $jsonPath . '.tmp_' . substr(sha1((string)microtime(true)), 0, 6);

    // 先寫暫存檔，避免跨磁區或鎖導致的失敗
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false) {
        respond(500, [
            'status'=>'error',
            'message'=>'無法在資料夾內建立暫存檔（可能無寫入權限）',
            'dirWritable'=> is_writable(dirname($jsonPath)) ? 'yes' : 'no',
        ]);
    }

    // 原子替換
    if (!@rename($tmp, $jsonPath)) {
        @unlink($tmp);
        respond(500, [
            'status'=>'error',
            'message'=>'無法寫入 terrains.json（rename 失敗，可能權限/鎖定）',
            'fileExists'=> file_exists($jsonPath) ? 'yes' : 'no',
        ]);
    }
    @chmod($jsonPath, 0666); // best-effort
}

function slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    return trim($value, '-') ?: 'terrain';
}

function sanitize_terrain_animations(array $animations, array $imageLookup): array {
    $seen = [];
    $result = [];
    foreach ($animations as $anim) {
        if (!is_array($anim)) continue;
        $id = trim((string)($anim['id'] ?? ''));
        if ($id === '') {
            $id = 'anim_' . substr(sha1((string) microtime(true) . random_int(0, 9999)), 0, 8);
        }
        if (isset($seen[$id])) {
            $id .= '_' . substr(sha1((string) microtime(true) . random_int(0, 9999)), 0, 4);
        }
        $seen[$id] = true;

        $name = trim((string)($anim['name'] ?? ''));
        $fps  = (float)($anim['fps'] ?? 6);
        if (!is_finite($fps) || $fps <= 0) $fps = 6;
        $fps = (int) round($fps);
        if ($fps < 1) $fps = 1;
        if ($fps > 60) $fps = 60;

        $framesIn = $anim['frames'] ?? [];
        if (!is_array($framesIn)) $framesIn = [];
        $framesOut = [];
        foreach ($framesIn as $frame) {
            if (!is_array($frame)) continue;
            $filename = trim((string)($frame['filename'] ?? ($frame['file'] ?? '')));
            if ($filename === '' || !isset($imageLookup[$filename])) continue;
            $frameOut = ['filename' => $filename];
            if (array_key_exists('duration', $frame) && $frame['duration'] !== '' && $frame['duration'] !== null) {
                $duration = (float) $frame['duration'];
                if (is_finite($duration) && $duration > 0) {
                    if ($duration > 30) $duration = 30;
                    if ($duration < 0.01) $duration = 0.01;
                    $frameOut['duration'] = round($duration, 4);
                }
            }
            $framesOut[] = $frameOut;
        }
        if (count($framesOut) === 0) continue;
        $result[] = [
            'id' => $id,
            'name' => $name,
            'fps' => $fps,
            'frames' => array_values($framesOut),
        ];
    }
    return array_values($result);
}

$data   = loadData($jsonPath);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // GET 永遠成功（只要能讀），檔案不存在則回空結構
    respond(200, ['status'=>'ok','terrains'=>$data['terrains'],'metadata'=>$data['metadata']]);
}

if ($method !== 'POST') {
    respond(405, ['status'=>'error','message'=>'Method not allowed']);
}

$action = $_POST['action'] ?? 'create';

// POST 只確保資料夾存在，不先做「檔案必須可寫」的致命檢查
ensureDirectory($baseDir);

switch ($action) {
    case 'create': {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') respond(400, ['status'=>'error','handledAction'=>'create','message'=>'缺少地形名稱']);
        $tag  = trim($_POST['tag'] ?? '');
        $id   = slugify($name) . '_' . substr(sha1((string) microtime(true)), 0, 6);

        $terrainDir = $baseDir . '/' . $id;
        ensureDirectory($terrainDir);

        $images = [];
        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
            $count = count($_FILES['images']['name']);
            for ($i=0; $i<$count; $i++) {
                $err = (int)($_FILES['images']['error'][$i] ?? UPLOAD_ERR_OK);
                $tmp = $_FILES['images']['tmp_name'][$i] ?? '';
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) continue;
                $orig = $_FILES['images']['name'][$i] ?? 'image';
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if ($ext === '' || !preg_match('/^(png|jpg|jpeg|gif|webp)$/', $ext)) continue;
                $imgSlug  = slugify(pathinfo($orig, PATHINFO_FILENAME));
                $filename = $imgSlug . '_' . substr(sha1((string) microtime(true) . $i), 0, 6) . '.' . $ext;
                $dest     = $terrainDir . '/' . $filename;
                if (!@move_uploaded_file($tmp, $dest)) continue;
                @chmod($dest, 0666);
                $images[] = [
                    'filename'=>$filename,
                    'path'=>"Terrains/$id/$filename",
                    'label'=>pathinfo($orig, PATHINFO_FILENAME),
                    'uploadedAt'=>date(DATE_ATOM)
                ];
            }
        }

        $terrain = ['id'=>$id,'name'=>$name,'tag'=>$tag !== '' ? $tag : null,'images'=>$images,'animations'=>[]];
        $data['terrains'][] = $terrain;
        trySave($jsonPath, $data);
        respond(201, ['status'=>'ok','handledAction'=>'create','terrain'=>$terrain,'terrains'=>$data['terrains']]);
    }

    case 'update': {
        $id = trim($_POST['id'] ?? '');
        if ($id === '') respond(400, ['status'=>'error','handledAction'=>'update','message'=>'缺少地形 ID']);
        $index = null;
        foreach ($data['terrains'] as $idx=>$t) { if (($t['id'] ?? '') === $id) { $index=$idx; break; } }
        if ($index === null) respond(404, ['status'=>'error','handledAction'=>'update','message'=>'找不到地形']);

        $name = trim($_POST['name'] ?? ($data['terrains'][$index]['name'] ?? '')) ?: ($data['terrains'][$index]['name'] ?? '未命名地形');
        $tag  = trim($_POST['tag'] ?? '');
        $data['terrains'][$index]['name'] = $name;
        $data['terrains'][$index]['tag']  = $tag !== '' ? $tag : null;

        $imagesRaw = $data['terrains'][$index]['images'] ?? [];
        $images = [];
        if (is_array($imagesRaw)) {
            foreach ($imagesRaw as $img) {
                if (!is_array($img)) continue;
                $filename = trim((string)($img['filename'] ?? ''));
                $path = trim((string)($img['path'] ?? ''));
                if ($filename === '' || $path === '') continue;
                $entry = $img;
                $entry['filename'] = $filename;
                $entry['path'] = $path;
                if (!isset($entry['label']) || !is_string($entry['label'])) {
                    $entry['label'] = '';
                }
                $images[] = $entry;
            }
        }

        $terrainDir = $baseDir . '/' . $id;
        ensureDirectory($terrainDir);

        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
            $count = count($_FILES['images']['name']);
            for ($i=0; $i<$count; $i++) {
                $err = (int)($_FILES['images']['error'][$i] ?? UPLOAD_ERR_OK);
                $tmp = $_FILES['images']['tmp_name'][$i] ?? '';
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) continue;
                $orig = $_FILES['images']['name'][$i] ?? 'image';
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if ($ext === '' || !preg_match('/^(png|jpg|jpeg|gif|webp)$/', $ext)) continue;
                $imgSlug  = slugify(pathinfo($orig, PATHINFO_FILENAME));
                if ($imgSlug === '') $imgSlug = 'image';
                $filename = $imgSlug . '_' . substr(sha1((string) microtime(true) . $i), 0, 6) . '.' . $ext;
                $dest     = $terrainDir . '/' . $filename;
                if (!@move_uploaded_file($tmp, $dest)) continue;
                @chmod($dest, 0666);
                $images[] = [
                    'filename'=>$filename,
                    'path'=>"Terrains/$id/$filename",
                    'label'=>pathinfo($orig, PATHINFO_FILENAME),
                    'uploadedAt'=>date(DATE_ATOM)
                ];
            }
        }

        $images = array_values($images);

        $removeSet = [];
        if (isset($_POST['removeImages'])) {
            $raw = $_POST['removeImages'];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $fn) {
                        if (!is_string($fn)) continue;
                        $fn = trim($fn);
                        if ($fn === '') continue;
                        $removeSet[$fn] = true;
                    }
                }
            }
        }

        if (!empty($removeSet)) {
            foreach ($images as $idx => $img) {
                $filename = trim((string)($img['filename'] ?? ''));
                if ($filename === '' || !isset($removeSet[$filename])) continue;
                $filePath = $terrainDir . '/' . $filename;
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
                unset($images[$idx]);
            }
            $images = array_values($images);
        }

        $renamedFiles = [];
        if (isset($_POST['renameImages'])) {
            $raw = $_POST['renameImages'];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $entry) {
                        if (!is_array($entry)) continue;
                        $filename = trim((string)($entry['filename'] ?? ''));
                        if ($filename === '') continue;
                        foreach ($images as $idx => &$img) {
                            if (trim((string)($img['filename'] ?? '')) !== $filename) continue;
                            $newLabel = isset($entry['label']) ? trim((string)$entry['label']) : $img['label'];
                            $img['label'] = $newLabel ?? '';
                            $renameFile = !empty($entry['renameFile']);
                            if ($renameFile) {
                                $labelSource = $newLabel !== '' ? $newLabel : pathinfo($filename, PATHINFO_FILENAME);
                                $slugBase = slugify($labelSource === '' ? pathinfo($filename, PATHINFO_FILENAME) : $labelSource);
                                if ($slugBase === '') $slugBase = 'frame';
                                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                if ($ext === '') $ext = 'png';
                                $candidate = $slugBase;
                                $suffix = 1;
                                do {
                                    $newFilename = $candidate . '.' . $ext;
                                    $exists = false;
                                    foreach ($images as $chkIdx => $chk) {
                                        if ($chkIdx === $idx) continue;
                                        if (trim((string)($chk['filename'] ?? '')) === $newFilename) { $exists = true; break; }
                                    }
                                    if (!$exists && !is_file($terrainDir . '/' . $newFilename)) {
                                        break;
                                    }
                                    $candidate = $slugBase . '-' . (++$suffix);
                                } while ($suffix < 2000);
                                $oldPath = $terrainDir . '/' . $filename;
                                $newPath = $terrainDir . '/' . $newFilename;
                                if (@rename($oldPath, $newPath)) {
                                    @chmod($newPath, 0666);
                                    $renamedFiles[$filename] = $newFilename;
                                    $img['filename'] = $newFilename;
                                    $img['path'] = "Terrains/$id/$newFilename";
                                    $filename = $newFilename;
                                }
                            }
                            break;
                        }
                        unset($img);
                    }
                }
            }
        }

        $images = array_values($images);
        $imageLookup = [];
        foreach ($images as $img) {
            if (!is_array($img)) continue;
            $filename = trim((string)($img['filename'] ?? ''));
            if ($filename === '') continue;
            $imageLookup[$filename] = $img;
        }

        $animationsRaw = $data['terrains'][$index]['animations'] ?? [];
        if (!is_array($animationsRaw)) $animationsRaw = [];

        if (!empty($renamedFiles)) {
            foreach ($animationsRaw as &$anim) {
                if (!is_array($anim)) continue;
                if (!isset($anim['frames']) || !is_array($anim['frames'])) continue;
                foreach ($anim['frames'] as &$frame) {
                    if (!is_array($frame)) continue;
                    $fname = trim((string)($frame['filename'] ?? ($frame['file'] ?? '')));
                    if ($fname === '' || !isset($renamedFiles[$fname])) continue;
                    $frame['filename'] = $renamedFiles[$fname];
                    if (isset($frame['file'])) unset($frame['file']);
                }
                unset($frame);
            }
            unset($anim);
        }

        $animationsPayload = $_POST['animations'] ?? null;
        if ($animationsPayload !== null) {
            $decoded = json_decode($animationsPayload, true);
            if ($decoded === null && trim((string)$animationsPayload) !== '' && json_last_error() !== JSON_ERROR_NONE) {
                respond(400, ['status'=>'error','handledAction'=>'update','message'=>'動畫資料格式錯誤']);
            }
            if (!is_array($decoded)) $decoded = [];
            $animations = sanitize_terrain_animations($decoded, $imageLookup);
        } else {
            $animations = sanitize_terrain_animations($animationsRaw, $imageLookup);
        }

        $data['terrains'][$index]['images'] = array_values($images);
        $data['terrains'][$index]['animations'] = $animations;

        trySave($jsonPath, $data);
        respond(200, ['status'=>'ok','handledAction'=>'update','terrain'=>$data['terrains'][$index],'terrains'=>$data['terrains']]);
    }

    case 'delete': {
        $id = trim($_POST['id'] ?? '');
        if ($id === '') respond(400, ['status'=>'error','handledAction'=>'delete','message'=>'缺少地形 ID']);
        $filtered = []; $removed = null;
        foreach ($data['terrains'] as $t) { if (($t['id'] ?? '') === $id) { $removed=$t; continue; } $filtered[]=$t; }
        if ($removed === null) respond(404, ['status'=>'error','handledAction'=>'delete','message'=>'找不到地形']);
        $data['terrains'] = $filtered;
        trySave($jsonPath, $data);
        respond(200, ['status'=>'ok','handledAction'=>'delete','removedId'=>$id,'terrains'=>$data['terrains']]);
    }

    default:
        respond(400, ['status'=>'error','handledAction'=>$action,'message'=>'未知的操作']);
}
