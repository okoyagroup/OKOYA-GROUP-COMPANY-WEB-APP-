<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: ../index.php'); exit; }

$code = $_GET['code'] ?? '';
if (!$code) { header('Location: construction_sites.php'); exit; }

$project = null;
try {
    $st = $pdo->prepare("SELECT * FROM construction_projects WHERE project_code = ?");
    $st->execute([$code]);
    $project = $st->fetch(PDO::FETCH_ASSOC);
} catch(Exception $ignore){}
if (!$project) { header('Location: projects.php?error=invalid_code'); exit; }

/* ==========================================================
   CENTRALIZED UPLOAD ROOT
   All photos stored at DOCUMENT_ROOT/uploads/projects/{id}/
   so every user/device can access them reliably.
   ========================================================== */
define('UPLOAD_ROOT', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'projects');

function getProjectUploadDir(int $projectId): string {
    $dir = UPLOAD_ROOT . DIRECTORY_SEPARATOR . (int)$projectId;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/* ==========================================================
   PHOTO SERVE ENDPOINT
   Streams photos through PHP — never exposes uploads directly.
   ========================================================== */
if (isset($_GET['serve_photo'])) {
    $photoId = (int)$_GET['serve_photo'];
    if ($photoId <= 0) { http_response_code(404); exit('Photo not found'); }

    try {
        $photoSt = $pdo->prepare("SELECT id, file_path FROM project_photos WHERE id=? AND project_id=? LIMIT 1");
        $photoSt->execute([$photoId, $project['id']]);
        $photo = $photoSt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $ignore) { $photo = false; }

    if (!$photo) { http_response_code(404); exit('Photo not found'); }

    $storedPath = trim((string)$photo['file_path']);
    $fileName = basename(str_replace('\\', '/', $storedPath));
    $absPath = UPLOAD_ROOT . DIRECTORY_SEPARATOR . (int)$project['id'] . DIRECTORY_SEPARATOR . $fileName;
    $realPath = realpath($absPath);

    if (!$realPath || !is_file($realPath) || !is_readable($realPath)) {
        http_response_code(404); exit('Photo file not found on server');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) { $detected = @finfo_file($finfo, $realPath); @finfo_close($finfo); if ($detected) $mime = $detected; }
    }
    $allowedMimes = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($mime, $allowedMimes, true)) {
        $info = @getimagesize($realPath);
        if (!empty($info['mime']) && in_array($info['mime'], $allowedMimes, true)) $mime = $info['mime'];
        else { http_response_code(415); exit('Unsupported image type'); }
    }

    $size = @filesize($realPath);
    header('Content-Type: ' . $mime);
    if ($size !== false) header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($realPath);
    exit;
}

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA CONSTRUCTION COMPANY LIMITED','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr wahab_files/logo_uigcps.jpg';

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

function photoServeUrl(int $id): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $code = $_GET['code'] ?? '';
    return $script . '?code=' . rawurlencode((string)$code) . '&serve_photo=' . $id;
}

function photoServerPath(?string $path): string {
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^(?:https?:)?//#i', $path)) return '';
    $fileName = basename(str_replace('\\', '/', $path));
    $absPath = UPLOAD_ROOT . DIRECTORY_SEPARATOR . (int)($GLOBALS['project']['id'] ?? 0) . DIRECTORY_SEPARATOR . $fileName;
    $real = realpath($absPath);
    return ($real && is_file($real)) ? $real : '';
}

