<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: ../index.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== ENSURE SCHEMA ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_equipment (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,status VARCHAR(20) DEFAULT 'Available',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS category VARCHAR(60) NULL AFTER name");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS serial_no VARCHAR(60) NULL AFTER category");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS purchase_date DATE NULL AFTER serial_no");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS `condition` VARCHAR(20) DEFAULT 'Good' AFTER purchase_date");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS assigned_to VARCHAR(120) NULL AFTER `condition`");
    $pdo->exec("ALTER TABLE construction_equipment ADD COLUMN IF NOT EXISTS next_maintenance DATE NULL AFTER status");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_equipment_usage (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,equipment_id INT UNSIGNED NOT NULL,project_id INT UNSIGNED NULL,assigned_to VARCHAR(120) NULL,date_out DATE NOT NULL,date_returned DATE NULL,status ENUM('In Use','Returned') DEFAULT 'In Use',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $ignore){}

/* ===== ADD EQUIPMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_add_equipment') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $name=trim($_POST['name']??''); $cat=trim($_POST['category']??''); $serial=trim($_POST['serial_no']??'');
        $pd=$_POST['purchase_date']??''; $cond=$_POST['condition']??'Good'; $status=$_POST['status']??'Available'; $nm=$_POST['next_maintenance']??'';
        if(mb_strlen($name)<2) flash('error','Equipment name is required.');
        else{
            try{
                $pdo->prepare("INSERT INTO construction_equipment (name,category,serial_no,purchase_date,`condition`,status,next_maintenance) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name,$cat?:null,$serial?:null,$pd?:null,$cond,$status,$nm?:null]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'ADD_EQUIPMENT','equipment',(int)$pdo->lastInsertId(),"Added equipment: $name"]);}catch(Exception $ignore){}
                flash('success','Equipment "'.$name.'" added.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        }
        header('Location: construction_equipment.php'); exit;
    }
}

/* ===== UPDATE EQUIPMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update_equipment') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['equipment_id']??0); $name=trim($_POST['name']??''); $cat=trim($_POST['category']??'');
        $serial=trim($_POST['serial_no']??''); $pd=$_POST['purchase_date']??''; $cond=$_POST['condition']??'Good';
        $status=$_POST['status']??'Available'; $nm=$_POST['next_maintenance']??'';
        if($id>0 && mb_strlen($name)>=2){
            try{
                $pdo->prepare("UPDATE construction_equipment SET name=?,category=?,serial_no=?,purchase_date=?,`condition`=?,status=?,next_maintenance=? WHERE id=?")
                    ->execute([$name,$cat?:null,$serial?:null,$pd?:null,$cond,$status,$nm?:null,$id]);
                flash('success','Equipment updated.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Equipment name is required.');
        header('Location: construction_equipment.php'); exit;
    }
}

/* ===== DELETE EQUIPMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete_equipment') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['equipment_id']??0);
        if($id>0){ try{$pdo->prepare("DELETE FROM construction_equipment WHERE id=?")->execute([$id]); flash('success','Equipment deleted.');}catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); } }
        header('Location: construction_equipment.php'); exit;
    }
}

/* ===== ASSIGN EQUIPMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_assign') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $eid=(int)($_POST['equipment_id']??0); $pid=(int)($_POST['project_id']??0);
        $assignedTo=trim($_POST['assigned_to']??''); $dateOut=$_POST['date_out']??date('Y-m-d');
        if($eid>0){
            try{
                $pdo->prepare("UPDATE construction_equipment SET status='In Use',assigned_to=? WHERE id=?")->execute([$assignedTo?:null,$eid]);
                $pdo->prepare("INSERT INTO construction_equipment_usage (equipment_id,project_id,assigned_to,date_out,status) VALUES (?,?,?,?, 'In Use')")
                    ->execute([$eid,$pid>0?$pid:null,$assignedTo?:null,$dateOut]);
                flash('success','Equipment assigned.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Equipment not found.');
        header('Location: construction_equipment.php'); exit;
    }
}

/* ===== RETURN EQUIPMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_return') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $eid=(int)($_POST['equipment_id']??0);
        if($eid>0){
            try{
                $pdo->prepare("UPDATE construction_equipment_usage SET date_returned=CURDATE(),status='Returned' WHERE equipment_id=? AND status='In Use'")->execute([$eid]);
                $pdo->prepare("UPDATE construction_equipment SET status='Available',assigned_to=NULL WHERE id=?")->execute([$eid]);
                flash('success','Equipment returned.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Equipment not found.');
        header('Location: construction_equipment.php'); exit;
    }
}

/* ===== FETCH EQUIPMENT ===== */
$equipment=[];
try{
    $equipment=$pdo->query("SELECT e.*,
        (SELECT COUNT(*) FROM construction_equipment_usage u WHERE u.equipment_id=e.id) AS usage_count
      FROM construction_equipment e ORDER BY e.name ASC")->fetchAll();
}catch(Exception $ignore){}

/* ===== USAGE LOG ===== */
$usage=[];
try{
    $usage=$pdo->query("SELECT u.*, e.name AS equipment_name, e.category, p.name AS project_name
      FROM construction_equipment_usage u
      LEFT JOIN construction_equipment e ON e.id=u.equipment_id
      LEFT JOIN construction_projects p ON p.id=u.project_id
      ORDER BY u.date_out DESC, u.id DESC LIMIT 100")->fetchAll();
}catch(Exception $ignore){}

/* ===== PROJECTS FOR ASSIGN ===== */
$projects=[]; try{ $projects=$pdo->query("SELECT id,name FROM construction_projects ORDER BY name ASC")->fetchAll(); }catch(Exception $ignore){}

/* ===== STATS ===== */
$totalEq=count($equipment); $available=0;$inUse=0;$maint=0;$overdue=0;
$today=date('Y-m-d');
foreach($equipment as $eq){
    if($eq['status']==='Available')$available++;
    elseif($eq['status']==='In Use')$inUse++;
    elseif($eq['status']==='Maintenance')$maint++;
    if($eq['next_maintenance'] && strtotime($eq['next_maintenance'])<strtotime($today))$overdue++;
}

$flash=get_flash();
function statusPill(string $s): string {
    if($s==='Available')return '<span class="pill p-in">Available</span>';
    if($s==='In Use')return '<span class="pill p-use">In Use</span>';
    return '<span class="pill p-low">Maintenance</span>';
}
function condPill(?string $c): string {
    $c=$c??'Good';
    if($c==='Good')return '<span class="pill p-in">Good</span>';
    if($c==='Fair')return '<span class="pill p-low">Fair</span>';
    return '<span class="pill p-out">Poor</span>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Equipment &amp; Machines | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-600-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-700-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-800-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-400-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-500-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-600-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-700-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-800-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/jetbrains-mono@latest/latin-500-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/jetbrains-mono@latest/latin-700-normal.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f4f4f2;--surface:#fff;--surface2:#f7f7f4;--ink:#1c2226;--ink2:#626b70;--line:#e1e3dd;--line2:#ccd2c8;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--amber:#d97a16;--amber2:#b45f0a;--amber-soft:#fdeed7;--steel:#2b3439;--steel2:#1f262a;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(28,34,38,.05),0 12px 32px rgba(28,34,38,.10);--shadow-sm:0 1px 2px rgba(28,34,38,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px;--hazard:repeating-linear-gradient(45deg,var(--amber) 0 12px,var(--steel) 12px 24px)}
[data-theme="dark"]{--bg:#0d1214;--surface:#161d20;--surface2:#1a2428;--ink:#e8ecec;--ink2:#8b958f;--line:#263134;--line2:#334044;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--amber:#f09a3a;--amber2:#d97a16;--amber-soft:#3a2a13;--steel:#39444a;--steel2:#2b3439;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 16px 40px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(800px 500px at 88% -10%,color-mix(in srgb,var(--amber) 13%,transparent),transparent 60%),radial-gradient(600px 400px at -10% 40%,color-mix(in srgb,var(--steel) 10%,transparent),transparent 60%)}
svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.container{max-width:1280px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}
::selection{background:color-mix(in srgb,var(--amber) 30%,transparent)}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:38px;height:38px;object-fit:contain}
.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}
.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}
.back-link:hover{color:var(--amber);border-color:var(--amber)}
.back-link svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}
.badge.amber{background:var(--amber-soft);color:var(--amber2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--amber);border-color:var(--amber);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

.page-header{margin:24px 0 4px;display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap}
.ph-strip{height:6px;background:var(--hazard);border-radius:99px;margin-bottom:18px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:26px;height:26px;stroke:var(--amber2)}
.page-header h1 em{font-style:normal;color:var(--amber2)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:5px;font-size:13.5px}
.ph-btns{display:flex;gap:10px;flex-wrap:wrap}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:20px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}
.stat .ic svg{width:21px;height:21px}
.stat .ic.a{background:var(--amber-soft)}.stat .ic.a svg{stroke:var(--amber2)}
.stat .ic.g{background:var(--green-soft)}.stat .ic.g svg{stroke:var(--green)}
.stat .ic.b{background:var(--blue-soft)}.stat .ic.b svg{stroke:var(--blue)}
.stat .ic.r{background:var(--red-soft)}.stat .ic.r svg{stroke:var(--red)}
.stat b{font-family:var(--fd);font-size:20px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.stat sub{font-size:10px;color:var(--ink2);font-weight:600}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn svg{width:16px;height:16px}
.btn-primary{background:linear-gradient(135deg,var(--amber),var(--amber2));color:#fff;box-shadow:0 6px 16px color-mix(in srgb,var(--amber) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--amber);color:var(--amber2)}
.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{height:34px;padding:0 12px;font-size:11.5px;border-radius:9px}

/* TABS */
.tabs{display:flex;gap:8px;margin:22px 0 16px;flex-wrap:wrap}
.tab-btn{padding:11px 20px;border-radius:12px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink2);font-family:var(--fd);font-weight:700;font-size:13px;transition:.2s;display:inline-flex;align-items:center;gap:8px}
.tab-btn svg{width:16px;height:16px}
.tab-btn:hover{border-color:var(--amber);color:var(--amber2)}
.tab-btn.active{background:var(--amber);color:#fff;border-color:var(--amber);box-shadow:0 6px 16px color-mix(in srgb,var(--amber) 30%,transparent)}
.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadein .3s}
@keyframes fadein{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--amber-soft);color:var(--amber2);padding:4px 11px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:900px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:12px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.pill{font-size:9.5px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;white-space:nowrap}
.p-in{background:var(--green-soft);color:var(--green)}
.p-use{background:var(--blue-soft);color:var(--blue)}
.p-low{background:var(--amber-soft);color:var(--amber2)}
.p-out{background:var(--red-soft);color:var(--red)}
.empty{padding:60px 20px;text-align:center;color:var(--ink2)}
.empty .big{font-size:46px;margin-bottom:10px}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px;display:block;margin-top:4px}
.overdue-txt{color:var(--red);font-weight:700}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:150;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.m-head{padding:20px 24px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.m-head h3{font-family:var(--fd);font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px}
.m-head h3 svg{width:20px;height:20px;stroke:var(--amber2)}
.m-x{width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:16px;cursor:pointer;display:grid;place-items:center}
.m-body{padding:20px 24px 24px}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.span2{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:6px}
.fl{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 13px;height:46px;transition:.2s}
.ctrl:focus-within{border-color:var(--amber);box-shadow:0 0 0 4px color-mix(in srgb,var(--amber) 12%,transparent);background:var(--surface)}
.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;min-width:0}
.ctrl select{cursor:pointer;appearance:none}
.ctrl textarea{padding:11px 0;height:auto;min-height:56px;resize:vertical}
.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.m-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
.m-actions .btn{height:46px}

