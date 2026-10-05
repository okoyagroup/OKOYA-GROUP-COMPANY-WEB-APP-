<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria','company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function fmtT($kg):string{return number_format((float)$kg/1000,2).' t';}
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `weighbridge` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `ticket_no` VARCHAR(40) NOT NULL,
        `weigh_date` DATETIME NOT NULL,
        `weigh_type` ENUM('Outbound','Inbound') DEFAULT 'Outbound',
        `vehicle_plate` VARCHAR(30) NOT NULL,
        `driver_name` VARCHAR(120) DEFAULT NULL,
        `product` VARCHAR(120) DEFAULT NULL,
        `client_name` VARCHAR(120) DEFAULT NULL,
        `gross_weight` DECIMAL(14,2) DEFAULT 0,
        `tare_weight` DECIMAL(14,2) DEFAULT 0,
        `net_weight` DECIMAL(14,2) DEFAULT 0,
        `destination` VARCHAR(150) DEFAULT NULL,
        `status` ENUM('Open','Completed','Cancelled') DEFAULT 'Open',
        `notes` TEXT NULL,
        `created_by` INT UNSIGNED DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ignore) {}

/* ===== CREATE TICKET ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_create') {
    $dt    = $_POST['weigh_date'] ?? date('Y-m-d H:i');
    $type  = in_array($_POST['weigh_type']??'',['Outbound','Inbound']) ? $_POST['weigh_type'] : 'Outbound';
    $plate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
    $drv   = trim($_POST['driver_name'] ?? '');
    $prod  = trim($_POST['product'] ?? '');
    $client= trim($_POST['client_name'] ?? '');
    $gross = floatval($_POST['gross_weight'] ?? 0);
    $tare  = floatval($_POST['tare_weight'] ?? 0);
    $dest  = trim($_POST['destination'] ?? '');
    $status= in_array($_POST['status']??'',['Open','Completed','Cancelled'])?$_POST['status']:'Open';
    $notes = trim($_POST['notes'] ?? '');
    $net   = $gross - $tare;
    $errs=[];
    if($plate==='')$errs[]='Vehicle plate number is required';
    if($gross<=0)$errs[]='Gross weight must be greater than 0';
    if($tare<0)$errs[]='Tare weight cannot be negative';
    if($tare>$gross)$errs[]='Tare weight cannot exceed gross weight';
    if(empty($errs)){
        try{
            $d8=date('Ymd',strtotime($dt));
            $st=$pdo->prepare("SELECT COUNT(*) FROM weighbridge WHERE DATE(weigh_date)=?");
            $st->execute([date('Y-m-d',strtotime($dt))]);
            $seq=(int)$st->fetchColumn()+1;
            $ticket='WB-'.$d8.'-'.str_pad((string)$seq,3,'0',STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO weighbridge (ticket_no,weigh_date,weigh_type,vehicle_plate,driver_name,product,client_name,gross_weight,tare_weight,net_weight,destination,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$ticket,$dt,$type,$plate,$drv?:null,$prod?:null,$client?:null,$gross,$tare,$net,$dest?:null,$status,$notes?:null,$auth['id']]);
            /* Auto-register new product if not in catalog */
            if ($prod !== '') {
                try {
                    $checkProd = $pdo->prepare("SELECT id FROM products WHERE name = ?");
                    $checkProd->execute([$prod]);
                    if (!$checkProd->fetch()) {
                        $genCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $prod), 0, 8));
                        if ($genCode === '') $genCode = 'NEW' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO products (name, code, status) VALUES (?, ?, 'Active')")->execute([$prod, $genCode]);
                    }
                } catch (Exception $ignore) {}
            }
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                ->execute([$auth['id'],'WEIGH_TICKET','weighbridge',(int)$pdo->lastInsertId(),"Ticket $ticket — $plate"]);}catch(Exception $ignore){}
            flash('success',"Weigh ticket $ticket saved — net ".number_format($net)." kg.");
            header('Location: logistics.php'); exit;
        }catch(Exception $ex){ flash('error','Database error: '.$ex->getMessage()); }
    } else flash('error', implode('. ',$errs));
}

/* ===== UPDATE TICKET ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update') {
    $id=(int)($_POST['ticket_id']??0);
    if($id>0){
        $gross=floatval($_POST['gross_weight']??0);$tare=floatval($_POST['tare_weight']??0);
        if($gross>0 && $tare>=0 && $tare<=$gross){
            try{
                $updProd = trim($_POST['product']??'');
                $pdo->prepare("UPDATE weighbridge SET vehicle_plate=?,driver_name=?,product=?,client_name=?,gross_weight=?,tare_weight=?,net_weight=?,destination=?,status=?,notes=? WHERE id=?")
                    ->execute([strtoupper(trim($_POST['vehicle_plate']??'')),trim($_POST['driver_name']??'')?:null,
                        $updProd?:null,trim($_POST['client_name']??'')?:null,
                        $gross,$tare,$gross-$tare,trim($_POST['destination']??'')?:null,
                        $_POST['status']??'Open',trim($_POST['notes']??'')?:null,$id]);
                /* Auto-register new product on update too */
                if ($updProd !== '') {
                    try {
                        $checkProd = $pdo->prepare("SELECT id FROM products WHERE name = ?");
                        $checkProd->execute([$updProd]);
                        if (!$checkProd->fetch()) {
                            $genCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $updProd), 0, 8));
                            if ($genCode === '') $genCode = 'UPD' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
                            $pdo->prepare("INSERT INTO products (name, code, status) VALUES (?, ?, 'Active')")->execute([$updProd, $genCode]);
                        }
                    } catch (Exception $ignore) {}
                }
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                    ->execute([$auth['id'],'UPDATE_WEIGH_TICKET','weighbridge',$id,'Updated weigh ticket']);}catch(Exception $ignore){}
                flash('success','Weigh ticket updated.');
            }catch(Exception $ex){ flash('error','Update failed: '.$ex->getMessage()); }
        } else flash('error','Check weights — tare must be between 0 and gross.');
        header('Location: logistics.php'); exit;
    }
}