// Ensure tables exist
try { $pdo->exec("CREATE TABLE IF NOT EXISTS project_photos (id INT AUTO_INCREMENT PRIMARY KEY,project_id INT NOT NULL,file_path VARCHAR(255) NOT NULL,caption VARCHAR(255) DEFAULT '',taken_on DATE NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $ignore){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS project_progress_logs (id INT AUTO_INCREMENT PRIMARY KEY,project_id INT NOT NULL,log_date DATE NOT NULL,milestone VARCHAR(255) DEFAULT '',progress INT DEFAULT 0,notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $ignore){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS site_daily_logs (id INT AUTO_INCREMENT PRIMARY KEY,project_id INT NOT NULL,ref VARCHAR(30) DEFAULT NULL,log_date DATE NOT NULL,log_type ENUM('WORKER','MATERIAL','EQUIPMENT') NOT NULL,role VARCHAR(80) DEFAULT NULL,item_name VARCHAR(150) DEFAULT NULL,quantity DECIMAL(12,2) DEFAULT 0,unit VARCHAR(20) DEFAULT NULL,notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); } catch(Exception $ignore){}

$uploadMsg = '';
$ref = 'RPT-' . strtoupper(substr(md5(uniqid()), 0, 6));

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_meta') {
        $prog = min(100, max(0, (int)($_POST['progress'] ?? 0)));
        $notes = trim($_POST['site_notes'] ?? '');
        $pdo->prepare("UPDATE construction_projects SET progress=?, site_notes=? WHERE id=?")->execute([$prog, $notes, $project['id']]);
    }

    if ($action === 'log_progress') {
        $date = $_POST['progress_date'] ?? date('Y-m-d');
        $milestone = trim($_POST['milestone'] ?? '');
        $prog = min(100, max(0, (int)($_POST['progress'] ?? 0)));
        $notes = trim($_POST['progress_notes'] ?? '');
        if ($milestone !== '') {
            $pdo->prepare("INSERT INTO project_progress_logs (project_id, log_date, milestone, progress, notes) VALUES (?,?,?,?,?)")->execute([$project['id'], $date, $milestone, $prog, $notes]);
            $pdo->prepare("UPDATE construction_projects SET progress=? WHERE id=?")->execute([$prog, $project['id']]);
            $project['progress'] = $prog;
        }
    }

    // ===== FIXED: action name changed from '../../upload_photos' to 'upload_photos' =====
    // ===== FIXED: upload path uses absolute UPLOAD_ROOT instead of relative ../../ =====
    if ($action === 'upload_photos') {
        $date = $_POST['photo_date'] ?? date('Y-m-d');
        $caption = trim($_POST['photo_caption'] ?? '');
        $serverDir = getProjectUploadDir((int)$project['id']);
        $files = $_FILES['photos'] ?? null;
        $saved = 0; $failed = 0;

        if ($files && is_array($files['name'] ?? null)) {
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
            foreach ($files['name'] as $i => $name) {
                if ($saved >= 3) break;
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $tmp = $files['tmp_name'][$i] ?? '';
                $imgInfo = @getimagesize($tmp); $mime = $imgInfo['mime'] ?? '';
                if (!isset($allowed[$mime])) { $failed++; continue; }
                if (($files['size'][$i] ?? 0) > 10 * 1024 * 1024) { $failed++; continue; }
                $fname = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
                $destPath = $serverDir . DIRECTORY_SEPARATOR . $fname;
                if (@move_uploaded_file($tmp, $destPath)) {
                    $dbPath = 'uploads/projects/' . (int)$project['id'] . '/' . $fname;
                    try {
                        $pdo->prepare("INSERT INTO project_photos (project_id, file_path, caption, taken_on) VALUES (?,?,?,?)")->execute([$project['id'], $dbPath, $caption, $date]);
                        $saved++;
                    } catch(Exception $ignore){ @unlink($destPath); $failed++; }
                } else { $failed++; }
            }
        }
        $uploadMsg = $saved > 0 ? "✓ {$saved} photo(s) saved" . ($failed ? " · {$failed} rejected" : '') : '✗ No photos saved (invalid file or upload error)';
    }

    if ($action === 'delete_photo') {
        $pid = (int)($_POST['photo_id'] ?? 0);
        try {
            $st = $pdo->prepare("SELECT * FROM project_photos WHERE id=? AND project_id=?"); $st->execute([$pid, $project['id']]); $ph = $st->fetch(PDO::FETCH_ASSOC);
            if ($ph) { $sp = photoServerPath($ph['file_path']); if ($sp !== '' && is_file($sp)) @unlink($sp); $pdo->prepare("DELETE FROM project_photos WHERE id=?")->execute([$pid]); }
        } catch(Exception $ignore){}
    }

    if ($action === 'log_worker') {
        $date = $_POST['log_date'] ?? date('Y-m-d'); $notes = trim($_POST['notes'] ?? '');
        $roles = $_POST['worker_role'] ?? []; $counts = $_POST['worker_count'] ?? [];
        foreach($roles as $i => $roleName) { $roleName = trim($roleName); $count = (int)($counts[$i] ?? 0);
            if ($roleName !== '' && $count > 0) $pdo->prepare("INSERT INTO site_daily_logs (project_id, ref, log_date, log_type, role, item_name, quantity, notes) VALUES (?,?,?,?,?,?,?,?)")->execute([$project['id'], $ref, $date, 'WORKER', $roleName, $roleName, $count, $notes]);
        }
    }

    if ($action === 'log_material') {
        $date = $_POST['log_date'] ?? date('Y-m-d'); $notes = trim($_POST['notes'] ?? '');
        $names = $_POST['material_name'] ?? []; $qtys = $_POST['material_qty'] ?? []; $units = $_POST['material_unit'] ?? [];
        foreach($names as $i => $matName) { $matName = trim($matName); $qty = (float)($qtys[$i] ?? 0); $unit = trim($units[$i] ?? 'units');
            if ($matName !== '' && $qty > 0) $pdo->prepare("INSERT INTO site_daily_logs (project_id, ref, log_date, log_type, item_name, quantity, unit, notes) VALUES (?,?,?,?,?,?,?,?)")->execute([$project['id'], $ref, $date, 'MATERIAL', $matName, $qty, $unit, $notes]);
        }
    }

    if ($action === 'log_equipment') {
        $date = $_POST['log_date'] ?? date('Y-m-d'); $notes = trim($_POST['notes'] ?? '');
        $names = $_POST['equipment_name'] ?? []; $qtys = $_POST['equipment_qty'] ?? [];
        foreach($names as $i => $eqName) { $eqName = trim($eqName); $qty = (float)($qtys[$i] ?? 1);
            if ($eqName !== '') $pdo->prepare("INSERT INTO site_daily_logs (project_id, ref, log_date, log_type, item_name, quantity, unit, notes) VALUES (?,?,?,?,?,?,?,?)")->execute([$project['id'], $ref, $date, 'EQUIPMENT', $eqName, $qty, 'unit(s)', $notes]);
        }
    }

    $st = $pdo->prepare("SELECT * FROM construction_projects WHERE id = ?"); $st->execute([$project['id']]); $project = $st->fetch(PDO::FETCH_ASSOC);
}

$siteLogs = [];
try { $st = $pdo->prepare("SELECT * FROM site_daily_logs WHERE project_id=? ORDER BY log_date DESC, created_at DESC"); $st->execute([$project['id']]); $siteLogs = $st->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $ignore){}
$logsByRef = [];
foreach ($siteLogs as $log) { $rk = $log['ref'] ?: ('LOG-'.$log['id']); if (!isset($logsByRef[$rk])) $logsByRef[$rk] = ['ref'=>$rk,'date'=>$log['log_date'],'notes'=>$log['notes']??'','created_at'=>$log['created_at'],'items'=>[]]; $logsByRef[$rk]['items'][] = ['type'=>$log['log_type'],'role'=>$log['role']??'','item'=>$log['item_name']??'','qty'=>(float)$log['quantity'],'unit'=>$log['unit']??'']; }
usort($logsByRef, fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));

$progressLogs = [];
try { $st = $pdo->prepare("SELECT * FROM project_progress_logs WHERE project_id=? ORDER BY log_date DESC, created_at DESC"); $st->execute([$project['id']]); $progressLogs = $st->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $ignore){}

$photos = [];
try { $st = $pdo->prepare("SELECT * FROM project_photos WHERE project_id=? ORDER BY taken_on DESC, created_at DESC"); $st->execute([$project['id']]); $photos = $st->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $ignore){}

