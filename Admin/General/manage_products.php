<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria','company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'];
$logoUrl = './Client Order Form _ Okoya Food   mr wahab_files/logo_uigcps.jpg';

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== ADD PRODUCT (admin) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_create' && $isAdmin) {
    $name = trim($_POST['name'] ?? '');
    $code = strtolower(trim($_POST['code'] ?? ''));
    $desc = trim($_POST['description'] ?? '');
    $price = floatval($_POST['default_price'] ?? 0);
    $stock = floatval($_POST['stock_kg'] ?? 0);
    $unit = trim($_POST['unit'] ?? 'kg');
    $img  = trim($_POST['image_url'] ?? '');
    $active = isset($_POST['is_active']) ? 1 : 0;
    $errs=[];
    if(strlen($name)<2)$errs[]='Product name is required';
    if($code==='')$errs[]='Product code is required';
    if($price<=0)$errs[]='Price must be greater than 0';
    if(empty($errs)){
        try{
            $st=$pdo->prepare("SELECT id FROM products WHERE code=? OR LOWER(name)=LOWER(?)");
            $st->execute([$code,$name]);
            if($st->fetch()) $errs[]='A product with this code or name already exists';
            else{
                $pdo->prepare("INSERT INTO products (code,name,description,default_price,stock_kg,unit,image_url,is_active) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$code,$name,$desc?:null,$price,$stock,$unit,$img?:null,$active]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                    ->execute([$auth['id'],'CREATE_PRODUCT','product',(int)$pdo->lastInsertId(),"Added product: $name"]);}catch(Exception $ignore){}
                flash('success',"Product '$name' added to catalog.");
                header('Location: manage_products.php'); exit;
            }
        }catch(Exception $ex){ $errs[]='Database error: '.$ex->getMessage(); }
    }
    if(!empty($errs)) flash('error', implode('. ',$errs));
}

/* ===== UPDATE PRODUCT (admin) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update' && $isAdmin) {
    $id=(int)($_POST['product_id']??0);
    $name=trim($_POST['name']??''); $desc=trim($_POST['description']??'');
    $price=floatval($_POST['default_price']??0); $stock=floatval($_POST['stock_kg']??0);
    $unit=trim($_POST['unit']??'kg'); $img=trim($_POST['image_url']??'');
    $active=isset($_POST['is_active'])?1:0;
    if($id>0 && strlen($name)>=2 && $price>0){
        try{
            $pdo->prepare("UPDATE products SET name=?,description=?,default_price=?,stock_kg=?,unit=?,image_url=?,is_active=? WHERE id=?")
                ->execute([$name,$desc?:null,$price,$stock,$unit,$img?:null,$active,$id]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                ->execute([$auth['id'],'UPDATE_PRODUCT','product',$id,"Updated product: $name"]);}catch(Exception $ignore){}
            flash('success','Product updated.');
        }catch(Exception $ex){ flash('error','Update failed: '.$ex->getMessage()); }
        header('Location: manage_products.php'); exit;
    } else flash('error','Name and a valid price are required.');
}

/* ===== TOGGLE ACTIVE (admin) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_toggle' && $isAdmin) {
    $id=(int)($_POST['product_id']??0);
    if($id>0){
        try{
            $pdo->prepare("UPDATE products SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            flash('success','Product status updated.');
        }catch(Exception $ex){ flash('error','Toggle failed: '.$ex->getMessage()); }
        header('Location: manage_products.php'); exit;
    }
}

/* ===== DELETE PRODUCT (admin) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete' && $isAdmin) {
    $id=(int)($_POST['product_id']??0);
    if($id>0){
        try{
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                ->execute([$auth['id'],'DELETE_PRODUCT','product',$id,"Deleted product ID $id"]);}catch(Exception $ignore){}
            flash('success','Product deleted.');
        }catch(Exception $ex){ flash('error','Delete failed (product may have linked orders): '.$ex->getMessage()); }
        header('Location: manage_products.php'); exit;
    }
}

/* ===== FETCH PRODUCTS ===== */
$search=trim($_GET['search']??''); $filterStatus=$_GET['status']??'';
$where=[];$params=[];
if($search!==''){ $where[]="(p.name LIKE ? OR p.code LIKE ?)"; array_push($params,"%$search%","%$search%"); }
if($filterStatus==='active'){ $where[]="p.is_active=1"; }
if($filterStatus==='inactive'){ $where[]="p.is_active=0"; }
$wc=$where?'WHERE '.implode(' AND ',$where):'';
$products=[];
try{
    $st=$pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM orders o WHERE o.product_id=p.id) AS order_count,
                       (SELECT IFNULL(SUM(o.total_amount),0) FROM orders o WHERE o.product_id=p.id) AS revenue
                       FROM products p $wc ORDER BY p.name ASC");
    $st->execute($params); $products=$st->fetchAll();
}catch(Exception $ignore){}