/* ===== DELETE TICKET ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete' && $isAdmin) {
    $id=(int)($_POST['ticket_id']??0);
    if($id>0){
        try{
            $pdo->prepare("DELETE FROM weighbridge WHERE id=?")->execute([$id]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                ->execute([$auth['id'],'DELETE_WEIGH_TICKET','weighbridge',$id,'Deleted weigh ticket']);}catch(Exception $ignore){}
            flash('success','Weigh ticket deleted.');
        }catch(Exception $ex){ flash('error','Delete failed: '.$ex->getMessage()); }
        header('Location: logistics.php'); exit;
    }
}

/* ===== FETCH TICKETS ===== */
$search=trim($_GET['search']??''); $filterDate=$_GET['wdate']??'';
$where=[];$params=[];
if($search!==''){ $where[]="(w.ticket_no LIKE ? OR w.vehicle_plate LIKE ? OR w.driver_name LIKE ? OR w.client_name LIKE ?)"; array_push($params,"%$search%","%$search%","%$search%","%$search%"); }
if($filterDate!==''){ $where[]="DATE(w.weigh_date)=?"; $params[]=$filterDate; }
$wc=$where?'WHERE '.implode(' AND ',$where):'';
$tickets=[];$fetchError=null;
try{
    $st=$pdo->prepare("SELECT w.*, u.full_name AS created_by_name FROM weighbridge w LEFT JOIN users u ON u.id=w.created_by $wc ORDER BY w.id DESC LIMIT 200");
    $st->execute($params); $tickets=$st->fetchAll();
}catch(Exception $ex){ $fetchError=$ex->getMessage(); }

$todayTickets=0;$todayNet=0.0;$totTickets=0;$totNet=0.0;$totDrivers=0;$totVehicles=0;
try{
    $todayTickets=(int)$pdo->query("SELECT COUNT(*) FROM weighbridge WHERE DATE(weigh_date)=CURDATE()")->fetchColumn();
    $todayNet=(float)$pdo->query("SELECT IFNULL(SUM(net_weight),0) FROM weighbridge WHERE DATE(weigh_date)=CURDATE()")->fetchColumn();
    $totTickets=(int)$pdo->query("SELECT COUNT(*) FROM weighbridge")->fetchColumn();
    $totNet=(float)$pdo->query("SELECT IFNULL(SUM(net_weight),0) FROM weighbridge")->fetchColumn();
}catch(Exception $ignore){}
try{ $totDrivers=(int)$pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn(); }catch(Exception $ignore){}
try{ $totVehicles=(int)$pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(); }catch(Exception $ignore){}

