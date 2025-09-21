<?php
// reset_terrains.php - Recreate a clean Terrains/terrains.json

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

define('JSON_FLAGS', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$base = __DIR__ . '/Terrains';
$jsonPath = $base . '/terrains.json';

if (!is_dir($base)) {
    if (!@mkdir($base, 0775, true) && !is_dir($base)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => '無法建立 Terrains 目錄',
            'path' => $base,
        ], JSON_FLAGS);
        exit;
    }
}

$seed = [
    'terrains' => [],
    'metadata' => ['lastUpdated' => gmdate('c')],
];

$json = json_encode($seed, JSON_FLAGS);
if ($json === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'JSON encode failed: ' . json_last_error_msg(),
    ], JSON_FLAGS);
    exit;
}

$bytes = @file_put_contents($jsonPath, $json);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '寫入 terrains.json 失敗',
        'path' => $jsonPath,
    ], JSON_FLAGS);
    exit;
}

@chmod($jsonPath, 0666);

echo json_encode([
    'status' => 'ok',
    'path' => $jsonPath,
    'bytes' => $bytes,
], JSON_FLAGS);