$totProducts=0;$activeProducts=0;$avgPrice=0.0;$topName='—';
try{
    $totProducts=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $activeProducts=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
    $avgPrice=(float)$pdo->query("SELECT IFNULL(AVG(default_price),0) FROM products")->fetchColumn();
    $top=$pdo->query("SELECT p.name FROM products p LEFT JOIN orders o ON o.product_id=p.id GROUP BY p.id ORDER BY COUNT(o.id) DESC LIMIT 1")->fetchColumn();
    if($top!==false)$topName=$top;
}catch(Exception $ignore){}

$flashData = get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Products Catalog | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;--ink:#0f172a;--ink2:#64748b;--line:#e2e8f0;--line2:#cbd5e1;--green:#059669;--green2:#047857;--green-soft:#ecfdf5;--gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--red:#dc2626;--red-soft:#fef2f2;--blue:#2563eb;--blue-soft:#eff6ff;--purple:#7c3aed;--purple-soft:#f5f3ff;--shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);--shadow-sm:0 1px 2px rgba(0,0,0,.04);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:14px}
[data-theme="dark"]{--bg:#0c1222;--surface:#1a2332;--surface2:#243044;--ink:#f1f5f9;--ink2:#94a3b8;--line:#2d3f56;--line2:#3d5068;--green:#34d399;--green2:#10b981;--green-soft:#064e3b;--gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--red:#f87171;--red-soft:#450a0a;--blue:#60a5fa;--blue-soft:#1e3a5f;--purple:#a78bfa;--purple-soft:#2e1065;--shadow:0 1px 3px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.3);--shadow-sm:0 1px 2px rgba(0,0,0,.2)}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 10%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 8%,transparent),transparent 60%)}
.container{max-width:1280px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:10px;margin-right:auto}.logo-chip{width:38px;height:38px;border-radius:10px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:32px;height:32px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--ink2);padding:6px 12px;border-radius:8px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--green);border-color:var(--green)}.back-link svg{width:13px;height:13px}.badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 9px;border-radius:99px}.badge.role{background:var(--gold-soft);color:var(--gold2)}.theme-btn{width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}.theme-btn svg{width:15px;height:15px}

.page-header{padding:28px 0 6px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}.ph-left h1 em{font-style:normal;color:var(--green)}.ph-left h1 svg{width:26px;height:26px;stroke:var(--green)}.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}.ph-btns{display:flex;gap:10px;flex-wrap:wrap}

.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px;display:flex;gap:12px;align-items:center;box-shadow:var(--shadow-sm);transition:transform .25s,box-shadow .25s}.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}.stat .ic{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex:none}.stat .ic svg{width:20px;height:20px}.stat .ic.g{background:var(--green-soft);color:var(--green)}.stat .ic.y{background:var(--gold-soft);color:var(--gold2)}.stat .ic.b{background:var(--blue-soft);color:var(--blue)}.stat .ic.r{background:var(--purple-soft);color:var(--purple)}.stat b{font-family:var(--fd);font-size:18px;font-weight:800;display:block;line-height:1.15}.stat span{font-size:9.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:10px;font-weight:800;font-size:12px;padding:0 16px;height:40px;transition:.2s;cursor:pointer}.btn svg{width:14px;height:14px}.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--green) 25%,transparent)}.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn-ghost:hover{border-color:var(--green);color:var(--green)}.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}.btn-danger:hover{background:var(--red);color:#fff}.btn-sm{height:32px;padding:0 10px;font-size:10px;border-radius:8px}

.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:18px 0 14px;box-shadow:var(--shadow-sm)}.ff{display:flex;flex-direction:column;gap:4px;flex:1;min-width:160px}.ff label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.ff input,.ff select{height:40px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:13px;font-weight:600;outline:none;transition:.2s}.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}

