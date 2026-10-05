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
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== SELF-HEALING TABLES ===== */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM ice_production")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sale_price', $cols)) $pdo->exec("ALTER TABLE ice_production ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL AFTER price_per_block");
    if (!in_array('block_size', $cols)) $pdo->exec("ALTER TABLE ice_production ADD COLUMN block_size VARCHAR(10) DEFAULT 'small' AFTER blocks_produced");
} catch (Exception $ignore) {}
try {
    $cols2 = $pdo->query("SHOW COLUMNS FROM ice_sales")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('block_size', $cols2)) $pdo->exec("ALTER TABLE ice_sales ADD COLUMN block_size VARCHAR(10) DEFAULT 'small' AFTER blocks_sold");
} catch (Exception $ignore) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ice_sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        production_date DATE NOT NULL,
        customer_name VARCHAR(100) DEFAULT NULL,
        blocks_sold INT NOT NULL DEFAULT 0,
        block_size VARCHAR(10) DEFAULT 'small',
        sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        notes VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT DEFAULT NULL,
        INDEX idx_date (production_date),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ignore) {}

/* ===== ICE PRICES SETTINGS ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ice_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        block_size VARCHAR(10) NOT NULL,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_size (block_size)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* Ensure both sizes exist */
    $pdo->exec("INSERT IGNORE INTO ice_prices (block_size,cost_price,sale_price) VALUES ('small',0,0)");
    $pdo->exec("INSERT IGNORE INTO ice_prices (block_size,cost_price,sale_price) VALUES ('big',0,0)");
} catch (Exception $ignore) {}

/* Fetch current prices */
$icePrices = ['small'=>['cost'=>0,'sale'=>0],'big'=>['cost'=>0,'sale'=>0]];
try {
    $st = $pdo->query("SELECT block_size,cost_price,sale_price FROM ice_prices");
    while($row = $st->fetch(PDO::FETCH_ASSOC)){
        $sz = $row['block_size'];
        if(isset($icePrices[$sz])){
            $icePrices[$sz] = ['cost'=>(float)$row['cost_price'],'sale'=>(float)$row['sale_price']];
        }
    }
} catch (Exception $ignore) {}

