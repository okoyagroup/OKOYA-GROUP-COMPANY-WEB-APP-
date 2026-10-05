<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: ../index.php'); exit; }
$isAdmin = in_array($auth['role'],['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Maintenance & Operations Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function flash(string $t,string $m):void{ $_SESSION['flash']=['type'=>$t,'msg'=>$m]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ensure schema */
try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_materials (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,category VARCHAR(60) DEFAULT 'General',unit VARCHAR(20) DEFAULT 'units',quantity DECIMAL(12,2) DEFAULT 0,reorder_level DECIMAL(12,2) DEFAULT 0,unit_cost DECIMAL(12,2) DEFAULT 0,status ENUM('In Stock','Low Stock','Out of Stock') DEFAULT 'In Stock',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_usage (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,material_id INT UNSIGNED NOT NULL,quantity_used DECIMAL(12,2) NOT NULL,used_date DATE NOT NULL,used_by INT UNSIGNED NULL,purpose VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}catch(Exception $ignore){}

$categories=['Fuel','Lubricants & Oils','Spare Parts','Tools & Equipment','Cleaning & Sanitation','Safety & PPE','Electrical','Plumbing','Consumables','General'];

/* helper to refresh material status from quantity */
function refreshStatus(PDO $pdo,int $id):void{
    try{
        $st=$pdo->prepare("SELECT quantity,reorder_level FROM maintenance_materials WHERE id=?");$st->execute([$id]);
        if($m=$st->fetch()){
            $q=(float)$m['quantity'];$r=(float)$m['reorder_level'];
            $s=$q<=0?'Out of Stock':($r>0&&$q<=$r?'Low Stock':'In Stock');
            $pdo->prepare("UPDATE maintenance_materials SET status=? WHERE id=?")->execute([$s,$id]);
        }
    }catch(Exception $ignore){}
}

/* ===== ADD MATERIAL ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_add'){
    $name=trim($_POST['name']??'');$cat=$_POST['category']??'General';$unit=trim($_POST['unit']??'units');
    $qty=floatval($_POST['quantity']??0);$reorder=floatval($_POST['reorder_level']??0);$cost=floatval($_POST['unit_cost']??0);
    if(mb_strlen($name)<2)flash('error','Material name is required.');
    else{
        try{
            $pdo->prepare("INSERT INTO maintenance_materials(name,category,unit,quantity,reorder_level,unit_cost,status)VALUES(?,?,?,?,?,?,?)")
                ->execute([$name,$cat,$unit,$qty,$reorder,$cost,$qty<=0?'Out of Stock':($reorder>0&&$qty<=$reorder?'Low Stock':'In Stock')]);
            flash('success','Material "'.$name.'" added.');
        }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
    }
    header('Location: admin_maintenance_dashboard.php');exit;
}

/* ===== UPDATE MATERIAL ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_update'){
    $id=(int)($_POST['material_id']??0);$name=trim($_POST['name']??'');$cat=$_POST['category']??'General';
    $unit=trim($_POST['unit']??'units');$qty=floatval($_POST['quantity']??0);$reorder=floatval($_POST['reorder_level']??0);$cost=floatval($_POST['unit_cost']??0);
    if($id>0&&mb_strlen($name)>=2){
        try{
            $pdo->prepare("UPDATE maintenance_materials SET name=?,category=?,unit=?,quantity=?,reorder_level=?,unit_cost=? WHERE id=?")
                ->execute([$name,$cat,$unit,$qty,$reorder,$cost,$id]);
            refreshStatus($pdo,$id);
            flash('success','Material updated.');
        }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
    }else flash('error','Material name is required.');
    header('Location: admin_maintenance_dashboard.php');exit;
}

/* ===== DELETE MATERIAL ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_delete'&&$isAdmin){
    $id=(int)($_POST['material_id']??0);
    if($id>0){try{$pdo->prepare("DELETE FROM maintenance_materials WHERE id=?")->execute([$id]);flash('success','Material deleted.');}catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}}
    header('Location: maintenance_dashboard.php');exit;
}

/* ===== RECORD CONSUMPTION ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_consume'){
    $mid=(int)($_POST['material_id']??0);$qty=floatval($_POST['quantity_used']??0);
    $usedDate=$_POST['used_date']??date('Y-m-d');$purpose=trim($_POST['purpose']??'');
    if($mid>0&&$qty>0){
        try{
            $st=$pdo->prepare("SELECT quantity,unit,name FROM maintenance_materials WHERE id=?");$st->execute([$mid]);
            if($m=$st->fetch()){
                if($qty>(float)$m['quantity'])flash('error','Not enough stock. Available: '.number_format((float)$m['quantity']).' '.$m['unit']);
                else{
                    $pdo->prepare("UPDATE maintenance_materials SET quantity=quantity-? WHERE id=?")->execute([$qty,$mid]);
                    refreshStatus($pdo,$mid);
                    $pdo->prepare("INSERT INTO maintenance_usage(material_id,quantity_used,used_date,used_by,purpose)VALUES(?,?,?,?,?)")
                        ->execute([$mid,$qty,$usedDate,$auth['id'],$purpose?:null]);
                    flash('success','Consumed '.number_format($qty).' '.$m['unit'].' of '.$m['name'].'.');
                }
            }else flash('error','Material not found.');
        }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
    }else flash('error','Select a material and enter a quantity.');
    header('Location: maintenance_dashboard.php');exit;
}

/* ===== FETCH ===== */
$materials=[];try{$materials=$pdo->query("SELECT * FROM maintenance_materials ORDER BY name ASC")->fetchAll();}catch(Exception $ignore){}
$usage=[];try{$usage=$pdo->query("SELECT u.*,m.name AS material_name,m.unit FROM maintenance_usage u LEFT JOIN maintenance_materials m ON m.id=u.material_id ORDER BY u.used_date DESC,u.id DESC LIMIT 60")->fetchAll();}catch(Exception $ignore){}

/* stats */
$totalMaterials=count($materials);$lowStock=0;$outStock=0;$stockValue=0.0;$todayUse=0.0;
foreach($materials as $m){
    if($m['status']==='Low Stock')$lowStock++;
    if($m['status']==='Out of Stock')$outStock++;
    $stockValue+=(float)$m['quantity']*(float)$m['unit_cost'];
}
try{$todayUse=(float)$pdo->query("SELECT IFNULL(SUM(quantity_used),0) FROM maintenance_usage WHERE used_date=CURDATE()")->fetchColumn();}catch(Exception $ignore){}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance Dashboard | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
    /* PAGE TRANSITION LOADER */
.page-loader{position:fixed;inset:0;z-index:99999;background:var(--bg,#f3f5f0);display:flex;flex-direction:column;align-items:center;justify-content:center;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .3s ease,visibility .3s ease}
.page-loader.active{opacity:1;visibility:visible;pointer-events:all}
.pl-orbits{position:absolute;width:160px;height:160px;pointer-events:none}
.pl-orbit{position:absolute;inset:0;border-radius:50%;border:1.5px solid transparent}
.pl-o1{border-top-color:var(--green,#166e45);border-right-color:var(--green,#166e45);animation:plSpin 2s linear infinite}
.pl-o2{inset:12px;border-bottom-color:var(--gold,#d99a2b);border-left-color:var(--gold,#d99a2b);animation:plSpin 2.8s linear infinite reverse}
.pl-o3{inset:24px;border-top-color:var(--blue,#2563eb);border-right-color:var(--blue,#2563eb);animation:plSpin 1.5s linear infinite;opacity:.5}
@keyframes plSpin{to{transform:rotate(360deg)}}
.pl-logo{width:64px;height:64px;border-radius:16px;background:var(--surface,#fff);border:1px solid var(--line,#dfe5da);display:grid;place-items:center;overflow:hidden;margin-bottom:20px;animation:plPulse 1.8s ease-in-out infinite;box-shadow:0 4px 16px rgba(0,0,0,.08)}
.pl-logo img{width:52px;height:52px;object-fit:contain}
@keyframes plPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(22,110,69,.25)}50%{transform:scale(1.06);box-shadow:0 0 0 14px rgba(22,110,69,0)}}
.pl-bar-wrap{width:180px;height:3px;background:var(--line,#dfe5da);border-radius:99px;overflow:hidden}
.pl-bar{height:100%;width:0;background:linear-gradient(90deg,var(--green,#166e45),var(--gold,#d99a2b));border-radius:99px}
.pl-bar.animating{animation:plBar 1.6s ease-in-out forwards}
@keyframes plBar{0%{width:0}30%{width:45%}60%{width:75%}100%{width:100%}}
.pl-text{font-family:'Sora',sans-serif;font-size:12px;font-weight:700;color:var(--ink2,#5f6e64);margin-top:12px;letter-spacing:.5px;animation:plTextFade 1.2s ease-in-out infinite alternate}
@keyframes plTextFade{0%{opacity:.3}100%{opacity:1}}

/* Page content hidden during transition */
#app{transition:opacity .4s ease}
#app.fade-out{opacity:0}
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
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
.badge.admin{background:var(--gold-soft);color:var(--gold2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}
.page-header{padding:30px 0 10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:28px;height:28px;stroke:var(--green)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.header-btns{display:flex;gap:10px;flex-wrap:wrap}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:18px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}
.stat .ic svg{width:21px;height:21px}
.stat .ic.g{background:var(--green-soft)}.stat .ic.g svg{stroke:var(--green)}
.stat .ic.y{background:var(--gold-soft)}.stat .ic.y svg{stroke:var(--gold2)}
.stat .ic.r{background:var(--red-soft)}.stat .ic.r svg{stroke:var(--red)}
.stat .ic.b{background:var(--blue-soft)}.stat .ic.b svg{stroke:var(--blue)}
.stat b{font-family:var(--fd);font-size:20px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
.btn svg{width:15px;height:15px}
/* tabs */
.tabs{display:flex;gap:8px;margin:22px 0 16px;flex-wrap:wrap}
.tab-btn{padding:11px 20px;border-radius:12px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink2);font-family:var(--fd);font-weight:700;font-size:13px;transition:.2s;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.tab-btn svg{width:16px;height:16px}
.tab-btn:hover{border-color:var(--green);color:var(--green)}
.tab-btn.active{background:var(--green);color:#fff;border-color:var(--green)}
.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadein .3s}
@keyframes fadein{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:820px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.st-pill{font-size:10px;font-weight:800;padding:4px 10px;border-radius:99px;display:inline-block}
.st-pill.in{background:var(--green-soft);color:var(--green)}
.st-pill.low{background:var(--gold-soft);color:var(--gold2)}
.st-pill.out{background:var(--red-soft);color:var(--red)}
.stock-bar{height:7px;border-radius:99px;background:var(--line);overflow:hidden;min-width:60px;margin-top:5px}
.stock-bar div{height:100%;border-radius:99px}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty svg{width:48px;height:48px;stroke:var(--ink2);margin:0 auto 10px;opacity:.5}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}
.actions-cell{display:flex;gap:6px;white-space:nowrap}
/* modal */
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.m-header{padding:22px 26px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--surface);z-index:2}
.m-header h3{font-family:var(--fd);font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px}
.m-header h3 svg{width:20px;height:20px;stroke:var(--green)}
.m-x{width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:17px;cursor:pointer;display:grid;place-items:center}
.m-body{padding:22px 26px 26px}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.span2{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:5px}
.fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 13px;height:46px;transition:.2s}
.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent);background:var(--surface)}
.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:14px}
.ctrl select{cursor:pointer;appearance:none}
.ctrl textarea{padding:10px 0;height:auto;min-height:50px;resize:vertical}
.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
/* PRINT */
#printSheet{display:none}
@media print{
  @page{size:A4;margin:11mm}
  body{background:#fff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body::before,#app,.toasts,.modal-overlay,.topbar,.page-header,.tabs,.card,.stats,.footer-el{display:none!important}
  #printSheet{display:block!important;color:#1a231d;font-family:var(--fb);font-size:8.5pt}
  .pr-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #166e45;padding-bottom:8pt;margin-bottom:12pt}
  .pr-logo{width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .pr-co{flex:1}.pr-co h1{font-family:'Sora',sans-serif;font-size:13pt;font-weight:800;color:#166e45;line-height:1.15}
  .pr-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .pr-doc{text-align:right;flex:none}.pr-doc h2{font-size:8pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#166e45;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .pr-doc p{font-size:6pt;color:#7a857c;margin-top:3pt}
  .pr-table{width:100%;border-collapse:collapse;font-size:7.5pt;margin-top:6pt}
  .pr-table th{background:#166e45;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #166e45}
  .pr-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .pr-table tr:nth-child(even) td{background:#fafcf9}
  .pr-h{font-family:'Sora',sans-serif;font-size:9pt;font-weight:800;color:#166e45;margin:14pt 0 5pt;text-transform:uppercase;letter-spacing:.9pt;border-bottom:1.2pt solid #166e45;padding-bottom:3pt}
  .pr-foot{margin-top:12pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.fgrid{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr;gap:10px}.topbar .badge{display:none}.brand strong{font-size:12px}.m-body{padding:18px}.m-header{padding:18px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}.header-btns{width:100%}.header-btns .btn{flex:1}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $isAdmin?'../admin_first_dashboard.php':'staff_first_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back</a>
  <span class="badge admin">MAINTENANCE</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div><h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Maintenance <em>Dashboard</em></h1><p>Manage daily materials — petrol, diesel, and every consumable the company needs.</p></div>
    <div class="header-btns">
      <button class="btn btn-ghost" id="btnPrint"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Report</button>
      <?php if($isAdmin): ?><button class="btn btn-primary" id="btnAddOpen"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Material</button><?php endif; ?>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg></div><div><b><?php echo $totalMaterials; ?></b><span>Materials</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></div><div><b><?php echo $lowStock; ?></b><span>Low Stock</span></div></div>
    <div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div><div><b><?php echo $outStock; ?></b><span>Out of Stock</span></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg></div><div><b><?php echo number_format($todayUse); ?></b><span>Used Today</span></div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="inventory"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Materials Inventory</button>
    <button class="tab-btn" data-tab="consume"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Record Consumption</button>
    <button class="tab-btn" data-tab="log"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Usage Log</button>
  </div>

  <!-- INVENTORY TAB -->
  <div class="tab-panel active" id="tab-inventory">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Materials Inventory</h2><span class="count-pill"><?php echo count($materials); ?> items</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Material</th><th>Category</th><th>In Stock</th><th>Reorder At</th><th>Unit Cost</th><th>Stock Value</th><th>Status</th><?php if($isAdmin): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
          <?php if(empty($materials)): ?>
            <tr><td colspan="8"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg><p>No materials yet</p><small>Add your first material (petrol, diesel, etc.).</small></div></td></tr>
          <?php else: foreach($materials as $m):
            $q=(float)$m['quantity'];$r=(float)$m['reorder_level'];
            $barMax=max($r*2,1);$barPct=min(100,round($q/$barMax*100));
            $barColor=$m['status']==='In Stock'?'var(--green)':($m['status']==='Low Stock'?'var(--gold)':'var(--red)');
            $pillCls=$m['status']==='In Stock'?'in':($m['status']==='Low Stock'?'low':'out');
          ?>
            <tr>
              <td style="font-weight:800"><?php echo e($m['name']); ?></td>
              <td><?php echo e($m['category']); ?></td>
              <td><span class="mono" style="font-weight:700"><?php echo number_format($q); ?> <?php echo e($m['unit']); ?></span><div class="stock-bar"><div style="width:<?php echo $barPct; ?>%;background:<?php echo $barColor; ?>"></div></div></td>
              <td class="mono"><?php echo number_format($r); ?></td>
              <td class="mono"><?php echo fmtN($m['unit_cost']); ?></td>
              <td class="mono" style="color:var(--green);font-weight:700"><?php echo fmtN($q*(float)$m['unit_cost']); ?></td>
              <td><span class="st-pill <?php echo $pillCls; ?>"><?php echo e($m['status']); ?></span></td>
              <?php if($isAdmin): ?><td><div class="actions-cell">
                <button class="btn btn-ghost btn-sm btn-edit" data-id="<?php echo (int)$m['id']; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
                <button class="btn btn-danger btn-sm btn-del" data-id="<?php echo (int)$m['id']; ?>" data-name="<?php echo e($m['name']); ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
              </div></td><?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- CONSUME TAB -->
  <div class="tab-panel" id="tab-consume">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Record Daily Consumption</h2></div>
      <div style="padding:22px 26px 26px">
        <form method="post">
          <input type="hidden" name="action" value="_consume">
          <div class="fgrid">
            <div class="field span2"><label class="fl">Material <b>*</b></label><div class="ctrl"><select name="material_id" required><option value="">Select material…</option><?php foreach($materials as $m): ?><option value="<?php echo (int)$m['id']; ?>"><?php echo e($m['name']); ?> (<?php echo number_format((float)$m['quantity']); ?> <?php echo e($m['unit']); ?> available)</option><?php endforeach; ?></select></div></div>
            <div class="field"><label class="fl">Quantity Used <b>*</b></label><div class="ctrl"><input name="quantity_used" type="number" step="0.01" min="0.01" required placeholder="0"></div></div>
            <div class="field"><label class="fl">Date</label><div class="ctrl"><input name="used_date" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
            <div class="field span2"><label class="fl">Purpose / Notes</label><div class="ctrl ta"><textarea name="purpose" rows="2" placeholder="e.g. Diesel for generator, petrol for delivery van…"></textarea></div></div>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:15px;margin-top:16px"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>Record Consumption</button>
        </form>
      </div>
    </div>
  </div>

  <!-- USAGE LOG TAB -->
  <div class="tab-panel" id="tab-log">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Consumption Log</h2><span class="count-pill"><?php echo count($usage); ?> records</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Material</th><th>Qty Used</th><th>Purpose</th></tr></thead>
        <tbody>
          <?php if(empty($usage)): ?>
            <tr><td colspan="4"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><p>No consumption recorded yet</p><small>Record daily consumption to see it here.</small></div></td></tr>
          <?php else: foreach($usage as $u): ?>
            <tr>
              <td class="mono" style="font-weight:700"><?php echo date('d M Y',strtotime($u['used_date'])); ?></td>
              <td style="font-weight:800"><?php echo e($u['material_name']??'—'); ?></td>
              <td class="mono" style="color:var(--gold2);font-weight:700">−<?php echo number_format((float)$u['quantity_used']); ?> <?php echo e($u['unit']); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo e($u['purpose']??'—'); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-overlay" id="matModal"><div class="modal">
  <div class="m-header"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg><span id="matTitle">Add Material</span></h3><button class="m-x" onclick="closeModal('matModal')">&times;</button></div>
  <div class="m-body">
    <form method="post" id="matForm">
      <input type="hidden" name="action" id="matAction" value="_add">
      <input type="hidden" name="material_id" id="matId">
      <div class="fgrid">
        <div class="field span2"><label class="fl">Material Name <b>*</b></label><div class="ctrl"><input name="name" id="m_name" required placeholder="e.g. Diesel (AGO)"></div></div>
        <div class="field"><label class="fl">Category</label><div class="ctrl"><select name="category" id="m_cat"><?php foreach($categories as $c): ?><option><?php echo e($c); ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="fl">Unit</label><div class="ctrl"><input name="unit" id="m_unit" placeholder="litres / units / pcs"></div></div>
        <div class="field"><label class="fl">Quantity In Stock</label><div class="ctrl"><input name="quantity" id="m_qty" type="number" step="0.01" placeholder="0"></div></div>
        <div class="field"><label class="fl">Reorder Level</label><div class="ctrl"><input name="reorder_level" id="m_reorder" type="number" step="0.01" placeholder="0"></div></div>
        <div class="field span2"><label class="fl">Unit Cost (₦)</label><div class="ctrl"><input name="unit_cost" id="m_cost" type="number" step="0.01" placeholder="0.00"></div></div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:15px;margin-top:16px"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/></svg>Save Material</button>
    </form>
  </div>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-header"><h3 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Material?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:13px;margin-bottom:16px">This will permanently remove <strong id="del_name"></strong>.</p>
    <form method="post"><input type="hidden" name="action" value="_delete"><input type="hidden" name="material_id" id="del_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>

<footer class="footer-el"><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Maintenance &amp; Operations</div> </br>
     <div> <h5> Developped by Lawani Djamiou Alade </h5> </div>
    </footer>  
   
    
<div class="toasts" id="toastWrap"></div>
<div id="printSheet"></div>

<script>
const $=id=>document.getElementById(id);
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'&mdash;':String(s);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),4000);}
function openModal(id){$(id).classList.add('open')}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* tabs */
document.querySelectorAll('.tab-btn').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(x=>x.classList.remove('active'));
  b.classList.add('active');$('tab-'+b.dataset.tab).classList.add('active');
}));

const materialsData=<?php echo json_encode(array_map(function($m){return['id'=>(int)$m['id'],'name'=>$m['name'],'category'=>$m['category'],'unit'=>$m['unit'],'quantity'=>(float)$m['quantity'],'reorder_level'=>(float)$m['reorder_level'],'unit_cost'=>(float)$m['unit_cost'],'status'=>$m['status']];},$materials),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const cn='<?php echo addslashes($company['company_name']); ?>';
const ca='<?php echo addslashes($company['company_address']); ?>';
const logo='<?php echo e($logoUrl); ?>';

/* add material */
if($('btnAddOpen'))$('btnAddOpen').addEventListener('click',()=>{$('matForm').reset();$('matAction').value='_add';$('matId').value='';$('matTitle').textContent='Add Material';openModal('matModal');});
/* edit material */
document.querySelectorAll('.btn-edit').forEach(b=>b.addEventListener('click',()=>{
  const m=materialsData.find(x=>x.id==b.dataset.id);if(!m)return;
  $('matAction').value='_update';$('matId').value=m.id;$('matTitle').textContent='Edit Material';
  $('m_name').value=m.name;$('m_cat').value=m.category;$('m_unit').value=m.unit;
  $('m_qty').value=m.quantity;$('m_reorder').value=m.reorder_level;$('m_cost').value=m.unit_cost;
  openModal('matModal');
}));
/* delete material */
document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click',()=>{$('del_id').value=b.dataset.id;$('del_name').textContent=b.dataset.name;openModal('deleteModal');}));

/* print report */
$('btnPrint').addEventListener('click',()=>{
  if(!materialsData.length){toast('No materials to print.','error');return;}
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  const rows=materialsData.map(m=>{
    const val=m.quantity*m.unit_cost;
    return '<tr><td>'+m.name+'</td><td>'+m.category+'</td><td>'+Number(m.quantity).toLocaleString()+' '+m.unit+'</td><td>'+Number(m.reorder_level).toLocaleString()+'</td><td>₦'+Number(m.unit_cost).toLocaleString()+'</td><td>₦'+Number(val).toLocaleString()+'</td><td>'+m.status+'</td></tr>';
  }).join('');
  const totVal=materialsData.reduce((s,m)=>s+m.quantity*m.unit_cost,0);
  $('printSheet').innerHTML=
  '<div>'+
    '<div class="pr-head"><img class="pr-logo" src="'+logo+'" alt="">'+
      '<div class="pr-co"><h1>'+cn+'</h1><p>'+ca+'</p><p>Maintenance &amp; Operations Department</p></div>'+
      '<div class="pr-doc"><h2>MAINTENANCE MATERIALS REPORT</h2><p>Generated: '+genDate+'</p></div>'+
    '</div>'+
    '<div class="pr-h">Materials Inventory</div>'+
    '<table class="pr-table"><thead><tr><th>Material</th><th>Category</th><th>In Stock</th><th>Reorder At</th><th>Unit Cost</th><th>Stock Value</th><th>Status</th></tr></thead><tbody>'+rows+'</tbody></table>'+
    '<div class="pr-h" style="margin-top:10pt">Total Stock Value: ₦'+Number(totVal).toLocaleString()+'</div>'+
    '<div class="pr-foot"><span>'+cn+' — MAINTENANCE MATERIALS (CONFIDENTIAL)</span><span>Generated '+genDate+'</span></div>'+
  '</div>';
  window.print();
});

<?php if($flash=get_flash()): ?>window.addEventListener('DOMContentLoaded',()=>toast(<?php echo json_encode($flash['msg'],JSON_HEX_TAG|JSON_HEX_QUOT); ?>,'<?php echo $flash['type']; ?>'));<?php endif; ?>
</script>
</body>
</html>