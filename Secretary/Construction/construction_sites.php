<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: ../index.php'); exit; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA CONSTRUCTION LIMITED','company_dept'=>'Construction & Projects'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}

function generateCode():string{
    $chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code='';for($i=0;$i<8;$i++)$code.=$chars[random_int(0,strlen($chars)-1)];
    return $code;
}

define('PROJECT_UPLOAD_ROOT', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'projects');

/* ===== SERVE PROJECT IMAGE VIA PHP ===== */
if (isset($_GET['serve_image'])) {
    $pid = (int)$_GET['serve_image'];
    if ($pid <= 0) { http_response_code(404); exit; }
    try {
        $st = $pdo->prepare("SELECT site_image FROM construction_projects WHERE id=? LIMIT 1");
        $st->execute([$pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch(Exception $ignore) { $row = false; }
    if (!$row || empty($row['site_image'])) { http_response_code(404); exit; }
    $fileName = basename(str_replace('\\', '/', $row['site_image']));
    $absPath = PROJECT_UPLOAD_ROOT . DIRECTORY_SEPARATOR . $pid . DIRECTORY_SEPARATOR . $fileName;
    $realPath = realpath($absPath);
    if (!$realPath || !is_file($realPath)) { http_response_code(404); exit; }
    $info = @getimagesize($realPath);
    $mime = $info['mime'] ?? 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($realPath));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($realPath);
    exit;
}

/* ===== ENSURE COLUMNS EXIST ===== */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM construction_projects")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('project_code', $cols)) $pdo->exec("ALTER TABLE construction_projects ADD COLUMN project_code VARCHAR(20) DEFAULT NULL AFTER id");
    if (!in_array('site_image', $cols)) $pdo->exec("ALTER TABLE construction_projects ADD COLUMN site_image VARCHAR(255) DEFAULT NULL AFTER site");
} catch(Exception $ignore){}

/* ===== DELETE PROJECT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete_project') {
    $pid=(int)($_POST['project_id']??0);
    if($pid>0){
        try{
            $imgDir=PROJECT_UPLOAD_ROOT.DIRECTORY_SEPARATOR.$pid;
            if(is_dir($imgDir)){
                $files=glob($imgDir.DIRECTORY_SEPARATOR.'*');
                if($files) foreach($files as $f) @unlink($f);
                @rmdir($imgDir);
            }
            $pdo->prepare("DELETE FROM project_photos WHERE project_id=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM project_progress_logs WHERE project_id=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM site_daily_logs WHERE project_id=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM construction_projects WHERE id=?")->execute([$pid]);
        }catch(Exception $ignore){}
    }
    header('Location: construction_sites.php'); exit;
}

/* ===== UPLOAD SITE IMAGE ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_upload_image') {
    $pid=(int)($_POST['project_id']??0);
    if($pid>0 && isset($_FILES['site_image']) && $_FILES['site_image']['error']===UPLOAD_ERR_OK){
        $tmp=$_FILES['site_image']['tmp_name'];
        $imgInfo=@getimagesize($tmp);
        $mime=$imgInfo['mime']??'';
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(isset($allowed[$mime]) && $_FILES['site_image']['size']<=5*1024*1024){
            $dir=PROJECT_UPLOAD_ROOT.DIRECTORY_SEPARATOR.$pid;
            if(!is_dir($dir)) @mkdir($dir,0775,true);
            $fname=date('Ymd').'_'.bin2hex(random_bytes(6)).'.'.$allowed[$mime];
            $dest=$dir.DIRECTORY_SEPARATOR.$fname;
            if(@move_uploaded_file($tmp,$dest)){
                $dbPath='uploads/projects/'.$pid.'/'.$fname;
                $pdo->prepare("UPDATE construction_projects SET site_image=? WHERE id=?")->execute([$dbPath,$pid]);
            }
        }
    }
    header('Location: construction_sites.php'); exit;
}

/* ===== FETCH PROJECTS ===== */
$projects=[];
try{$projects=$pdo->query("SELECT * FROM construction_projects ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}

$updateStmt=$pdo->prepare("UPDATE construction_projects SET project_code=? WHERE id=?");
foreach($projects as &$p){
    if(empty($p['project_code'])){$newCode=generateCode();$updateStmt->execute([$newCode,$p['id']]);$p['project_code']=$newCode;}
}
unset($p);

$totalProjects=count($projects);
$activeCount=0;foreach($projects as $p)if(in_array(strtolower($p['status']??''),['active','in progress']))$activeCount++;
$completedCount=0;foreach($projects as $p)if(strtolower($p['status']??'')==='completed')$completedCount++;
$avgProgress=$totalProjects>0?round(array_sum(array_column($projects,'progress'))/$totalProjects):0;

/* Helper: build serve URL for a project image */
function imgServeUrl(int $id):string{
    $script = $_SERVER['SCRIPT_NAME'] ?? 'construction_sites.php';
    return $script . '?serve_image=' . $id;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Projects | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}
.container{max-width:1240px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:38px;height:38px;object-fit:contain}
.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}
.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}
.back-link:hover{color:var(--green);border-color:var(--green)}
.back-link svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}
.badge.admin{background:var(--gold-soft);color:var(--gold2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}
.page-header{padding:30px 0 10px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}
.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.ph-left h1 svg{width:28px;height:28px;stroke:var(--green)}
.ph-left h1 em{font-style:normal;color:var(--green)}
.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn svg{width:15px;height:15px}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.stats-row{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}
.stat-pill{display:inline-flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 16px;box-shadow:var(--shadow-sm)}
.stat-pill b{font-family:var(--fm);font-size:18px;font-weight:800}
.stat-pill span{font-size:11px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px}
.proj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:24px;margin-top:24px}
.proj-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;cursor:pointer;position:relative;transition:all .4s cubic-bezier(.25,.46,.45,.94);transform-style:preserve-3d;perspective:800px}
.proj-card:hover{transform:translateY(-8px) rotateX(2deg);box-shadow:0 20px 40px rgba(22,110,69,.15),0 8px 16px rgba(0,0,0,.08);border-color:var(--green)}
.pc-image{width:100%;height:200px;position:relative;overflow:hidden;background:var(--surface2)}
.pc-image img{width:100%;height:100%;object-fit:cover;transition:transform .6s cubic-bezier(.25,.46,.45,.94)}
.proj-card:hover .pc-image img{transform:scale(1.08)}
.pc-image-overlay{position:absolute;inset:0;background:linear-gradient(180deg,transparent 40%,rgba(0,0,0,.6) 100%);pointer-events:none}
.pc-image-badge{position:absolute;top:12px;left:12px;z-index:2}
.pc-status{font-size:10px;font-weight:800;padding:5px 12px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px;backdrop-filter:blur(8px)}
.st-active{background:rgba(22,110,69,.85);color:#fff}
.st-planning{background:rgba(37,99,235,.85);color:#fff}
.st-completed{background:rgba(37,99,235,.85);color:#fff}
.st-hold{background:rgba(217,154,43,.85);color:#fff}
.pc-code-float{position:absolute;top:12px;right:12px;z-index:3;font-family:var(--fm);font-size:12px;font-weight:800;color:#fff;background:rgba(0,0,0,.6);backdrop-filter:blur(10px);padding:6px 14px;border-radius:8px;letter-spacing:1.5px;border:1px solid rgba(255,255,255,.2)}
.pc-body{padding:20px 22px 22px}
.pc-name{font-family:var(--fd);font-size:19px;font-weight:800;margin-bottom:6px;line-height:1.3}
.pc-site{font-size:13px;color:var(--ink2);font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:16px}
.pc-site svg{width:14px;height:14px;flex:none}
.pc-progress{margin-bottom:16px}
.pc-prog-head{display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:var(--ink2);margin-bottom:6px}
.pc-bar{height:8px;border-radius:99px;background:var(--line);overflow:hidden}
.pc-bar div{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--green),var(--gold));transition:width .6s cubic-bezier(.25,.46,.45,.94)}
.pc-footer{display:flex;align-items:center;justify-content:space-between;padding-top:14px;border-top:1px solid var(--line);gap:8px}
.pc-footer-left{font-size:11px;color:var(--ink2);font-weight:600;display:flex;align-items:center;gap:6px}
.pc-footer-left svg{width:14px;height:14px}
.pc-footer-actions{display:flex;align-items:center;gap:6px}
.pc-access-btn{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:800;color:var(--green);padding:6px 14px;border:1.5px solid var(--green-soft);border-radius:99px;transition:.2s;background:transparent;cursor:pointer}
.pc-access-btn:hover{background:var(--green);color:#fff;border-color:var(--green)}
.pc-access-btn svg{width:13px;height:13px}
.pc-delete-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:99px;border:1.5px solid var(--red-soft);background:var(--red-soft);color:var(--red);cursor:pointer;transition:.2s}
.pc-delete-btn:hover{background:var(--red);color:#fff;border-color:var(--red)}
.pc-delete-btn svg{width:14px;height:14px}
.pc-no-image{width:100%;height:200px;background:linear-gradient(135deg,var(--surface2),var(--line));display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--ink2);position:relative}
.pc-no-image svg{width:48px;height:48px;opacity:.3}
.pc-no-image span{font-size:12px;font-weight:700;opacity:.5}
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.6);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
@keyframes fadein{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:420px;width:100%;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1);overflow:hidden}
@keyframes pop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}
.m-head{padding:20px 24px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.m-head h3{font-family:var(--fd);font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px}
.m-head h3 svg{width:20px;height:20px;stroke:var(--green)}
.m-x{width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);display:grid;place-items:center;cursor:pointer;font-size:18px}
.m-body{padding:20px 24px 24px}
.ctrl{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 14px;height:48px;margin-bottom:16px}
.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent)}
.ctrl input{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;font-family:var(--fm);letter-spacing:1px;text-transform:uppercase}
.upload-zone{position:relative;border:2.5px dashed var(--line);border-radius:16px;padding:32px 20px;text-align:center;cursor:pointer;transition:all .3s ease;margin-bottom:16px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;min-height:180px}
.upload-zone:hover,.upload-zone.drag{border-color:var(--green);background:var(--green-soft)}
.upload-zone-icon{width:56px;height:56px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;transition:all .3s ease;flex-shrink:0}
.upload-zone:hover .upload-zone-icon{background:var(--green);transform:translateY(-4px)}
.upload-zone:hover .upload-zone-icon svg{stroke:#fff}
.upload-zone-icon svg{width:28px;height:28px;stroke:var(--ink2);transition:stroke .3s}
.upload-zone-title{font-family:var(--fd);font-size:14px;font-weight:800;color:var(--ink)}
.upload-zone-sub{font-size:11px;color:var(--ink2);opacity:.7}
.upload-file-input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;z-index:5}
.upload-preview{width:100%;border-radius:14px;overflow:hidden;margin-bottom:16px;background:var(--surface2);border:1.5px solid var(--line);display:none}
.upload-preview.has-image{display:block}
.upload-preview img{width:100%;height:180px;object-fit:cover;display:block}
.del-modal-body{text-align:center;padding:24px}
.del-modal-body .del-icon{width:56px;height:56px;border-radius:50%;background:var(--red-soft);display:grid;place-items:center;margin:0 auto 16px}
.del-modal-body .del-icon svg{width:28px;height:28px;stroke:var(--red)}
.del-modal-body h3{font-family:var(--fd);font-size:18px;font-weight:800;margin-bottom:8px}
.del-modal-body p{font-size:13px;color:var(--ink2);margin-bottom:20px;line-height:1.5}
.del-modal-body .del-name{font-weight:800;color:var(--ink)}
.del-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
@media(max-width:768px){.proj-grid{grid-template-columns:1fr}.stats-row{flex-direction:column}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:480px){.pc-image{height:160px}.pc-body{padding:16px 18px 18px}.pc-name{font-size:17px}.upload-zone{min-height:150px;padding:24px 16px}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="admin_construction_dashboard.php" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back</a>
  <span class="badge admin">PROJECTS</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-left">
      <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg>Project <em>Management</em></h1>
      <p>Select a project to access site reports, worker logs, and material consumption.</p>
    </div>
    <a href="../Maintenance/admin_maintenance_dashboard.php" class="btn btn-ghost"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Maintenance</a>
  </div>

  <div class="stats-row">
    <div class="stat-pill"><b><?php echo $totalProjects; ?></b><span>Total Projects</span></div>
    <div class="stat-pill" style="color:var(--green)"><b><?php echo $activeCount; ?></b><span>Active</span></div>
    <div class="stat-pill" style="color:var(--blue)"><b><?php echo $completedCount; ?></b><span>Completed</span></div>
    <div class="stat-pill" style="color:var(--gold2)"><b><?php echo $avgProgress; ?>%</b><span>Avg Progress</span></div>
  </div>

  <div class="proj-grid">
    <?php foreach($projects as $p): 
      $statusCls=match(strtolower($p['status']??'')){
        'active','in progress'=>'st-active','completed'=>'st-completed','on hold'=>'st-hold',default=>'st-planning'
      };
      $hasImage=!empty($p['site_image']);
      $imgSrc=$hasImage ? imgServeUrl((int)$p['id']) : '';
    ?>
      <div class="proj-card" onclick="openCodeModal('<?php echo e($p['project_code']); ?>','<?php echo e($p['name']); ?>')">
        <?php if($hasImage): ?>
          <div class="pc-image">
            <img src="<?php echo e($imgSrc); ?>" alt="<?php echo e($p['name']); ?>">
            <div class="pc-image-overlay"></div>
            <div class="pc-image-badge"><span class="pc-status <?php echo $statusCls; ?>"><?php echo e($p['status']); ?></span></div>
            <span class="pc-code-float"><?php echo e($p['project_code']); ?></span>
          </div>
        <?php else: ?>
          <div class="pc-no-image">
            <svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
            <span>No site image</span>
            <span class="pc-status <?php echo $statusCls; ?>" style="margin-top:4px"><?php echo e($p['status']); ?></span>
            <span class="pc-code-float"><?php echo e($p['project_code']); ?></span>
          </div>
        <?php endif; ?>
        <div class="pc-body">
          <div class="pc-name"><?php echo e($p['name']); ?></div>
          <div class="pc-site">
            <svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <?php echo e($p['site']??'General Site'); ?>
          </div>
          <div class="pc-progress">
            <div class="pc-prog-head"><span>Progress</span><span><?php echo (int)$p['progress']; ?>%</span></div>
            <div class="pc-bar"><div style="width:<?php echo (int)$p['progress']; ?>%"></div></div>
          </div>
          <div class="pc-footer">
            <div class="pc-footer-left">
              <svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
              <?php echo date('d M Y',strtotime($p['created_at'])); ?>
            </div>
            <div class="pc-footer-actions">
              <span class="pc-access-btn" onclick="event.stopPropagation();openImageModal(<?php echo (int)$p['id']; ?>,'<?php echo e($p['name']); ?>',<?php echo $hasImage?'true':'false'; ?>)">
                <svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <?php echo $hasImage?'Change Image':'Add Image'; ?>
              </span>
              <button class="pc-delete-btn" onclick="event.stopPropagation();openDeleteModal(<?php echo (int)$p['id']; ?>,'<?php echo e($p['name']); ?>')" title="Delete project">
                <svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- SECURITY CODE MODAL -->
<div class="modal-overlay" id="codeModal">
  <div class="modal">
    <div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Secure Access</h3><button class="m-x" onclick="closeModal('codeModal')">&times;</button></div>
    <div class="m-body">
      <p style="font-size:13px;color:var(--ink2);margin-bottom:20px">Enter the project code to access <strong id="modalProjName"></strong></p>
      <form id="codeForm" onsubmit="verifyCode(event)">
        <input type="hidden" id="targetCode">
        <div class="ctrl"><svg class="icon-svg" width="18" height="18" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="text" id="codeInput" placeholder="ENTER PROJECT CODE" required autocomplete="off"></div>
        <button type="submit" class="btn btn-primary" style="width:100%">Access Project</button>
      </form>
    </div>
  </div>
</div>

<!-- IMAGE UPLOAD MODAL -->
<div class="modal-overlay" id="imageModal">
  <div class="modal">
    <div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Site Image</h3><button class="m-x" onclick="closeModal('imageModal')">&times;</button></div>
    <div class="m-body">
      <p style="font-size:13px;color:var(--ink2);margin-bottom:16px">Upload a site photo for <strong id="imageModalName"></strong></p>
      <form method="post" enctype="multipart/form-data" id="imageForm">
        <input type="hidden" name="action" value="_upload_image">
        <input type="hidden" name="project_id" id="imageProjectId">
        <div class="upload-preview" id="uploadPreview"><img id="previewImg" src="" alt="Preview"></div>
        <label for="imageFileInput" class="upload-zone" id="uploadZone">
          <div class="upload-zone-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></div>
          <div class="upload-zone-title">Click or drag image here</div>
          <div class="upload-zone-sub">JPG, PNG, WEBP · Max 5MB</div>
          <input type="file" name="site_image" id="imageFileInput" accept="image/png,image/jpeg,image/webp" class="upload-file-input">
        </label>
        <button type="submit" class="btn btn-primary" style="width:100%">Save Site Image</button>
      </form>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:400px">
    <div class="del-modal-body">
      <div class="del-icon"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></div>
      <h3>Delete Project?</h3>
      <p>This will permanently delete <span class="del-name" id="delProjName"></span> and all its data including photos, progress logs, and site records. This cannot be undone.</p>
      <form method="post">
        <input type="hidden" name="action" value="_delete_project">
        <input type="hidden" name="project_id" id="delProjId">
        <div class="del-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
          <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,var(--red),#8b2318)">Delete Permanently</button>
        </div>
      </form>
    </div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Project Management System</div>
<div><h5>Developed by Lawani Djamiou Alade</h5></div>
</footer>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

function openModal(id){$(id).classList.add('open')}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* Code modal */
function openCodeModal(code,name){
  $('targetCode').value=code;$('modalProjName').textContent=name;
  $('codeInput').value='';openModal('codeModal');
  setTimeout(()=>$('codeInput').focus(),100);
}
function verifyCode(e){
  e.preventDefault();
  const input=$('codeInput').value.trim().toUpperCase();
  const target=$('targetCode').value;
  if(input===target){window.location.href='project_detail.php?code='+encodeURIComponent(input);}
  else{$('codeInput').style.borderColor='var(--red)';$('codeInput').style.animation='shake .3s';setTimeout(()=>$('codeInput').style.animation='',300);}
}

/* Image modal — uses serve endpoint for preview */
function openImageModal(id,name,hasImage){
  $('imageProjectId').value=id;
  $('imageModalName').textContent=name;
  $('imageFileInput').value='';
  if(hasImage){
    $('previewImg').src='<?php echo $_SERVER['SCRIPT_NAME'] ?? "construction_sites.php"; ?>?serve_image='+id;
    $('uploadPreview').classList.add('has-image');
  } else {
    $('previewImg').src='';
    $('uploadPreview').classList.remove('has-image');
  }
  openModal('imageModal');
}

/* File input change — preview new selection */
document.addEventListener('DOMContentLoaded',function(){
  var fi=document.getElementById('imageFileInput');
  if(fi){
    fi.addEventListener('change',function(){
      var file=this.files[0];
      if(!file)return;
      var reader=new FileReader();
      reader.onload=function(ev){
        document.getElementById('previewImg').src=ev.target.result;
        document.getElementById('uploadPreview').classList.add('has-image');
      };
      reader.readAsDataURL(file);
    });
  }
  /* Drag & drop */
  var uz=document.getElementById('uploadZone');
  if(uz){
    uz.addEventListener('dragover',function(e){e.preventDefault();e.stopPropagation();uz.classList.add('drag');});
    uz.addEventListener('dragenter',function(e){e.preventDefault();e.stopPropagation();uz.classList.add('drag');});
    uz.addEventListener('dragleave',function(e){e.preventDefault();e.stopPropagation();uz.classList.remove('drag');});
    uz.addEventListener('dragend',function(e){e.preventDefault();e.stopPropagation();uz.classList.remove('drag');});
    uz.addEventListener('drop',function(e){
      e.preventDefault();e.stopPropagation();uz.classList.remove('drag');
      var file=e.dataTransfer.files[0];
      if(file&&file.type.startsWith('image/')){
        var dt=new DataTransfer();dt.items.add(file);
        document.getElementById('imageFileInput').files=dt.files;
        var reader=new FileReader();
        reader.onload=function(ev){
          document.getElementById('previewImg').src=ev.target.result;
          document.getElementById('uploadPreview').classList.add('has-image');
        };
        reader.readAsDataURL(file);
      }
    });
  }
});

function openDeleteModal(id,name){
  $('delProjId').value=id;$('delProjName').textContent=name;
  openModal('deleteModal');
}
</script>
</body>
</html>
