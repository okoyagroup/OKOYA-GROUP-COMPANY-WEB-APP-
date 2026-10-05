<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA GROUP OF COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }
function fmtN($n): string { return '₦'.number_format((float)$n,2); }
function fmtCompact($n): string { $n=(float)$n; if($n>=1000000)return '₦'.number_format($n/1000000,1).'M'; if($n>=1000)return '₦'.number_format($n/1000,1).'K'; return '₦'.number_format($n,0); }
function matStatus(float $q, float $reorder): string { if($q<=0)return 'Out of Stock'; if($reorder>0 && $q<=$reorder)return 'Low Stock'; return 'In Stock'; }

/* ===== ENSURE SCHEMA ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_materials (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,quantity DECIMAL(12,2) DEFAULT 0,unit VARCHAR(20) DEFAULT 'units',status VARCHAR(20) DEFAULT 'In Stock',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE construction_materials ADD COLUMN IF NOT EXISTS category VARCHAR(60) NULL AFTER name");
    $pdo->exec("ALTER TABLE construction_materials ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(12,2) DEFAULT 0 AFTER quantity");
    $pdo->exec("ALTER TABLE construction_materials ADD COLUMN IF NOT EXISTS reorder_level DECIMAL(12,2) DEFAULT 0 AFTER unit_cost");
    $pdo->exec("ALTER TABLE construction_materials ADD COLUMN IF NOT EXISTS supplier VARCHAR(120) NULL AFTER reorder_level");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_material_movements (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,material_id INT UNSIGNED NOT NULL,movement_type ENUM('In','Out') NOT NULL,quantity DECIMAL(12,2) NOT NULL,project_id INT UNSIGNED NULL,movement_date DATE NOT NULL,notes VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $ignore){}

/* ===== ADD MATERIAL ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_add_material') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $name=trim($_POST['name']??''); $cat=trim($_POST['category']??''); $qty=floatval($_POST['quantity']??0);
        $unit=trim($_POST['unit']??'units'); $cost=floatval($_POST['unit_cost']??0); $reorder=floatval($_POST['reorder_level']??0);
        $supplier=trim($_POST['supplier']??'');
        if(mb_strlen($name)<2) flash('error','Material name is required.');
        else{
            try{
                $status=matStatus($qty,$reorder);
                $pdo->prepare("INSERT INTO construction_materials (name,category,quantity,unit,unit_cost,reorder_level,supplier,status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name,$cat?:null,$qty,$unit,$cost,$reorder,$supplier?:null,$status]);
                $mid=(int)$pdo->lastInsertId();
                if($qty>0){ try{$pdo->prepare("INSERT INTO construction_material_movements (material_id,movement_type,quantity,movement_date,notes) VALUES (?,?,?,CURDATE(),'Opening stock')")->execute([$mid,'In',$qty]);}catch(Exception $ignore){} }
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'ADD_MATERIAL','material',$mid,"Added material: $name"]);}catch(Exception $ignore){}
                flash('success','Material "'.$name.'" added to inventory.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        }
        header('Location: construction_materials.php'); exit;
    }
}

/* ===== UPDATE MATERIAL ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update_material') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['material_id']??0); $name=trim($_POST['name']??''); $cat=trim($_POST['category']??'');
        $qty=floatval($_POST['quantity']??0); $unit=trim($_POST['unit']??'units'); $cost=floatval($_POST['unit_cost']??0);
        $reorder=floatval($_POST['reorder_level']??0); $supplier=trim($_POST['supplier']??'');
        if($id>0 && mb_strlen($name)>=2){
            try{
                $status=matStatus($qty,$reorder);
                $pdo->prepare("UPDATE construction_materials SET name=?,category=?,quantity=?,unit=?,unit_cost=?,reorder_level=?,supplier=?,status=? WHERE id=?")
                    ->execute([$name,$cat?:null,$qty,$unit,$cost,$reorder,$supplier?:null,$status,$id]);
                flash('success','Material updated.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Material name is required.');
        header('Location: construction_materials.php'); exit;
    }
}

/* ===== DELETE MATERIAL ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete_material') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $id=(int)($_POST['material_id']??0);
        if($id>0){ try{$pdo->prepare("DELETE FROM construction_materials WHERE id=?")->execute([$id]); flash('success','Material deleted.');}catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); } }
        header('Location: construction_materials.php'); exit;
    }
}

/* ===== STOCK IN (increase) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_stock_in') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $mid=(int)($_POST['material_id']??0); $qty=floatval($_POST['quantity']??0); $mvDate=$_POST['movement_date']??date('Y-m-d'); $notes=trim($_POST['notes']??'');
        if($mid>0 && $qty>0){
            try{
                $st=$pdo->prepare("SELECT quantity,reorder_level,name FROM construction_materials WHERE id=?");
                $st->execute([$mid]); $m=$st->fetch();
                if(!$m) flash('error','Material not found.');
                else{
                    $newQty=(float)$m['quantity']+$qty;
                    $newStatus=matStatus($newQty,(float)$m['reorder_level']);
                    $pdo->prepare("UPDATE construction_materials SET quantity=?,status=? WHERE id=?")->execute([$newQty,$newStatus,$mid]);
                    $pdo->prepare("INSERT INTO construction_material_movements (material_id,movement_type,quantity,movement_date,notes) VALUES (?,?,?, ?,?)")
                        ->execute([$mid,'In',$qty,$mvDate,$notes?:null]);
                    try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'STOCK_IN','material',$mid,"Stock IN +$qty ".$m['name']]);}catch(Exception $ignore){}
                    flash('success','Stock IN recorded — +'.number_format($qty).' '.$m['unit'].' added.');
                }
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Enter a valid quantity to add.');
        header('Location: construction_materials.php'); exit;
    }
}

/* ===== STOCK OUT (decrease, assign to project) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_stock_out') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $mid=(int)($_POST['material_id']??0); $pid=(int)($_POST['project_id']??0); $qty=floatval($_POST['quantity']??0);
        $mvDate=$_POST['movement_date']??date('Y-m-d'); $notes=trim($_POST['notes']??'');
        if($mid>0 && $qty>0){
            try{
                $st=$pdo->prepare("SELECT quantity,reorder_level,name,unit FROM construction_materials WHERE id=?");
                $st->execute([$mid]); $m=$st->fetch();
                if(!$m) flash('error','Material not found.');
                elseif($qty>(float)$m['quantity']) flash('error','Not enough stock. Available: '.number_format((float)$m['quantity']).' '.$m['unit']);
                else{
                    $newQty=(float)$m['quantity']-$qty;
                    $newStatus=matStatus($newQty,(float)$m['reorder_level']);
                    $pdo->prepare("UPDATE construction_materials SET quantity=?,status=? WHERE id=?")->execute([$newQty,$newStatus,$mid]);
                    $pdo->prepare("INSERT INTO construction_material_movements (material_id,movement_type,quantity,project_id,movement_date,notes) VALUES (?,?,?,?,?,?)")
                        ->execute([$mid,'Out',$qty,$pid>0?$pid:null,$mvDate,$notes?:null]);
                    try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'STOCK_OUT','material',$mid,"Stock OUT -$qty ".$m['name']]);}catch(Exception $ignore){}
                    flash('success','Stock OUT recorded — stock updated.');
                }
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Enter a valid quantity to issue.');
        header('Location: construction_materials.php'); exit;
    }
}

/* ===== FETCH DATA ===== */
$materials=[]; try{ $materials=$pdo->query("SELECT * FROM construction_materials ORDER BY name ASC")->fetchAll(); }catch(Exception $ignore){}
$movements=[]; try{ $movements=$pdo->query("SELECT mv.*, m.name AS material_name, m.unit, p.name AS project_name FROM construction_material_movements mv LEFT JOIN construction_materials m ON m.id=mv.material_id LEFT JOIN construction_projects p ON p.id=mv.project_id ORDER BY mv.movement_date DESC, mv.id DESC LIMIT 120")->fetchAll(); }catch(Exception $ignore){}
$projects=[]; try{ $projects=$pdo->query("SELECT id,name FROM construction_projects ORDER BY name ASC")->fetchAll(); }catch(Exception $ignore){}

