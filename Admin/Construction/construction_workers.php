<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }
function fmtN($n): string { return '₦'.number_format((float)$n,2); }
function fmtCompact($n): string { $n=(float)$n; if($n>=1000000)return '₦'.number_format($n/1000000,1).'M'; if($n>=1000)return '₦'.number_format($n/1000,1).'K'; return '₦'.number_format($n,0); }

/* ===== ENSURE SCHEMA ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_workers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,trade VARCHAR(80) NULL,status VARCHAR(20) DEFAULT 'On Site',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE construction_workers ADD COLUMN IF NOT EXISTS worker_type ENUM('Daily','Contract') DEFAULT 'Daily' AFTER trade");
    $pdo->exec("ALTER TABLE construction_workers ADD COLUMN IF NOT EXISTS daily_rate DECIMAL(12,2) DEFAULT 0 AFTER worker_type");
    $pdo->exec("ALTER TABLE construction_workers ADD COLUMN IF NOT EXISTS contract_amount DECIMAL(16,2) DEFAULT 0 AFTER daily_rate");
    $pdo->exec("ALTER TABLE construction_workers ADD COLUMN IF NOT EXISTS phone VARCHAR(25) NULL AFTER contract_amount");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_worker_payments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,worker_id INT UNSIGNED NOT NULL,amount DECIMAL(12,2) NOT NULL,paid_date DATE NOT NULL,payment_type VARCHAR(30) DEFAULT 'Daily Wage',notes VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $ignore){}

/* ===== ADD WORKER ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_add_worker') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $name=trim($_POST['name']??''); $trade=trim($_POST['trade']??''); $type=$_POST['worker_type']??'Daily';
        $rate=floatval($_POST['daily_rate']??0); $cAmt=floatval($_POST['contract_amount']??0);
        $phone=trim($_POST['phone']??''); $status=$_POST['status']??'On Site';
        if(mb_strlen($name)<2) flash('error','Worker name is required.');
        else{
            try{
                $pdo->prepare("INSERT INTO construction_workers (name,trade,worker_type,daily_rate,contract_amount,phone,status) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name,$trade?:null,$type,$rate,$cAmt,$phone?:null,$status]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'ADD_WORKER','worker',(int)$pdo->lastInsertId(),"Added worker: $name"]);}catch(Exception $ignore){}
                flash('success','Worker "'.$name.'" added.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        }
        header('Location: construction_workers.php'); exit;
    }
}

/* ===== UPDATE WORKER ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update_worker') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['worker_id']??0); $name=trim($_POST['name']??''); $trade=trim($_POST['trade']??'');
        $type=$_POST['worker_type']??'Daily'; $rate=floatval($_POST['daily_rate']??0); $cAmt=floatval($_POST['contract_amount']??0);
        $phone=trim($_POST['phone']??''); $status=$_POST['status']??'On Site';
        if($id>0 && mb_strlen($name)>=2){
            try{
                $pdo->prepare("UPDATE construction_workers SET name=?,trade=?,worker_type=?,daily_rate=?,contract_amount=?,phone=?,status=? WHERE id=?")
                    ->execute([$name,$trade?:null,$type,$rate,$cAmt,$phone?:null,$status,$id]);
                flash('success','Worker updated.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Worker name is required.');
        header('Location: construction_workers.php'); exit;
    }
}

/* ===== DELETE WORKER ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete_worker') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['worker_id']??0);
        if($id>0){ try{$pdo->prepare("DELETE FROM construction_workers WHERE id=?")->execute([$id]); flash('success','Worker deleted.');}catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); } }
        header('Location: construction_workers.php'); exit;
    }
}

/* ===== RECORD PAYMENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_pay') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $wid=(int)($_POST['worker_id']??0); $amt=floatval($_POST['amount']??0);
        $paidDate=$_POST['paid_date']??date('Y-m-d'); $ptype=$_POST['payment_type']??'Daily Wage'; $notes=trim($_POST['notes']??'');
        if($wid>0 && $amt>0){
            try{
                $pdo->prepare("INSERT INTO construction_worker_payments (worker_id,amount,paid_date,payment_type,notes) VALUES (?,?,?,?,?)")
                    ->execute([$wid,$amt,$paidDate,$ptype,$notes?:null]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'PAY_WORKER','worker',$wid,"Paid ".fmtN($amt)]);}catch(Exception $ignore){}
                flash('success','Payment of '.fmtN($amt).' recorded.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Select a worker and enter a valid amount.');
        header('Location: construction_workers.php'); exit;
    }
}

/* ===== FETCH WORKERS WITH AGGREGATES ===== */
$workers=[];
try{
    $workers=$pdo->query("SELECT w.*,
        (SELECT IFNULL(SUM(p.amount),0) FROM construction_worker_payments p WHERE p.worker_id=w.id) AS total_paid,
        (SELECT COUNT(*) FROM construction_worker_payments p WHERE p.worker_id=w.id) AS pay_count,
        (SELECT MAX(p.paid_date) FROM construction_worker_payments p WHERE p.worker_id=w.id) AS last_paid
      FROM construction_workers w ORDER BY w.name ASC")->fetchAll();
}catch(Exception $ignore){}

/* ===== PAYMENTS LOG ===== */
$payments=[];
try{
    $payments=$pdo->query("SELECT p.*, w.name AS worker_name, w.trade FROM construction_worker_payments p LEFT JOIN construction_workers w ON w.id=p.worker_id ORDER BY p.paid_date DESC, p.id DESC LIMIT 100")->fetchAll();
}catch(Exception $ignore){}

/* ===== STATS ===== */
$totalWorkers=count($workers);
$dailyW=0;$contractW=0;$onSite=0;
foreach($workers as $w){ if(($w['worker_type']??'Daily')==='Daily')$dailyW++; else $contractW++; if($w['status']==='On Site')$onSite++; }
$paidToday=0.0;$totalPaid=0.0;
foreach($payments as $p){ $totalPaid+=(float)$p['amount']; if(date('Y-m-d',strtotime($p['paid_date']))===date('Y-m-d'))$paidToday+=(float)$p['amount']; }

$flash=get_flash();
function typePill(string $t): string {
    return $t==='Contract' ? '<span class="pill p-contract">Contract</span>' : '<span class="pill p-daily">Daily</span>';
}
function statusPill(string $s): string {
    if($s==='On Site')return '<span class="pill p-in">On Site</span>';
    if($s==='Off Duty')return '<span class="pill p-low">Off Duty</span>';
    return '<span class="pill p-out">Terminated</span>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Workers &amp; Payroll | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
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
table{width:100%;border-collapse:collapse;min-width:860px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:12px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.pill{font-size:9.5px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;white-space:nowrap}
.p-daily{background:var(--amber-soft);color:var(--amber2)}
.p-contract{background:var(--blue-soft);color:var(--blue)}
.p-in{background:var(--green-soft);color:var(--green)}
.p-low{background:var(--amber-soft);color:var(--amber2)}
.p-out{background:var(--red-soft);color:var(--red)}
.empty{padding:60px 20px;text-align:center;color:var(--ink2)}
.empty .big{font-size:46px;margin-bottom:10px}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px;display:block;margin-top:4px}
.rate-txt{font-family:var(--fm);font-weight:700;color:var(--amber2)}

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
  .pw-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #b45f0a;padding-bottom:8pt;margin-bottom:10pt}
  .pw-logo{width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .pw-co{flex:1}
  .pw-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#b45f0a;line-height:1.15}
  .pw-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .pw-doc{text-align:right}
  .pw-doc h2{font-size:8.5pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#b45f0a;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .pw-doc p{font-size:6.5pt;color:#7a857c;margin-top:3pt}
  .pw-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:6pt;margin:9pt 0}
  .pw-sumbox{border:1pt solid #cfd8cd;border-radius:5pt;padding:6pt 8pt;text-align:center;background:#fafcf9}
  .pw-sumbox b{font-family:var(--fm);font-size:11pt;font-weight:700;display:block;color:#b45f0a}
  .pw-sumbox span{font-size:5.5pt;font-weight:800;letter-spacing:.8pt;color:#667268;text-transform:uppercase}
  .pw-table{width:100%;border-collapse:collapse;font-size:7pt;margin-top:4pt}
  .pw-table th{background:#b45f0a;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.5pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #b45f0a}
  .pw-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .pw-table tr:nth-child(even) td{background:#fafcf9}
  .pw-h{font-family:var(--fd);font-size:9pt;font-weight:800;color:#b45f0a;margin:12pt 0 4pt;text-transform:uppercase;letter-spacing:.8pt}
  .pw-sigs{display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid}
  .pw-sig{text-align:center}
  .pw-sigline{height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt}
  .pw-sig span{font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block}
  .pw-foot{margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
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
  <span class="badge amber">👷 WORKERS</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container" id="app">
  <div class="page-header">
    <div>
      <div class="ph-strip"></div>
      <h1><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Workers &amp; <em>Payroll</em></h1>
      <p>Manage daily-paid and contract workers, and track every payment day by day.</p>
    </div>
    <div class="ph-btns">
      <button class="btn btn-ghost" id="btnPrintPay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Payment Register</button>
      <button class="btn btn-primary" id="btnAddWorker"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>Add Worker</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div><b><?php echo $totalWorkers; ?></b><span>Total Workers</span><sub><?php echo $onSite; ?> on site</sub></div></div>
    <div class="stat"><div class="ic b"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-.8.9-1.5 3-1.5s3 .7 3 1.5-.9 1.5-3 1.5-3 .7-3 1.5.9 1.5 3 1.5 3-.7 3-1.5"/></svg></div><div><b><?php echo fmtCompact($paidToday); ?></b><span>Paid Today</span><sub>all workers</sub></div></div>
    <div class="stat"><div class="ic g"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg></div><div><b><?php echo fmtCompact($totalPaid); ?></b><span>Total Paid</span><sub>all time</sub></div></div>
    <div class="stat"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg></div><div><b><?php echo $dailyW; ?></b><span>Daily Workers</span><sub><?php echo $contractW; ?> contract</sub></div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="workers"><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Workers</button>
    <button class="tab-btn" data-tab="payments"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Payments Log</button>
  </div>

  <!-- WORKERS TAB -->
  <div class="tab-panel active" id="tab-workers">
    <div class="card">
      <div class="card-head"><h2>Workers</h2><span class="count-pill"><?php echo count($workers); ?> workers</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Worker</th><th>Type</th><th>Rate</th><th>Total Paid</th><th>Payments</th><th>Last Paid</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($workers)): ?>
            <tr><td colspan="8"><div class="empty"><div class="big">👷</div><p>No workers yet</p><small>Add your first daily or contract worker to begin tracking payments.</small></div></td></tr>
          <?php else: foreach($workers as $w): ?>
            <tr>
              <td style="font-weight:800"><?php echo e($w['name']); ?><?php if($w['trade']): ?><br><small style="color:var(--ink2);font-size:11px"><?php echo e($w['trade']); ?></small><?php endif; ?></td>
              <td><?php echo typePill($w['worker_type']??'Daily'); ?></td>
              <td class="rate-txt"><?php echo ($w['worker_type']??'Daily')==='Contract'?fmtCompact($w['contract_amount']).' (contract)':fmtN($w['daily_rate']).'/day'; ?></td>
              <td class="mono" style="color:var(--amber2);font-weight:700"><?php echo fmtCompact($w['total_paid']); ?></td>
              <td class="mono"><?php echo (int)$w['pay_count']; ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo $w['last_paid']?date('d M Y',strtotime($w['last_paid'])):'—'; ?></td>
              <td><?php echo statusPill($w['status']); ?></td>
              <td style="white-space:nowrap">
                <button class="btn btn-primary btn-sm btn-pay" data-id="<?php echo (int)$w['id']; ?>" data-name="<?php echo e($w['name']); ?>" data-rate="<?php echo e($w['daily_rate']); ?>" data-type="<?php echo e($w['worker_type']??'Daily'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>Pay</button>
                <button class="btn btn-ghost btn-sm btn-editworker" data-id="<?php echo (int)$w['id']; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                <button class="btn btn-danger btn-sm btn-delworker" data-id="<?php echo (int)$w['id']; ?>" data-name="<?php echo e($w['name']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- PAYMENTS TAB -->
  <div class="tab-panel" id="tab-payments">
    <div class="card">
      <div class="card-head"><h2>Payment Log (Day by Day)</h2><span class="count-pill"><?php echo count($payments); ?> payments</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Worker</th><th>Trade</th><th>Type</th><th>Amount</th><th>Notes</th></tr></thead>
        <tbody>
          <?php if(empty($payments)): ?>
            <tr><td colspan="6"><div class="empty"><div class="big">💵</div><p>No payments recorded yet</p><small>Use the Pay action on a worker to log each payment.</small></div></td></tr>
          <?php else: foreach($payments as $p): ?>
            <tr>
              <td style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y',strtotime($p['paid_date'])); ?></td>
              <td style="font-weight:800"><?php echo e($p['worker_name']??'—'); ?></td>
              <td><?php echo e($p['trade']??'—'); ?></td>
              <td><span class="pill p-daily"><?php echo e($p['payment_type']); ?></span></td>
              <td class="mono" style="color:var(--amber2);font-weight:700"><?php echo fmtN($p['amount']); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo e($p['notes']??'—'); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- ADD/EDIT WORKER MODAL -->
<div class="modal-overlay" id="workerModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><span id="workerTitle">Add Worker</span></h3><button class="m-x" onclick="closeModal('workerModal')">&times;</button></div>
  <div class="m-body"><form method="post" id="workerForm">
    <input type="hidden" name="action" id="workerAction" value="_add_worker">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="worker_id" id="workerId">
    <div class="fgrid">
      <div class="field span2"><label class="fl">Full Name <b>*</b></label><div class="ctrl"><input name="name" id="w_name" placeholder="Worker full name" required></div></div>
      <div class="field"><label class="fl">Trade</label><div class="ctrl"><input name="trade" id="w_trade" placeholder="e.g. Mason, Electrician"></div></div>
      <div class="field"><label class="fl">Worker Type</label><div class="ctrl"><select name="worker_type" id="w_type"><option value="Daily">Daily Worker</option><option value="Contract">Contract Worker</option></select></div></div>
      <div class="field"><label class="fl">Daily Rate (₦/day)</label><div class="ctrl"><input name="daily_rate" id="w_rate" inputmode="decimal" placeholder="0.00"></div></div>
      <div class="field"><label class="fl">Contract Amount (₦)</label><div class="ctrl"><input name="contract_amount" id="w_camt" inputmode="decimal" placeholder="0.00"></div></div>
      <div class="field"><label class="fl">Phone</label><div class="ctrl"><input name="phone" id="w_phone" placeholder="0803 000 0000"></div></div>
      <div class="field"><label class="fl">Status</label><div class="ctrl"><select name="status" id="w_status"><option>On Site</option><option>Off Duty</option><option>Terminated</option></select></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('workerModal')">Cancel</button><button type="submit" class="btn btn-primary"><span id="workerSaveLbl">Save Worker</span></button></div>
  </form></div>
</div></div>

<!-- PAY MODAL -->
<div class="modal-overlay" id="payModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14"/></svg>Record Payment</h3><button class="m-x" onclick="closeModal('payModal')">&times;</button></div>
  <div class="m-body"><form method="post">
    <input type="hidden" name="action" value="_pay">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="worker_id" id="p_worker_id">
    <div class="fgrid">
      <div class="field span2"><label class="fl">Worker</label><div class="ctrl"><input id="p_worker_name" readonly style="font-weight:700"></div></div>
      <div class="field"><label class="fl">Amount (₦) <b>*</b></label><div class="ctrl"><input name="amount" id="p_amount" inputmode="decimal" placeholder="0.00" required></div></div>
      <div class="field"><label class="fl">Payment Date</label><div class="ctrl"><input name="paid_date" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
      <div class="field span2"><label class="fl">Payment Type</label><div class="ctrl"><select name="payment_type"><option>Daily Wage</option><option>Contract Payment</option><option>Advance</option><option>Bonus</option></select></div></div>
      <div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="notes" rows="2" placeholder="Optional note"></textarea></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('payModal')">Cancel</button><button type="submit" class="btn btn-primary">Record Payment</button></div>
  </form></div>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-head"><h3 style="color:var(--red)"><svg viewBox="0 0 24 24" fill="none" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Worker?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:13px;margin-bottom:18px">This will permanently remove <strong id="del_name"></strong>.</p>
    <form method="post"><input type="hidden" name="action" value="_delete_worker"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="worker_id" id="del_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Workers &amp; Payroll</div></footer>
<div class="toasts" id="toastWrap"></div>

<!-- PAYMENT REGISTER PRINT SHEET (server-rendered) -->
<div id="printSheet">
  <div class="pw-head">
    <img class="pw-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
    <div class="pw-co"><h1><?php echo e($company['company_name']); ?></h1><p><?php echo e($company['company_address']); ?></p><p>Construction &amp; Projects Department — Workers Payroll</p></div>
    <div class="pw-doc"><h2>WORKERS PAYMENT REGISTER</h2><p>Date: <?php echo date('d M Y'); ?></p><p>Generated: <?php echo date('d M Y, H:i'); ?></p></div>
  </div>
  <div class="pw-sum">
    <div class="pw-sumbox"><b><?php echo $totalWorkers; ?></b><span>Workers</span></div>
    <div class="pw-sumbox"><b><?php echo fmtCompact($paidToday); ?></b><span>Paid Today</span></div>
    <div class="pw-sumbox"><b><?php echo fmtCompact($totalPaid); ?></b><span>Total Paid</span></div>
    <div class="pw-sumbox"><b><?php echo count($payments); ?></b><span>Payments</span></div>
  </div>
  <div class="pw-h">Payment Register</div>
  <table class="pw-table"><thead><tr><th>Date</th><th>Worker</th><th>Trade</th><th>Type</th><th>Amount</th><th>Notes</th></tr></thead><tbody>
    <?php if(empty($payments)): ?><tr><td colspan="6">No payments recorded.</td></tr>
    <?php else: foreach($payments as $p): ?>
      <tr><td><?php echo date('d M Y',strtotime($p['paid_date'])); ?></td><td><?php echo e($p['worker_name']??'—'); ?></td><td><?php echo e($p['trade']??'—'); ?></td><td><?php echo e($p['payment_type']); ?></td><td><?php echo fmtN($p['amount']); ?></td><td><?php echo e($p['notes']??'—'); ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody></table>
  <div class="pw-sigs">
    <div class="pw-sig"><div class="pw-sigline"></div><span>Prepared By (Payroll Officer)</span></div>
    <div class="pw-sig"><div class="pw-sigline"></div><span>Authorised By (Manager)</span></div>
  </div>
  <div class="pw-foot"><span><?php echo e($company['company_name']); ?> — WORKERS PAYMENT REGISTER (CONFIDENTIAL)</span><span>Generated <?php echo date('d M Y, H:i'); ?></span></div>
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

/* workers data for edit */
const workersData=<?php echo json_encode(array_map(function($w){return['id'=>(int)$w['id'],'name'=>$w['name'],'trade'=>$w['trade']??'','worker_type'=>$w['worker_type']??'Daily','daily_rate'=>(float)$w['daily_rate'],'contract_amount'=>(float)$w['contract_amount'],'phone'=>$w['phone']??'','status'=>$w['status']];},$workers),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ADD worker */
$('btnAddWorker').addEventListener('click',()=>{
  $('workerForm').reset();$('workerAction').value='_add_worker';$('workerId').value='';
  $('workerTitle').textContent='Add Worker';$('workerSaveLbl').textContent='Save Worker';
  $('w_status').value='On Site';$('w_type').value='Daily';
  openModal('workerModal');
});
/* EDIT worker */
document.querySelectorAll('.btn-editworker').forEach(b=>b.addEventListener('click',()=>{
  const w=workersData.find(x=>x.id==b.dataset.id);if(!w)return;
  $('workerAction').value='_update_worker';$('workerId').value=w.id;
  $('workerTitle').textContent='Edit Worker';$('workerSaveLbl').textContent='Save Changes';
  $('w_name').value=w.name;$('w_trade').value=w.trade;$('w_type').value=w.worker_type;
  $('w_rate').value=w.daily_rate;$('w_camt').value=w.contract_amount;$('w_phone').value=w.phone;$('w_status').value=w.status;
  openModal('workerModal');
}));
/* PAY worker — prefill amount based on type */
document.querySelectorAll('.btn-pay').forEach(b=>b.addEventListener('click',()=>{
  $('p_worker_id').value=b.dataset.id;$('p_worker_name').value=b.dataset.name;
  $('p_amount').value = (b.dataset.type==='Contract') ? '' : (b.dataset.rate||'');
  openModal('payModal');
}));
/* DELETE worker */
document.querySelectorAll('.btn-delworker').forEach(b=>b.addEventListener('click',()=>{
  $('del_id').value=b.dataset.id;$('del_name').textContent=b.dataset.name;openModal('deleteModal');
}));

/* PRINT */
$('btnPrintPay').addEventListener('click',()=>window.print());

const FLASH=<?php echo json_encode($flash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
<?php if($flash): ?>
window.addEventListener('DOMContentLoaded',()=>toast(<?php echo json_encode($flash['msg'],JSON_HEX_TAG|JSON_HEX_QUOT); ?>,'<?php echo $flash['type']; ?>'));
<?php endif; ?>
</script>
</body>
</html>