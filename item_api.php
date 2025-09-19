<?php
// Item API — PHP 7.4+
// 修正：回傳帶 items+categories；分類補 label；穩健寫入（暫存檔→rename）；保留 drops 支援。

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
date_default_timezone_set('UTC');
umask(0);

$itemsDir = __DIR__ . '/Items';
$jsonPath = $itemsDir . '/items.json';
$allowedExt = ['png','jpg','jpeg','gif','webp'];
$maxUpload  = 5 * 1024 * 1024;
$allowedSources = ['entity','material','weapon','armor','decor','interactive','building','resource','consumable','crop','mineral','tree','animal'];

function respond($code, $payload) {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}
function ensure_dir($path, $fatal = true){
  if (is_dir($path)) { @chmod($path, 0777); return true; }
  if (@mkdir($path, 0777, true) || is_dir($path)) { @chmod($path, 0777); return true; }
  if ($fatal) {
    respond(500, ["status"=>"error","message"=>"無法建立 Items 目錄（可能無權限）"]);
  }
  return false;
}
function try_save_items($jsonPath, $data){
  ensure_dir(dirname($jsonPath));
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  $tmp  = $jsonPath . '.tmp_' . substr(sha1((string)microtime(true)), 0, 6);
  $bytes = @file_put_contents($tmp, $json, LOCK_EX);
  if ($bytes === false) {
    @unlink($tmp);
    $fallbackBytes = false;
    if (is_file($jsonPath) && @is_writable($jsonPath)) {
      $fallbackBytes = @file_put_contents($jsonPath, $json, LOCK_EX);
    }
    if ($fallbackBytes === false) {
      respond(500, ["status"=>"error","message"=>"無法寫入 items.json（暫存檔建立失敗且原檔不可寫）"]);
    }
    @chmod($jsonPath, 0666);
    return;
  }
  if (!@rename($tmp, $jsonPath)) {
    @unlink($tmp);
    respond(500, ["status"=>"error","message"=>"無法寫入 items.json（rename 失敗，可能權限/鎖定）"]);
  }
  @chmod($jsonPath, 0666);
}
function slugify($s){
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}\-_\s]/u', '', $s) ?? '';
  $s = preg_replace('/\s+/', '-', $s) ?? '';
  return $s !== '' ? $s : 'item';
}
function parse_array_field($key){
  if (isset($_POST[$key])) {
    $raw = $_POST[$key];
    if (is_array($raw)) return array_values(array_filter($raw, fn($v)=>$v!==''));
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    $parts = array_map('trim', explode(',', (string)$raw));
    return array_values(array_filter($parts, fn($v)=>$v!==''));
  }
  $out = [];
  foreach ($_POST as $k=>$v) {
    if (preg_match('/^'.preg_quote($key,'/').'\[\]$/', $k)) {
      if (is_array($v)) $out = array_merge($out, $v);
      else $out[] = $v;
    }
  }
  return $out;
}
function parse_drops_from_request($key='drops'){
  global $allowedSources;
  $raw = $_POST[$key] ?? '';
  if ($raw === '' || $raw === null) return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  $out = [];
  foreach ($data as $d){
    $chance = isset($d['chance']) ? (float)$d['chance'] : 0.0;
    if ($chance < 0) $chance = 0; if ($chance > 1) $chance = 1;
    $min = isset($d['min']) ? (int)$d['min'] : 1;
    $max = isset($d['max']) ? (int)$d['max'] : $min;
    if ($min < 0) $min = 0; if ($max < $min) $max = $min;
    $entry = ['chance'=>$chance,'min'=>$min,'max'=>$max];
    if (isset($d['sourceType']) && isset($d['sourceId'])) {
      $stype = strtolower((string)$d['sourceType']);
      if (in_array($stype, $allowedSources, true)) {
        $entry['sourceType'] = $stype;
        $entry['sourceId']   = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$d['sourceId']);
      }
    }
    $out[] = $entry;
  }
  return $out;
}
function seed_categories(){
  return [
    ["id"=>"material","name"=>"素材","label"=>"素材"],
    ["id"=>"weapon","name"=>"武器","label"=>"武器"],
    ["id"=>"armor","name"=>"防具","label"=>"防具"],
    ["id"=>"decor","name"=>"裝飾","label"=>"裝飾"],
    ["id"=>"consumable","name"=>"消耗品","label"=>"消耗品"],
    ["id"=>"crop","name"=>"農作物","label"=>"農作物"],
    ["id"=>"mineral","name"=>"礦物","label"=>"礦物"],
    ["id"=>"tree","name"=>"樹木","label"=>"樹木"],
    ["id"=>"animal","name"=>"生物","label"=>"生物"],

  ];
}
function load_items_json($jsonPath){
  if (!file_exists($jsonPath)) {
    ensure_dir(dirname($jsonPath));
    $seed = ["categories"=>seed_categories(), "items"=>[]];
    @file_put_contents($jsonPath, json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return $seed;
  }
  $txt = @file_get_contents($jsonPath);
  $data = json_decode($txt, true);
  if (!is_array($data)) $data = ["categories"=>[], "items"=>[]];
  if (!isset($data["items"]) || !is_array($data["items"])) $data["items"] = [];
  if (!isset($data["categories"]) || !is_array($data["categories"])) $data["categories"] = [];

  // 保證三個新分類存在
  $need = ["crop"=>"農作物","mineral"=>"礦物","tree"=>"樹木","animal"=>"生物"];
  $have = [];
  foreach ($data["categories"] as $c) $have[$c["id"] ?? ""] = true;
  foreach ($need as $cid=>$name) if (empty($have[$cid])) $data["categories"][] = ["id"=>$cid,"name"=>$name,"label"=>$name];
  foreach ($data["categories"] as &$c) {
    if (!isset($c["label"]) || $c["label"]==="") $c["label"]=$c["name"] ?? ($c["id"] ?? "");
  } unset($c);

  return $data;
}

// ---------- Routes ----------
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
  $data = load_items_json($jsonPath);
  respond(200, ["status"=>"ok","items"=>$data["items"],"categories"=>$data["categories"]]);
}
if ($method !== 'POST') {
  respond(405, ["status"=>"error","message"=>"Method not allowed"]);
}
$action = $_POST['action'] ?? '';