/* ===== SAVE PRICES ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_save_prices') {
    $sc = floatval($_POST['small_cost'] ?? 0);
    $sp = floatval($_POST['small_sale'] ?? 0);
    $bc = floatval($_POST['big_cost'] ?? 0);
    $bp = floatval($_POST['big_sale'] ?? 0);
    try {
        $pdo->prepare("UPDATE ice_prices SET cost_price=?,sale_price=? WHERE block_size='small'")->execute([$sc,$sp]);
        $pdo->prepare("UPDATE ice_prices SET cost_price=?,sale_price=? WHERE block_size='big'")->execute([$bc,$bp]);
        flash('success','Ice block prices updated.');
    } catch (Exception $ex) { flash('error','Failed: '.$ex->getMessage()); }
    header('Location: ice_block_register.php'); exit;
}

function getCurrentStock(PDO $pdo, string $size): int {
    try {
        $produced = (int)$pdo->prepare("SELECT IFNULL(SUM(blocks_produced),0) FROM ice_production WHERE block_size=?")->execute([$size]) ? (int)$pdo->query("SELECT IFNULL(SUM(blocks_produced),0) FROM ice_production WHERE block_size='$size'")->fetchColumn() : 0;
        $sold = (int)$pdo->query("SELECT IFNULL(SUM(blocks_sold),0) FROM ice_sales WHERE block_size='$size'")->fetchColumn();
        return max(0, $produced - $sold);
    } catch (Exception $e) { return 0; }
}

$stockSmall = getCurrentStock($pdo, 'small');
$stockBig = getCurrentStock($pdo, 'big');
$currentStock = $stockSmall + $stockBig;

/* ===== PRODUCTION ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_produce') {
    $date = $_POST['prod_date'] ?? date('Y-m-d');
    $qty = (int)($_POST['prod_qty'] ?? 0);
    $blockSize = $_POST['block_size'] ?? 'small';
    $costPrice = floatval($_POST['prod_cost'] ?? 0);
    $salePrice = floatval($_POST['prod_sale_price'] ?? 0);
    $notes = trim($_POST['prod_notes'] ?? '');
    $errs=[];
    if(!$date) $errs[]='Date is required';
    if($qty<=0) $errs[]='Quantity must be greater than 0';
    if($costPrice<=0) $errs[]='Cost price must be greater than 0';
    if($salePrice<=0) $errs[]='Sale price must be greater than 0';
    if(empty($errs)){
        try{
            $pdo->prepare("INSERT INTO ice_production (production_date,blocks_produced,block_size,blocks_delivered,price_per_block,sale_price,notes,created_by) VALUES (?,?,?,0,?,?,?,?)")
                ->execute([$date,$qty,$blockSize,$costPrice,$salePrice,$notes?:null,$auth['id']]);
            $label = $blockSize==='big'?'Big':'Small';
            flash('success',"$qty $label blocks added to stock on $date.");
            header('Location: ice_block_register.php'); exit;
        }catch(Exception $ex){ flash('error','DB error: '.$ex->getMessage()); }
    }
    if(!empty($errs)) flash('error', implode('. ',$errs));
}

/* ===== SALE ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_sell') {
    $customer = trim($_POST['sale_customer'] ?? '');
    $qty = (int)($_POST['sale_qty'] ?? 0);
    $blockSize = $_POST['sale_block_size'] ?? 'small';
    $salePrice = floatval($_POST['sale_price'] ?? 0);
    $notes = trim($_POST['sale_notes'] ?? '');
    $stockNow = getCurrentStock($pdo, $blockSize);
    $errs=[];
    if($qty<=0) $errs[]='Enter number of blocks to sell';
    if($salePrice<=0) $errs[]='Sale price must be greater than 0';
    if($qty>$stockNow) $errs[]="Only $stockNow ".($blockSize==='big'?'big':'small')." blocks in stock.";
    if(empty($errs)){
        try{
            $total = $qty * $salePrice;
            $pdo->prepare("INSERT INTO ice_sales (production_date,customer_name,blocks_sold,block_size,sale_price,total_amount,notes,created_by) VALUES (CURDATE(),?,?,?,?,?,?,?)")
                ->execute([$customer?:null,$qty,$blockSize,$salePrice,$total,$notes?:null,$auth['id']]);
            $label = $blockSize==='big'?'Big':'Small';
            flash('success',"Sold $qty $label blocks to ".($customer?:'walk-in customer')." for ".fmtN($total));
            header('Location: ice_block_register.php'); exit;
        }catch(Exception $ex){ flash('error','Sale failed: '.$ex->getMessage()); }
    }
    if(!empty($errs)) flash('error', implode('. ',$errs));
}

/* ===== FETCH DATA ===== */
$todaySales=[];
try{
    $st=$pdo->prepare("SELECT s.*, u.full_name AS created_by_name FROM ice_sales s LEFT JOIN users u ON u.id=s.created_by WHERE s.production_date=CURDATE() ORDER BY s.created_at DESC");
    $st->execute(); $todaySales=$st->fetchAll();
}catch(Exception $ignore){}

$recentProd=[];
try{
    $st=$pdo->prepare("SELECT p.*, u.full_name AS created_by_name FROM ice_production p LEFT JOIN users u ON u.id=p.created_by ORDER BY p.production_date DESC LIMIT 30");
    $st->execute(); $recentProd=$st->fetchAll();
}catch(Exception $ignore){}

$todayProduced=0;$todaySold=0;$todayRev=0.0;$todayCost=0.0;
$totProduced=0;$totSold=0;$totRev=0.0;$totCost=0.0;
try{
    $todayProduced=(int)$pdo->query("SELECT IFNULL(SUM(blocks_produced),0) FROM ice_production WHERE production_date=CURDATE()")->fetchColumn();
    $todaySold=(int)$pdo->query("SELECT IFNULL(SUM(blocks_sold),0) FROM ice_sales WHERE production_date=CURDATE()")->fetchColumn();
    $todayRev=(float)$pdo->query("SELECT IFNULL(SUM(total_amount),0) FROM ice_sales WHERE production_date=CURDATE()")->fetchColumn();
    $todayCost=(float)$pdo->query("SELECT IFNULL(SUM(blocks_produced*price_per_block),0) FROM ice_production WHERE production_date=CURDATE()")->fetchColumn();
    $totProduced=(int)$pdo->query("SELECT IFNULL(SUM(blocks_produced),0) FROM ice_production")->fetchColumn();
    $totSold=(int)$pdo->query("SELECT IFNULL(SUM(blocks_sold),0) FROM ice_sales")->fetchColumn();
    $totRev=(float)$pdo->query("SELECT IFNULL(SUM(total_amount),0) FROM ice_sales")->fetchColumn();
    $totCost=(float)$pdo->query("SELECT IFNULL(SUM(blocks_produced*price_per_block),0) FROM ice_production")->fetchColumn();
}catch(Exception $ignore){}

