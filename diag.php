<?php
// diag.php - Minimal diagnostics for terrain_api.php
error_reporting(E_ALL);
ini_set('display_errors','1');
header('Content-Type: text/plain; charset=utf-8');
ob_start();

echo "== Environment ==\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? '(unknown)') . "\n";
echo "Current dir: " . __DIR__ . "\n\n";

// Check JSON extension
echo "json extension loaded: " . (extension_loaded('json') ? 'yes' : 'NO (please enable)') . "\n";

// Folder checks
$base = __DIR__ . '/Terrains';
$json = $base . '/terrains.json';
echo "\n== Folder & file checks ==\n";
echo "Terrains dir: $base\n";
echo "  exists: " . (file_exists($base) ? 'yes' : 'no') . "\n";
echo "  is_dir: " . (is_dir($base) ? 'yes' : 'no') . "\n";
echo "  writable: " . (is_writable($base) ? 'yes' : 'NO (fix permissions)') . "\n";

echo "terrains.json: $json\n";
echo "  exists: " . (file_exists($json) ? 'yes' : 'no (will attempt to create)') . "\n";

if (!file_exists($json)) {
  if (!is_dir($base)) { @mkdir($base,0755,true); }
  $seed = ["terrains"=>[], "metadata"=>["lastUpdated"=>gmdate('c')]];
  $ok = @file_put_contents($json, json_encode($seed, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  echo "  create attempt: " . ($ok===false ? "FAILED" : "ok") . "\n";
}

if (file_exists($json)) {
  $raw = @file_get_contents($json);
  echo "  readable: " . ($raw===false ? "NO" : "yes") . "\n";
  if ($raw!==false) {
    $data = json_decode($raw, true);
    $err = json_last_error_msg();
    echo "  json_decode: " . (is_array($data) ? "ok" : "FAILED ($err)") . "\n";
  }
}

// Try calling terrain_api.php
$api = __DIR__ . '/terrain_api.php';
echo "\n== Direct include test ==\n";
if (!file_exists($api)) {
  echo "terrain_api.php not found next to diag.php\n";
  exit;
}

echo "Including terrain_api.php with REQUEST_METHOD=GET ...\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
define('TERRAIN_API_CAPTURE_RESPONSE', true);
ob_start();
include $api;
$out = ob_get_clean();
$status = http_response_code();
if (!is_int($status) || $status === 0) {
  $status = 200;
}
echo "HTTP status: $status\n";
echo "Returned bytes: " . strlen($out) . "\n";
echo "First 400 bytes:\n";
echo substr($out,0,400);
echo "\n\nDone.\n";