/* ===== STATS ===== */
$totalMaterials=count($materials);
$lowStock=0;$outStock=0;$stockValue=0.0;
foreach($materials as $m){ $q=(float)$m['quantity']; $r=(float)$m['reorder_level'];
    $st=matStatus($q,$r); if($st==='Low Stock')$lowStock++; if($st==='Out of Stock')$outStock++;
    $stockValue += $q*(float)$m['unit_cost']; }
$inToday=0;$outToday=0;
foreach($movements as $mv){ if(date('Y-m-d',strtotime($mv['movement_date']))===date('Y-m-d')){ if($mv['movement_type']==='In')$inToday++; else $outToday++; } }

$flash=get_flash();
function matPill(string $s): string {
    if($s==='In Stock')return '<span class="pill p-in">In Stock</span>';
    if($s==='Low Stock')return '<span class="pill p-low">Low Stock</span>';
    return '<span class="pill p-out">Out of Stock</span>';
}
function mvPill(string $t): string {
    return $t==='In' ? '<span class="pill p-in">+ IN</span>' : '<span class="pill p-out">− OUT</span>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Materials Inventory | <?php echo e($company['company_name']); ?></title>
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
.btn-in{background:var(--green-soft);color:var(--green);border:1.5px solid transparent}
.btn-in:hover{background:var(--green);color:#fff}
.btn-out{background:var(--amber-soft);color:var(--amber2);border:1.5px solid transparent}
.btn-out:hover{background:var(--amber);color:#fff}
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

/* TABLE */
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
.p-low{background:var(--amber-soft);color:var(--amber2)}
.p-out{background:var(--red-soft);color:var(--red)}
.empty{padding:60px 20px;text-align:center;color:var(--ink2)}
.empty .big{font-size:46px;margin-bottom:10px}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px;display:block;margin-top:4px}
.stock-bar{height:7px;border-radius:99px;background:var(--line);overflow:hidden;min-width:70px;margin-top:6px;position:relative}
.stock-bar div{height:100%;border-radius:99px;transition:width .5s cubic-bezier(.2,.8,.3,1)}

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
.stock-hint{display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border:1px solid var(--line);border-radius:11px;padding:10px 14px;font-size:12px;font-weight:700;color:var(--ink2);margin-bottom:14px}
.stock-hint b{font-family:var(--fm);color:var(--amber2)}

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
  .pi-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #b45f0a;padding-bottom:8pt;margin-bottom:10pt}
  .pi-logo{width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .pi-co{flex:1}
  .pi-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#b45f0a;line-height:1.15}
  .pi-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .pi-doc{text-align:right}
  .pi-doc h2{font-size:8.5pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#b45f0a;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .pi-doc p{font-size:6.5pt;color:#7a857c;margin-top:3pt}
  .pi-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:6pt;margin:9pt 0}
  .pi-sumbox{border:1pt solid #cfd8cd;border-radius:5pt;padding:6pt 8pt;text-align:center;background:#fafcf9}
  .pi-sumbox b{font-family:var(--fm);font-size:11pt;font-weight:700;display:block;color:#b45f0a}
  .pi-sumbox span{font-size:5.5pt;font-weight:800;letter-spacing:.8pt;color:#667268;text-transform:uppercase}
  .pi-table{width:100%;border-collapse:collapse;font-size:7pt;margin-top:4pt}
  .pi-table th{background:#b45f0a;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.5pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #b45f0a}
  .pi-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .pi-table tr:nth-child(even) td{background:#fafcf9}
  .pi-h{font-family:var(--fd);font-size:9pt;font-weight:800;color:#b45f0a;margin:12pt 0 4pt;text-transform:uppercase;letter-spacing:.8pt}
  .pi-sigs{display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid}
  .pi-sig{text-align:center}
  .pi-sigline{height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt}
  .pi-sig span{font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block}
  .pi-foot{margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
}

/* RESPONSIVE */
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
  <span class="badge amber">📦 INVENTORY</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container" id="app">
  <div class="page-header">
    <div>
      <div class="ph-strip"></div>
      <h1><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>Materials <em>Inventory</em></h1>
      <p>Full stock control — receive stock in, issue stock out, and track every movement.</p>
    </div>
    <div class="ph-btns">
      <button class="btn btn-ghost" id="btnPrintInv"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Inventory</button>
      <button class="btn btn-primary" id="btnAddMat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>Add Material</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg></div><div><b><?php echo $totalMaterials; ?></b><span>Materials</span><sub>in inventory</sub></div></div>
    <div class="stat"><div class="ic g"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-.8.9-1.5 3-1.5s3 .7 3 1.5-.9 1.5-3 1.5-3 .7-3 1.5.9 1.5 3 1.5 3-.7 3-1.5"/></svg></div><div><b><?php echo fmtCompact($stockValue); ?></b><span>Stock Value</span><sub>quantity × unit cost</sub></div></div>
    <div class="stat"><div class="ic r"><svg viewBox="0 0 24 24" fill="none"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg></div><div><b><?php echo $lowStock+$outStock; ?></b><span>Stock Alerts</span><sub><?php echo $lowStock; ?> low · <?php echo $outStock; ?> out</sub></div></div>
    <div class="stat"><div class="ic g"><svg viewBox="0 0 24 24" fill="none"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg></div><div><b><?php echo $inToday; ?></b><span>Stock In Today</span><sub>received</sub></div></div>
    <div class="stat"><div class="ic a"><svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg></div><div><b><?php echo $outToday; ?></b><span>Stock Out Today</span><sub>issued</sub></div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="inventory"><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Inventory</button>
    <button class="tab-btn" data-tab="movements"><svg viewBox="0 0 24 24" fill="none"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Stock Movements</button>
  </div>

  <!-- INVENTORY TAB -->
  <div class="tab-panel active" id="tab-inventory">
    <div class="card">
      <div class="card-head"><h2>Materials Inventory</h2><span class="count-pill"><?php echo count($materials); ?> items</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Material</th><th>Category</th><th>In Stock</th><th>Unit Cost</th><th>Stock Value</th><th>Reorder At</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($materials)): ?>
            <tr><td colspan="8"><div class="empty"><div class="big">📦</div><p>No materials in inventory</p><small>Add your first material to begin tracking stock.</small></div></td></tr>
          <?php else: foreach($materials as $m):
            $q=(float)$m['quantity'];$r=(float)$m['reorder_level'];$st=matStatus($q,$r);
            $maxBar=max($r*2,1); $barPct=min(100,round($q/$maxBar*100));
            $barColor=$st==='In Stock'?'var(--green)':($st==='Low Stock'?'var(--amber)':'var(--red)');
          ?>
            <tr>
              <td style="font-weight:800"><?php echo e($m['name']); ?><?php if($m['supplier']): ?><br><small style="color:var(--ink2);font-size:11px">Supplier: <?php echo e($m['supplier']); ?></small><?php endif; ?></td>
              <td><?php echo e($m['category']??'—'); ?></td>
              <td><span class="mono" style="font-weight:700"><?php echo number_format($q); ?> <?php echo e($m['unit']); ?></span><div class="stock-bar"><div style="width:<?php echo $barPct; ?>%;background:<?php echo $barColor; ?>"></div></div></td>
              <td class="mono"><?php echo fmtN($m['unit_cost']); ?></td>
              <td class="mono" style="color:var(--amber2);font-weight:700"><?php echo fmtCompact($q*(float)$m['unit_cost']); ?></td>
              <td class="mono"><?php echo number_format($r); ?></td>
              <td><?php echo matPill($st); ?></td>
              <td style="white-space:nowrap">
                <button class="btn btn-in btn-sm btn-stockin" data-id="<?php echo (int)$m['id']; ?>" data-name="<?php echo e($m['name']); ?>" data-unit="<?php echo e($m['unit']); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>In</button>
                <button class="btn btn-out btn-sm btn-stockout" data-id="<?php echo (int)$m['id']; ?>" data-name="<?php echo e($m['name']); ?>" data-unit="<?php echo e($m['unit']); ?>" data-avail="<?php echo $q; ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>Out</button>
                
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <!-- MOVEMENTS TAB -->
  <div class="tab-panel" id="tab-movements">
    <div class="card">
      <div class="card-head"><h2>Stock Movement Ledger</h2><span class="count-pill"><?php echo count($movements); ?> movements</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Quantity</th><th>Project</th><th>Notes</th></tr></thead>
        <tbody>
          <?php if(empty($movements)): ?>
            <tr><td colspan="6"><div class="empty"><div class="big">📉</div><p>No stock movements yet</p><small>Use Stock In / Stock Out on a material to log movements.</small></div></td></tr>
          <?php else: foreach($movements as $mv): ?>
            <tr>
              <td style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y',strtotime($mv['movement_date'])); ?></td>
              <td style="font-weight:800"><?php echo e($mv['material_name']??'—'); ?></td>
              <td><?php echo mvPill($mv['movement_type']); ?></td>
              <td class="mono" style="font-weight:700;color:<?php echo $mv['movement_type']==='In'?'var(--green)':'var(--amber2)'; ?>"><?php echo $mv['movement_type']==='In'?'+':'−'; ?><?php echo number_format((float)$mv['quantity']); ?> <?php echo e($mv['unit']); ?></td>
              <td><?php echo e($mv['project_name']??'—'); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo e($mv['notes']??'—'); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<!-- ADD/EDIT MATERIAL MODAL -->
<div class="modal-overlay" id="matModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg><span id="matTitle">Add Material</span></h3><button class="m-x" onclick="closeModal('matModal')">&times;</button></div>
  <div class="m-body"><form method="post" id="matForm">
    <input type="hidden" name="action" id="matAction" value="_add_material">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="material_id" id="matId">
    <div class="fgrid">
      <div class="field span2"><label class="fl">Material Name <b>*</b></label><div class="ctrl"><input name="name" id="m_name" placeholder="e.g. Cement" required></div></div>
      <div class="field"><label class="fl">Category</label><div class="ctrl"><input name="category" id="m_cat" placeholder="e.g. Building Material"></div></div>
      <div class="field"><label class="fl">Unit</label><div class="ctrl"><input name="unit" id="m_unit" placeholder="bags / tons / pcs"></div></div>
      <div class="field"><label class="fl">Quantity In Stock</label><div class="ctrl"><input name="quantity" id="m_qty" inputmode="decimal" placeholder="0"></div></div>
      <div class="field"><label class="fl">Unit Cost (₦)</label><div class="ctrl"><input name="unit_cost" id="m_cost" inputmode="decimal" placeholder="0.00"></div></div>
      <div class="field"><label class="fl">Reorder Level</label><div class="ctrl"><input name="reorder_level" id="m_reorder" inputmode="decimal" placeholder="0"></div></div>
      <div class="field"><label class="fl">Supplier</label><div class="ctrl"><input name="supplier" id="m_supplier" placeholder="Supplier name"></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('matModal')">Cancel</button><button type="submit" class="btn btn-primary"><span id="matSaveLbl">Save Material</span></button></div>
  </form></div>
</div></div>

<!-- STOCK IN MODAL -->
<div class="modal-overlay" id="stockInModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>Stock In (Receive)</h3><button class="m-x" onclick="closeModal('stockInModal')">&times;</button></div>
  <div class="m-body"><form method="post">
    <input type="hidden" name="action" value="_stock_in">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="material_id" id="si_material_id">
    <div class="stock-hint"><span>Material</span><b id="si_material_name">—</b></div>
    <div class="fgrid">
      <div class="field"><label class="fl">Quantity Received <b>*</b></label><div class="ctrl"><input name="quantity" id="si_qty" inputmode="decimal" placeholder="0" required></div></div>
      <div class="field"><label class="fl">Date</label><div class="ctrl"><input name="movement_date" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
      <div class="field span2"><label class="fl">Notes / Supplier</label><div class="ctrl ta"><textarea name="notes" rows="2" placeholder="e.g. Received from supplier"></textarea></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('stockInModal')">Cancel</button><button type="submit" class="btn btn-primary">Record Stock In</button></div>
  </form></div>
</div></div>

<!-- STOCK OUT MODAL -->
<div class="modal-overlay" id="stockOutModal"><div class="modal">
  <div class="m-head"><h3><svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>Stock Out (Issue)</h3><button class="m-x" onclick="closeModal('stockOutModal')">&times;</button></div>
  <div class="m-body"><form method="post">
    <input type="hidden" name="action" value="_stock_out">
    <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
    <input type="hidden" name="material_id" id="so_material_id">
    <div class="stock-hint"><span>Material</span><b id="so_material_name">—</b></div>
    <div class="stock-hint"><span>Available Stock</span><b id="so_avail">0</b></div>
    <div class="fgrid">
      <div class="field"><label class="fl">Quantity To Issue <b>*</b></label><div class="ctrl"><input name="quantity" id="so_qty" inputmode="decimal" placeholder="0" required></div></div>
      <div class="field"><label class="fl">Date</label><div class="ctrl"><input name="movement_date" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
      <div class="field span2"><label class="fl">Issue To Project</label><div class="ctrl"><select name="project_id"><option value="">General / Unassigned</option><?php foreach($projects as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div></div>
      <div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="notes" rows="2" placeholder="Optional note"></textarea></div></div>
    </div>
    <div class="m-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('stockOutModal')">Cancel</button><button type="submit" class="btn btn-primary">Record Stock Out</button></div>
  </form></div>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-head"><h3 style="color:var(--red)"><svg viewBox="0 0 24 24" fill="none" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Material?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:13px;margin-bottom:18px">This will permanently remove <strong id="del_name"></strong>.</p>
    <form method="post"><input type="hidden" name="action" value="_delete_material"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="material_id" id="del_mat_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>

    <footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Materials Inventory</div> </br>
    <div> <h5> Developped by Lawani Djamiou Alade </h5> </div>
    </footer>
<div class="toasts" id="toastWrap"></div>

<!-- DAILY INVENTORY PRINT SHEET (server-rendered) -->
<div id="printSheet">
  <div class="pi-head">
    <img class="pi-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
    <div class="pi-co"><h1><?php echo e($company['company_name']); ?></h1><p><?php echo e($company['company_address']); ?></p><p>Construction &amp; Projects Department — Inventory Unit</p></div>
    <div class="pi-doc"><h2>MATERIALS INVENTORY REPORT</h2><p>Date: <?php echo date('d M Y'); ?></p><p>Generated: <?php echo date('d M Y, H:i'); ?></p></div>
  </div>
  <div class="pi-sum">
    <div class="pi-sumbox"><b><?php echo $totalMaterials; ?></b><span>Materials</span></div>
    <div class="pi-sumbox"><b><?php echo fmtCompact($stockValue); ?></b><span>Stock Value</span></div>
    <div class="pi-sumbox"><b><?php echo $lowStock+$outStock; ?></b><span>Stock Alerts</span></div>
    <div class="pi-sumbox"><b><?php echo count($movements); ?></b><span>Movements</span></div>
  </div>
  <div class="pi-h">Materials In Stock</div>
  <table class="pi-table"><thead><tr><th>Material</th><th>Category</th><th>Qty</th><th>Unit</th><th>Unit Cost</th><th>Stock Value</th><th>Status</th></tr></thead><tbody>
    <?php if(empty($materials)): ?><tr><td colspan="7">No materials recorded.</td></tr>
    <?php else: foreach($materials as $m): $q=(float)$m['quantity']; ?>
      <tr><td><?php echo e($m['name']); ?></td><td><?php echo e($m['category']??'—'); ?></td><td><?php echo number_format($q); ?></td><td><?php echo e($m['unit']); ?></td><td><?php echo fmtN($m['unit_cost']); ?></td><td><?php echo fmtCompact($q*(float)$m['unit_cost']); ?></td><td><?php echo matStatus($q,(float)$m['reorder_level']); ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody></table>
  <div class="pi-h">Recent Stock Movements</div>
  <table class="pi-table"><thead><tr><th>Date</th><th>Material</th><th>Type</th><th>Quantity</th><th>Project</th><th>Notes</th></tr></thead><tbody>
    <?php if(empty($movements)): ?><tr><td colspan="6">No stock movements recorded.</td></tr>
    <?php else: foreach(array_slice($movements,0,30) as $mv): ?>
      <tr><td><?php echo date('d M Y',strtotime($mv['movement_date'])); ?></td><td><?php echo e($mv['material_name']??'—'); ?></td><td><?php echo e($mv['movement_type']); ?></td><td><?php echo number_format((float)$mv['quantity']); ?> <?php echo e($mv['unit']); ?></td><td><?php echo e($mv['project_name']??'—'); ?></td><td><?php echo e($mv['notes']??'—'); ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody></table>
  <div class="pi-sigs">
    <div class="pi-sig"><div class="pi-sigline"></div><span>Prepared By (Store Officer)</span></div>
    <div class="pi-sig"><div class="pi-sigline"></div><span>Authorised By (Manager)</span></div>
  </div>
  <div class="pi-foot"><span><?php echo e($company['company_name']); ?> — MATERIALS INVENTORY (CONFIDENTIAL)</span><span>Generated <?php echo date('d M Y, H:i'); ?></span></div>
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

/* materials data for edit */
const materialsData=<?php echo json_encode(array_map(function($m){return['id'=>(int)$m['id'],'name'=>$m['name'],'category'=>$m['category']??'','quantity'=>(float)$m['quantity'],'unit'=>$m['unit'],'unit_cost'=>(float)$m['unit_cost'],'reorder_level'=>(float)$m['reorder_level'],'supplier'=>$m['supplier']??''];},$materials),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ADD material */
$('btnAddMat').addEventListener('click',()=>{
  $('matForm').reset();$('matAction').value='_add_material';$('matId').value='';
  $('matTitle').textContent='Add Material';$('matSaveLbl').textContent='Save Material';
  openModal('matModal');
});
/* EDIT material */
document.querySelectorAll('.btn-editmat').forEach(b=>b.addEventListener('click',()=>{
  const m=materialsData.find(x=>x.id==b.dataset.id);if(!m)return;
  $('matAction').value='_update_material';$('matId').value=m.id;
  $('matTitle').textContent='Edit Material';$('matSaveLbl').textContent='Save Changes';
  $('m_name').value=m.name;$('m_cat').value=m.category;$('m_qty').value=m.quantity;$('m_unit').value=m.unit;
  $('m_cost').value=m.unit_cost;$('m_reorder').value=m.reorder_level;$('m_supplier').value=m.supplier;
  openModal('matModal');
}));
/* STOCK IN */
document.querySelectorAll('.btn-stockin').forEach(b=>b.addEventListener('click',()=>{
  $('si_material_id').value=b.dataset.id;$('si_material_name').textContent=b.dataset.name;
  $('si_qty').value='';openModal('stockInModal');
}));
/* STOCK OUT */
document.querySelectorAll('.btn-stockout').forEach(b=>b.addEventListener('click',()=>{
  $('so_material_id').value=b.dataset.id;$('so_material_name').textContent=b.dataset.name;
  $('so_avail').textContent=Number(b.dataset.avail).toLocaleString()+' '+b.dataset.unit;
  $('so_qty').value='';openModal('stockOutModal');
}));
/* DELETE material */
document.querySelectorAll('.btn-delmat').forEach(b=>b.addEventListener('click',()=>{
  $('del_mat_id').value=b.dataset.id;$('del_name').textContent=b.dataset.name;openModal('deleteModal');
}));

/* PRINT */
$('btnPrintInv').addEventListener('click',()=>window.print());

const FLASH=<?php echo json_encode($flash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
<?php if($flash): ?>
window.addEventListener('DOMContentLoaded',()=>toast(<?php echo json_encode($flash['msg'],JSON_HEX_TAG|JSON_HEX_QUOT); ?>,'<?php echo $flash['type']; ?>'));
<?php endif; ?>
</script>
</body>
</html>