$chart=[];
try{
    $st=$pdo->query("SELECT d.dt AS d, COALESCE(p.prod,0) AS prod, COALESCE(s.sold,0) AS sold FROM (
        SELECT DATE_SUB(CURDATE(), INTERVAL n DAY) AS dt FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) nums
    ) d
    LEFT JOIN (SELECT production_date, SUM(blocks_produced) AS prod FROM ice_production GROUP BY production_date) p ON p.production_date=d.dt
    LEFT JOIN (SELECT production_date, SUM(blocks_sold) AS sold FROM ice_sales GROUP BY production_date) s ON s.production_date=d.dt
    ORDER BY d.dt ASC");
    $chart=$st->fetchAll();
}catch(Exception $ignore){}

$flashData = get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Ice Block Production | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧊</text></svg>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;--ink:#0f172a;--ink2:#64748b;--line:#e2e8f0;--green:#059669;--green2:#047857;--green-soft:#ecfdf5;--gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--red:#dc2626;--red-soft:#fef2f2;--blue:#2563eb;--blue-soft:#eff6ff;--ice:#0891b2;--ice2:#0e7490;--ice-soft:#ecfeff;--purple:#7c3aed;--purple-soft:#f5f3ff;--shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);--shadow-sm:0 1px 2px rgba(0,0,0,.04);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:14px}[data-theme="dark"]{--bg:#0c1222;--surface:#1a2332;--surface2:#243044;--ink:#f1f5f9;--ink2:#94a3b8;--line:#2d3f56;--green:#34d399;--green2:#10b981;--green-soft:#064e3b;--gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--red:#f87171;--red-soft:#450a0a;--blue:#60a5fa;--blue-soft:#1e3a5f;--ice:#22d3ee;--ice2:#06b6d4;--ice-soft:#083344;--purple:#a78bfa;--purple-soft:#2e1065;--shadow:0 1px 3px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.3);--shadow-sm:0 1px 2px rgba(0,0,0,.2)}
html{scroll-behavior:smooth}body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--ice) 10%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--green) 8%,transparent),transparent 60%)}.container{max-width:1280px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:10px;margin-right:auto}.logo-chip{width:38px;height:38px;border-radius:10px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:32px;height:32px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--ink2);padding:6px 12px;border-radius:8px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--ice);border-color:var(--ice)}.back-link svg{width:13px;height:13px}.badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 9px;border-radius:99px}.badge.ice{background:var(--ice-soft);color:var(--ice)}.theme-btn{width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--ice);border-color:var(--ice);transform:rotate(18deg)}.theme-btn svg{width:15px;height:15px}
.page-header{padding:24px 0 4px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px}.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(20px,2.5vw,26px);display:flex;align-items:center;gap:8px}.ph-left h1 em{font-style:normal;color:var(--ice)}.ph-left h1 svg{width:22px;height:22px}.ph-left p{color:var(--ink2);font-weight:600;margin-top:3px;font-size:12px}
.stock-banner{background:linear-gradient(135deg,var(--ice),var(--ice2));border-radius:14px;padding:20px 24px;color:#fff;margin-top:16px;display:flex;align-items:center;gap:16px;position:relative;overflow:hidden}.stock-banner::after{content:'';position:absolute;right:-20px;bottom:-20px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.08)}.sb-icon{width:44px;height:44px;border-radius:11px;background:rgba(255,255,255,.15);display:grid;place-items:center;flex:none}.sb-icon svg{width:22px;height:22px;stroke:#fff}.sb-info{flex:1}.sb-label{font-size:9px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.85}.sb-val{font-family:var(--fd);font-size:28px;font-weight:800;margin-top:2px}.sb-sub{font-size:11px;opacity:.8;font-weight:600;margin-top:3px}.sb-stats{display:flex;gap:14px;margin-top:6px;flex-wrap:wrap}.sb-stat{font-size:10px;font-weight:700;opacity:.85}.sb-stat b{font-family:var(--fm)}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px}.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:12px 14px;display:flex;gap:10px;align-items:center;box-shadow:var(--shadow-sm);transition:transform .25s,box-shadow .25s}.stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}.stat .ic{width:36px;height:36px;border-radius:9px;display:grid;place-items:center;flex:none}.stat .ic svg{width:16px;height:16px}.stat .ic.i{background:var(--ice-soft);color:var(--ice)}.stat .ic.g{background:var(--green-soft);color:var(--green)}.stat .ic.y{background:var(--gold-soft);color:var(--gold2)}.stat .ic.p{background:var(--purple-soft);color:var(--purple)}.stat b{font-family:var(--fd);font-size:18px;font-weight:800;display:block;line-height:1.15}.stat span{font-size:9.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.dual-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:13px 18px;border-bottom:1px solid var(--line)}.card-head h2{font-family:var(--fd);font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px}.card-head h2 svg{width:15px;height:15px}.card-head h2.produce-h svg{stroke:var(--blue)}.card-head h2.sell-h svg{stroke:var(--green)}.count-pill{font-family:var(--fm);font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px}.count-pill.blue{background:var(--blue-soft);color:var(--blue)}.count-pill.green{background:var(--green-soft);color:var(--green)}
.form-body{padding:18px}.fs-title{font-family:var(--fd);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;padding-bottom:7px;border-bottom:1.5px solid var(--line);display:flex;align-items:center;gap:6px}.fs-title.blue{color:var(--blue);border-color:var(--blue-soft)}.fs-title.blue svg{stroke:var(--blue)}.fs-title.green{color:var(--green);border-color:var(--green-soft)}.fs-title.green svg{stroke:var(--green)}.fs-title.gold{color:var(--gold2);border-color:var(--gold-soft)}.fs-title.gold svg{stroke:var(--gold2)}.fs-title svg{width:13px;height:13px}.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.span2{grid-column:1/-1}.field{display:flex;flex-direction:column;gap:4px}.fl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:7px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:0 11px;height:44px;transition:all .2s}.ctrl:focus-within{border-color:var(--ice);box-shadow:0 0 0 3px color-mix(in srgb,var(--ice) 12%,transparent);background:var(--surface)}.ctrl.invalid{border-color:var(--red)!important;animation:shake .3s}@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}.ctrl svg{color:var(--ink2);flex:none;width:15px;height:15px;transition:stroke .2s}.ctrl:focus-within svg{stroke:var(--ice)}.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:13px}.ctrl select{cursor:pointer;appearance:none}.ctrl textarea{padding:8px 0;height:auto;min-height:44px;resize:vertical}.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.size-toggle{display:grid;grid-template-columns:1fr 1fr;gap:4px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:4px}.size-toggle label input{display:none}.size-toggle span{display:flex;align-items:center;justify-content:center;gap:5px;padding:8px 6px;border-radius:8px;font-weight:800;font-size:11px;color:var(--ink2);cursor:pointer;transition:all .2s;border:1px solid transparent}.size-toggle span svg{width:14px;height:14px}.size-toggle label:has(input[value="small"]:checked) span{background:var(--ice-soft);color:var(--ice);border-color:color-mix(in srgb,var(--ice) 20%,transparent)}.size-toggle label:has(input[value="big"]:checked) span{background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb,var(--blue) 20%,transparent)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:10px;font-weight:800;font-size:12px;padding:0 16px;height:40px;transition:all .2s;cursor:pointer}.btn:active{transform:scale(.97)}.btn-blue{background:linear-gradient(135deg,var(--blue),#1d4ed8);color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--blue) 25%,transparent)}.btn-blue:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-green{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--green) 25%,transparent)}.btn-green:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--gold) 25%,transparent)}.btn-gold:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn-ghost:hover{border-color:var(--ice);color:var(--ice)}.btn-sm{height:30px;padding:0 10px;font-size:10px;border-radius:8px}.btn svg{width:14px;height:14px}.btn-xlarge{width:100%;height:46px;font-size:14px;margin-top:16px}
.sale-calc{margin-top:12px;border-radius:10px;padding:12px 16px;border:1.5px dashed var(--green);background:linear-gradient(135deg,var(--green-soft),color-mix(in srgb,var(--green) 8%,var(--surface)));display:flex;justify-content:space-between;align-items:center}.sale-calc span{font-size:9px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--green)}.sale-calc b{font-family:var(--fm);font-size:16px;font-weight:700;color:var(--green)}
.sales-log{margin-top:16px}.sl-item{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--line);transition:background .15s}.sl-item:hover{background:var(--surface2)}.sl-item:last-child{border-bottom:none}.sl-avatar{width:32px;height:32px;border-radius:8px;background:var(--green-soft);color:var(--green);display:grid;place-items:center;font-family:var(--fd);font-size:12px;font-weight:800;flex:none}.sl-info{flex:1;min-width:0}.sl-name{font-weight:700;font-size:12px;display:block}.sl-meta{font-size:10px;color:var(--ink2);font-weight:600}.sl-amount{font-family:var(--fm);font-size:13px;font-weight:700;color:var(--green);white-space:nowrap}.sl-blocks{font-family:var(--fm);font-size:10px;color:var(--ink2);font-weight:600;text-align:right}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;min-width:700px}thead th{font-size:9px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}tbody td{padding:10px 12px;border-bottom:1px solid var(--line);font-size:12px;font-weight:600;vertical-align:middle}tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}.empty{padding:36px 20px;text-align:center;color:var(--ink2)}.empty svg{width:32px;height:32px;stroke:var(--ink2);margin:0 auto 6px;opacity:.5;display:block}.empty p{font-weight:700;font-size:12px}.empty small{font-weight:600;font-size:10px}
.chart-section{margin-top:16px}.chart-box{padding:16px 18px}.cb-bars{display:flex;align-items:flex-end;gap:8px;height:110px;padding-top:4px}.cb-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end}.cb-pair{display:flex;gap:2px;align-items:flex-end;height:100%;width:100%;justify-content:center}.cb-bar{width:12px;border-radius:4px 4px 0 0;min-height:2px;transition:height .5s}.cb-bar.prod{background:linear-gradient(180deg,var(--blue),#1d4ed8)}.cb-bar.sold{background:linear-gradient(180deg,var(--green),var(--green2))}.cb-lbl{font-size:8px;font-weight:800;color:var(--ink2)}.cb-legend{display:flex;gap:12px;margin-top:10px;font-size:10px;font-weight:700;color:var(--ink2)}.cb-legend i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px}
.toasts{position:fixed;top:70px;right:16px;z-index:120;display:flex;flex-direction:column;gap:8px}.toast{display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--ice);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:340px}.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:24px;border-top:1px solid var(--line);padding:16px 20px 24px;text-align:center;color:var(--ink2);font-size:10px;font-weight:700}footer div+div{margin-top:2px;font-weight:600;opacity:.7}
@media(max-width:1020px){.dual-layout{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.fgrid{grid-template-columns:1fr}.topbar .badge{display:none}.brand strong{font-size:12px}.stats{grid-template-columns:1fr 1fr;gap:8px}.stock-banner{flex-direction:column;text-align:center;gap:10px}.sb-stats{justify-content:center}}
@media(max-width:480px){.stats{grid-template-columns:1fr}.cb-bars{gap:5px}.cb-bar{width:9px}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container"><div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small>Ice Block Production Unit</small></div></div><a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a><span class="badge ice"><svg class="icon-svg" width="10" height="10" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> ICE UNIT</span><button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button></div></header>
<div class="container">
  <div class="page-header"><div class="ph-left"><h1><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--ice)"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Ice Block <em>Manager</em></h1><p>Produce small &amp; big ice blocks, sell to customers, track stock &amp; revenue</p></div></div>

  <div class="stock-banner">
    <div class="sb-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 7h-3a2 2 0 0 0-2-2h-6a2 2 0 0 0-2 2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/></svg></div>
    <div class="sb-info">
      <div class="sb-label">Total Stock Available</div>
      <div class="sb-val"><?php echo number_format($currentStock); ?> blocks</div>
      <div class="sb-sub">Small: <?php echo number_format($stockSmall); ?> · Big: <?php echo number_format($stockBig); ?></div>
      <div class="sb-stats">
        <span class="sb-stat">Produced: <b><?php echo number_format($totProduced); ?></b></span>
        <span class="sb-stat">Sold: <b><?php echo number_format($totSold); ?></b></span>
        <span class="sb-stat">Revenue: <b><?php echo fmtN($totRev); ?></b></span>
        <span class="sb-stat">Profit: <b><?php echo fmtN($totRev-$totCost); ?></b></span>
      </div>
    </div>
  </div>

  <div class="stats">
    <div class="stat"><div class="ic i"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div><div><b><?php echo number_format($totProduced); ?></b><span>Total Produced</span></div></div>
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div><div><b><?php echo number_format($totSold); ?></b><span>Total Sold</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><b><?php echo fmtN($totRev); ?></b><span>Total Revenue</span></div></div>
    <div class="stat"><div class="ic p"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div><div><b><?php echo fmtN($totRev-$totCost); ?></b><span>Total Profit</span></div></div>
  </div>

  <!-- PRICE SETTINGS -->
  <div class="card" style="margin-top:16px">
    <div class="card-head"><h2 style="color:var(--gold2)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--gold2)"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Ice Block Pricing</h2></div>
    <form method="post" class="form-body">
      <input type="hidden" name="action" value="_save_prices">
      <div class="fgrid">
        <div class="field"><label class="fl">Small Block — Cost (₦)</label><div class="ctrl"><input type="number" name="small_cost" step="0.01" min="0" value="<?php echo $icePrices['small']['cost']; ?>"></div></div>
        <div class="field"><label class="fl">Small Block — Sale Price (₦)</label><div class="ctrl"><input type="number" name="small_sale" step="0.01" min="0" value="<?php echo $icePrices['small']['sale']; ?>"></div></div>
        <div class="field"><label class="fl">Big Block — Cost (₦)</label><div class="ctrl"><input type="number" name="big_cost" step="0.01" min="0" value="<?php echo $icePrices['big']['cost']; ?>"></div></div>
        <div class="field"><label class="fl">Big Block — Sale Price (₦)</label><div class="ctrl"><input type="number" name="big_sale" step="0.01" min="0" value="<?php echo $icePrices['big']['sale']; ?>"></div></div>
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn btn-gold"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Prices</button></div>
    </form>
  </div>

  <div class="dual-layout">
    <!-- PRODUCTION -->
    <div class="card">
      <div class="card-head"><h2 class="produce-h"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg> Add Production</h2></div>
      <form method="post" id="prodForm" class="form-body" novalidate>
        <input type="hidden" name="action" value="_produce">
        <div class="fs-title blue"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> Production Details</div>
        <div class="fgrid">
          <div class="field span2"><label class="fl">Block Size <b>*</b></label>
            <div class="size-toggle">
              <label><input type="radio" name="block_size" value="small" checked><span><svg class="icon-svg" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/></svg> Small</span></label>
              <label><input type="radio" name="block_size" value="big"><span><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="3"/></svg> Big</span></label>
            </div>
          </div>
          <div class="field"><label class="fl">Date <b>*</b></label><div class="ctrl"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><input type="date" name="prod_date" id="p_date" value="<?php echo date('Y-m-d'); ?>" required></div></div>
          <div class="field"><label class="fl">Blocks Produced <b>*</b></label><div class="ctrl"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><input type="number" name="prod_qty" id="p_qty" min="1" placeholder="e.g. 100" required></div></div>
          <div class="field"><label class="fl">Cost / Block (₦) <b>*</b></label><div class="ctrl"><input type="number" name="prod_cost" id="p_cost" min="1" step="0.01" required></div></div>
          <div class="field"><label class="fl">Sale Price (₦) <b>*</b></label><div class="ctrl"><input type="number" name="prod_sale_price" id="p_sale" min="1" step="0.01" required></div></div>
          <div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="prod_notes" rows="2" placeholder="Optional..."></textarea></div></div>
        </div>
        <button type="submit" class="btn btn-blue btn-xlarge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Add to Stock</button>
      </form>
    </div>

    <!-- SALES -->
    <div class="card">
      <div class="card-head"><h2 class="sell-h"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Sell to Customer</h2><span class="count-pill green"><?php echo count($todaySales); ?> today</span></div>
      <form method="post" id="saleForm" class="form-body" novalidate>
        <input type="hidden" name="action" value="_sell">
        <div class="fs-title green"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Customer Transaction</div>
        <div class="fgrid">
          <div class="field span2"><label class="fl">Block Size <b>*</b></label>
            <div class="size-toggle">
              <label><input type="radio" name="sale_block_size" value="small" checked onchange="autoFillSalePrice(this.value)"><span><svg class="icon-svg" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/></svg> Small (<?php echo number_format($stockSmall); ?> avail)</span></label>
              <label><input type="radio" name="sale_block_size" value="big" onchange="autoFillSalePrice(this.value)"><span><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="3"/></svg> Big (<?php echo number_format($stockBig); ?> avail)</span></label>
            </div>
          </div>
          <div class="field span2"><label class="fl">Customer Name</label><div class="ctrl"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="sale_customer" placeholder="Walk-in customer"></div></div>
          <div class="field"><label class="fl">Blocks Needed <b>*</b></label><div class="ctrl"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><input type="number" name="sale_qty" id="s_qty" min="1" placeholder="How many?" required></div></div>
          <div class="field"><label class="fl">Price / Block (₦) <b>*</b></label><div class="ctrl"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg><input type="number" name="sale_price" id="s_price" min="1" step="0.01" required></div></div>
          <div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="sale_notes" rows="1" placeholder="Optional..."></textarea></div></div>
        </div>
        <div class="sale-calc"><span>Total Amount</span><b id="s_total">₦0.00</b></div>
        <button type="submit" class="btn btn-green btn-xlarge"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Complete Sale</button>
      </form>
    </div>
  </div>

  <!-- TODAY'S SALES LOG -->
  <div class="card sales-log">
    <div class="card-head"><h2 class="sell-h"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Today's Sales</h2><span class="count-pill green"><?php echo count($todaySales); ?></span></div>
    <?php if(empty($todaySales)): ?>
      <div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg><p>No sales today</p><small>Sell blocks using the form above.</small></div>
    <?php else: foreach($todaySales as $s):
      $initials=strtoupper(substr(trim($s['customer_name']?:'W'),0,1));
      $sizeLabel=($s['block_size']??'small')==='big'?'BIG':'SMALL';
    ?>
      <div class="sl-item">
        <div class="sl-avatar"><?php echo e($initials); ?></div>
        <div class="sl-info"><span class="sl-name"><?php echo e($s['customer_name']?:'Walk-in Customer'); ?></span><span class="sl-meta"><?php echo date('g:i A',strtotime($s['created_at'])); ?> · <?php echo e($s['created_by_name']??'System'); ?> · <span style="color:var(--ice)"><?php echo $sizeLabel; ?></span></span></div>
        <div style="text-align:right"><div class="sl-amount"><?php echo fmtN($s['total_amount']); ?></div><div class="sl-blocks"><?php echo number_format($s['blocks_sold']); ?> blks @ <?php echo fmtN($s['sale_price']); ?></div></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- CHART -->
  <div class="card chart-section"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--ice)"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg> Last 7 Days</h2></div><div class="chart-box"><div class="cb-bars" id="chartBars"></div><div class="cb-legend"><span><i style="background:var(--blue)"></i>Produced</span><span><i style="background:var(--green)"></i>Sold</span></div></div></div>

  <!-- PRODUCTION HISTORY -->
  <div class="card" style="margin-top:16px"><div class="card-head"><h2 class="produce-h"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Production History</h2></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Size</th><th>Produced</th><th>Cost/Blk</th><th>Sale Price</th><th>Cost Total</th><th>By</th></tr></thead><tbody>
      <?php if(empty($recentProd)): ?><tr><td colspan="7"><div class="empty"><p>No records</p></div></td></tr>
      <?php else: foreach($recentProd as $r): $szLabel=($r['block_size']??'small')==='big'?'Big':'Small'; ?>
        <tr><td class="mono" style="font-weight:700"><?php echo date('D, d M Y',strtotime($r['production_date'])); ?></td><td><span style="font-size:9px;font-weight:800;padding:2px 8px;border-radius:99px;background:<?php echo $szLabel==='Big'?'var(--blue-soft)':'var(--ice-soft)'; ?>;color:<?php echo $szLabel==='Big'?'var(--blue)':'var(--ice)'; ?>"><?php echo $szLabel; ?></span></td><td class="mono"><?php echo number_format((float)$r['blocks_produced']); ?></td><td class="mono"><?php echo fmtN($r['price_per_block']); ?></td><td class="mono"><?php echo fmtN($r['sale_price']??0); ?></td><td class="mono"><?php echo fmtN((float)$r['blocks_produced']*(float)$r['price_per_block']); ?></td><td style="font-size:10px"><?php echo e($r['created_by_name']??'—'); ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?></div><div>Enterprise HR &amp; Operations System</div><div>Developed by Lawani Djamiou Alade</div></footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const fmt=n=>'₦'+Number(n||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t)}$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));setTheme(store.get('okoya_theme','light'));