.catalog{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:10px}
.prod-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden;transition:.25s;display:flex;flex-direction:column}.prod-card:hover{transform:translateY(-3px);box-shadow:var(--shadow);border-color:color-mix(in srgb,var(--green) 30%,var(--line))}
.pc-img{height:130px;background:var(--surface2);display:grid;place-items:center;overflow:hidden;border-bottom:1px solid var(--line);position:relative}.pc-img img{width:100%;height:100%;object-fit:cover}.pc-img .ph-icon{opacity:.3}.pc-img .ph-icon svg{width:40px;height:40px;stroke:var(--ink2)}
.pc-status{position:absolute;top:8px;right:8px;font-size:8px;font-weight:800;letter-spacing:.6px;padding:3px 9px;border-radius:99px;text-transform:uppercase;display:flex;align-items:center;gap:4px}.pc-status svg{width:10px;height:10px}.pc-status.on{background:var(--green-soft);color:var(--green)}.pc-status.off{background:var(--red-soft);color:var(--red)}
.pc-body{padding:14px 16px;display:flex;flex-direction:column;gap:7px;flex:1}
.pc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}.pc-name{font-family:var(--fd);font-size:15px;font-weight:800;line-height:1.2}.pc-code{font-family:var(--fm);font-size:9px;font-weight:700;color:var(--green);background:var(--green-soft);padding:3px 8px;border-radius:99px;letter-spacing:.8px;text-transform:uppercase;flex:none}
.pc-desc{font-size:11px;color:var(--ink2);font-weight:600;line-height:1.45;min-height:16px}
.pc-price{display:flex;align-items:baseline;gap:5px;margin-top:2px}.pc-price b{font-family:var(--fd);font-size:20px;font-weight:800;color:var(--green)}.pc-price span{font-size:10px;font-weight:700;color:var(--ink2)}
.pc-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:2px}.pc-meta span{font-size:9px;font-weight:700;background:var(--surface2);border:1px solid var(--line);border-radius:7px;padding:3px 8px;color:var(--ink2);display:flex;align-items:center;gap:4px}.pc-meta svg{width:11px;height:11px}.pc-meta b{color:var(--ink)}
.pc-actions{display:flex;gap:6px;margin-top:auto;padding-top:10px;border-top:1px solid var(--line)}.pc-actions .btn{flex:1}
.empty{padding:50px 20px;text-align:center;color:var(--ink2);grid-column:1/-1}.empty svg{width:40px;height:40px;stroke:var(--ink2);margin:0 auto 10px;opacity:.4}.empty p{font-weight:700;font-size:13px}.empty small{font-weight:600;font-size:11px}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}.modal-overlay.open{display:flex;animation:fadein .25s}@keyframes fadein{from{opacity:0}to{opacity:1}}.modal{background:var(--surface);border:1px solid var(--line);border-radius:18px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.15);animation:pop .35s cubic-bezier(.34,1.56,.64,1)}@keyframes pop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}.m-head{padding:18px 22px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}.m-head h3{font-family:var(--fd);font-size:16px;font-weight:800;display:flex;align-items:center;gap:7px}.m-head h3 svg{width:17px;height:17px;stroke:var(--green)}.m-x{width:30px;height:30px;border-radius:9px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:14px;cursor:pointer;display:grid;place-items:center}.m-x:hover{background:var(--red-soft);color:var(--red)}.m-body{padding:18px 22px 22px}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.span2{grid-column:1/-1}.field{display:flex;flex-direction:column;gap:4px}.fl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:7px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:0 11px;height:44px;transition:.2s}.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 10%,transparent);background:var(--surface)}.ctrl.invalid{border-color:var(--red)!important;animation:shake .3s}@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:13px}.ctrl textarea{padding:8px 0;height:auto;min-height:44px;resize:vertical}.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.ctrl.readonly{background:var(--surface);opacity:.65;pointer-events:none}.ctrl.readonly input{cursor:not-allowed;color:var(--ink2)}
.img-preview{display:flex;align-items:center;gap:10px;margin-top:5px}.img-thumb{width:56px;height:56px;border-radius:10px;border:1.5px dashed var(--line2);background:var(--surface2);display:grid;place-items:center;overflow:hidden;flex:none}.img-thumb svg{width:22px;height:22px;stroke:var(--ink2);opacity:.4}.img-thumb img{width:100%;height:100%;object-fit:cover}
.chk-row{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;cursor:pointer;user-select:none;padding:6px 0}.chk-row input{width:16px;height:16px;accent-color:var(--green);cursor:pointer}
.edit-divider{margin-top:14px;padding-top:12px;border-top:1.5px solid var(--green-soft)}.edit-divider-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--green);margin-bottom:10px;display:flex;align-items:center;gap:6px}.edit-divider-title svg{width:13px;height:13px;stroke:var(--green)}

