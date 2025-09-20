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
function parse_bool_like($value){
  if (is_bool($value)) return $value;
  if (is_int($value) || is_float($value)) return ((int)$value) !== 0;
  $str = strtolower(trim((string)$value));
  if ($str === '') return false;
  return in_array($str, ['1','true','yes','y','on'], true);
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
function parse_list_allow_empty($key){
  if (!isset($_POST[$key])) return [];
  $raw = $_POST[$key];
  if (is_array($raw)) return array_values(array_map(fn($v)=>(string)$v, $raw));
  $str = trim((string)$raw);
  if ($str === '') return [];
  $decoded = json_decode($str, true);
  if (is_array($decoded)) return array_values(array_map(fn($v)=>(string)$v, $decoded));
  return [(string)$raw];
}
function normalize_uploads($field){
  if (!isset($_FILES[$field])) return [];
  $info = $_FILES[$field];
  $files = [];
  if (is_array($info['name'])) {
    $count = count($info['name']);
    for ($i = 0; $i < $count; $i++) {
      $files[] = [
        'name' => $info['name'][$i] ?? '',
        'type' => $info['type'][$i] ?? '',
        'tmp_name' => $info['tmp_name'][$i] ?? '',
        'error' => $info['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $info['size'][$i] ?? 0,
      ];
    }
  } else {
    $files[] = [
      'name' => $info['name'] ?? '',
      'type' => $info['type'] ?? '',
      'tmp_name' => $info['tmp_name'] ?? '',
      'error' => $info['error'] ?? UPLOAD_ERR_NO_FILE,
      'size' => $info['size'] ?? 0,
    ];
  }
  return $files;
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

function sanitize_ui_screens($raw, $itemId = ''){
  if (!is_array($raw)) return [];
  $screens = [];
  foreach ($raw as $screen) {
    if (!is_array($screen)) continue;
    $id = trim((string)($screen['id'] ?? ''));
    if ($id === '') $id = 'ui_' . substr(sha1(uniqid('', true)), 0, 10);
    $filename = trim((string)($screen['filename'] ?? ''));
    $path = trim((string)($screen['path'] ?? ''));
    if ($path === '' && $filename !== '' && $itemId !== '') {
      $path = 'Items/' . $itemId . '/ui/' . $filename;
    }
    if ($filename === '' && $path !== '') {
      $filename = basename($path);
    }
    if ($path === '' && $filename === '') continue;
    $label = isset($screen['label']) ? trim((string)$screen['label']) : '';
    $uploadedAt = trim((string)($screen['uploadedAt'] ?? ''));
    $entry = ['id'=>$id,'filename'=>$filename,'path'=>$path];
    if ($label !== '') $entry['label'] = $label;
    if ($uploadedAt !== '') $entry['uploadedAt'] = $uploadedAt;
    $screens[] = $entry;
  }
  return $screens;
}
function sanitize_ui_payload($raw, $itemId = ''){
  if (is_array($raw) && isset($raw['screens']) && is_array($raw['screens'])) {
    return ['screens' => sanitize_ui_screens($raw['screens'], $itemId)];
  }
  if (is_array($raw) && !isset($raw['screens'])) {
    return ['screens' => sanitize_ui_screens($raw, $itemId)];
  }
  return ['screens' => []];
}
function parse_ui_screens_from_request($key='uiScreens'){
  if (!isset($_POST[$key])) return [];
  $raw = $_POST[$key];
  if (is_array($raw)) {
    $data = $raw;
  } else {
    $str = trim((string)$raw);
    if ($str === '' || strtolower($str) === 'null') return [];
    $decoded = json_decode($str, true);
    if (!is_array($decoded)) return [];
    $data = $decoded;
  }
  $out = [];
  foreach ($data as $entry) {
    if (!is_array($entry)) continue;
    $id = trim((string)($entry['id'] ?? ''));
    if ($id === '') continue;
    $label = isset($entry['label']) ? trim((string)$entry['label']) : '';
    $out[] = ['id'=>$id, 'label'=>$label];
  }
  return $out;
}
function ensure_ui_dir($itemsDir, $itemId){
  $dir = $itemsDir . '/' . $itemId;
  ensure_dir($dir, false);
  $uiDir = $dir . '/ui';
  ensure_dir($uiDir, false);
  return $uiDir;
}
function remove_ui_directory($itemsDir, $itemId){
  $dir = $itemsDir . '/' . $itemId . '/ui';
  if (!is_dir($dir)) return;
  $files = glob($dir.'/*');
  if (is_array($files)) {
    foreach ($files as $f) {
      if (is_file($f)) @unlink($f);
    }
  }
  @rmdir($dir);
}
function append_ui_uploads($itemId, $files, $labels, $itemsDir, $existingScreens = [], $handledAction = 'update'){
  global $allowedExt, $maxUpload;
  $screens = $existingScreens;
  if (!is_array($files) || count($files) === 0) return $screens;
  $uiDir = ensure_ui_dir($itemsDir, $itemId);
  foreach ($files as $idx => $file) {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) {
      respond(400, ["status"=>"error","handledAction"=>$handledAction,"message"=>"ui image upload error: $err"]);
    }
    $size = (int)($file['size'] ?? 0);
    if ($size > $maxUpload) {
      respond(400, ["status"=>"error","handledAction"=>$handledAction,"message"=>"ui image too large"]);
    }
    $nameOrig = (string)($file['name'] ?? 'ui.png');
    $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
      respond(400, ["status"=>"error","handledAction"=>$handledAction,"message"=>"invalid ui image ext"]);
    }
    $screenId = 'ui_' . substr(sha1(uniqid('', true)), 0, 10);
    $filename = $screenId . '.' . $ext;
    $dest = $uiDir . '/' . $filename;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
      respond(500, ["status"=>"error","handledAction"=>$handledAction,"message"=>"failed to save ui image"]);
    }
    @chmod($dest, 0666);
    $label = isset($labels[$idx]) ? trim((string)$labels[$idx]) : '';
    $entry = [
      'id' => $screenId,
      'filename' => $filename,
      'path' => 'Items/' . $itemId . '/ui/' . $filename,
      'uploadedAt' => gmdate('c')
    ];
    if ($label !== '') {
      $entry['label'] = $label;
    }
    $screens[] = $entry;
  }
  return $screens;
}