function toast(msg,type){var t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900)}

var icePrices=<?php echo json_encode($icePrices); ?>;
var stockSmall=<?php echo $stockSmall; ?>;
var stockBig=<?php echo $stockBig; ?>;

/* Auto-fill prices when block size changes */
function autoFillSalePrice(size){
  var p=icePrices[size]||{};
  $('s_price').value=p.sale||'';
  calcSale();
}
/* Auto-fill production prices when size changes */
document.querySelectorAll('input[name="block_size"]').forEach(function(r){
  r.addEventListener('change',function(){
    var p=icePrices[this.value]||{};
    $('p_cost').value=p.cost||'';
    $('p_sale').value=p.sale||'';
  });
});
/* Trigger initial fill */
(function(){var checked=document.querySelector('input[name="block_size"]:checked');if(checked){var p=icePrices[checked.value]||{};$('p_cost').value=p.cost||'';$('p_sale').value=p.sale||'';}var sc=document.querySelector('input[name="sale_block_size"]:checked');if(sc){autoFillSalePrice(sc.value);}})();

function calcSale(){var qty=parseFloat($('s_qty').value)||0;var price=parseFloat($('s_price').value)||0;$('s_total').textContent=fmt(qty*price)}
['s_qty','s_price'].forEach(id=>$(id).addEventListener('input',calcSale));
calcSale();