$workerRoles = ['Carpenter','Mason','Electrician','Plumber','Welder','Painter','Laborer','Foreman','Site Engineer','Surveyor','Crane Operator','Driver','Security','Cleaner','Supervisor'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($project['name']); ?> | Project Detail</title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--purple:#7c3aed;--purple-soft:#f0ebfd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--purple:#a78bfa;--purple-soft:#2a1f4a;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}
.container{max-width:1240px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
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
.badge.code{background:var(--green-soft);color:var(--green);font-family:var(--fm)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}
.page-header{padding:30px 0 20px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:28px;height:28px;stroke:var(--green)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn svg{width:15px;height:15px}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
.dash-grid{display:grid;grid-template-columns:320px 1fr;gap:24px;margin-top:20px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:20px}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line);background:var(--surface2)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.card-body{padding:20px}
.prog-widget{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:24px;box-shadow:var(--shadow-sm);margin-bottom:20px}
.prog-circle{width:140px;height:140px;margin:0 auto 16px;position:relative}
.prog-circle svg{transform:rotate(-90deg);width:100%;height:100%}
.prog-circle circle{fill:none;stroke-width:8;stroke-linecap:round}
.prog-bg{stroke:var(--line)}
.prog-fill{stroke:var(--green);transition:stroke-dashoffset 1s ease}
.prog-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:var(--fd);font-size:36px;font-weight:800;color:var(--green)}
.prog-label{text-align:center;font-size:13px;font-weight:700;color:var(--ink2);margin-bottom:16px}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab-btn{padding:10px 18px;border-radius:12px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink2);font-family:var(--fd);font-weight:700;font-size:13px;transition:.2s;cursor:pointer}
.tab-btn:hover{border-color:var(--green);color:var(--green)}
.tab-btn.active{background:var(--green);color:#fff;border-color:var(--green)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ctrl{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 14px;height:46px}
.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent)}
.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%}
.ctrl select{cursor:pointer;appearance:none}
.ctrl textarea{padding:10px 0;height:auto;min-height:60px;resize:vertical}
.ctrl.ta{height:auto;align-items:flex-start;padding-top:10px}
/* progression timeline */
.timeline{position:relative;padding-left:24px}
.timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:var(--line)}
.tl-item{position:relative;margin-bottom:16px}
.tl-item::before{content:'';position:absolute;left:-21px;top:5px;width:12px;height:12px;border-radius:50%;background:var(--green);border:2px solid var(--surface);box-shadow:0 0 0 2px var(--green)}
.tl-date{font-family:var(--fm);font-size:11px;font-weight:700;color:var(--ink2)}
.tl-milestone{font-weight:800;font-size:14px;margin:2px 0}
.tl-prog{display:flex;align-items:center;gap:8px;margin:4px 0}
.tl-bar{flex:1;height:8px;border-radius:99px;background:var(--line);overflow:hidden}
.tl-bar div{height:100%;border-radius:99px;background:var(--green)}
.tl-pct{font-family:var(--fm);font-weight:800;color:var(--green);font-size:13px}
.tl-notes{font-size:12px;color:var(--ink2);margin-top:2px}
.tl-notes b{color:var(--gold2)}
/* dynamic rows */
.dyn-row{display:grid;gap:8px;margin-bottom:8px;align-items:center}
.dyn-row.w3{grid-template-columns:1fr 100px 40px}
.dyn-row.w4{grid-template-columns:1fr 100px 100px 40px}
.dyn-row input{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-weight:600;width:100%}
.dyn-row input:focus{border-color:var(--green);outline:none}
.remove-row{width:40px;height:42px;border-radius:10px;border:1.5px solid var(--red-soft);background:var(--red-soft);color:var(--red);font-weight:800;cursor:pointer}
.add-row-btn{margin-top:4px;width:100%;height:40px;border-radius:10px;border:1.5px dashed var(--green);background:var(--green-soft);color:var(--green);font-weight:700;cursor:pointer}
/* activity log grouped */
.log-group{border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow:hidden;background:var(--surface)}
.log-group-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;background:var(--surface2);flex-wrap:wrap}
.log-group-head .lg-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.log-date{font-family:var(--fm);font-size:12px;font-weight:700;color:var(--ink)}
.log-type{font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:.5px}
.lt-worker{background:var(--blue-soft);color:var(--blue)}
.lt-material{background:var(--gold-soft);color:var(--gold2)}
.lt-equipment{background:var(--purple-soft);color:var(--purple)}
.log-notes{background:var(--gold-soft);border-left:4px solid var(--gold);padding:10px 16px;font-size:13px;font-weight:600;color:var(--ink)}
.log-notes b{color:var(--gold2)}
.log-items{padding:8px 16px 14px}
.log-item-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--line);font-size:13px}
.log-item-row:last-child{border-bottom:none}
.log-item-row .n{font-weight:700}
.log-item-row .q{font-family:var(--fm);font-weight:700}
.empty-log{padding:30px;text-align:center;color:var(--ink2);font-weight:600}
.date-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