.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--amber);border-radius:12px;padding:13px 17px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}

footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}

/* ===== PRINT ===== */
#printSheet{display:none}
@media print{
  @page{size:A4;margin:11mm}
  body{background:#fff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body::before,#app,.toasts,.modal-overlay,.topbar{display:none!important}
  #printSheet{display:block!important;color:#1a231d;font-family:var(--fb)}
  .pe-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #b45f0a;padding-bottom:8pt;margin-bottom:10pt}
  .pe-logo{width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .pe-co{flex:1}
  .pe-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#b45f0a;line-height:1.15}
  .pe-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .pe-doc{text-align:right}
  .pe-doc h2{font-size:8.5pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#b45f0a;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .pe-doc p{font-size:6.5pt;color:#7a857c;margin-top:3pt}
  .pe-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:6pt;margin:9pt 0}
  .pe-sumbox{border:1pt solid #cfd8cd;border-radius:5pt;padding:6pt 8pt;text-align:center;background:#fafcf9}
  .pe-sumbox b{font-family:var(--fm);font-size:11pt;font-weight:700;display:block;color:#b45f0a}
  .pe-sumbox span{font-size:5.5pt;font-weight:800;letter-spacing:.8pt;color:#667268;text-transform:uppercase}
  .pe-table{width:100%;border-collapse:collapse;font-size:7pt;margin-top:4pt}
  .pe-table th{background:#b45f0a;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.5pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #b45f0a}
  .pe-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .pe-table tr:nth-child(even) td{background:#fafcf9}
  .pe-h{font-family:var(--fd);font-size:9pt;font-weight:800;color:#b45f0a;margin:12pt 0 4pt;text-transform:uppercase;letter-spacing:.8pt}
  .pe-sigs{display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid}
  .pe-sig{text-align:center}
  .pe-sigline{height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt}
  .pe-sig span{font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block}
  .pe-foot{margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
}

@media(max-width:1000px){.stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  .topbar .badge{display:none}
  .brand strong{font-size:13px}
  .stats{grid-template-columns:1fr 1fr;gap:10px}
  .fgrid{grid-template-columns:1fr}
  .tabs{gap:6px}
  .tab-btn{padding:10px 14px;font-size:12px}
}
@media(max-width:480px){.stats{grid-template-columns:1fr}.ph-btns{width:100%}.ph-btns .btn{flex:1}}
</style>
</head>
<body>

<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="admin_construction_dashboard.php" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Construction Dashboard</a>
  <span class="badge amber">🔧 EQUIPMENT</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container" id="app">
  <div class="page-header">
    <div>
      <div class="ph-strip"></div>
      <h1><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Equipment &amp; <em>Machines</em></h1>
      <p>Tools &amp; machines inventory, usage status, and maintenance tracking.</p>
    </div>
    <div class="ph-btns">
      <button class="btn btn-ghost" id="btnPrintEq"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Inventory</button>
      <button class="btn btn-primary" id="btnAddEq"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>Add Equipment</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div><div><b><?php echo $totalEq; ?></b><span>Total Equipment</span><sub>tools &amp; machines</sub></div></div>
    <div class="stat"><div class="ic g"><svg viewBox="0 0 24 24" fill="none"><path d="M20 6 9 17l-5-5"/></svg></div><div><b><?php echo $available; ?></b><span>Available</span><sub>ready to assign</sub></div></div>
    <div class="stat"><div class="ic b"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg></div><div><b><?php echo $inUse; ?></b><span>In Use</span><sub>assigned on site</sub></div></div>
    <div class="stat"><div class="ic r"><svg viewBox="0 0 24 24" fill="none"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></div><div><b><?php echo $maint; ?></b><span>Maintenance</span><sub><?php echo $overdue; ?> overdue</sub></div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="equipment"><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Equipment Inventory</button>
    <button class="tab-btn" data-tab="usage"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Usage Log</button>
  </div>

  <!-- EQUIPMENT TAB -->
  <div class="tab-panel active" id="tab-equipment">
    <div class="card">
      <div class="card-head"><h2>Equipment Inventory</h2><span class="count-pill"><?php echo count($equipment); ?> items</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Equipment</th><th>Category</th><th>Serial No.</th><th>Condition</th><th>Status</th><th>Next Maintenance</th><th>Assigned To</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($equipment)): ?>
            <tr><td colspan="8"><div class="empty"><div class="big">🔧</div><p>No equipment recorded</p><small>Add your first tool or machine to begin tracking.</small></div></td></tr>
          <?php else: foreach($equipment as $eq):
            $overdue = $eq['next_maintenance'] && strtotime($eq['next_maintenance'])<strtotime(date('Y-m-d'));
          ?>
            <tr>
              <td style="font-weight:800"><?php echo e($eq['name']); ?></td>
              <td><?php echo e($eq['category']??'—'); ?></td>
              <td class="mono"><?php echo e($eq['serial_no']??'—'); ?></td>
              <td><?php echo condPill($eq['condition']??'Good'); ?></td>
              <td><?php echo statusPill($eq['status']); ?></td>
              <td style="font-size:12px" class="<?php echo $overdue?'overdue-txt':''; ?>"><?php echo $eq['next_maintenance']?date('d M Y',strtotime($eq['next_maintenance'])):'—'; ?><?php echo $overdue?' (overdue)':''; ?></td>
              <td style="font-size:12px"><?php echo e($eq['assigned_to']??'—'); ?></td>
              <td style="white-space:nowrap">
                <?php if($eq['status']==='In Use'): ?>
                  <form method="post" style="display:inline"><input type="hidden" name="action" value="_return"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="equipment_id" value="<?php echo (int)$eq['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm">Return</button></form>
                <?php else: ?>
                  <button class="btn btn-primary btn-sm btn-assign" data-id="<?php echo (int)$eq['id']; ?>" data-name="<?php echo e($eq['name']); ?>">Assign</button>
                <?php endif; ?>
                <button class="btn btn-ghost btn-sm btn-editeq" data-id="<?php echo (int)$eq['id']; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                <button class="btn btn-danger btn-sm btn-deleq" data-id="<?php echo (int)$eq['id']; ?>" data-name="<?php echo e($eq['name']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- USAGE TAB -->
  <div class="tab-panel" id="tab-usage">
    <div class="card">
      <div class="card-head"><h2>Equipment Usage Log</h2><span class="count-pill"><?php echo count($usage); ?> records</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date Out</th><th>Equipment</th><th>Project</th><th>Assigned To</th><th>Date Returned</th><th>Status</th></tr></thead>
        <tbody>
          <?php if(empty($usage)): ?>
            <tr><td colspan="6"><div class="empty"><div class="big">📋</div><p>No usage recorded yet</p><small>Assign equipment to a project to log usage.</small></div></td></tr>
          <?php else: foreach($usage as $u): ?>
            <tr>
              <td style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y',strtotime($u['date_out'])); ?></td>
              <td style="font-weight:800"><?php echo e($u['equipment_name']??'—'); ?></td>
              <td><?php echo e($u['project_name']??'General / Unassigned'); ?></td>
              <td><?php echo e($u['assigned_to']??'—'); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo $u['date_returned']?date('d M Y',strtotime($u['date_returned'])):'—'; ?></td>
              <td><?php echo $u['status']==='In Use'?'<span class="pill p-use">In Use</span>':'<span class="pill p-in">Returned</span>'; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- ADD/EDIT EQUIPMENT MODAL -->
<div class="modal-overlay" id="eqModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg><span id="eqTitle">Add Equipment</span></h3><button class="m-x" onclick="closeModal('eqModal')">&times;</button></div>
  <div class="m-body"><form method="post" id="eqForm">
    <input type="hidden" name="action" id="eqAction" value="_add_equipment">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="equipment_id" id="eqId">
    <div class="fgrid">
      <div class="field span2"><label class="fl">Equipment Name <b>*</b></label><div class="ctrl"><input name="name" id="e_name" placeholder="e.g. Concrete Mixer" required></div></div>
      <div class="field"><label class="fl">Category</label><div class="ctrl"><input name="category" id="e_cat" placeholder="e.g. Machinery, Tool"></div></div>
      <div class="field"><label class="fl">Serial No.</label><div class="ctrl"><input name="serial_no" id="e_serial" placeholder="Serial number"></div></div>
      <div class="field"><label class="fl">Purchase Date</label><div class="ctrl"><input name="purchase_date" id="e_pd" type="date"></div></div>
      <div class="field"><label class="fl">Condition</label><div class="ctrl"><select name="condition" id="e_cond"><option>Good</option><option>Fair</option><option>Poor</option></select></div></div>
      <div class="field"><label class="fl">Status</label><div class="ctrl"><select name="status" id="e_status"><option>Available</option><option>In Use</option><option>Maintenance</option></select></div></div>
      <div class="field"><label class="fl">Next Maintenance</label><div class="ctrl"><input name="next_maintenance" id="e_nm" type="date"></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('eqModal')">Cancel</button><button type="submit" class="btn btn-primary"><span id="eqSaveLbl">Save Equipment</span></button></div>
  </form></div>
</div></div>

<!-- ASSIGN MODAL -->
<div class="modal-overlay" id="assignModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Assign Equipment</h3><button class="m-x" onclick="closeModal('assignModal')">&times;</button></div>
  <div class="m-body"><form method="post">
    <input type="hidden" name="action" value="_assign">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="equipment_id" id="a_equipment_id">
    <div class="fgrid">
      <div class="field span2"><label class="fl">Equipment</label><div class="ctrl"><input id="a_equipment_name" readonly style="font-weight:700"></div></div>
      <div class="field span2"><label class="fl">Assign To Project</label><div class="ctrl"><select name="project_id"><option value="">General / Unassigned</option><?php foreach($projects as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label class="fl">Assigned To (Person)</label><div class="ctrl"><input name="assigned_to" placeholder="Person's name"></div></div>
      <div class="field"><label class="fl">Date Out</label><div class="ctrl"><input name="date_out" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('assignModal')">Cancel</button><button type="submit" class="btn btn-primary">Assign Equipment</button></div>
  </form></div>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-head"><h3 style="color:var(--red)"><svg viewBox="0 0 24 24" fill="none" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Equipment?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:13px;margin-bottom:18px">This will permanently remove <strong id="del_name"></strong>.</p>
    <form method="post"><input type="hidden" name="action" value="_delete_equipment"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="equipment_id" id="del_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Equipment &amp; Machines</div></footer>
<div class="toasts" id="toastWrap"></div>

<!-- EQUIPMENT INVENTORY PRINT SHEET (server-rendered) -->
<div id="printSheet">
  <div class="pe-head">
    <img class="pe-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
    <div class="pe-co"><h1><?php echo e($company['company_name']); ?></h1><p><?php echo e($company['company_address']); ?></p><p>Construction &amp; Projects Department — Equipment Unit</p></div>
    <div class="pe-doc"><h2>EQUIPMENT INVENTORY</h2><p>Date: <?php echo date('d M Y'); ?></p><p>Generated: <?php echo date('d M Y, H:i'); ?></p></div>
  </div>
  <div class="pe-sum">
    <div class="pe-sumbox"><b><?php echo $totalEq; ?></b><span>Equipment</span></div>
    <div class="pe-sumbox"><b><?php echo $available; ?></b><span>Available</span></div>
    <div class="pe-sumbox"><b><?php echo $inUse; ?></b><span>In Use</span></div>
    <div class="pe-sumbox"><b><?php echo $maint; ?></b><span>Maintenance</span></div>
  </div>
  <div class="pe-h">Equipment Inventory</div>
  <table class="pe-table"><thead><tr><th>Equipment</th><th>Category</th><th>Serial No.</th><th>Condition</th><th>Status</th><th>Next Maintenance</th><th>Assigned To</th></tr></thead><tbody>
    <?php if(empty($equipment)): ?><tr><td colspan="7">No equipment recorded.</td></tr>
    <?php else: foreach($equipment as $eq): ?>
      <tr><td><?php echo e($eq['name']); ?></td><td><?php echo e($eq['category']??'—'); ?></td><td><?php echo e($eq['serial_no']??'—'); ?></td><td><?php echo e($eq['condition']??'Good'); ?></td><td><?php echo e($eq['status']); ?></td><td><?php echo $eq['next_maintenance']?date('d M Y',strtotime($eq['next_maintenance'])):'—'; ?></td><td><?php echo e($eq['assigned_to']??'—'); ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody></table>
  <div class="pe-sigs">
    <div class="pe-sig"><div class="pe-sigline"></div><span>Prepared By (Store Officer)</span></div>
    <div class="pe-sig"><div class="pe-sigline"></div><span>Authorised By (Manager)</span></div>
  </div>
  <div class="pe-foot"><span><?php echo e($company['company_name']); ?> — EQUIPMENT INVENTORY (CONFIDENTIAL)</span><span>Generated <?php echo date('d M Y, H:i'); ?></span></div>
</div>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}
function openModal(id){$(id).classList.add('open')}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* TABS */
document.querySelectorAll('.tab-btn').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(x=>x.classList.remove('active'));
  b.classList.add('active');$('tab-'+b.dataset.tab).classList.add('active');
}));

/* equipment data for edit */
const equipmentData=<?php echo json_encode(array_map(function($m){return['id'=>(int)$m['id'],'name'=>$m['name'],'category'=>$m['category']??'','serial_no'=>$m['serial_no']??'','purchase_date'=>$m['purchase_date']??'','condition'=>$m['condition']??'Good','status'=>$m['status'],'next_maintenance'=>$m['next_maintenance']??''];},$equipment),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ADD equipment */
$('btnAddEq').addEventListener('click',()=>{
  $('eqForm').reset();$('eqAction').value='_add_equipment';$('eqId').value='';
  $('eqTitle').textContent='Add Equipment';$('eqSaveLbl').textContent='Save Equipment';
  $('e_status').value='Available';$('e_cond').value='Good';
  openModal('eqModal');
});
/* EDIT equipment */
document.querySelectorAll('.btn-editeq').forEach(b=>b.addEventListener('click',()=>{
  const m=equipmentData.find(x=>x.id==b.dataset.id);if(!m)return;
  $('eqAction').value='_update_equipment';$('eqId').value=m.id;
  $('eqTitle').textContent='Edit Equipment';$('eqSaveLbl').textContent='Save Changes';
  $('e_name').value=m.name;$('e_cat').value=m.category;$('e_serial').value=m.serial_no;
  $('e_pd').value=m.purchase_date;$('e_cond').value=m.condition;$('e_status').value=m.status;$('e_nm').value=m.next_maintenance;
  openModal('eqModal');
}));
/* ASSIGN equipment */
document.querySelectorAll('.btn-assign').forEach(b=>b.addEventListener('click',()=>{
  $('a_equipment_id').value=b.dataset.id;$('a_equipment_name').value=b.dataset.name;
  openModal('assignModal');
}));
/* DELETE equipment */
document.querySelectorAll('.btn-deleq').forEach(b=>b.addEventListener('click',()=>{
  $('del_id').value=b.dataset.id;$('del_name').textContent=b.dataset.name;openModal('deleteModal');
}));

/* PRINT */
$('btnPrintEq').addEventListener('click',()=>window.print());

const FLASH=<?php echo json_encode($flash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
<?php if($flash): ?>
window.addEventListener('DOMContentLoaded',()=>toast(<?php echo json_encode($flash['msg'],JSON_HEX_TAG|JSON_HEX_QUOT); ?>,'<?php echo $flash['type']; ?>'));
<?php endif; ?>
</script>
</body>
</html>