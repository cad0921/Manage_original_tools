<?php
// animals_api.php — Animals CRUD + mirror to Items as category 'animal' (生物). PHP 7.4+

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
date_default_timezone_set('UTC');
umask(0);

$animalsDir = __DIR__ . '/Animals';
$animalsJson = $animalsDir . '/animals.json';

$itemsDir = __DIR__ . '/Items';
$itemsJson = __DIR__ . '/Items/items.json';

$allowedExt = ['png','jpg','jpeg','gif','webp'];
$maxUpload  = 5 * 1024 * 1024;

function respond($code, $payload){ http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); exit; }
function ensure_dir($p){ if(!is_dir($p)){ if(!@mkdir($p,0777,true) && !is_dir($p)) respond(500,["status"=>"error","message"=>"無法建立目錄"]); } @chmod($p,0777); }
function try_save($path,$data){ ensure_dir(dirname($path)); $j=json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); $tmp=$path.'.tmp_'.substr(sha1((string)microtime(true)),0,6); $b=@file_put_contents($tmp,$j,LOCK_EX); if($b===false) respond(500,["status"=>"error","message"=>"無法建立暫存檔"]); if(!@rename($tmp,$path)){ @unlink($tmp); respond(500,["status"=>"error","message"=>"無法寫入檔案"]); } @chmod($path,0666); }
function load_json($path,$rootKey){ if(!file_exists($path)){ $seed=[$rootKey=>[]]; @file_put_contents($path, json_encode($seed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); return $seed; } $txt=@file_get_contents($path); $d=json_decode($txt,true); if(!is_array($d)) $d=[$rootKey=>[]]; if(!isset($d[$rootKey])||!is_array($d[$rootKey])) $d[$rootKey]=[]; return $d; }
function slugify($s){ $s = mb_strtolower(trim((string)$s),'UTF-8'); $s = preg_replace('/[^\p{L}\p{N}\-_\s]/u','',$s) ?? ''; $s = preg_replace('/\s+/', '-', $s) ?? ''; return $s !== '' ? $s : 'animal'; }
function parse_array_field($key){ if(isset($_POST[$key])){ $raw=$_POST[$key]; if(is_array($raw)) return array_values(array_filter($raw, fn($v)=>$v!=='')); $j=json_decode($raw,true); if(is_array($j)) return $j; $parts=array_map('trim', explode(',', (string)$raw)); return array_values(array_filter($parts, fn($v)=>$v!=='')); } $out=[]; foreach($_POST as $k=>$v){ if(preg_match('/^'.preg_quote($key,'/').'\[\]$/',$k)){ if(is_array($v)) $out=array_merge($out,$v); else $out[]=$v; } } return $out; }
function parse_drops_from_request($key='drops'){ $raw=$_POST[$key]??''; if($raw===''||$raw===null) return []; $data=json_decode($raw,true); if(!is_array($data)) return []; $out=[]; foreach($data as $d){ $chance=isset($d['chance'])?(float)$d['chance']:0.0; if($chance<0)$chance=0; if($chance>1)$chance=1; $min=isset($d['min'])?(int)$d['min']:1; $max=isset($d['max'])?(int)$d['max']:$min; if($min<0)$min=0; if($max<$min)$max=$min; $entry=['chance'=>$chance,'min'=>$min,'max'=>$max]; if(isset($d['itemId'])) $entry['itemId']=(string)$d['itemId']; $out[]=$entry; } return $out; }
function ensure_item_categories(&$data){ if(!isset($data['categories'])||!is_array($data['categories'])) $data['categories']=[]; $have=[]; foreach($data['categories'] as $c){ $have[$c['id']??'']=true; } if(empty($have['animal'])) $data['categories'][]=['id'=>'animal','name'=>'生物','label'=>'生物']; }

// ---------- ROUTES ----------
$method = $_SERVER['REQUEST_METHOD'];
$animals = load_json($animalsJson, 'animals');

if ($method === 'GET') { respond(200, ["status"=>"ok","animals"=>$animals['animals']]); }
if ($method !== 'POST') { respond(405, ["status"=>"error","message"=>"Method not allowed"]); }
$action = $_POST['action'] ?? '';

// Load items for mirroring
$items = load_json($itemsJson, 'items'); ensure_item_categories($items);

if ($action === 'create') {
  $name = trim($_POST['name'] ?? '');
  if ($name === '') respond(400, ["status"=>"error","handledAction"=>"create","message"=>"name is required"]);
  $notes = trim($_POST['notes'] ?? '');
  $dropSetIds = parse_array_field('dropSetIds');
  $drops = parse_drops_from_request('drops');

  $slug = slugify($name);
  $id = $slug.'_'.substr(sha1(uniqid('',true)),0,6);
  $dir = $animalsDir.'/'.$id; ensure_dir($dir);

  $imageMeta = null;
  if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    $err = (int)$_FILES['image']['error']; if ($err !== UPLOAD_ERR_OK) respond(400, ["status"=>"error","message"=>"image upload error: $err"]);
    $size = (int)$_FILES['image']['size'] ?? 0; if ($size > $maxUpload) respond(400, ["status"=>"error","message"=>"image too large"]);
    $nameOrig = (string)($_FILES['image']['name'] ?? 'image');
    $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
    global $allowedExt; if (!in_array($ext, $allowedExt, true)) respond(400, ["status"=>"error","message"=>"invalid image ext"]);
    $safe='image.'.$ext; $dest=$dir.'/'.$safe;
    if (!@move_uploaded_file($_FILES['image']['tmp_name'],$dest)) respond(500, ["status"=>"error","message"=>"failed to save image"]);
    @chmod($dest,0666);
    $imageMeta=["filename"=>$safe,"path"=>"Animals/$id/$safe","label"=>($_POST['imageLabel'] ?? ""),"uploadedAt"=>gmdate('c')];
  }

  $animal = ['id'=>$id,'name'=>$name,'notes'=>$notes,'image'=>$imageMeta,'dropSetIds'=>$dropSetIds,'drops'=>$drops,'createdAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];
  $animals['animals'][] = $animal; try_save($animalsJson, $animals);

  // Mirror to Items
  $itemId = 'animal-'.$id;
  $itemDir = $itemsDir.'/'.$itemId; ensure_dir($itemDir);
  // copy image if exists
  $itemImage = null;
  if ($imageMeta && isset($imageMeta['filename'])) {
    $src = $animalsDir.'/'.$id.'/'.$imageMeta['filename'];
    $ext = pathinfo($src, PATHINFO_EXTENSION);
    $dst = $itemDir.'/image.'.$ext;
    @copy($src, $dst); @chmod($dst,0666);
    $itemImage = ["filename"=>'image.'.$ext, "path"=>"Items/$itemId/image.$ext", "label"=>$imageMeta['label'] ?? "", "uploadedAt"=>gmdate('c')];
  }
  // remove existing same id if any
  $idx=-1; foreach ($items['items'] as $i=>$it) if (($it['id']??'')===$itemId){ $idx=$i; break; }
  $mirrored = ['id'=>$itemId,'linkedAnimalId'=>$id,'name'=>$name,'categoryId'=>'animal','notes'=>$notes,'image'=>$itemImage,'dropSetIds'=>$dropSetIds,'drops'=>$drops,'createdAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];
  if ($idx===-1) { $items['items'][]=$mirrored; } else { $items['items'][$idx]=$mirrored; }
  try_save($itemsJson, $items);

  respond(200, ["status"=>"ok","handledAction"=>"create","animal"=>$animal]);
}

if ($action === 'update') {
  $id = $_POST['id'] ?? '';
  if ($id === '') respond(400, ["status"=>"error","handledAction"=>"update","message"=>"id is required"]);
  $aidx=-1; foreach($animals['animals'] as $i=>$a) if(($a['id']??'')===$id){ $aidx=$i; break; }
  if ($aidx===-1) respond(404, ["status"=>"error","handledAction"=>"update","message"=>"animal not found"]);

  $changed=false;
  if(isset($_POST['name'])){ $animals['animals'][$aidx]['name']=trim((string)$_POST['name']); $changed=true; }
  if(isset($_POST['notes'])){ $animals['animals'][$aidx]['notes']=trim((string)$_POST['notes']); $changed=true; }
  if(isset($_POST['dropSetIds'])){ $animals['animals'][$aidx]['dropSetIds']=parse_array_field('dropSetIds'); $changed=true; }
  if(isset($_POST['drops'])){ $animals['animals'][$aidx]['drops']=parse_drops_from_request('drops'); $changed=true; }

  $dir = $animalsDir.'/'.$id; ensure_dir($dir);
  if (!empty($_FILES['image']) && (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    $err = (int)$_FILES['image']['error']; if($err!==UPLOAD_ERR_OK) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"image upload error: $err"]);
    $size = (int)($_FILES['image']['size'] ?? 0); if($size>$maxUpload) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"image too large"]);
    $nameOrig = (string)($_FILES['image']['name'] ?? 'image'); $ext = strtolower(pathinfo($nameOrig, PATHINFO_EXTENSION));
    if(!in_array($ext,$allowedExt,true)) respond(400, ["status"=>"error","handledAction"=>"update","message"=>"invalid image ext"]);
    if(isset($animals['animals'][$aidx]['image']['filename'])){ @unlink($dir.'/'.$animals['animals'][$aidx]['image']['filename']); }
    $safe='image.'.$ext; $dest=$dir.'/'.$safe; if(!@move_uploaded_file($_FILES['image']['tmp_name'],$dest)) respond(500, ["status"=>"error","handledAction"=>"update","message"=>"failed to save image"]);
    @chmod($dest,0666);
    $animals['animals'][$aidx]['image'] = ["filename"=>$safe,"path"=>"Animals/$id/$safe","label"=>($_POST['imageLabel'] ?? ""),"uploadedAt"=>gmdate('c')];
    $changed=true;
  }

  if($changed){ $animals['animals'][$aidx]['updatedAt']=gmdate('c'); try_save($animalsJson,$animals); }

  // Mirror update to Items
  $itemId = 'animal-'.$id;
  $iidx=-1; foreach($items['items'] as $i=>$it) if(($it['id']??'')===$itemId){ $iidx=$i; break; }
  $itemDir = $itemsDir.'/'.$itemId; ensure_dir($itemDir);
  $itemImage = $items['items'][$iidx]['image'] ?? null;
  if(isset($_FILES['image'])){
    if(isset($animals['animals'][$aidx]['image']['filename'])){
      $src = $animalsDir.'/'.$id.'/'.$animals['animals'][$aidx]['image']['filename'];
      $ext = pathinfo($src, PATHINFO_EXTENSION);
      $dst = $itemDir.'/image.'.$ext;
      @copy($src,$dst); @chmod($dst,0666);
      $itemImage = ["filename"=>'image.'.$ext, "path"=>"Items/$itemId/image.$ext", "label"=>$animals['animals'][$aidx]['image']['label'] ?? "", "uploadedAt"=>gmdate('c')];
    }
  }
  $mirrored = [
    'id'=>$itemId,'linkedAnimalId'=>$id,
    'name'=>$animals['animals'][$aidx]['name'],
    'categoryId'=>'animal',
    'notes'=>$animals['animals'][$aidx]['notes'],
    'image'=>$itemImage,
    'dropSetIds'=>$animals['animals'][$aidx]['dropSetIds'] ?? [],
    'drops'=>$animals['animals'][$aidx]['drops'] ?? [],
    'createdAt'=>$items['items'][$iidx]['createdAt'] ?? gmdate('c'),
    'updatedAt'=>gmdate('c')
  ];
  if ($iidx===-1) { $items['items'][]=$mirrored; } else { $items['items'][$iidx]=$mirrored; }
  try_save($itemsJson,$items);

  respond(200, ["status"=>"ok","handledAction"=>"update","animal"=>$animals['animals'][$aidx]]);
}