$('saleForm').addEventListener('submit',function(ev){
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  var qty=parseFloat($('s_qty').value)||0;var price=parseFloat($('s_price').value)||0;
  var size=document.querySelector('input[name="sale_block_size"]:checked').value;
  var avail=size==='big'?stockBig:stockSmall;
  var ok=true,msg='';
  if(qty<=0){ok=false;msg='Enter number of blocks.';}
  else if(price<=0){ok=false;msg='Enter sale price.';}
  else if(qty>avail){ok=false;msg='Only '+avail+' '+(size==='big'?'big':'small')+' blocks in stock!';}
  if(!ok){ev.preventDefault();toast(msg,'error');}
});

$('prodForm').addEventListener('submit',function(ev){
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  var qty=parseFloat($('p_qty').value)||0;var cost=parseFloat($('p_cost').value)||0;var sale=parseFloat($('p_sale').value)||0;
  var ok=true,msg='';
  if(!$('p_date').value){ok=false;msg='Date required.';}
  else if(qty<=0){ok=false;msg='Quantity must be > 0.';}
  else if(cost<=0){ok=false;msg='Cost must be > 0.';}
  else if(sale<=0){ok=false;msg='Sale price must be > 0.';}
  if(!ok){ev.preventDefault();toast(msg,'error');}
});

var chartData=<?php echo json_encode(array_map(function($c){return['d'=>$c['d'],'prod'=>(int)$c['prod'],'sold'=>(int)$c['sold']];},$chart),JSON_HEX_TAG); ?>;
(function(){var box=$('chartBars');if(!box)return;if(!chartData.length){box.innerHTML='<div style="width:100%;text-align:center;color:var(--ink2);font-size:11px;font-weight:600;align-self:center">No data</div>';return;}var max=Math.max(...chartData.map(c=>Math.max(c.prod,c.sold)),1);box.innerHTML=chartData.map(c=>{var hp=Math.max(2,Math.round(c.prod/max*100)),hs=Math.max(2,Math.round(c.sold/max*100));return '<div class="cb-col" title="Produced: '+c.prod+' · Sold: '+c.sold+'"><div class="cb-pair"><div class="cb-bar prod" style="height:'+hp+'%"></div><div class="cb-bar sold" style="height:'+hs+'%"></div></div><span class="cb-lbl">'+c.d+'</span></div>';}).join('');})();

var FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>