.toasts{position:fixed;top:70px;right:16px;z-index:120;display:flex;flex-direction:column;gap:8px}.toast{display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--green);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:340px}.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:24px;border-top:1px solid var(--line);padding:16px 20px 24px;text-align:center;color:var(--ink2);font-size:10px;font-weight:700}footer div+div{margin-top:2px;font-weight:600;opacity:.7}

#printSheet{display:none}
@media print{@page{size:A4;margin:11mm}body{background:#fff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}body::before,#app,.toasts,.modal-overlay,.topbar{display:none!important}#printSheet{display:block!important;color:#1a231d;font-family:Arial,sans-serif;font-size:8.5pt;line-height:1.4}}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.filter-bar{flex-direction:column}.topbar .badge{display:none}.brand strong{font-size:12px}.stats{grid-template-columns:1fr 1fr;gap:10px}.fgrid{grid-template-columns:1fr}}
@media(max-width:480px){.stats{grid-template-columns:1fr}.catalog{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_general_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a>
  <span class="badge role"><?php echo strtoupper(e($auth['role'])); ?></span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-left">
      <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>Products <em>Catalog</em></h1>
      <p>Manage product range, prices per kg and stock levels</p>
    </div>
    <div class="ph-btns">
      <button class="btn btn-ghost" id="btnPrintCatalog"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Catalog</button>
      <?php if($isAdmin): ?>
      <button class="btn btn-primary" id="btnAddOpen"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add Product</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div><div><b><?php echo number_format($totProducts); ?></b><span>Total Products</span></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div><div><b><?php echo number_format($activeProducts); ?></b><span>Active</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><b><?php echo fmtN($avgPrice); ?></b><span>Avg Price/kg</span></div></div>
    <div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div><div><b style="font-size:14px;padding-top:3px"><?php echo e($topName); ?></b><span>Top Product</span></div></div>
  </div>

  <form method="get" class="filter-bar">
    <div class="ff"><label>Search</label><input type="text" name="search" placeholder="Product name or code..." value="<?php echo e($search); ?>"></div>
    <div class="ff" style="max-width:160px"><label>Status</label><select name="status"><option value="">All</option><option value="active" <?php echo $filterStatus==='active'?'selected':''; ?>>Active</option><option value="inactive" <?php echo $filterStatus==='inactive'?'selected':''; ?>>Inactive</option></select></div>
    <div style="display:flex;gap:6px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button><a href="manage_products.php" class="btn btn-ghost">Clear</a></div>
  </form>

  <div class="catalog">
    <?php if(empty($products)): ?>
      <div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><p>No products found</p><small>Add your first product or adjust filters.</small></div>
    <?php else: foreach($products as $i=>$p): ?>
      <div class="prod-card">
        <div class="pc-img">
          <?php if(!empty($p['image_url'])): ?><img src="<?php echo e($p['image_url']); ?>" alt="<?php echo e($p['name']); ?>" onerror="this.style.display='none';this.parentNode.querySelector('.ph-icon').style.display='block'"><?php endif; ?>
          <span class="ph-icon" <?php echo !empty($p['image_url'])?'style="display:none"':''; ?>><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
          <span class="pc-status <?php echo $p['is_active']?'on':'off'; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><?php echo $p['is_active']?'<polyline points="20 6 9 17 4 12"/>':'<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'; ?></svg><?php echo $p['is_active']?'Active':'Inactive'; ?></span>
        </div>
        <div class="pc-body">
          <div class="pc-top"><span class="pc-name"><?php echo e($p['name']); ?></span><span class="pc-code"><?php echo e($p['code']); ?></span></div>
          <div class="pc-desc"><?php echo e($p['description']??'—'); ?></div>
          <div class="pc-price"><b><?php echo fmtN($p['default_price']); ?></b><span>/ <?php echo e($p['unit']); ?></span></div>
          <div class="pc-meta">
            <span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><b><?php echo number_format((float)$p['stock_kg']); ?> kg</b></span>
            <span><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><b><?php echo number_format((float)$p['order_count']); ?></b></span>
            <span><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><b><?php echo fmtN((float)$p['stock_kg'] * (float)$p['default_price']); ?></b></span>
          </div>
          <?php if($isAdmin): ?>
          <div class="pc-actions">
            <button class="btn btn-ghost btn-sm btn-edit" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>
            <form method="post" style="flex:1"><input type="hidden" name="action" value="_toggle"><input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm" style="width:100%"><?php echo $p['is_active']?'Deactivate':'Activate'; ?></button></form>
            <button class="btn btn-danger btn-sm btn-del" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php if($isAdmin): ?>
<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal"><div class="modal">
  <div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add Product</h3><button class="m-x" onclick="closeModal('addModal')">&times;</button></div>
  <div class="m-body">
    <form method="post" id="addForm" novalidate>
      <input type="hidden" name="action" value="_create">
      <div class="fgrid">
        <div class="field span2"><label class="fl">Product Name <b>*</b></label><div class="ctrl"><input name="name" id="a_name" placeholder="e.g. Groundnut" required></div></div>
        <div class="field"><label class="fl">Code <b>*</b></label><div class="ctrl"><input name="code" id="a_code" placeholder="e.g. groundnut" required></div></div>
        <div class="field"><label class="fl">Unit</label><div class="ctrl"><select name="unit"><option value="kg">kg</option><option value="bag">bag</option><option value="ton">ton</option><option value="piece">piece</option></select></div></div>
        <div class="field"><label class="fl">Price per Unit (₦) <b>*</b></label><div class="ctrl"><input type="number" name="default_price" id="a_price" min="1" step="0.01" placeholder="0.00" required></div></div>
        <div class="field"><label class="fl">Stock (kg)</label><div class="ctrl"><input type="number" name="stock_kg" id="a_stock" min="0" step="0.01" value="0"></div></div>
        <div class="field span2"><label class="fl">Description</label><div class="ctrl ta"><textarea name="description" id="a_desc" rows="2" placeholder="Grade, quality notes..."></textarea></div></div>
        <div class="field span2"><label class="fl">Image URL</label><div class="ctrl"><input name="image_url" id="a_img" placeholder="https://..."></div>
          <div class="img-preview"><div class="img-thumb" id="a_thumb"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div><span style="font-size:10px;color:var(--ink2);font-weight:600">Paste image URL to preview</span></div>
          <label class="chk-row"><input type="checkbox" name="is_active" checked>Active (available for orders)</label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;height:46px;font-size:14px;margin-top:14px"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Add Product</button>
    </form>
  </div>
</div></div>

<!-- EDIT MODAL — Read-only + Editable fields -->
<div class="modal-overlay" id="editModal"><div class="modal">
  <div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Product</h3><button class="m-x" onclick="closeModal('editModal')">&times;</button></div>
  <div class="m-body">
    <form method="post" id="editForm" novalidate>
      <input type="hidden" name="action" value="_update">
      <input type="hidden" name="product_id" id="e_id">
      <!-- READ-ONLY FIELDS -->
      <div class="fgrid">
        <div class="field span2"><label class="fl">Product Name</label><div class="ctrl readonly"><input type="text" id="e_name_display" readonly></div></div>
        <div class="field"><label class="fl">Code</label><div class="ctrl readonly"><input type="text" id="e_code_display" readonly></div></div>
        <div class="field"><label class="fl">Unit</label><div class="ctrl readonly"><input type="text" id="e_unit_display" readonly></div></div>
        <div class="field"><label class="fl">Stock (kg)</label><div class="ctrl readonly"><input type="text" id="e_stock_display" readonly></div></div>
      </div>
      <!-- HIDDEN FIELDS TO PRESERVE VALUES -->
      <input type="hidden" name="name" id="e_name">
      <input type="hidden" name="unit" id="e_unit">
      <input type="hidden" name="stock_kg" id="e_stock">
      <!-- EDITABLE FIELDS -->
      <div class="edit-divider">
        <div class="edit-divider-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Editable Fields</div>
        <div class="fgrid">
          <div class="field"><label class="fl">Price per Unit (₦) <b>*</b></label><div class="ctrl"><input type="number" name="default_price" id="e_price" min="1" step="0.01" required></div></div>
          <div class="field"><label class="fl">Status</label><div class="ctrl"><label class="chk-row" style="padding:0"><input type="checkbox" name="is_active" id="e_active">Active</label></div></div>
          <div class="field span2"><label class="fl">Description</label><div class="ctrl ta"><textarea name="description" id="e_desc" rows="2"></textarea></div></div>
          <div class="field span2"><label class="fl">Image URL</label><div class="ctrl"><input name="image_url" id="e_img"></div>
            <div class="img-preview"><div class="img-thumb" id="e_thumb"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div><span style="font-size:10px;color:var(--ink2);font-weight:600">Product image</span></div>
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;height:46px;font-size:14px;margin-top:14px"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
    </form>
  </div>
</div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px">
  <div class="m-head"><h3 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Product?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div>
  <div class="m-body" style="text-align:center">
    <p style="color:var(--ink2);font-size:12px;margin-bottom:14px">Permanently delete <strong id="del_name"></strong>? Products with linked orders cannot be deleted.</p>
    <form method="post"><input type="hidden" name="action" value="_delete"><input type="hidden" name="product_id" id="del_id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
    </form>
  </div>
</div></div>
<?php endif; ?>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?></div><div>Enterprise HR &amp; Operations System</div><div>Developed by Lawani Djamiou Alade</div></footer>
<div class="toasts" id="toastWrap"></div>
<div id="printSheet"></div>

<script>
const $=id=>document.getElementById(id);
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'—':String(s);
const fmt=n=>'₦'+Number(n||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

const products=<?php echo json_encode(array_map(function($p){return['id'=>(int)$p['id'],'code'=>$p['code'],'name'=>$p['name'],'description'=>$p['description']??'','price'=>(float)$p['default_price'],'stock'=>(float)$p['stock_kg'],'unit'=>$p['unit'],'image'=>$p['image_url']??'','active'=>(int)$p['is_active'],'orders'=>(int)$p['order_count'],'revenue'=>(float)$p['revenue']];},$products),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const cn='<?php echo addslashes($company['company_name']); ?>';
const ca='<?php echo addslashes($company['company_address']); ?>';
const logo='<?php echo e($logoUrl); ?>';
const boxSvg='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';

<?php if($isAdmin): ?>
$('btnAddOpen').addEventListener('click',()=>{$('addForm').reset();$('a_thumb').innerHTML=boxSvg;$('addModal').classList.add('open');});
$('a_img').addEventListener('input',()=>{const u=$('a_img').value.trim();$('a_thumb').innerHTML=u?'<img src="'+u+'" onerror="this.parentNode.innerHTML=\''+boxSvg+'\'">':boxSvg;});
$('e_img').addEventListener('input',()=>{const u=$('e_img').value.trim();$('e_thumb').innerHTML=u?'<img src="'+u+'" onerror="this.parentNode.innerHTML=\''+boxSvg+'\'">':boxSvg;});

function mark(el){el.closest('.ctrl').classList.add('invalid')}
$('addForm').addEventListener('submit',ev=>{
  document.querySelectorAll('#addForm .ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  let ok=true,msg='';
  if($('a_name').value.trim().length<2){mark($('a_name'));ok=false;msg='Product name is required.';}
  else if($('a_code').value.trim()===''){mark($('a_code'));ok=false;msg='Product code is required.';}
  else if(!(parseFloat($('a_price').value)>0)){mark($('a_price'));ok=false;msg='Price must be greater than 0.';}
  if(!ok){ev.preventDefault();toast(msg,'error');}
});
$('editForm').addEventListener('submit',ev=>{
  if($('e_name').value.trim().length<2||!(parseFloat($('e_price').value)>0)){ev.preventDefault();toast('Name and a valid price are required.','error');}
});

document.querySelectorAll('.btn-edit').forEach(b=>b.addEventListener('click',()=>{
  const p=products[+b.dataset.idx];if(!p)return;
  $('e_id').value=p.id;
  /* Read-only display */
  $('e_name_display').value=p.name;
  $('e_code_display').value=p.code.toUpperCase();
  $('e_unit_display').value=p.unit;
  $('e_stock_display').value=Number(p.stock).toLocaleString()+' kg';
  /* Hidden preserved values */
  $('e_name').value=p.name;
  $('e_unit').value=p.unit;
  $('e_stock').value=p.stock;
  /* Editable */
  $('e_price').value=p.price;
  $('e_active').checked=!!p.active;
  $('e_desc').value=p.description;
  $('e_img').value=p.image;
  $('e_thumb').innerHTML=p.image?'<img src="'+p.image+'" onerror="this.parentNode.innerHTML=\''+boxSvg+'\'">':boxSvg;
  $('editModal').classList.add('open');
}));
document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click',()=>{
  const p=products[+b.dataset.idx];if(!p)return;
  $('del_id').value=p.id;$('del_name').textContent=p.name;
  $('deleteModal').classList.add('open');
}));
<?php endif; ?>

/* Print catalog via iframe */
$('btnPrintCatalog').addEventListener('click',()=>{
  if(!products.length){toast('No products to print.','error');return;}
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  const rows=products.map(p=>'<tr><td style="font-family:monospace;font-weight:700">'+p.code.toUpperCase()+'</td><td style="font-weight:700">'+v(p.name)+'</td><td>'+v(p.description)+'</td><td style="font-family:monospace;text-align:right">'+fmt(p.price)+' / '+p.unit+'</td><td style="font-family:monospace;text-align:right">'+Number(p.stock).toLocaleString()+' kg</td><td>'+(p.active?'Active':'Inactive')+'</td><td style="text-align:center">'+p.orders+'</td><td style="font-family:monospace;text-align:right">'+fmt(p.revenue)+'</td></tr>').join('');
  let h='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Products Catalog</title><style>@page{size:A4;margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1a231d;font-size:8.5pt;line-height:1.4;background:#fff}</style></head><body>';
  h+='<div style="display:flex;align-items:center;gap:12pt;border-bottom:3pt double #059669;padding-bottom:8pt;margin-bottom:12pt">';
  h+='<div style="width:50pt;height:50pt;border:1.5pt solid #059669;border-radius:6pt;display:flex;align-items:center;justify-content:center;background:#ecfdf5;flex:none"><span style="font-size:18pt;color:#059669;font-weight:800">OF</span></div>';
  h+='<div style="flex:1"><h1 style="font-size:14pt;font-weight:800;color:#059669;line-height:1.15">'+cn+'</h1><p style="font-size:7pt;color:#5a655c;margin:1pt 0">'+ca+'</p><p style="font-size:7pt;color:#5a655c;margin:1pt 0">Products &amp; Inventory Department</p></div>';
  h+='<div style="text-align:right"><div style="font-size:10pt;font-weight:800;color:#fff;background:#059669;padding:4pt 10pt;border-radius:3pt;display:inline-block;margin-bottom:3pt">PRODUCTS CATALOG</div><p style="font-size:6.5pt;color:#7a857c;margin:2pt 0">Products: '+products.length+'</p><p style="font-size:6.5pt;color:#7a857c;margin:1pt 0">Generated: '+genDate+'</p></div></div>';
  h+='<table style="width:100%;border-collapse:collapse;font-size:7.5pt;margin-top:6pt"><thead><tr><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:left">Code</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:left">Product</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:left">Description</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:right">Price</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:right">Stock</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:center">Status</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:center">Orders</th><th style="background:#059669;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;padding:4pt 6pt;border:1pt solid #059669;text-align:right">Revenue</th></tr></thead><tbody>'+rows+'</tbody></table>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid"><div style="text-align:center"><div style="height:34pt;border-bottom:1.5pt solid #333;margin-bottom:3pt"></div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#555">Prepared By</span></div><div style="text-align:center"><div style="height:34pt;border-bottom:1.5pt solid #333;margin-bottom:3pt"></div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#555">Authorised By</span></div></div>';
  h+='<div style="margin-top:14pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c"><span style="color:#c0392b;font-weight:800;letter-spacing:.5pt">CONFIDENTIAL</span><span>'+cn+'</span><span>'+genDate+'</span></div>';
  h+='</body></html>';
  var old=document.getElementById('printFrame');if(old)old.remove();
  var iframe=document.createElement('iframe');iframe.id='printFrame';iframe.style.position='fixed';iframe.style.right='0';iframe.style.bottom='0';iframe.style.width='0';iframe.style.height='0';iframe.style.border='0';
  document.body.appendChild(iframe);var doc=iframe.contentWindow.document;doc.open();doc.write(h);doc.close();iframe.contentWindow.focus();
  setTimeout(function(){iframe.contentWindow.print();setTimeout(function(){iframe.remove()},1000)},500);
});

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>