if ($action === 'delete') {
  $id = $_POST['id'] ?? '';
  if ($id === '') respond(400, ["status"=>"error","handledAction"=>"delete","message"=>"id is required"]);
  $aidx=-1; foreach($animals['animals'] as $i=>$a) if(($a['id']??'')===$id){ $aidx=$i; break; }
  if ($aidx===-1) respond(404, ["status"=>"error","handledAction"=>"delete","message"=>"animal not found"]);

  // delete animal folder
  $dir=$animalsDir.'/'.$id;
  if (is_dir($dir)) { $files=glob($dir.'/*'); if (is_array($files)) foreach($files as $f) if (is_file($f)) @unlink($f); @rmdir($dir); }

  // remove from animals
  array_splice($animals['animals'],$aidx,1); try_save($animalsJson,$animals);

  // remove mirrored item
  $itemId = 'animal-'.$id;
  $iidx=-1; foreach($items['items'] as $i=>$it) if(($it['id']??'')===$itemId){ $iidx=$i; break; }
  if ($iidx!==-1) {
    $itemDir = $itemsDir.'/'.$itemId;
    if (is_dir($itemDir)) { $files=glob($itemDir.'/*'); if(is_array($files)) foreach($files as $f) if(is_file($f)) @unlink($f); @rmdir($itemDir); }
    array_splice($items['items'],$iidx,1); try_save($itemsJson,$items);
  }

  respond(200, ["status"=>"ok","handledAction"=>"delete","deleted"=>$id]);
}

respond(400, ["status"=>"error","message"=>"Unknown action"]);
