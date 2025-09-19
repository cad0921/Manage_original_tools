<?php
// reset_terrains.php - Recreate a clean Terrains/terrains.json
error_reporting(E_ALL);
ini_set('display_errors','1');
header('Content-Type: application/json; charset=utf-8');

$base = __DIR__ . '/Terrains';
$json = $base . '/terrains.json';
if (!is_dir($base)) @mkdir($base,0755,true);

$seed = ["terrains"=>[], "metadata"=>["lastUpdated"=>gmdate('c')]];
$ok = file_put_contents($json, json_encode($seed, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

echo json_encode([
  "status" => $ok===false ? "error" : "ok",
  "path" => $json,
  "bytes" => $ok===false ? 0 : $ok
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