function default_ai_payload(){
  return ['enabled'=>false,'dialogues'=>[]];
}

function sanitize_ai_array($raw){
  if (!is_array($raw)) $raw = [];
  $enabled = parse_bool_like($raw['enabled'] ?? false);
  $dialogues = [];
  if (isset($raw['dialogues']) && is_array($raw['dialogues'])) {
    foreach ($raw['dialogues'] as $dialog) {
      if (!is_array($dialog)) continue;
      $trigger = trim((string)($dialog['trigger'] ?? ''));
      $tone = trim((string)($dialog['tone'] ?? ''));
      $line = trim((string)($dialog['line'] ?? ($dialog['text'] ?? '')));
      if ($trigger === '' && $tone === '' && $line === '') continue;
      $entry = [];
      if ($trigger !== '') $entry['trigger'] = $trigger;
      if ($tone !== '') $entry['tone'] = $tone;
      if ($line !== '') $entry['line'] = $line;
      $dialogues[] = $entry;
    }
  }
  return ['enabled'=>$enabled,'dialogues'=>$dialogues];
}

function parse_ai_from_request($key='ai'){
  if (!isset($_POST[$key])) return null;
  $raw = $_POST[$key];
  if (is_array($raw)) {
    return sanitize_ai_array($raw);
  }
  $rawStr = trim((string)$raw);
  if ($rawStr === '' || strtolower($rawStr) === 'null') return null;
  $decoded = json_decode($rawStr, true);
  if (!is_array($decoded)) return null;
  return sanitize_ai_array($decoded);
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

  if (isset($data['items']) && is_array($data['items'])) {
    foreach ($data['items'] as &$it) {
      if (!is_array($it)) continue;
      $category = isset($it['categoryId']) ? (string)$it['categoryId'] : '';
      if ($category === 'animal') {
        $it['ai'] = sanitize_ai_array($it['ai'] ?? ($it['creature'] ?? []));
      } elseif (isset($it['ai'])) {
        $it['ai'] = sanitize_ai_array($it['ai']);
      }
      $itemIdForUi = isset($it['id']) ? (string)$it['id'] : '';
      $it['ui'] = sanitize_ui_payload($it['ui'] ?? [], $itemIdForUi);
      if (isset($it['creature'])) {
        unset($it['creature']);
      }
    }
    unset($it);
  }

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
  $aiData = parse_ai_from_request('ai');

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
  if ($categoryId === 'animal') {
    $item['ai'] = $aiData ?? default_ai_payload();
  } elseif ($aiData !== null) {
    $item['ai'] = $aiData;
  }
  if ($categoryId === 'interactive') {
    $uiLabels = parse_list_allow_empty('uiImageLabels');
    $uiFiles = normalize_uploads('uiImages');
    $uiScreens = append_ui_uploads($id, $uiFiles, $uiLabels, $itemsDir, [], 'create');
    $item['ui'] = ['screens'=>$uiScreens];
  } else {
    $item['ui'] = ['screens'=>[]];
  }
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

  $finalCategoryId = trim((string)($items[$idx]['categoryId'] ?? ''));

  if(isset($_POST['ai'])){
    $ai = parse_ai_from_request('ai');
    if ($finalCategoryId === 'animal') {
      $items[$idx]['ai'] = $ai ?? default_ai_payload();
    } else {
      unset($items[$idx]['ai']);
    }
    $changed = true;
  } else {
    if ($finalCategoryId === 'animal') {
      if (!isset($items[$idx]['ai']) || !is_array($items[$idx]['ai'])) {
        $items[$idx]['ai'] = default_ai_payload();
        $changed = true;
      } else {
        $items[$idx]['ai'] = sanitize_ai_array($items[$idx]['ai']);
      }
    } elseif (isset($items[$idx]['ai'])) {
      unset($items[$idx]['ai']);
      $changed = true;
    }
  }

  $currentUi = sanitize_ui_payload($items[$idx]['ui'] ?? [], $id);
  $screens = $currentUi['screens'];
  $removeUi = parse_array_field('removeUiScreens');
  if (!empty($removeUi)) {
    $removeSet = array_flip($removeUi);
    $remaining = [];
    foreach ($screens as $screen) {
      if (isset($removeSet[$screen['id']])) {
        if (!empty($screen['path'])) {
          @unlink(__DIR__ . '/' . $screen['path']);
        }
        $changed = true;
      } else {
        $remaining[] = $screen;
      }
    }
    $screens = $remaining;
  }

  $orderBefore = implode('|', array_map(fn($s) => $s['id'], $screens));
  $uiMeta = parse_ui_screens_from_request('uiScreens');
  if (!empty($uiMeta)) {
    $order = [];
    $used = [];
    foreach ($uiMeta as $entry) {
      foreach ($screens as $screen) {
        if ($screen['id'] === $entry['id']) {
          $label = $entry['label'];
          if (($screen['label'] ?? '') !== $label) {
            if ($label === '') {
              unset($screen['label']);
            } else {
              $screen['label'] = $label;
            }
            $changed = true;
          }
          $order[] = $screen;
          $used[$screen['id']] = true;
          break;
        }
      }
    }
    foreach ($screens as $screen) {
      if (!isset($used[$screen['id']])) {
        $order[] = $screen;
      }
    }
    $screens = $order;
    if (implode('|', array_map(fn($s) => $s['id'], $screens)) !== $orderBefore) {
      $changed = true;
    }
  }

  $clearUi = isset($_POST['clearUi']) ? parse_bool_like($_POST['clearUi']) : false;
  $uiLabels = parse_list_allow_empty('uiImageLabels');
  $uiFiles = normalize_uploads('uiImages');
  if ($finalCategoryId === 'interactive' && !$clearUi) {
    $before = count($screens);
    $screens = append_ui_uploads($id, $uiFiles, $uiLabels, $itemsDir, $screens, 'update');
    if (count($screens) > $before) $changed = true;
    $items[$idx]['ui'] = ['screens'=>$screens];
  } else {
    if (!empty($screens)) {
      foreach ($screens as $screen) {
        if (!empty($screen['path'])) {
          @unlink(__DIR__ . '/' . $screen['path']);
        }
      }
      $changed = true;
    }
    remove_ui_directory($itemsDir, $id);
    $items[$idx]['ui'] = ['screens'=>[]];
  }

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