$data  = load_items_json($jsonPath);
$items = $data['items'];

if ($action === 'create') {
  $name = trim($_POST['name'] ?? '');
  $categoryId = trim($_POST['categoryId'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $terrains = parse_array_field('terrains');

  if ($name === '') respond(400, ["status"=>"error","handledAction"=>"create","message"=>"name is required"]);
  if ($categoryId === '') $categoryId = 'material';

  $slug = slugify($name);
  $id   = $slug.'_'.substr(sha1(uniqid('',true)),0,6);
  $itemDir = $itemsDir.'/'.$id;

  $imageMeta = null;
  if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    ensure_dir($itemDir);
    $err = (int)$_FILES['image']['error'];
    if ($err !== UPLOAD_ERR_OK) respond(400, ["status"=>"error","handledAction"=>"create","message"=>"image upload error: $err"]);
    $size = (int)($_FILES['image']['size'] ?? 0);
    if ($size > $maxUpload) respond(400, ["status"=>"error","handledAction"=>"create","message"=>"image too large"]);
    $nameOrig = (string)($_FILES['image']['name'] ?? 'image');
    $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) respond(400, ["status"=>"error","handledAction"=>"create","message"=>"invalid image ext"]);
    $useSubdir = ensure_dir($itemDir, false);
    if (!$useSubdir) ensure_dir($itemsDir);
    $safe = $useSubdir ? ('image.'.$ext) : ($id.'_'.substr(sha1((string)microtime(true)),0,6).'.'.$ext);
    $destDir = $useSubdir ? $itemDir : $itemsDir;
    $dest = $destDir.'/'.$safe;
    if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) respond(500, ["status"=>"error","handledAction"=>"create","message"=>"failed to save image"]);
    @chmod($dest, 0666);
    $pathRel = 'Items/'.($useSubdir ? ($id.'/'.$safe) : $safe);
    $imageMeta=["filename"=>$safe,"path"=>$pathRel,"label"=>($_POST['imageLabel'] ?? ""), "uploadedAt"=>gmdate('c')];
  }

  $item = [
    'id'=>$id,'name'=>$name,'categoryId'=>$categoryId,'notes'=>$notes,
    'terrains'=>$terrains,'image'=>$imageMeta,
    'createdAt'=>gmdate('c'),'updatedAt'=>gmdate('c'),
    'drops'=>parse_drops_from_request('drops')
  ];
  $items[] = $item; $data['items'] = $items; try_save_items($jsonPath,$data);

  respond(200, ["status"=>"ok","handledAction"=>"create","item"=>$item,"items"=>$data["items"],"categories"=>$data["categories"]]);
}