$driverRows=[];
try{ $driverRows=$pdo->query("SELECT full_name, vehicle_plate, phone FROM drivers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $ignore){
    try{ $names=$pdo->query("SELECT full_name FROM drivers ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
         foreach($names as $n) $driverRows[]=['full_name'=>$n,'vehicle_plate'=>'','phone'=>'']; }catch(Exception $ignore2){}
}
$driverNames=array_column($driverRows,'full_name');
$plates=[]; try{$plates=$pdo->query("SELECT plate_number FROM vehicles ORDER BY plate_number")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $ignore){}
$productNames=[]; try{$productNames=$pdo->query("SELECT name FROM products ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);}catch(Exception $ignore){}

$flashData=get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Logistics &amp; Weighbridge | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--gold) 13%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--green) 10%,transparent),transparent 60%)}
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
.badge.role{background:var(--gold-soft);color:var(--gold2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}
.page-header{padding:30px 0 6px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}
.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.ph-left h1 em{font-style:normal;color:var(--gold2)}
.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:18px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}
.stat .ic.g{background:var(--green-soft);color:var(--green)}.stat .ic.y{background:var(--gold-soft);color:var(--gold2)}.stat .ic.b{background:var(--blue-soft);color:var(--blue)}
.stat .ic svg{width:20px;height:20px}
.stat b{font-family:var(--fd);font-size:20px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.stat sub{font-size:10px;color:var(--ink2);font-weight:600}
.layout{display:grid;grid-template-columns:1.5fr 1fr;gap:18px;align-items:start;margin-top:20px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--gold2)}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--gold-soft);color:var(--gold2);padding:4px 11px;border-radius:99px}
.form-body{padding:20px}
.form-section{margin-bottom:20px}.form-section:last-of-type{margin-bottom:0}
.fs-title{font-family:var(--fd);font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--gold2);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--gold-soft);display:flex;align-items:center;gap:8px}
.fs-title svg{width:15px;height:15px;stroke:var(--gold2)}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.span2{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:5px}
.fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--line);border-radius:11px;padding:0 12px;height:46px;transition:.2s}
.ctrl:focus-within{border-color:var(--gold);box-shadow:0 0 0 3px color-mix(in srgb,var(--gold) 12%,transparent);background:var(--surface)}
.ctrl.invalid{border-color:var(--red)!important;animation:shake .3s}
@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:14px}
.ctrl textarea{padding:10px 0;height:auto;min-height:50px;resize:vertical}
.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.driver-hint{font-size:10.5px;color:var(--ink2);font-weight:600;margin-top:-2px;margin-bottom:8px}
.net-strip{margin-top:14px;display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px}
.ns-box{border-radius:12px;padding:12px 16px;border:1.5px dashed var(--line);background:var(--surface2)}
.ns-box.net{border-color:var(--gold);background:linear-gradient(135deg,var(--gold-soft),color-mix(in srgb,var(--gold) 20%,var(--surface)))}
.ns-box span{display:block;font-size:9.5px;font-weight:800;letter-spacing:.8px;color:var(--ink2);text-transform:uppercase}
.ns-box.net span{color:var(--gold2)}
.ns-box b{font-family:var(--fm);font-size:16px;font-weight:700;color:var(--ink)}
.ns-box.net b{color:var(--gold2);font-size:19px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--gold) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--gold);color:var(--gold2)}
.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
.btn svg{width:15px;height:15px}
.btn-xlarge{width:100%;height:50px;font-size:15px;margin-top:18px}
.side-stick{position:sticky;top:78px;display:flex;flex-direction:column;gap:16px}
.ref-list{padding:14px 18px;display:flex;flex-direction:column;gap:8px}
.ref-item{display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border:1px solid var(--line);border-radius:11px;padding:10px 14px}
.ref-item span{font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:8px}
.ref-item span svg{width:16px;height:16px}
.ref-item small{font-size:10.5px;color:var(--ink2);font-weight:700}
.ref-chips{display:flex;flex-wrap:wrap;gap:7px;padding:14px 18px}
.ref-chip{font-size:11px;font-weight:700;background:var(--surface2);border:1px solid var(--line);border-radius:8px;padding:5px 10px;color:var(--ink2)}
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:24px 0 16px;box-shadow:var(--shadow-sm)}
.ff{display:flex;flex-direction:column;gap:5px;min-width:170px;flex:1}
.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ff input{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}
.ff input:focus{border-color:var(--gold);background:var(--surface)}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:1080px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.actions-cell{display:flex;gap:6px;white-space:nowrap}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty svg{width:48px;height:48px;margin:0 auto 10px;opacity:.4}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}
.query-error{margin:0 20px 12px;background:var(--red-soft);color:var(--red);border:1px solid var(--red);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700}
.net-cell{font-family:var(--fm);font-weight:700;color:var(--gold2)}
.type-pill{font-size:9.5px;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.4px;text-transform:uppercase;display:inline-block}
.type-pill.outbound{background:var(--green-soft);color:var(--green)}
.type-pill.inbound{background:var(--blue-soft);color:var(--blue)}
.status-pill{font-size:9.5px;font-weight:800;padding:3px 9px;border-radius:99px;letter-spacing:.4px;text-transform:uppercase;display:inline-block}
.status-pill.st-open{background:var(--gold-soft);color:var(--gold2)}
.status-pill.st-completed{background:var(--green-soft);color:var(--green)}
.status-pill.st-cancelled{background:var(--red-soft);color:var(--red)}
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
@keyframes fadein{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.m-head{padding:20px 24px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.m-head h3{font-family:var(--fd);font-size:17px;font-weight:800;display:flex;align-items:center;gap:8px}
.m-head h3 svg{width:18px;height:18px;stroke:var(--gold2)}
.m-x{width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:16px;cursor:pointer;display:grid;place-items:center}
.m-body{padding:20px 24px 24px}
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
#printSheet{display:none}
@media print{
  @page{size:A4 portrait;margin:8mm}
  html,body{background:#fff!important;margin:0!important;padding:0!important;height:auto}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body::before,#app,.toasts,.modal-overlay{display:none!important}
  #printSheet{display:block!important;font-family:var(--fb);color:#1a231d;font-size:8pt}
  .print-page{display:flex;flex-direction:column;gap:6mm;width:194mm;height:281mm}
  .pt-ticket{position:relative;flex:1 1 0;min-height:0;border:2pt solid #b57e17;border-radius:6pt;padding:2.5pt;background:#fff;overflow:hidden;display:flex;flex-direction:column;page-break-inside:avoid;break-inside:avoid}
  .pt-ticket::after{content:attr(data-wm);position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) rotate(-25deg);font-family:var(--fd);font-weight:800;font-size:48pt;letter-spacing:4pt;color:rgba(181,126,23,.05);white-space:nowrap;pointer-events:none;z-index:0}
  .pt-inner{flex:1;display:flex;flex-direction:column;justify-content:space-between;border:.7pt solid #e6cf9c;border-radius:4pt;padding:9pt 12pt;position:relative;z-index:1}
  .cut-line{flex:0 0 auto;display:flex;align-items:center;gap:6pt;color:#bbb;font-size:5pt;font-weight:700;letter-spacing:2pt;text-transform:uppercase}
  .cut-line::before,.cut-line::after{content:'';flex:1;height:0;border-top:1px dashed #ccc}
  .pt-head{display:flex;align-items:center;gap:8pt;border-bottom:1.5pt solid #b57e17;padding-bottom:6pt;margin-bottom:6pt}
  .pt-logo{width:40pt;height:40pt;object-fit:contain;flex:none;border:.5pt solid #e3e9e1;border-radius:4pt;background:#fff;padding:1.5pt}
  .pt-co{flex:1;min-width:0}
  .pt-co h1{font-family:var(--fd);font-size:10.5pt;font-weight:800;line-height:1.15;color:#1a231d}
  .pt-co .tag{font-size:4pt;font-weight:800;letter-spacing:.8pt;color:#b57e17;margin:1pt 0;text-transform:uppercase}
  .pt-co p{font-size:5pt;color:#5a655c;line-height:1.35;margin:0}
  .pt-doc{flex:none;text-align:right}
  .doc-badge{display:inline-block;background:#b57e17;color:#fff;font-size:5.5pt;font-weight:800;letter-spacing:1pt;text-transform:uppercase;padding:3pt 7pt;border-radius:2pt}
  .doc-no{font-family:var(--fm);font-size:9pt;font-weight:700;color:#b57e17;margin-top:2pt}
  .doc-meta{font-size:5pt;color:#667268;margin-top:1pt}
  .doc-meta b{color:#1a231d}
  .pt-grid{display:grid;grid-template-columns:1fr 1fr;gap:6pt;margin-bottom:6pt}
  .pt-sec{border:.7pt solid #dbe4da;border-radius:4pt;overflow:hidden}
  .pt-sec h4{background:#faf3e2;color:#8a6a1f;font-size:5pt;font-weight:800;letter-spacing:1pt;text-transform:uppercase;padding:3pt 7pt;border-bottom:.7pt solid #eddfbf}
  .pt-row{display:flex;justify-content:space-between;gap:8pt;padding:2pt 7pt;border-bottom:.5pt dotted #e0e7df;font-size:6pt;line-height:1.35}
  .pt-row:last-child{border-bottom:none}
  .pt-row span{color:#77836f;font-weight:700;flex:none}
  .pt-row b{font-weight:700;text-align:right;word-break:break-word}
  .pt-weights{display:grid;grid-template-columns:1fr 1fr 1.3fr;gap:6pt;margin:6pt 0}
  .pt-wbox{border:.8pt solid #cfd8cd;border-radius:5pt;padding:5pt;text-align:center;background:#fbfdfb}
  .pt-wbox b{font-family:var(--fm);font-size:11pt;font-weight:700;display:block;line-height:1.1}
  .pt-wbox small{display:block;font-size:4.5pt;color:#9aa79c;font-weight:600;margin-top:.5pt}
  .pt-wbox span{display:block;font-size:4pt;font-weight:800;letter-spacing:.8pt;color:#77836f;text-transform:uppercase;margin-top:1pt}
  .pt-wbox.net{background:#b57e17;border-color:#8f6210}
  .pt-wbox.net b{color:#fff;font-size:13pt}
  .pt-wbox.net small{color:#f3ddb0}
  .pt-wbox.net span{color:#ffe9c4}
  .pt-note{font-size:5.5pt;color:#4a554c;border:.7pt dashed #cfd8cd;border-radius:4pt;padding:4pt 7pt;margin-bottom:6pt;line-height:1.4}
  .pt-decl{font-size:5pt;color:#667268;line-height:1.4;margin-bottom:3pt}
  .pt-sigs{display:grid;grid-template-columns:repeat(3,1fr);gap:10pt;margin-top:6pt}
  .pt-sig{text-align:center}
  .pt-sig .line{height:24pt;border-bottom:1pt solid #4a554c;margin-bottom:2pt}
  .pt-sig span{display:block;font-size:4.5pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;color:#5a554c}
  .pt-sig small{display:block;font-size:4pt;color:#9aa79c;margin-top:.4pt}
  .pt-foot{margin-top:6pt;padding-top:3pt;border-top:.6pt solid #dfe5dd;display:flex;justify-content:space-between;gap:8pt;font-size:4pt;color:#9aa79c}
}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.layout{grid-template-columns:1fr}.side-stick{position:static}}
@media(max-width:600px){.topbar .badge{display:none}.brand strong{font-size:12px}.page-header{flex-direction:column;align-items:flex-start}.filter-bar{flex-direction:column}.stats{grid-template-columns:1fr 1fr;gap:10px}.net-strip{grid-template-columns:1fr}.fgrid{grid-template-columns:1fr}.actions-cell{flex-direction:column;gap:4px}.actions-cell .btn-sm{width:100%;justify-content:center}}
@media(max-width:480px){.stats{grid-template-columns:1fr}.page-header .btn-primary{width:100%}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small>Logistics &amp; Weighbridge Unit</small></div></div>
  <a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_general_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a>
  <span class="badge role"><?php echo strtoupper(e($auth['role'])); ?></span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-left">
      <h1><svg class="icon-svg" width="28" height="28" viewBox="0 0 24 24" style="stroke:var(--gold2)"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Logistics &amp; <em>Weighbridge</em></h1>
      <p>Weigh vehicles with load — record gross, tare and net product weight per ticket</p>
    </div>
    <button class="btn btn-ghost" id="btnPrintLog"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Weigh Log</button>
  </div>

  <div class="stats">
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div><div><b><?php echo number_format($todayTickets); ?></b><span>Today Tickets</span><sub>weighings today</sub></div></div>
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 7l7-4 7 4"/><path d="M3 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/><path d="M17 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/></svg></div><div><b><?php echo fmtT($todayNet); ?></b><span>Net Weighed Today</span><sub>product weight</sub></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><polyline points="12 22.08 12 12"/></svg></div><div><b><?php echo fmtT($totNet); ?></b><span>Total Net Weight</span><sub>all time</sub></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18h.01"/><path d="M12 14h.01"/></svg></div><div><b><?php echo number_format($totTickets); ?></b><span>Total Tickets</span><sub>all time</sub></div></div>
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><b><?php echo number_format($totDrivers); ?></b><span>Drivers</span><sub>in fleet</sub></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div><div><b><?php echo number_format($totVehicles); ?></b><span>Vehicles</span><sub>registered</sub></div></div>
  </div>

  <div class="layout">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>New Weigh Ticket</h2></div>
      <form method="post" id="weighForm" class="form-body" novalidate>
        <input type="hidden" name="action" value="_create">
        <div class="form-section">
          <div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Vehicle &amp; Load</div>
          <div class="fgrid">
            <div class="field"><label class="fl">Date &amp; Time <b>*</b></label><div class="ctrl"><input type="datetime-local" name="weigh_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required></div></div>
            <div class="field"><label class="fl">Weigh Type</label><div class="ctrl"><select name="weigh_type"><option value="Outbound">Outbound (delivery)</option><option value="Inbound">Inbound (incoming)</option></select></div></div>
            <div class="field span2">
              <label class="fl">Driver <b>*</b></label>
              <div class="ctrl"><input name="driver_name" id="f_driver" list="driverList" placeholder="Select registered driver or type manually" autocomplete="off"><datalist id="driverList"><?php foreach($driverNames as $dn): ?><option value="<?php echo e($dn); ?>"><?php endforeach; ?></datalist></div>
              <span class="driver-hint" id="driverHint">Select a registered driver to auto-fill their vehicle, or type a new driver's name.</span>
            </div>
            <div class="field"><label class="fl">Vehicle Plate <b>*</b></label><div class="ctrl"><input name="vehicle_plate" id="f_plate" list="plateList" placeholder="e.g. ABC-482-XA" required style="text-transform:uppercase"><datalist id="plateList"><?php foreach($plates as $pl): ?><option value="<?php echo e($pl); ?>"><?php endforeach; ?></datalist></div></div>
            <div class="field"><label class="fl">Product</label><div class="ctrl"><input name="product" id="f_product" list="productList" placeholder="Select or type any product name" autocomplete="off"><datalist id="productList"><?php foreach($productNames as $pn): ?><option value="<?php echo e($pn); ?>"><?php endforeach; ?></datalist></div></div>
            <div class="field"><label class="fl">Client</label><div class="ctrl"><input name="client_name" placeholder="Client name"></div></div>
            <div class="field"><label class="fl">Destination</label><div class="ctrl"><input name="destination" placeholder="Delivery destination"></div></div>
          </div>
        </div>
        <div class="form-section">
          <div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 7l7-4 7 4"/><path d="M3 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/><path d="M17 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/></svg>Scale Weights (kg)</div>
          <div class="fgrid">
            <div class="field"><label class="fl">Gross Weight — vehicle + load <b>*</b></label><div class="ctrl"><input type="number" name="gross_weight" id="f_gross" min="1" step="0.01" placeholder="e.g. 15200" required></div></div>
            <div class="field"><label class="fl">Tare Weight — empty vehicle</label><div class="ctrl"><input type="number" name="tare_weight" id="f_tare" min="0" step="0.01" value="0" placeholder="e.g. 8200"></div></div>
          </div>
          <div class="net-strip">
            <div class="ns-box"><span>Gross (vehicle + load)</span><b id="calcGross">0 kg</b></div>
            <div class="ns-box"><span>Tare (empty vehicle)</span><b id="calcTare">0 kg</b></div>
            <div class="ns-box net"><span>Net — Product Weight</span><b id="calcNet">0 kg</b></div>
          </div>
        </div>
        <div class="form-section">
          <div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Ticket Details</div>
          <div class="fgrid">
            <div class="field"><label class="fl">Status</label><div class="ctrl"><select name="status"><option value="Open">Open</option><option value="Completed">Completed</option><option value="Cancelled">Cancelled</option></select></div></div>
            <div class="field"><label class="fl">Notes</label><div class="ctrl"><input name="notes" placeholder="Optional remarks"></div></div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-xlarge" id="submitBtn"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg><span id="submitLabel">Save Weigh Ticket</span></button>
      </form>
    </div>

    <div class="side-stick">
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 7l7-4 7 4"/><path d="M3 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/><path d="M17 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/></svg>How Scaling Works</h2></div>
        <div class="ref-list">
          <div class="ref-item"><span><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Weigh loaded vehicle</span><small>GROSS</small></div>
          <div class="ref-item"><span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 7l7-4 7 4"/><path d="M3 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/><path d="M17 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/></svg> Weigh empty vehicle</span><small>TARE</small></div>
          <div class="ref-item"><span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg> Net = Gross - Tare</span><small>PRODUCT WEIGHT</small></div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Fleet Vehicles</h2></div>
        <div class="ref-chips">
          <?php if(empty($plates)): ?><span class="ref-chip">No vehicles registered</span><?php else: foreach(array_slice($plates,0,12) as $pl): ?><span class="ref-chip"><?php echo e($pl); ?></span><?php endforeach; endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Drivers</h2></div>
        <div class="ref-list">
          <?php if(empty($driverNames)): ?><div class="ref-item"><span>No drivers registered</span></div><?php else: foreach(array_slice($driverNames,0,8) as $dn): ?><div class="ref-item"><span><?php echo e($dn); ?></span></div><?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <form method="get" class="filter-bar">
    <div class="ff"><label>Search</label><input type="text" name="search" placeholder="Ticket no, plate, driver, client..." value="<?php echo e($search); ?>"></div>
    <div class="ff" style="max-width:190px"><label>Date</label><input type="date" name="wdate" value="<?php echo e($filterDate); ?>"></div>
    <div style="display:flex;gap:8px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button><a href="logistics.php" class="btn btn-ghost">Clear</a></div>
  </form>

  <div class="card">
    <div class="card-head">
      <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Weigh Tickets</h2>
      <span class="count-pill"><?php echo count($tickets); ?> tickets</span>
    </div>
    <?php if(!empty($fetchError)): ?><div class="query-error">Query error: <?php echo e($fetchError); ?></div><?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Ticket</th><th>Date / Time</th><th>Vehicle</th><th>Driver</th><th>Product</th><th>Gross</th><th>Tare</th><th>Net</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($tickets)): ?>
            <tr><td colspan="11"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 3v18"/><path d="M5 7l7-4 7 4"/><path d="M3 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/><path d="M17 12l2-5 2 5a2.5 2.5 0 0 1-4 0Z"/></svg><p>No weigh tickets yet</p><small>Record your first vehicle weighing above.</small></div></td></tr>
          <?php else: foreach($tickets as $i=>$t): ?>
            <tr>
              <td class="mono" style="color:var(--gold2);font-weight:700"><?php echo e($t['ticket_no']); ?></td>
              <td style="font-size:12px"><?php echo date('d M Y, H:i',strtotime($t['weigh_date'])); ?></td>
              <td class="mono"><?php echo e($t['vehicle_plate']); ?></td>
              <td style="font-size:12px"><?php echo e($t['driver_name']??'—'); ?></td>
              <td style="font-size:12px"><?php echo e($t['product']??'—'); ?></td>
              <td class="mono"><?php echo number_format((float)$t['gross_weight']); ?></td>
              <td class="mono"><?php echo number_format((float)$t['tare_weight']); ?></td>
              <td class="net-cell"><?php echo number_format((float)$t['net_weight']); ?> kg</td>
              <td><span class="type-pill <?php echo strtolower($t['weigh_type']); ?>"><?php echo e($t['weigh_type']); ?></span></td>
              <td><span class="status-pill st-<?php echo strtolower(e($t['status'])); ?>"><?php echo e($t['status']); ?></span></td>
              <td><div class="actions-cell">
                <button class="btn btn-ghost btn-sm btn-ticket" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Ticket</button>
                <button class="btn btn-ghost btn-sm btn-edit" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
                <?php if($isAdmin): ?>
                <button class="btn btn-danger btn-sm btn-del" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal"><div class="modal">
  <div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Weigh Ticket</h3><button class="m-x" onclick="closeModal('editModal')">&times;</button></div>
  <div class="m-body">
    <form method="post" id="editForm" novalidate>
      <input type="hidden" name="action" value="_update">
      <input type="hidden" name="ticket_id" id="e_id">
      <div class="fgrid">
        <div class="field"><label class="fl">Vehicle Plate <b>*</b></label><div class="ctrl"><input name="vehicle_plate" id="e_plate" style="text-transform:uppercase"></div></div>
        <div class="field"><label class="fl">Driver</label><div class="ctrl"><input name="driver_name" id="e_driver"></div></div>
        <div class="field"><label class="fl">Product</label><div class="ctrl"><input name="product" id="e_product" list="productListEdit" placeholder="Select or type any product" autocomplete="off"><datalist id="productListEdit"><?php foreach($productNames as $pn): ?><option value="<?php echo e($pn); ?>"><?php endforeach; ?></datalist></div></div>
        <div class="field"><label class="fl">Client</label><div class="ctrl"><input name="client_name" id="e_client"></div></div>
        <div class="field"><label class="fl">Gross (kg) <b>*</b></label><div class="ctrl"><input type="number" name="gross_weight" id="e_gross" min="1" step="0.01"></div></div>
        <div class="field"><label class="fl">Tare (kg)</label><div class="ctrl"><input type="number" name="tare_weight" id="e_tare" min="0" step="0.01"></div></div>
        <div class="field"><label class="fl">Destination</label><div class="ctrl"><input name="destination" id="e_dest"></div></div>
        <div class="field"><label class="fl">Status</label><div class="ctrl"><select name="status" id="e_status"><option>Open</option><option>Completed</option><option>Cancelled</option></select></div></div>
        <div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="notes" id="e_notes" rows="2"></textarea></div></div>
      </div>
      <button type="submit" class="btn btn-primary btn-xlarge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
    </form>
  </div>
</div></div>

<?php if($isAdmin): ?>
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-head"><h3 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Ticket?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:13px;margin-bottom:16px">This will permanently delete weigh ticket <strong id="del_no"></strong>.</p>
    <form method="post"><input type="hidden" name="action" value="_delete"><input type="hidden" name="ticket_id" id="del_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>
<?php endif; ?>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Logistics &amp; Weighbridge Unit</div><br>
<div><h5>Developed By Lawani Djamiou Alade</h5></div>
</footer>
<div class="toasts" id="toastWrap"></div>
</div>
<div id="printSheet" aria-hidden="true"></div>

<script>
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const v=s=>{const x=String(s??'').trim();return x===''?'&mdash;':esc(x);};
const fmtKg=n=>Number(n||0).toLocaleString()+' kg';
const fmtT=n=>(Number(n||0)/1000).toFixed(2)+' t';
const fmtDT=s=>esc(String(s??'').replace('T',' '));
const nowStr=()=>new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>')+'</span>'+esc(msg);$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

const tickets=<?php echo json_encode(array_map(function($t){return['id'=>(int)$t['id'],'ticket'=>$t['ticket_no'],'date'=>$t['weigh_date'],'type'=>$t['weigh_type'],'plate'=>$t['vehicle_plate'],'driver'=>$t['driver_name']??'','product'=>$t['product']??'','client'=>$t['client_name']??'','gross'=>(float)$t['gross_weight'],'tare'=>(float)$t['tare_weight'],'net'=>(float)$t['net_weight'],'dest'=>$t['destination']??'','status'=>$t['status'],'notes'=>$t['notes']??'','by'=>$t['created_by_name']??''];},$tickets),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const driversData=<?php echo json_encode(array_map(function($d){return['name'=>$d['full_name'],'plate'=>$d['vehicle_plate']??'','phone'=>$d['phone']??''];},$driverRows),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const CO={name:<?php echo json_encode($company['company_name']); ?>,dept:<?php echo json_encode($company['company_dept']); ?>,addr:<?php echo json_encode($company['company_address']); ?>,tag:<?php echo json_encode($company['company_tagline']); ?>};

$('f_driver').addEventListener('change',()=>{
  const val=$('f_driver').value.trim().toLowerCase();
  const d=driversData.find(x=>x.name.toLowerCase()===val);
  const hint=$('driverHint');
  if(d){
    if(d.plate) $('f_plate').value=d.plate;
    hint.textContent='Registered driver loaded'+(d.plate?' — vehicle '+d.plate+' auto-filled':'')+'.';
    hint.style.color='var(--green)';
  }else if(val!==''){
    hint.textContent='New driver — fill the vehicle plate manually below.';
    hint.style.color='var(--gold2)';
  }else{
    hint.textContent='Select a registered driver to auto-fill their vehicle, or type a new driver\u2019s name.';
    hint.style.color='var(--ink2)';
  }
});

function calcNet(){
  const g=parseFloat($('f_gross').value)||0, t=parseFloat($('f_tare').value)||0;
  $('calcGross').textContent=fmtKg(g);$('calcTare').textContent=fmtKg(t);
  $('calcNet').textContent=fmtKg(Math.max(0,g-t))+' ('+fmtT(Math.max(0,g-t))+')';
}
['f_gross','f_tare'].forEach(id=>$(id).addEventListener('input',calcNet));
calcNet();

function mark(el){el.closest('.ctrl').classList.add('invalid')}
$('weighForm').addEventListener('submit',ev=>{
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  let ok=true,msg='';
  const g=parseFloat($('f_gross').value)||0, t=parseFloat($('f_tare').value)||0;
  if(!$('f_driver').value.trim()){mark($('f_driver'));ok=false;msg='Driver name is required.';}
  else if(!$('f_plate').value.trim()){mark($('f_plate'));ok=false;msg='Vehicle plate is required.';}
  else if(g<=0){mark($('f_gross'));ok=false;msg='Gross weight must be greater than 0.';}
  else if(t<0){mark($('f_tare'));ok=false;msg='Tare weight cannot be negative.';}
  else if(t>g){mark($('f_tare'));ok=false;msg='Tare weight cannot exceed gross weight.';}
  if(!ok){ev.preventDefault();toast(msg,'error');}
  else{const b=$('submitBtn');b.disabled=true;$('submitLabel').textContent='Saving...';}
});

$('editForm').addEventListener('submit',ev=>{
  const g=parseFloat($('e_gross').value)||0, t=parseFloat($('e_tare').value)||0;
  if(g<=0||t<0||t>g){ev.preventDefault();toast('Check weights — tare must be between 0 and gross.','error');}
});
document.querySelectorAll('.btn-edit').forEach(b=>b.addEventListener('click',()=>{
  const t=tickets[+b.dataset.idx];if(!t)return;
  $('e_id').value=t.id;$('e_plate').value=t.plate;$('e_driver').value=t.driver;$('e_product').value=t.product;
  $('e_client').value=t.client;$('e_gross').value=t.gross;$('e_tare').value=t.tare;$('e_dest').value=t.dest;
  $('e_status').value=t.status;$('e_notes').value=t.notes;
  $('editModal').classList.add('open');
}));

<?php if($isAdmin): ?>
document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click',()=>{
  const t=tickets[+b.dataset.idx];if(!t)return;
  $('del_id').value=t.id;$('del_no').textContent=t.ticket;
  $('deleteModal').classList.add('open');
}));
<?php endif; ?>

function ticketHTML(t){
  const net=Number(t.net)||0;
  return '<div class="pt-ticket" data-wm="'+esc(CO.name)+'"><div class="pt-inner">'+
    '<div>'+
    '<div class="pt-head">'+
      '<img class="pt-logo" src="logo.ico" alt="Logo">'+
      '<div class="pt-co"><h1>'+esc(CO.name)+'</h1><p class="tag">'+esc(CO.tag)+'</p><p>'+esc(CO.addr)+'</p><p>'+esc(CO.dept)+' &bull; Weighbridge Operations</p></div>'+
      '<div class="pt-doc"><span class="doc-badge">WEIGHBRIDGE TICKET</span><div class="doc-no">'+esc(t.ticket)+'</div><p class="doc-meta">Weighed: <b>'+fmtDT(t.date)+'</b></p><p class="doc-meta">Type: <b>'+esc(t.type)+'</b> &middot; Status: <b>'+esc(t.status)+'</b></p></div>'+
    '</div>'+
    '<div class="pt-grid">'+
      '<div class="pt-sec"><h4>Vehicle &amp; Driver</h4><div class="pt-row"><span>Vehicle Plate</span><b>'+v(t.plate)+'</b></div><div class="pt-row"><span>Driver</span><b>'+v(t.driver)+'</b></div><div class="pt-row"><span>Destination</span><b>'+v(t.dest)+'</b></div><div class="pt-row"><span>Recorded By</span><b>'+v(t.by)+'</b></div></div>'+
      '<div class="pt-sec"><h4>Load Details</h4><div class="pt-row"><span>Product</span><b>'+v(t.product)+'</b></div><div class="pt-row"><span>Client</span><b>'+v(t.client)+'</b></div><div class="pt-row"><span>Weigh Type</span><b>'+esc(t.type)+'</b></div><div class="pt-row"><span>Status</span><b>'+esc(t.status)+'</b></div></div>'+
    '</div>'+
    '<div class="pt-weights">'+
      '<div class="pt-wbox"><b>'+Number(t.gross||0).toLocaleString()+'</b><small>kg</small><span>Gross &mdash; Vehicle + Load</span></div>'+
      '<div class="pt-wbox"><b>'+Number(t.tare||0).toLocaleString()+'</b><small>kg</small><span>Tare &mdash; Empty Vehicle</span></div>'+
      '<div class="pt-wbox net"><b>'+net.toLocaleString()+'</b><small>kg &middot; '+fmtT(net)+'</small><span>Net &mdash; Product Weight</span></div>'+
    '</div>'+
    (String(t.notes||'').trim()?('<div class="pt-note"><b>Notes:</b> '+esc(t.notes)+'</div>'):'')+
    '<p class="pt-decl">I confirm that the weights above were taken on the company weighbridge in my presence and are true and accurate. Gross = vehicle + load. Tare = empty vehicle. Net = Gross &minus; Tare.</p>'+
    '</div>'+
    '<div>'+
    '<div class="pt-sigs"><div class="pt-sig"><div class="line"></div><span>Driver Signature</span><small>Sign &amp; date</small></div><div class="pt-sig"><div class="line"></div><span>Weighbridge Operator</span><small>Sign &amp; date</small></div><div class="pt-sig"><div class="line"></div><span>Security / Checker</span><small>Sign &amp; date</small></div></div>'+
    '<div class="pt-foot"><span>'+esc(CO.name)+' &mdash; TICKET '+esc(t.ticket)+'</span><span>Generated '+nowStr()+'</span></div>'+
    '</div>'+
  '</div></div>';
}

function runPrint(){
  const sheet=$('printSheet');
  if(!sheet.innerHTML.trim()){toast('Nothing to print.','error');return;}
  toast('Preparing print...','success');
  setTimeout(()=>{try{window.print();}catch(err){toast('Print failed: '+err.message,'error');}},200);
}
window.addEventListener('afterprint',()=>{$('printSheet').innerHTML='';});

document.querySelectorAll('.btn-ticket').forEach(b=>b.addEventListener('click',()=>{
  const t=tickets[+b.dataset.idx];
  if(!t){toast('Ticket data not found.','error');return;}
  const ticket=ticketHTML(t);
  $('printSheet').innerHTML='<div class="print-page">'+ticket+'<div class="cut-line">&#9986; Cut Here &#9986;</div>'+ticket+'</div>';
  runPrint();
}));

$('btnPrintLog').addEventListener('click',()=>{
  if(!tickets.length){toast('No tickets to print.','error');return;}
  const totG=tickets.reduce((s,t)=>s+Number(t.gross||0),0);
  const totT=tickets.reduce((s,t)=>s+Number(t.tare||0),0);
  const totN=tickets.reduce((s,t)=>s+Number(t.net||0),0);
  const heads=['Ticket','Date / Time','Plate','Driver','Product','Gross (kg)','Tare (kg)','Net (kg)','Type','Status'];
  const rows=tickets.map(t=>'<tr><td style="font-weight:700;color:#8a6a1f;white-space:nowrap">'+esc(t.ticket)+'</td><td>'+fmtDT(t.date)+'</td><td>'+v(t.plate)+'</td><td>'+v(t.driver)+'</td><td>'+v(t.product)+'</td><td style="font-family:var(--fm)">'+Number(t.gross||0).toLocaleString()+'</td><td style="font-family:var(--fm)">'+Number(t.tare||0).toLocaleString()+'</td><td style="font-family:var(--fm);font-weight:700">'+Number(t.net||0).toLocaleString()+'</td><td>'+esc(t.type)+'</td><td>'+esc(t.status)+'</td></tr>').join('');
  $('printSheet').innerHTML='<div style="font-family:var(--fb);color:#1a231d;font-size:8pt">'+
    '<div class="pt-head"><img class="pt-logo" src="logo.ico" alt="Logo"><div class="pt-co"><h1>'+esc(CO.name)+'</h1><p class="tag">'+esc(CO.tag)+'</p><p>'+esc(CO.addr)+'</p><p>'+esc(CO.dept)+' &bull; Weighbridge Operations</p></div><div class="pt-doc"><span class="doc-badge">WEIGH LOG REPORT</span><div class="doc-no">'+tickets.length+' ticket'+(tickets.length===1?'':'s')+'</div><p class="doc-meta">Generated: <b>'+nowStr()+'</b></p></div></div>'+
    '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6pt;margin:8pt 0"><div style="border:1pt solid #dbe4da;border-radius:4pt;padding:5pt 7pt;text-align:center"><b style="font-family:var(--fm);font-size:10pt;display:block">'+tickets.length+'</b><span style="font-size:5pt;font-weight:800;letter-spacing:.8pt;text-transform:uppercase;color:#77836f">Tickets</span></div><div style="border:1pt solid #dbe4da;border-radius:4pt;padding:5pt 7pt;text-align:center"><b style="font-family:var(--fm);font-size:10pt;display:block">'+totG.toLocaleString()+'</b><span style="font-size:5pt;font-weight:800;letter-spacing:.8pt;text-transform:uppercase;color:#77836f">Total Gross (kg)</span></div><div style="border:1pt solid #dbe4da;border-radius:4pt;padding:5pt 7pt;text-align:center"><b style="font-family:var(--fm);font-size:10pt;display:block">'+totT.toLocaleString()+'</b><span style="font-size:5pt;font-weight:800;letter-spacing:.8pt;text-transform:uppercase;color:#77836f">Total Tare (kg)</span></div><div style="border:1pt solid #e6cf9c;border-radius:4pt;padding:5pt 7pt;text-align:center;background:#faf3e2"><b style="font-family:var(--fm);font-size:10pt;display:block;color:#b57e17">'+totN.toLocaleString()+'</b><span style="font-size:5pt;font-weight:800;letter-spacing:.8pt;text-transform:uppercase;color:#8a6a1f">Total Net (kg)</span></div></div>'+
    '<table style="width:100%;border-collapse:collapse;font-size:6.5pt;margin-top:2pt"><thead><tr>'+heads.map(h=>'<th style="background:#b57e17;color:#fff;font-size:5.5pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 5pt;border:.7pt solid #9a6d12;text-align:left;white-space:nowrap">'+h+'</th>').join('')+'</tr></thead><tbody>'+rows+'</tbody><tfoot><tr><td colspan="5" style="background:#faf3e2;border:.7pt solid #e0c68f;font-weight:800;padding:4pt 5pt">TOTALS &mdash; '+tickets.length+' tickets</td><td style="background:#faf3e2;border:.7pt solid #e0c68f;font-weight:800;padding:4pt 5pt;font-family:var(--fm)">'+totG.toLocaleString()+'</td><td style="background:#faf3e2;border:.7pt solid #e0c68f;font-weight:800;padding:4pt 5pt;font-family:var(--fm)">'+totT.toLocaleString()+'</td><td style="background:#faf3e2;border:.7pt solid #e0c68f;font-weight:800;padding:4pt 5pt;font-family:var(--fm)">'+totN.toLocaleString()+'</td><td colspan="2" style="background:#faf3e2;border:.7pt solid #e0c68f;font-weight:800;padding:4pt 5pt">Net: '+fmtT(totN)+'</td></tr></tfoot></table>'+
    '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10pt;margin-top:14pt"><div style="text-align:center"><div style="height:28pt;border-bottom:1pt solid #4a554c;margin-bottom:2pt"></div><span style="font-size:5pt;font-weight:800;letter-spacing:.7pt;text-transform:uppercase;color:#5a554c">Weighbridge Operator</span></div><div style="text-align:center"><div style="height:28pt;border-bottom:1pt solid #4a554c;margin-bottom:2pt"></div><span style="font-size:5pt;font-weight:800;letter-spacing:.7pt;text-transform:uppercase;color:#5a554c">Logistics Manager</span></div><div style="text-align:center"><div style="height:28pt;border-bottom:1pt solid #4a554c;margin-bottom:2pt"></div><span style="font-size:5pt;font-weight:800;letter-spacing:.7pt;text-transform:uppercase;color:#5a554c">Authorised By</span></div></div>'+
    '<div style="margin-top:8pt;padding-top:4pt;border-top:.6pt solid #dfe5dd;display:flex;justify-content:space-between;font-size:4.5pt;color:#9aa79c"><span>'+esc(CO.name)+' &mdash; CONFIDENTIAL WEIGH LOG</span><span>Generated '+nowStr()+'</span></div>'+
  '</div>';
  runPrint();
});

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>