.icon-btn{width:36px;height:36px;border:1px solid var(--line);border-radius:10px;background:var(--surface);color:var(--ink2);display:inline-grid;place-items:center;transition:.2s;cursor:pointer}
.icon-btn svg{width:17px;height:17px}
.icon-btn:hover{color:var(--green);border-color:var(--green);background:var(--green-soft);transform:translateY(-1px)}
.remove-row{display:inline-grid;place-items:center}
.remove-row svg{width:17px;height:17px}
.pc-del svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.photo-card-image{position:relative;min-height:190px}
.photo-card-image img{background:var(--surface2)}
.photo-card-image .photo-loading{position:absolute;inset:0;display:grid;place-items:center;color:var(--ink2);font-size:11px;font-weight:700;background:var(--surface2)}
.photo-card-image.is-loaded .photo-loading{display:none}
.photo-card-image.is-error img{display:none}
.photo-card-image.is-error .photo-loading{display:flex;align-items:center;justify-content:center;padding:20px;text-align:center;color:var(--red);background:var(--red-soft)}
.photo-empty{padding:42px 20px;text-align:center;color:var(--ink2)}
.photo-empty .empty-icon{width:54px;height:54px;margin:0 auto 12px;border-radius:16px;background:var(--surface2);color:var(--green);display:grid;place-items:center}
.photo-empty .empty-icon svg{width:26px;height:26px}
.date-filter input{height:38px;border:1.5px solid var(--line);border-radius:10px;padding:0 10px;background:var(--surface);font-weight:600}
/* ===== photos ===== */
.upload-zone{position:relative;border:2px dashed var(--green);border-radius:16px;background:linear-gradient(135deg,var(--green-soft),var(--surface));padding:28px 20px;text-align:center;cursor:pointer;transition:.25s ease;margin-bottom:16px;min-height:170px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.upload-zone:hover,.upload-zone.drag{background:color-mix(in srgb,var(--green) 12%,var(--surface));transform:translateY(-2px);box-shadow:0 8px 22px color-mix(in srgb,var(--green) 15%,transparent)}
.upload-zone-icon{width:58px;height:58px;border-radius:16px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:14px;box-shadow:0 7px 18px color-mix(in srgb,var(--green) 25%,transparent)}
.upload-zone-icon svg{width:28px;height:28px;stroke:currentColor;fill:none;margin:0}
.upload-zone .uz-title{font-family:var(--fd);font-weight:800;font-size:15px;color:var(--green);line-height:1.3}
.upload-zone .uz-sub{font-size:11.5px;font-weight:600;color:var(--ink2);margin-top:6px;line-height:1.5}
.upload-zone input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.photo-slots{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px}
.photo-slot{aspect-ratio:4/3;border-radius:13px;border:1.5px dashed var(--line);display:grid;place-items:center;color:var(--ink2);font-size:14px;font-weight:800;overflow:hidden;position:relative;background:var(--surface2)}
.photo-slot.filled{border-style:solid;border-color:var(--green)}
.photo-slot img{width:100%;height:100%;object-fit:cover;display:block}
.photo-slot .slot-x{position:absolute;top:6px;right:6px;width:26px;height:26px;border-radius:50%;background:rgba(0,0,0,.72);color:#fff;border:none;font-size:13px;line-height:1;display:grid;place-items:center;cursor:pointer}
.slot-counter{font-family:var(--fm);font-size:10.5px;font-weight:700;color:var(--ink2);text-align:right;margin:0 0 14px}
.msg-flash{display:flex;align-items:center;gap:10px;padding:12px 15px;border-radius:12px;font-size:12.5px;font-weight:700;margin-bottom:16px;animation:flashIn .4s ease}
.msg-ok{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 30%,transparent)}
.msg-err{background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb,var(--red) 30%,transparent)}
.msg-icon{width:27px;height:27px;min-width:27px;border-radius:50%;display:grid;place-items:center;background:var(--green);color:#fff;font-size:15px;font-weight:900}
.msg-err .msg-icon{background:var(--red)}
@keyframes flashIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.photo-day-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0 12px;padding-bottom:9px;border-bottom:1px solid var(--line)}
.photo-day-head .log-date{font-size:13px;font-weight:800}
.count-chip{font-size:10px;font-weight:800;padding:4px 9px;border-radius:99px;background:var(--gold-soft);color:var(--gold2)}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:22px}
.photo-card{position:relative;border-radius:14px;overflow:hidden;border:1px solid var(--line);background:var(--surface);box-shadow:var(--shadow-sm);transition:.25s ease}
.photo-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.photo-card-image{width:100%;aspect-ratio:4/3;background:var(--surface2);display:flex;align-items:center;justify-content:center;overflow:hidden}
.photo-card-image img{width:100%;height:100%;object-fit:contain;display:block;cursor:zoom-in}
.photo-card .pc-cap{padding:10px 12px;font-size:11.5px;font-weight:700;color:var(--ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-top:1px solid var(--line)}
.photo-card .pc-del{position:absolute;top:8px;right:8px;width:30px;height:30px;border-radius:9px;background:rgba(0,0,0,.68);color:#fff;border:none;font-size:14px;display:grid;place-items:center;opacity:0;transition:.2s;cursor:pointer}
.photo-card:hover .pc-del{opacity:1}
.photo-card .pc-del:hover{background:var(--red)}
.all-toggle{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--ink2);cursor:pointer;user-select:none}
.all-toggle input{accent-color:var(--green);width:15px;height:15px;cursor:pointer}
.lightbox{position:fixed;inset:0;z-index:200;background:rgba(8,14,11,.9);display:none;align-items:center;justify-content:center;padding:24px;cursor:zoom-out}
.lightbox.open{display:flex;animation:lbIn .2s ease}
@keyframes lbIn{from{opacity:0}to{opacity:1}}
.lightbox img{max-width:92vw;max-height:88vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.6)}
.lightbox .lb-close{position:absolute;top:18px;right:20px;width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);font-size:18px;display:grid;place-items:center;cursor:pointer}
footer{margin-top:auto;border-top:1px solid var(--line);padding:22px 20px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
@media(max-width:900px){.dash-grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
@media(max-width:600px){.topbar .badge{display:none}.brand strong{font-size:12px}.dyn-row.w3{grid-template-columns:1fr 80px 40px}.dyn-row.w4{grid-template-columns:1fr 80px 40px}.upload-zone{min-height:155px;padding:24px 14px}.photo-grid{grid-template-columns:repeat(2,1fr);gap:10px}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small>Project Management</small></div></div>
  <a href="construction_sites.php" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>All Projects</a>
  <span class="badge code"><?php echo e($project['project_code']); ?></span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div>
      <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg><?php echo e($project['name']); ?></h1>
      <p><?php echo e($project['site'] ?? 'General Site'); ?> • <?php echo e($project['status']); ?> • <?php echo count($photos); ?> site photo(s)</p>
    </div>
  </div>

  <div class="dash-grid">
    <!-- LEFT -->
    <div>
      <div class="prog-widget">
        <div class="prog-circle">
          <svg viewBox="0 0 100 100">
            <circle class="prog-bg" cx="50" cy="50" r="40"/>
            <circle class="prog-fill" cx="50" cy="50" r="40" stroke-dasharray="251.2" stroke-dashoffset="<?php echo 251.2 - (251.2 * $project['progress'] / 100); ?>"/>
          </svg>
          <div class="prog-val"><?php echo (int)$project['progress']; ?>%</div>
        </div>
        <div class="prog-label">Project Completion</div>
      </div>

      <!-- PROJECT PROGRESSION FORM -->
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Add Progression</h2></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="log_progress">
            <div class="field"><label class="fl">Milestone / Phase <b style="color:var(--red)">*</b></label><div class="ctrl"><input type="text" name="milestone" required placeholder="e.g. Foundation Completed"></div></div>
            <div class="form-grid">
              <div class="field"><label class="fl">Progress %</label><div class="ctrl"><input type="number" name="progress" min="0" max="100" value="<?php echo (int)$project['progress']; ?>"></div></div>
              <div class="field"><label class="fl">Date</label><div class="ctrl"><input type="date" name="progress_date" value="<?php echo date('Y-m-d'); ?>"></div></div>
            </div>
            <div class="field"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="progress_notes" rows="2" placeholder="Progress notes..."></textarea></div></div>
            <button type="submit" class="btn btn-primary" style="width:100%">Log Progression</button>
          </form>
        </div>
      </div>

      <!-- DAILY PROGRESSION PHOTOS (CAMERA) -->
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>Daily Progress Photos</h2></div>
        <div class="card-body">
          <?php if ($uploadMsg): ?>
            <div class="msg-flash <?php echo str_starts_with($uploadMsg,'✓')?'msg-ok':'msg-err'; ?>"><span class="msg-icon"><?php echo str_starts_with($uploadMsg,'✓')?'✓':'!'; ?></span><span><?php echo e(ltrim($uploadMsg,'✓✗ ')); ?></span></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" id="photoForm" onsubmit="return validatePhotos()">
            <input type="hidden" name="action" value="upload_photos">
            <label class="upload-zone" id="uploadZone">
              <div class="upload-zone-icon">
                <svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
              </div>
              <div class="uz-title">Take / Choose up to 3 Photos</div>
              <div class="uz-sub">Tap to open camera or gallery · JPG, PNG, WEBP · Max 10MB each</div>
              <input type="file" name="photos[]" id="photoInput" accept="image/*" multiple onchange="handlePhotoFiles(this)">
             </label>
            <div class="photo-slots" id="photoSlots">
              <div class="photo-slot">1</div>
              <div class="photo-slot">2</div>
              <div class="photo-slot">3</div>
            </div>
            <div class="slot-counter" id="slotCounter">0 / 3 selected</div>
            <div class="form-grid">
              <div class="field"><label class="fl">Photo Date</label><div class="ctrl"><input type="date" name="photo_date" value="<?php echo date('Y-m-d'); ?>"></div></div>
              <div class="field"><label class="fl">Caption</label><div class="ctrl"><input type="text" name="photo_caption" maxlength="200" placeholder="e.g. Block work, East wing"></div></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Progress Photos</button>
          </form>
        </div>
      </div>

      <!-- PROGRESSION TIMELINE -->
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Progression Timeline</h2></div>
        <div class="card-body">
          <?php if(empty($progressLogs)): ?>
            <div class="empty-log">No progression logged yet</div>
          <?php else: ?>
            <div class="timeline">
              <?php foreach($progressLogs as $pl): ?>
                <div class="tl-item">
                  <div class="tl-date"><?php echo date('d M Y', strtotime($pl['log_date'])); ?></div>
                  <div class="tl-milestone"><?php echo e($pl['milestone']); ?></div>
                  <div class="tl-prog"><div class="tl-bar"><div style="width:<?php echo (int)$pl['progress']; ?>%"></div></div><span class="tl-pct"><?php echo (int)$pl['progress']; ?>%</span></div>
                  <?php if($pl['notes']): ?><div class="tl-notes"><b>Note:</b> <?php echo e($pl['notes']); ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <div>
      <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('worker',this)">Workers</button>
        <button class="tab-btn" onclick="switchTab('material',this)">Materials</button>
        <button class="tab-btn" onclick="switchTab('equipment',this)">Equipment</button>
      </div>

      <!-- WORKERS -->
      <div class="card tab-content" id="tab-worker">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Workers by Role</h2></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="log_worker">
            <div class="form-grid">
              <div class="field"><label class="fl">Date</label><div class="ctrl"><input type="date" name="log_date" value="<?php echo date('Y-m-d'); ?>"></div></div>
              <div class="field"><label class="fl">Notes</label><div class="ctrl"><input type="text" name="notes" placeholder="Shift notes..."></div></div>
            </div>
            <label class="fl" style="margin:8px 0 8px">Worker Roles &amp; Counts</label>
            <div id="workerRows">
              <div class="dyn-row w3">
                <input type="text" name="worker_role[]" list="workerRoles" placeholder="e.g. Carpenter">
                <input type="number" name="worker_count[]" min="1" placeholder="0">
                <button type="button" class="remove-row" title="Remove row" aria-label="Remove row" onclick="removeRow(this)"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
              </div>
            </div>
            <button type="button" class="add-row-btn" onclick="addRow('workerRows','w3',['worker_role[]','worker_count[]'])">+ Add Worker Role</button>
            <datalist id="workerRoles"><?php foreach($workerRoles as $wr): ?><option value="<?php echo e($wr); ?>"><?php endforeach; ?></datalist>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Log Workers</button>
          </form>
        </div>
      </div>

      <!-- MATERIALS -->
      <div class="card tab-content" id="tab-material" style="display:none">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Material Consumption</h2></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="log_material">
            <div class="form-grid">
              <div class="field"><label class="fl">Date</label><div class="ctrl"><input type="date" name="log_date" value="<?php echo date('Y-m-d'); ?>"></div></div>
              <div class="field"><label class="fl">Notes</label><div class="ctrl"><input type="text" name="notes" placeholder="Purpose..."></div></div>
            </div>
            <label class="fl" style="margin:8px 0 8px">Materials (Name, Qty, Unit)</label>
            <div id="materialRows">
              <div class="dyn-row w4">
                <input type="text" name="material_name[]" placeholder="e.g. Cement">
                <input type="number" name="material_qty[]" step="0.01" min="0.01" placeholder="0">
                <input type="text" name="material_unit[]" placeholder="bags">
                <button type="button" class="remove-row" title="Remove row" aria-label="Remove row" onclick="removeRow(this)"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
              </div>
            </div>
            <button type="button" class="add-row-btn" onclick="addRow('materialRows','w4',['material_name[]','material_qty[]','material_unit[]'])">+ Add Material</button>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Log Materials</button>
          </form>
        </div>
      </div>

      <!-- EQUIPMENT -->
      <div class="card tab-content" id="tab-equipment" style="display:none">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Equipment Usage</h2></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="log_equipment">
            <div class="form-grid">
              <div class="field"><label class="fl">Date</label><div class="ctrl"><input type="date" name="log_date" value="<?php echo date('Y-m-d'); ?>"></div></div>
              <div class="field"><label class="fl">Notes</label><div class="ctrl"><input type="text" name="notes" placeholder="Usage details..."></div></div>
            </div>
            <label class="fl" style="margin:8px 0 8px">Equipment (Name, Qty)</label>
            <div id="equipmentRows">
              <div class="dyn-row w3">
                <input type="text" name="equipment_name[]" placeholder="e.g. Concrete Mixer">
                <input type="number" name="equipment_qty[]" min="1" value="1">
                <button type="button" class="remove-row" title="Remove row" aria-label="Remove row" onclick="removeRow(this)"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
              </div>
            </div>
            <button type="button" class="add-row-btn" onclick="addRow('equipmentRows','w3',['equipment_name[]','equipment_qty[]'])">+ Add Equipment</button>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Log Equipment</button>
          </form>
        </div>
      </div>

      <!-- SITE ACTIVITY LOG -->
      <div class="card" style="margin-top:20px">
        <div class="card-head">
          <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>Site Activity Log</h2>
          <div class="date-filter">
            <input type="date" id="logDate" value="<?php echo date('Y-m-d'); ?>" onchange="renderLogs();renderGallery()">
            <button class="btn btn-primary btn-sm" onclick="printReport()"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Report</button>
          </div>
        </div>
        <div class="card-body" id="logContainer"></div>
      </div>

      <!-- SITE PHOTO GALLERY -->
      <div class="card">
        <div class="card-head">
          <h2><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>Progression Photo Gallery</h2>
          <label class="all-toggle"><input type="checkbox" id="showAllPhotos" onchange="renderGallery()"> Show all dates</label>
        </div>
        <div class="card-body" id="galleryContainer"></div>
      </div>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lb-close" type="button" aria-label="Close photo" title="Close" onclick="closeLightbox()"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
  <img id="lbImg" src="" alt="Site photo">
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Project Management System</div></footer>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

function switchTab(tab,btn){
  document.querySelectorAll('.tab-content').forEach(el=>el.style.display='none');
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  $('tab-'+tab).style.display='block';
  btn.classList.add('active');
}

function addRow(containerId,cls,names){
  const c=$(containerId);
  const row=document.createElement('div');
  row.className='dyn-row '+cls;
  const ph={worker_role:'e.g. Mason',worker_count:'0',material_name:'e.g. Granite',material_qty:'0',material_unit:'tons',equipment_name:'e.g. Generator',equipment_qty:'1'};
  let html='';
  names.forEach(n=>{
    const isNum=n.includes('count')||n.includes('qty');
    html+='<input type="'+(isNum?'number':'text')+'" name="'+n+'" placeholder="'+(ph[n]||'')+'"'+(isNum?' min="0"':'')+'>';
  });
  html+='<button type="button" class="remove-row" title="Remove row" aria-label="Remove row" onclick="removeRow(this)"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>';
  row.innerHTML=html;
  c.appendChild(row);
}
function removeRow(btn){
  const container=btn.parentElement.parentElement;
  if(container.querySelectorAll('.dyn-row').length>1) btn.parentElement.remove();
}

const logsByRef=<?php echo json_encode(array_values($logsByRef),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const progressLogs=<?php echo json_encode(array_map(function($p){return['date'=>$p['log_date'],'milestone'=>$p['milestone'],'progress'=>(int)$p['progress'],'notes'=>$p['notes']??''];},$progressLogs),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const sitePhotos=<?php echo json_encode(array_map(function($p){return['id'=>(int)$p['id'],'path'=>photoServeUrl((int)$p['id']),'caption'=>$p['caption']??'','date'=>$p['taken_on']];},$photos),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const projMeta={name:<?php echo json_encode($project['name']); ?>,code:<?php echo json_encode($project['project_code']); ?>,site:<?php echo json_encode($project['site']??'General Site'); ?>,progress:<?php echo (int)$project['progress']; ?>};
const coName=<?php echo json_encode($company['company_name']); ?>;
const coAddr=<?php echo json_encode($company['company_address']); ?>;
const typeBadge={WORKER:'lt-worker',MATERIAL:'lt-material',EQUIPMENT:'lt-equipment'};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* ===== PHOTO UPLOAD (3 slots) ===== */
let pendingPhotos=[];
const MAX_PHOTOS=3;

function handlePhotoFiles(input){
  const all=Array.from(input.files||[]);
  if(all.length>MAX_PHOTOS) alert('Maximum '+MAX_PHOTOS+' photos per upload. Only the first '+MAX_PHOTOS+' will be kept.');
  pendingPhotos=all.slice(0,MAX_PHOTOS);
  renderPhotoSlots();
}

function renderPhotoSlots(){
  const wrap=$('photoSlots');
  wrap.innerHTML='';
  for(let i=0;i<MAX_PHOTOS;i++){
    const slot=document.createElement('div');
    slot.className='photo-slot';
    if(pendingPhotos[i]){
      slot.classList.add('filled');
      const img=document.createElement('img');
      img.src=URL.createObjectURL(pendingPhotos[i]);
      slot.appendChild(img);
      const x=document.createElement('button');
      x.type='button';x.className='slot-x';x.textContent='✕';
      x.onclick=ev=>{ev.preventDefault();ev.stopPropagation();pendingPhotos.splice(i,1);syncFileInput();renderPhotoSlots();};
      slot.appendChild(x);
    }else{
      slot.textContent=String(i+1);
    }
    wrap.appendChild(slot);
  }
  $('slotCounter').textContent=pendingPhotos.length+' / '+MAX_PHOTOS+' selected';
}

function syncFileInput(){
  // Rebuild the input's file list from pendingPhotos so removed previews are not uploaded
  const dt=new DataTransfer();
  pendingPhotos.forEach(f=>dt.items.add(f));
  $('photoInput').files=dt.files;
}

function validatePhotos(){
  syncFileInput();
  if(pendingPhotos.length===0){alert('Please take or choose at least 1 photo (max 3).');return false;}
  const btn=$('photoForm').querySelector('button[type=submit]');
  btn.disabled=true;btn.innerHTML='Uploading…';
  return true;
}

// drag & drop on upload zone
const uz=$('uploadZone');
['dragover','dragenter'].forEach(ev=>uz.addEventListener(ev,e=>{e.preventDefault();uz.classList.add('drag');}));
['dragleave','drop'].forEach(ev=>uz.addEventListener(ev,e=>{e.preventDefault();uz.classList.remove('drag');}));
uz.addEventListener('drop',e=>{
  const dt=new DataTransfer();
  Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/')).slice(0,MAX_PHOTOS).forEach(f=>dt.items.add(f));
  $('photoInput').files=dt.files;
  handlePhotoFiles($('photoInput'));
});

/* ===== LIGHTBOX ===== */
function openLightbox(src){$('lbImg').src=src;$('lightbox').classList.add('open');}
function closeLightbox(){$('lightbox').classList.remove('open');$('lbImg').src='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLightbox();});

function deletePhoto(id){
  if(!confirm('Delete this progress photo permanently?'))return;
  const f=document.createElement('form');
  f.method='POST';
  f.innerHTML='<input type="hidden" name="action" value="delete_photo"><input type="hidden" name="photo_id" value="'+id+'">';
  document.body.appendChild(f);f.submit();
}

/* ===== PHOTO GALLERY ===== */
function renderGallery(){
  const selDate=$('logDate').value;
  const showAll=$('showAllPhotos').checked;
  const c=$('galleryContainer');
  const list=showAll?sitePhotos:sitePhotos.filter(p=>p.date===selDate);

  if(!list.length){
    c.innerHTML='<div class="photo-empty">'
      +'<div class="empty-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg></div>'
      +'<strong>No progress photos found</strong>'
      +'<div style="margin-top:4px;font-size:11px">'+(showAll?'No photos have been uploaded yet.':'No photos were uploaded for '+esc(selDate)+'.')+'</div>'
      +'</div>';
    return;
  }

  const byDate={};
  list.forEach(p=>{(byDate[p.date]=byDate[p.date]||[]).push(p);});
  const dates=Object.keys(byDate).sort((a,b)=>b.localeCompare(a));
  let html='';

  dates.forEach(d=>{
    html+='<div class="photo-day-head">'
      +'<span class="log-date">'+esc(d)+'</span>'
      +'<span class="count-chip">'+byDate[d].length+' photo(s)</span>'
      +'</div>';
    html+='<div class="photo-grid">';

    byDate[d].forEach(p=>{
      const safeSrc=esc(p.path);
      const safeJs=String(p.path||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
      html+='<div class="photo-card">'
        +'<div class="photo-card-image" id="photo-wrap-'+p.id+'">'
        +'<div class="photo-loading"><span class="photo-spinner"></span><span>Loading photo…</span></div>'
        +'<img src="'+safeSrc+'" alt="Progress photo" loading="lazy" onload="photoLoaded(this)" onerror="photoFailed(this)" onclick="openLightbox(\''+safeJs+'\')">'
        +'</div>'
        +'<button class="pc-del icon-btn" type="button" title="Delete photo" aria-label="Delete photo" onclick="deletePhoto('+p.id+')">'
        +'<svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>'
        +'</button>'
        +'<div class="pc-cap">'+(p.caption?esc(p.caption):'Site progress photo')+'</div>'
        +'</div>';
    });

    html+='</div>';
  });
  c.innerHTML=html;
}

function photoLoaded(img){
  const wrap=img.closest('.photo-card-image');
  if(wrap){
    wrap.classList.remove('is-error');
    wrap.classList.add('is-loaded');
  }
}

function photoFailed(img){
  const wrap=img.closest('.photo-card-image');
  if(!wrap) return;
  wrap.classList.remove('is-loaded');
  wrap.classList.add('is-error');
  const loading=wrap.querySelector('.photo-loading');
  if(loading) loading.innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5"></path><path d="M12 16h.01"></path></svg><span>Photo unavailable</span>';
}

renderGallery();

/* ===== ACTIVITY LOG ===== */
function renderLogs(){
  const selDate=$('logDate').value;
  const dayLogs=logsByRef.filter(g=>g.date===selDate);
  const c=$('logContainer');
  if(dayLogs.length===0){c.innerHTML='<div class="empty-log">No reports logged for '+esc(selDate)+'</div>';return;}
  let html='';
  dayLogs.forEach(g=>{
    const types=[...new Set(g.items.map(i=>i.type))];
    html+='<div class="log-group"><div class="log-group-head"><div class="lg-left"><span class="log-date">'+esc(g.date)+'</span>';
    types.forEach(t=>{html+='<span class="log-type '+(typeBadge[t]||'lt-material')+'">'+esc(t)+'</span>';});
    html+='<span style="font-size:11px;color:var(--ink2)">Ref: '+esc(g.ref)+'</span></div></div>';
    if(g.notes) html+='<div class="log-notes"><b>Note:</b> '+esc(g.notes)+'</div>';
    html+='<div class="log-items">';
    g.items.forEach(it=>{
      const label=it.type==='WORKER'?(it.role||it.item):(it.item||'—');
      const qty=it.qty>0?Number(it.qty).toLocaleString()+(it.unit?' '+it.unit:''):'';
      html+='<div class="log-item-row"><span class="n">'+esc(label)+'</span><span class="q">'+esc(qty)+'</span></div>';
    });
    html+='</div></div>';
  });
  c.innerHTML=html;
}
renderLogs();

/* print only the selected day, including progression + photos */
function printReport(){
  const selDate=$('logDate').value;
  const dayLogs=logsByRef.filter(g=>g.date===selDate);
  const dayProgress=progressLogs.filter(p=>p.date===selDate);
  const dayPhotos=sitePhotos.filter(p=>p.date===selDate);

  let workerRows='',materialRows='',equipRows='',notesHtml='',progRows='',photosHtml='';
  dayLogs.forEach(g=>{
    if(g.notes) notesHtml+='<div class="note-block"><b>Note:</b> '+esc(g.notes)+'</div>';
    g.items.forEach(it=>{
      if(it.type==='WORKER')workerRows+='<tr><td>'+esc(it.role||it.item||'—')+'</td><td style="text-align:right">'+Number(it.qty).toLocaleString()+'</td></tr>';
      if(it.type==='MATERIAL')materialRows+='<tr><td>'+esc(it.item||'—')+'</td><td style="text-align:right">'+Number(it.qty).toLocaleString()+(it.unit?' '+esc(it.unit):'')+'</td></tr>';
      if(it.type==='EQUIPMENT')equipRows+='<tr><td>'+esc(it.item||'—')+'</td><td style="text-align:right">'+Number(it.qty).toLocaleString()+'</td></tr>';
    });
  });
  dayProgress.forEach(p=>{
    progRows+='<tr><td>'+esc(p.milestone)+'</td><td style="text-align:right">'+p.progress+'%</td><td>'+esc(p.notes||'—')+'</td></tr>';
  });
  dayPhotos.forEach(p=>{
    photosHtml+='<figure class="ph"><img src="'+esc(p.path)+'"><figcaption>'+esc(p.caption||'Site progress')+'</figcaption></figure>';
  });

  const sec=(title,rows,cols)=>rows?'<h3>'+title+'</h3><table><thead><tr>'+cols+'</tr></thead><tbody>'+rows+'</tbody></table>':'';
  const html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Site Report '+esc(selDate)+'</title><style>'
    +'body{font-family:Arial,sans-serif;color:#1a231d;padding:24px;font-size:11px}'
    +'.head{border-bottom:3px double #166e45;padding-bottom:10px;margin-bottom:14px}'
    +'.head h1{font-size:16px;color:#166e45;margin:0}.head p{font-size:9px;color:#5a655c;margin:2px 0}'
    +'.meta{margin-bottom:12px;font-size:10px}.meta b{color:#166e45}'
    +'.note-block{background:#fff8e6;border:1px solid #d99a2b;border-left:5px solid #d99a2b;padding:10px;margin-bottom:10px;font-size:11px;font-weight:600}'
    +'h3{font-size:10px;color:#166e45;text-transform:uppercase;letter-spacing:.8px;margin:14px 0 6px;border-bottom:1px solid #166e45;padding-bottom:3px}'
    +'table{width:100%;border-collapse:collapse;margin-bottom:6px}th{background:#166e45;color:#fff;font-size:8px;text-transform:uppercase;text-align:left;padding:5px;border:1px solid #166e45}td{padding:4px 5px;border:1px solid #cfd8cd}tr:nth-child(even) td{background:#fafcf9}'
    +'.photo-strip{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}'
    +'.ph{margin:0;width:31%;min-width:150px;border:1px solid #cfd8cd;border-radius:6px;overflow:hidden;page-break-inside:avoid}'
    +'.ph img{width:100%;height:120px;object-fit:contain;background:#f4f6f3;display:block}'
    +'.ph figcaption{font-size:8px;padding:4px 6px;color:#5a655c;background:#fafcf9;border-top:1px solid #cfd8cd}'
    +'.foot{margin-top:16px;border-top:1px solid #ddd;padding-top:6px;font-size:8px;color:#9aa79c;text-align:center}'
    +'</style></head><body>'
    +'<div class="head"><h1>'+esc(coName)+'</h1><p>'+esc(coAddr)+'</p><p>Daily Site Report</p></div>'
    +'<div class="meta">Project: <b>'+esc(projMeta.name)+'</b> &nbsp;|&nbsp; Code: <b>'+esc(projMeta.code)+'</b> &nbsp;|&nbsp; Site: <b>'+esc(projMeta.site)+'</b><br>Report Date: <b>'+esc(selDate)+'</b> &nbsp;|&nbsp; Overall Progress: <b>'+projMeta.progress+'%</b></div>'
    +notesHtml
    +sec('Project Progression',progRows,'<th>Milestone</th><th style="text-align:right">Progress</th><th>Notes</th>')
    +sec('Workers by Role',workerRows,'<th>Role</th><th style="text-align:right">Count</th>')
    +sec('Materials Consumed',materialRows,'<th>Material</th><th style="text-align:right">Quantity</th>')
    +sec('Equipment Used',equipRows,'<th>Equipment</th><th style="text-align:right">Qty</th>')
    +(photosHtml?'<h3>Progress Photos ('+dayPhotos.length+')</h3><div class="photo-strip">'+photosHtml+'</div>':'')
    +'<div class="foot">Generated '+new Date().toLocaleString()+' &middot; '+esc(coName)+'</div>'
    +'</body></html>';
  const w=window.open('','_blank');
  w.document.write(html);w.document.close();w.focus();
  setTimeout(()=>w.print(),600);
}
</script>
</body>
</html>