if ($action === 'update') {
  $id = $_POST['id'] ?? '';
  if ($id === '') respond(400, ["status"=>"error","handledAction"=>"update","message"=>"id is required"]);
  $idx=-1; foreach($items as $i=>$it) if(($it['id'] ?? '') === $id){ $idx=$i; break; }
  if ($idx === -1) respond(404, ["status"=>"error","handledAction"=>"update","message"=>"item not found"]);

  $changed=false;
  if(isset($_POST['name'])){ $items[$idx]['name']=trim((string)$_POST['name']); $changed=true; }
  if(isset($_POST['categoryId'])){ $items[$idx]['categoryId']=trim((string)$_POST['categoryId']); $changed=true; }
  if(isset($_POST['notes'])){ $items[$idx]['notes']=trim((string)$_POST['notes']); $changed=true; }
  if(isset($_POST['terrains'])){ $items[$idx]['terrains']=parse_array_field('terrains'); $changed=true; }
  if(isset($_POST['drops'])){ $items[$idx]['drops']=parse_drops_from_request('drops'); $changed=true; }

  // 圖片處理
  $dir = $itemsDir.'/'.$id;
  if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    ensure_dir($dir);
    $err = (int)$_FILES['image']['error']; if($err!==UPLOAD_ERR_OK) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"image upload error: $err"]);
    $size = (int)($_FILES['image']['size'] ?? 0); if($size>$maxUpload) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"image too large"]);
    $nameOrig = (string)($_FILES['image']['name'] ?? 'image');
    $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"invalid image ext"]);
    if(isset($items[$idx]['image']['path'])){ @unlink(__DIR__.'/'.$items[$idx]['image']['path']); }
    $useSubdir = ensure_dir($dir, false);
    if (!$useSubdir) ensure_dir($itemsDir);
    $safe = $useSubdir ? ('image.'.$ext) : ($id.'_'.substr(sha1((string)microtime(true)),0,6).'.'.$ext);
    $destDir = $useSubdir ? $dir : $itemsDir;
    $dest = $destDir.'/'.$safe;
    if(!@move_uploaded_file($_FILES['image']['tmp_name'],$dest)) respond(500, ["status"=>"error","handledAction"=>"update","message"=>"failed to save image"]);
    @chmod($dest,0666);
    $pathRel = 'Items/'.($useSubdir ? ($id.'/'.$safe) : $safe);
    $items[$idx]['image']=["filename"=>$safe,"path"=>$pathRel,"label"=>($_POST['imageLabel'] ?? ($items[$idx]['image']['label'] ?? "")),"uploadedAt"=>gmdate('c')];
    $changed=true;
  }
  if(isset($_POST['removeImage']) && (($_POST['removeImage']==='1')||($_POST['removeImage']==='true'))){
    if(isset($items[$idx]['image']['path'])){ @unlink(__DIR__.'/'.$items[$idx]['image']['path']); }
    $items[$idx]['image']=null; $changed=true;
  }

  if($changed){ $items[$idx]['updatedAt']=gmdate('c'); $data['items']=$items; try_save_items($jsonPath,$data); }
  respond(200, ["status"=>"ok","handledAction"=>"update","item"=>$items[$idx],"items"=>$data["items"],"categories"=>$data["categories"]]);
}

if ($action === 'delete') {
  $id = $_POST['id'] ?? '';
  if ($id === '') respond(400, ["status"=>"error","handledAction"=>"delete","message"=>"id is required"]);
  $idx=-1; foreach($items as $i=>$it) if(($it['id'] ?? '') === $id){ $idx=$i; break; }
  if ($idx === -1) respond(404, ["status"=>"error","handledAction"=>"delete","message"=>"item not found"]);

  $dir=$itemsDir.'/'.$id;
  if (isset($items[$idx]['image']['path'])) {
    @unlink(__DIR__.'/'.$items[$idx]['image']['path']);
  }
  if (is_dir($dir)) { $files=glob($dir.'/*'); if (is_array($files)) foreach($files as $f) if (is_file($f)) @unlink($f); @rmdir($dir); }

  array_splice($items,$idx,1); $data['items']=$items; try_save_items($jsonPath,$data);
  respond(200, ["status"=>"ok","handledAction"=>"delete","deleted"=>$id,"items"=>$data["items"],"categories"=>$data["categories"]]);
}

respond(400, ["status"=>"error","message"=>"Unknown action"]);
