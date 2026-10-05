<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'],['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Operations & Commerce Analytics','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function fmtC($n):string{$n=(float)$n;if($n>=1e9)return '₦'.number_format($n/1e9,2).'B';if($n>=1e6)return '₦'.number_format($n/1e6,1).'M';if($n>=1e3)return '₦'.number_format($n/1e3,1).'K';return '₦'.number_format($n,0);}
function pPrice($p){foreach(['price_per_kg','price_perkg','price','price_kg','unit_price'] as $k){if(isset($p[$k])&&$p[$k]!==null)return(float)$p[$k];}return 0.0;}
function pStock($p){foreach(['stock','stock_kg','kilograms','quantity','stock_level','available'] as $k){if(isset($p[$k])&&$p[$k]!==null)return(float)$p[$k];}return 0.0;}
function pCategory($p){return $p['category']??($p['code']??'General');}

/* ===== PRODUCTS ===== */
$products=[];
try{$products=$pdo->query("SELECT * FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}

/* ===== ORDERS + RUNNING BALANCE ===== */
$orders=[];
try{$orders=$pdo->query("SELECT o.*,c.name AS client_name,p.name AS product_name FROM orders o LEFT JOIN clients c ON c.id=o.client_id LEFT JOIN products p ON p.id=o.product_id ORDER BY o.product_id ASC,o.order_date ASC,o.id ASC")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}

$stockByProduct=[];foreach($products as $p){$stockByProduct[$p['id']]=pStock($p);}
$netByProduct=[];$countByProduct=[];$revByProduct=[];
foreach($orders as $o){$pid=$o['product_id'];$netByProduct[$pid]=($netByProduct[$pid]??0)+(float)$o['kilograms'];$countByProduct[$pid]=($countByProduct[$pid]??0)+1;$revByProduct[$pid]=($revByProduct[$pid]??0)+(float)$o['total_amount'];}
$running=[];
foreach($orders as $i=>$o){$pid=$o['product_id'];if(!isset($running[$pid])){$running[$pid]=($stockByProduct[$pid]??0)+($netByProduct[$pid]??0);}$running[$pid]-=(float)$o['kilograms'];$orders[$i]['balance']=$running[$pid];$orders[$i]['price_per_kg']=(float)$o['kilograms']>0?(float)$o['total_amount']/(float)$o['kilograms']:0;}
$ordersDisplay=array_reverse($orders);
$ordersDisplay=array_slice($ordersDisplay,0,200);

/* ===== 14-DAY ORDER TREND ===== */
$orderTrend=[];
try{$rows=$pdo->query("SELECT DATE(order_date) AS d,SUM(kilograms) AS kg,SUM(total_amount) AS rev FROM orders WHERE DATE(order_date)>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY DATE(order_date)")->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r){$orderTrend[$r['d']]=$r;}}catch(Exception $ignore){}
$otLabels=[];$otKg=[];$otRev=[];
for($i=13;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$otLabels[]=date('d M',strtotime($d));$otKg[]=(float)($orderTrend[$d]['kg']??0);$otRev[]=(float)($orderTrend[$d]['rev']??0);}

/* ===== TOP PRODUCTS ===== */
$topProducts=[];
try{$topProducts=$pdo->query("SELECT p.name,SUM(o.kilograms) AS kg,SUM(o.total_amount) AS rev,COUNT(o.id) AS cnt FROM orders o JOIN products p ON p.id=o.product_id GROUP BY p.id,p.name ORDER BY rev DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}

/* ===== CATEGORY STATS ===== */
$catStats=[];
foreach($products as $p){$cat=pCategory($p);if(!isset($catStats[$cat]))$catStats[$cat]=['cnt'=>0,'val'=>0.0];$catStats[$cat]['cnt']++;$catStats[$cat]['val']+=pStock($p)*pPrice($p);}
arsort($catStats);
$mcLabels=array_keys($catStats);$mcValue=array_values(array_column($catStats,'val'));

$totalProducts=count($products);
$totalStockValue=0;foreach($products as $p){$totalStockValue+=pStock($p)*pPrice($p);}
$activeProducts=0;foreach($products as $p){if(($p['status']??'Active')==='Active')$activeProducts++;}
$avgPrice=$totalProducts>0?array_sum(array_map('pPrice',$products))/$totalProducts:0;
$totalRevenue=array_sum($revByProduct);
$totalOrdersCount=count($orders);

/* ===== ICE BLOCK (SMALL + BIG) ===== */
$iceSmallTrend=[];$iceBigTrend=[];
try{
    $rows=$pdo->query("SELECT production_date,
        SUM(CASE WHEN block_size='small' THEN blocks_produced ELSE 0 END) AS s_prod,
        SUM(CASE WHEN block_size='big' THEN blocks_produced ELSE 0 END) AS b_prod,
        SUM(CASE WHEN block_size='small' THEN blocks_delivered ELSE 0 END) AS s_deliv,
        SUM(CASE WHEN block_size='big' THEN blocks_delivered ELSE 0 END) AS b_deliv,
        SUM(CASE WHEN block_size='small' THEN blocks_delivered*price_per_block ELSE 0 END) AS s_rev,
        SUM(CASE WHEN block_size='big' THEN blocks_delivered*price_per_block ELSE 0 END) AS b_rev
        FROM ice_production WHERE production_date>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY production_date ORDER BY production_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)$iceSmallTrend[$r['production_date']]=$r;
}catch(Exception $ignore){}
/* Fallback if no block_size column */
if(empty($iceSmallTrend)){
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM ice_production")->fetchAll(PDO::FETCH_COLUMN);
        $hasSize=in_array('block_size',$cols);
        if(!$hasSize){
            $rows=$pdo->query("SELECT production_date,SUM(blocks_produced) AS prod,SUM(blocks_delivered) AS deliv,SUM(blocks_delivered*price_per_block) AS rev FROM ice_production WHERE production_date>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY production_date ORDER BY production_date ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $r){$iceSmallTrend[$r['production_date']]=['s_prod'=>$r['prod'],'b_prod'=>0,'s_deliv'=>$r['deliv'],'b_deliv'=>0,'s_rev'=>$r['rev'],'b_rev'=>0];}
        }
    }catch(Exception $ignore){}
}
$itLabels=[];$itSProd=[];$itBProd=[];$itSDeliv=[];$itBDeliv=[];$itSRev=[];$itBRev=[];
for($i=13;$i>=0;$i--){$d=date('Y-m-d',strtotime("-$i days"));$itLabels[]=date('d M',strtotime($d));$r=$iceSmallTrend[$d]??null;$itSProd[]=(int)($r['s_prod']??0);$itBProd[]=(int)($r['b_prod']??0);$itSDeliv[]=(int)($r['s_deliv']??0);$itBDeliv[]=(int)($r['b_deliv']??0);$itSRev[]=(float)($r['s_rev']??0);$itBRev[]=(float)($r['b_rev']??0);}

$iceTotals=['s_prod'=>0,'b_prod'=>0,'s_deliv'=>0,'b_deliv'=>0,'s_rev'=>0.0,'b_rev'=>0.0];
try{
    $cols=$pdo->query("SHOW COLUMNS FROM ice_production")->fetchAll(PDO::FETCH_COLUMN);
    $hasSize=in_array('block_size',$cols);
    if($hasSize){
        $r=$pdo->query("SELECT SUM(CASE WHEN block_size='small' THEN blocks_produced ELSE 0 END) AS sp,SUM(CASE WHEN block_size='big' THEN blocks_produced ELSE 0 END) AS bp,SUM(CASE WHEN block_size='small' THEN blocks_delivered ELSE 0 END) AS sd,SUM(CASE WHEN block_size='big' THEN blocks_delivered ELSE 0 END) AS bd,SUM(CASE WHEN block_size='small' THEN blocks_delivered*price_per_block ELSE 0 END) AS sr,SUM(CASE WHEN block_size='big' THEN blocks_delivered*price_per_block ELSE 0 END) AS br FROM ice_production")->fetch(PDO::FETCH_ASSOC);
        if($r)$iceTotals=['s_prod'=>(int)$r['sp'],'b_prod'=>(int)$r['bp'],'s_deliv'=>(int)$r['sd'],'b_deliv'=>(int)$r['bd'],'s_rev'=>(float)$r['sr'],'b_rev'=>(float)$r['br']];
    }else{
        $r=$pdo->query("SELECT IFNULL(SUM(blocks_produced),0) AS prod,IFNULL(SUM(blocks_delivered),0) AS deliv,IFNULL(SUM(blocks_delivered*price_per_block),0) AS rev FROM ice_production")->fetch(PDO::FETCH_ASSOC);
        if($r)$iceTotals=['s_prod'=>(int)$r['prod'],'b_prod'=>0,'s_deliv'=>(int)$r['deliv'],'b_deliv'=>0,'s_rev'=>(float)$r['rev'],'b_rev'=>0.0];
    }
}catch(Exception $ignore){}

/* ===== ACTIVITY LOG ===== */
$activities=[];
try{$activities=$pdo->query("SELECT a.*,u.full_name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}
$actByAction=[];
try{$actByAction=$pdo->query("SELECT action,COUNT(*) AS cnt FROM activity_logs GROUP BY action ORDER BY cnt DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $ignore){}
$abLabels=[];$abCnt=[];foreach($actByAction as $a){$abLabels[]=str_replace('_',' ',$a['action']);$abCnt[]=(int)$a['cnt'];}
$totalActivities=(int)array_sum($abCnt);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commerce Analytics | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f8fafc;--surface:#fff;--surface2:#f1f5f9;--ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;--line:#e2e8f0;--green:#059669;--green2:#047857;--green-soft:#ecfdf5;--gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--red:#dc2626;--red-soft:#fef2f2;--blue:#2563eb;--blue-soft:#eff6ff;--purple:#7c3aed;--purple-soft:#f5f3ff;--cyan:#0891b2;--cyan-soft:#ecfeff;--shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);--shadow-md:0 4px 6px -1px rgba(0,0,0,.07),0 2px 4px -2px rgba(0,0,0,.05);--shadow-lg:0 10px 15px -3px rgba(0,0,0,.08),0 4px 6px -4px rgba(0,0,0,.04);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:14px;--ease:cubic-bezier(.4,0,.2,1)}
[data-theme="dark"]{--bg:#0b1120;--surface:#151d2e;--surface2:#1c2740;--ink:#f1f5f9;--ink2:#94a3b8;--ink3:#64748b;--line:#1e293b;--green:#34d399;--green2:#10b981;--green-soft:#064e3b;--gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--red:#f87171;--red-soft:#450a0a;--blue:#60a5fa;--blue-soft:#1e3a5f;--purple:#a78bfa;--purple-soft:#2e1065;--cyan:#22d3ee;--cyan-soft:#164e63;--shadow-sm:0 1px 3px rgba(0,0,0,.25);--shadow-md:0 4px 6px rgba(0,0,0,.3);--shadow-lg:0 10px 15px rgba(0,0,0,.35)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:14px;line-height:1.6;transition:background .4s var(--ease),color .4s var(--ease);-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(ellipse 800px 500px at 85% -10%,rgba(5,150,105,.06),transparent 70%),radial-gradient(ellipse 600px 400px at -10% 40%,rgba(217,119,6,.05),transparent 70%)}
.container{max-width:1320px;margin:0 auto;padding:0 24px}
.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer;border:none;background:none}a{text-decoration:none;color:inherit}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(20px) saturate(1.4);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:42px;height:42px;border-radius:12px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:34px;height:34px;object-fit:contain}
.brand-text strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.25;letter-spacing:-.2px}
.brand-text small{color:var(--ink2);font-size:10px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:all .25s var(--ease)}
.back-link:hover{color:var(--green);border-color:var(--green)}
.back-link svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 10px;border-radius:99px;text-transform:uppercase}
.badge.report{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 20%,transparent)}
.theme-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:all .3s var(--ease)}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(180deg)}
.theme-btn svg{width:16px;height:16px}

.page-header{padding:32px 0 12px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(24px,3vw,32px);display:flex;align-items:center;gap:12px;letter-spacing:-.4px}
.page-header h1 svg{width:28px;height:28px;stroke:var(--green)}
.page-header h1 em{font-style:normal;color:var(--green)}
.page-header p{color:var(--ink2);font-weight:500;margin-top:6px;font-size:14px;max-width:700px}

.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-top:20px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:20px;box-shadow:var(--shadow-sm);transition:all .3s var(--ease);position:relative;overflow:hidden}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--shadow-md)}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi.k-green::before{background:var(--green)}.kpi.k-gold::before{background:var(--gold)}.kpi.k-blue::before{background:var(--blue)}.kpi.k-purple::before{background:var(--purple)}.kpi.k-cyan::before{background:var(--cyan)}
.kpi-label{font-size:10px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px}
.kpi-val{font-family:var(--fd);font-size:26px;font-weight:800;line-height:1;letter-spacing:-.5px}
.kpi-sub{font-size:11px;color:var(--ink2);font-weight:600;margin-top:6px}

.tabs{display:flex;gap:6px;margin:24px 0 18px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:0}
.tab-btn{padding:12px 20px;border-radius:10px 10px 0 0;border:1px solid transparent;border-bottom:none;background:transparent;color:var(--ink2);font-family:var(--fd);font-weight:700;font-size:13px;transition:all .2s var(--ease);cursor:pointer;display:inline-flex;align-items:center;gap:8px;position:relative;bottom:-1px}
.tab-btn svg{width:15px;height:15px}
.tab-btn:hover{color:var(--ink);background:var(--surface2)}
.tab-btn.active{background:var(--surface);color:var(--green);border-color:var(--line);border-bottom-color:var(--surface)}
.tab-panel{display:none}.tab-panel.active{display:block;animation:fadeIn .35s var(--ease)}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 22px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;letter-spacing:-.1px}
.card-head h2 svg{width:16px;height:16px;stroke:var(--green)}
.card-body{padding:22px}
.chart-wrap{position:relative;height:280px}
.chart-wrap.sm{height:220px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.mt-16{margin-top:16px}

.legend-row{display:flex;gap:16px;flex-wrap:wrap;margin-top:14px;justify-content:center}
.lg{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--ink2)}
.lg i{width:10px;height:10px;border-radius:3px;display:inline-block}

.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:720px}
thead th{font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink3);text-align:left;padding:12px 16px;border-bottom:2px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:12px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.empty{padding:48px 20px;text-align:center;color:var(--ink3)}
.empty svg{width:44px;height:44px;stroke:var(--ink3);margin:0 auto 10px;opacity:.4;display:block}
.empty p{font-weight:700;font-size:14px;color:var(--ink2)}.empty small{font-weight:600;font-size:12px}
.st-pill{font-size:9px;font-weight:800;padding:3px 10px;border-radius:99px;letter-spacing:.4px;text-transform:uppercase}
.st-pill.active{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 20%,transparent)}
.st-pill.inactive{background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb,var(--red) 20%,transparent)}
.st-pill.purple{background:var(--purple-soft);color:var(--purple);border:1px solid color-mix(in srgb,var(--purple) 20%,transparent)}
.rate-bar{height:8px;border-radius:99px;background:var(--surface2);overflow:hidden;min-width:60px}
.rate-bar div{height:100%;border-radius:99px}

.ice-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.ice-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:18px 20px;text-align:center;position:relative;overflow:hidden}
.ice-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.ice-card.ic-blue::before{background:var(--blue)}.ice-card.ic-cyan::before{background:var(--cyan)}.ice-card.ic-green::before{background:var(--green)}.ice-card.ic-gold::before{background:var(--gold)}
.ice-card .ic-label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--ink3);margin-bottom:6px}
.ice-card .ic-val{font-family:var(--fd);font-size:24px;font-weight:800;letter-spacing:-.3px}
.ice-card .ic-sub{font-size:10px;color:var(--ink2);font-weight:600;margin-top:4px}

footer{margin-top:40px;border-top:1px solid var(--line);padding:24px;text-align:center;color:var(--ink3);font-size:11px;font-weight:600}
footer span{color:var(--green)}

@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(3,1fr)}.ice-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.kpi-grid{grid-template-columns:1fr}.ice-summary{grid-template-columns:1fr}.tabs{gap:4px}.tab-btn{padding:10px 14px;font-size:12px}.chart-wrap{height:220px}.chart-wrap.sm{height:180px}}
</style>
</head>
<body>

<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div class="brand-text"><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $isAdmin?'admin_general_dashboard.php':'staff_first_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back</a>
  <span class="badge report">Analytics</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Commerce <em>Analytics</em></h1>
    <p>Comprehensive product ledger, stock valuation, ice block market intelligence, and operational activity tracking.</p>
  </div>

  <!-- KPI STRIP -->
  <div class="kpi-grid">
    <div class="kpi k-green"><div class="kpi-label">Total Products</div><div class="kpi-val"><?php echo $totalProducts; ?></div><div class="kpi-sub"><?php echo $activeProducts; ?> active in catalog</div></div>
    <div class="kpi k-gold"><div class="kpi-label">Stock Value</div><div class="kpi-val"><?php echo fmtC($totalStockValue); ?></div><div class="kpi-sub">Avg <?php echo fmtN($avgPrice); ?>/kg</div></div>
    <div class="kpi k-blue"><div class="kpi-label">Total Revenue</div><div class="kpi-val"><?php echo fmtC($totalRevenue); ?></div><div class="kpi-sub"><?php echo number_format($totalOrdersCount); ?> orders fulfilled</div></div>
    <div class="kpi k-cyan"><div class="kpi-label">Ice Produced</div><div class="kpi-val"><?php echo number_format($iceTotals['s_prod']+$iceTotals['b_prod']); ?></div><div class="kpi-sub"><?php echo number_format($iceTotals['s_prod']); ?> small · <?php echo number_format($iceTotals['b_prod']); ?> big</div></div>
    <div class="kpi k-purple"><div class="kpi-label">Activity Events</div><div class="kpi-val"><?php echo number_format($totalActivities); ?></div><div class="kpi-sub">System-wide audit trail</div></div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="overview"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Overview</button>
    <button class="tab-btn" data-tab="products"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>Products Ledger</button>
    <button class="tab-btn" data-tab="ice"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2v20M2 12h20M4.93 4.93l14.14 14.14M19.07 4.93L4.93 19.07"/></svg>Ice Block Market</button>
    <button class="tab-btn" data-tab="activity"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>Activity Log</button>
  </div>

  <!-- OVERVIEW -->
  <div class="tab-panel active" id="tab-overview">
    <div class="grid-2">
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>Stock Value by Category</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="catDonut"></canvas></div></div></div>
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Revenue Trend — 14 Days</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="revTrend"></canvas></div></div></div>
    </div>
    <div class="card mt-16"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="8" width="3" height="10"/><rect x="12" y="5" width="3" height="13"/><rect x="17" y="11" width="3" height="7"/></svg>Operational Activity Distribution</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="actBar"></canvas></div></div></div>
  </div>

  <!-- PRODUCTS LEDGER -->
  <div class="tab-panel" id="tab-products">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg>Product Catalog — Stock &amp; Pricing</h2><span class="badge report"><?php echo $totalProducts; ?> items</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Product</th><th>Category</th><th>Price/kg</th><th>Stock (kg)</th><th>Stock Value</th><th>Orders</th><th>Revenue</th><th>Status</th></tr></thead>
        <tbody>
          <?php if(empty($products)): ?><tr><td colspan="8"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg><p>No products in catalog</p><small>Add products to populate this ledger.</small></div></td></tr>
          <?php else: foreach($products as $p): $stock=pStock($p);$price=pPrice($p);$cnt=$countByProduct[$p['id']]??0;$rev=$revByProduct[$p['id']]??0;$stt=$p['status']??'Active';$pill=strtolower($stt)==='active'?'active':'inactive'; ?>
            <tr><td style="font-weight:800"><?php echo e($p['name']); ?></td><td><?php echo e(pCategory($p)); ?></td><td class="mono"><?php echo fmtN($price); ?></td><td class="mono" style="font-weight:700"><?php echo number_format($stock); ?></td><td class="mono" style="color:var(--green);font-weight:700"><?php echo fmtN($stock*$price); ?></td><td class="mono"><?php echo $cnt; ?></td><td class="mono" style="color:var(--green);font-weight:700"><?php echo fmtN($rev); ?></td><td><span class="st-pill <?php echo $pill; ?>"><?php echo e($stt); ?></span></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <div class="card mt-16">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Product Movements Ledger</h2><span class="badge report"><?php echo count($ordersDisplay); ?> entries</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Product</th><th>Client</th><th>Qty (kg)</th><th>₦/kg</th><th>Total</th><th>Balance</th></tr></thead>
        <tbody>
          <?php if(empty($ordersDisplay)): ?><tr><td colspan="7"><div class="empty"><p>No movements recorded</p></div></td></tr>
          <?php else: foreach($ordersDisplay as $o): ?>
            <tr><td class="mono" style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y',strtotime($o['order_date'])); ?></td><td style="font-weight:800"><?php echo e($o['product_name']??'—'); ?></td><td><?php echo e($o['client_name']??'Walk-in'); ?></td><td class="mono">-<?php echo number_format((float)$o['kilograms']); ?></td><td class="mono"><?php echo fmtN($o['price_per_kg']); ?></td><td class="mono" style="font-weight:700"><?php echo fmtN($o['total_amount']); ?></td><td class="mono" style="font-weight:700;color:var(--green)"><?php echo number_format((float)$o['balance']); ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <div class="grid-2 mt-16">
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Order Volume — 14 Days</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="ordVolChart"></canvas></div></div></div>
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>Top Products by Revenue</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Product</th><th>Revenue</th></tr></thead><tbody>
          <?php if(empty($topProducts)): ?><tr><td colspan="2"><div class="empty"><p>No sales data</p></div></td></tr>
          <?php else: $maxR=(float)($topProducts[0]['rev']??1);foreach($topProducts as $tp): $pct=$maxR>0?round((float)$tp['rev']/$maxR*100):0; ?>
            <tr><td style="font-weight:800"><?php echo e($tp['name']); ?></td><td><div style="display:flex;align-items:center;gap:10px"><div class="rate-bar" style="flex:1"><div style="width:<?php echo $pct; ?>%;background:var(--green)"></div></div><span class="mono" style="font-weight:700;white-space:nowrap"><?php echo fmtC($tp['rev']); ?></span></div></td></tr>
          <?php endforeach; endif; ?>
        </tbody></table></div>
      </div>
    </div>
  </div>

  <!-- ICE BLOCK MARKET -->
  <div class="tab-panel" id="tab-ice">
    <div class="ice-summary">
      <div class="ice-card ic-blue"><div class="ic-label">Small Blocks Produced</div><div class="ic-val"><?php echo number_format($iceTotals['s_prod']); ?></div><div class="ic-sub">Lifetime total</div></div>
      <div class="ice-card ic-cyan"><div class="ic-label">Big Blocks Produced</div><div class="ic-val"><?php echo number_format($iceTotals['b_prod']); ?></div><div class="ic-sub">Lifetime total</div></div>
      <div class="ice-card ic-green"><div class="ic-label">Total Delivered</div><div class="ic-val"><?php echo number_format($iceTotals['s_deliv']+$iceTotals['b_deliv']); ?></div><div class="ic-sub"><?php echo number_format($iceTotals['s_deliv']); ?> small · <?php echo number_format($iceTotals['b_deliv']); ?> big</div></div>
      <div class="ice-card ic-gold"><div class="ic-label">Ice Revenue</div><div class="ic-val"><?php echo fmtC($iceTotals['s_rev']+$iceTotals['b_rev']); ?></div><div class="ic-sub"><?php echo fmtC($iceTotals['s_rev']); ?> small · <?php echo fmtC($iceTotals['b_rev']); ?> big</div></div>
    </div>
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2v20M2 12h20M4.93 4.93l14.14 14.14M19.07 4.93L4.93 19.07"/></svg>Production: Small vs Big Blocks — 14 Days</h2></div>
      <div class="card-body"><div class="chart-wrap"><canvas id="iceProdChart"></canvas></div>
        <div class="legend-row"><span class="lg"><i style="background:var(--blue)"></i>Small Produced</span><span class="lg"><i style="background:var(--cyan)"></i>Big Produced</span><span class="lg"><i style="background:var(--green)"></i>Small Delivered</span><span class="lg"><i style="background:var(--gold)"></i>Big Delivered</span></div>
      </div>
    </div>
    <div class="grid-2 mt-16">
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Revenue: Small Blocks — 14 Days</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="iceSRevChart"></canvas></div></div></div>
      <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Revenue: Big Blocks — 14 Days</h2></div><div class="card-body"><div class="chart-wrap sm"><canvas id="iceBRevChart"></canvas></div></div></div>
    </div>
  </div>

  <!-- ACTIVITY LOG -->
  <div class="tab-panel" id="tab-activity">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>System Activity Audit Trail</h2><span class="badge report"><?php echo number_format($totalActivities); ?> events</span></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
          <?php if(empty($activities)): ?><tr><td colspan="4"><div class="empty"><p>No activity recorded</p></div></td></tr>
          <?php else: foreach($activities as $a): ?>
            <tr><td class="mono" style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y, H:i',strtotime($a['created_at'])); ?></td><td style="font-weight:800"><?php echo e($a['full_name']??'System'); ?></td><td><span class="st-pill purple"><?php echo e(str_replace('_',' ',$a['action'])); ?></span></td><td style="font-size:12px;color:var(--ink2)"><?php echo e($a['details']??'—'); ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Commerce Analytics Platform</div><div style="margin-top:4px">Developed by <span>Lawani Djamiou Alade</span></div></footer>

<script>
var g=function(id){return document.getElementById(id);};
var store={get:function(k,d){try{var v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
g('themeBtn').addEventListener('click',function(){setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark');});
setTheme(store.get('okoya_theme','light'));
var dk=document.documentElement.dataset.theme==='dark';
var C={green:dk?'#34d399':'#059669',gold:dk?'#fbbf24':'#d97706',red:dk?'#f87171':'#dc2626',blue:dk?'#60a5fa':'#2563eb',purple:dk?'#a78bfa':'#7c3aed',cyan:dk?'#22d3ee':'#0891b2'};
Chart.defaults.font.family="'Manrope',sans-serif";
Chart.defaults.color=dk?'#94a3b8':'#475569';
Chart.defaults.borderColor=dk?'#1e293b':'#e2e8f0';

document.querySelectorAll('.tab-btn').forEach(function(b){b.addEventListener('click',function(){
  document.querySelectorAll('.tab-btn').forEach(function(x){x.classList.remove('active');});
  document.querySelectorAll('.tab-panel').forEach(function(x){x.classList.remove('active');});
  b.classList.add('active');g('tab-'+b.dataset.tab).classList.add('active');
});});

var otL=<?php echo json_encode($otLabels); ?>;
var otK=<?php echo json_encode($otKg); ?>;
var otR=<?php echo json_encode($otRev); ?>;
var itL=<?php echo json_encode($itLabels); ?>;
var itSP=<?php echo json_encode($itSProd); ?>;
var itBP=<?php echo json_encode($itBProd); ?>;
var itSD=<?php echo json_encode($itSDeliv); ?>;
var itBD=<?php echo json_encode($itBDeliv); ?>;
var itSR=<?php echo json_encode($itSRev); ?>;
var itBR=<?php echo json_encode($itBRev); ?>;

new Chart(g('catDonut'),{type:'doughnut',data:{labels:<?php echo json_encode($mcLabels); ?>,datasets:[{data:<?php echo json_encode($mcValue); ?>,backgroundColor:[C.green,C.gold,C.blue,C.purple,C.cyan,C.red],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{padding:16,usePointStyle:true,pointStyleWidth:10}}}}});

new Chart(g('revTrend'),{type:'line',data:{labels:otL,datasets:[{label:'Revenue (₦)',data:otR,borderColor:C.green,backgroundColor:C.green+'22',fill:true,tension:.4,borderWidth:2,pointRadius:3,pointHoverRadius:5}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v;}}}}}});

new Chart(g('actBar'),{type:'bar',data:{labels:<?php echo json_encode(array_slice($abLabels,0,8)); ?>,datasets:[{label:'Events',data:<?php echo json_encode(array_slice($abCnt,0,8)); ?>,backgroundColor:C.purple,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});

new Chart(g('ordVolChart'),{type:'bar',data:{labels:otL,datasets:[{label:'Ordered (kg)',data:otK,backgroundColor:C.gold,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

new Chart(g('iceProdChart'),{type:'bar',data:{labels:itL,datasets:[
  {label:'Small Produced',data:itSP,backgroundColor:C.blue,borderRadius:4},
  {label:'Big Produced',data:itBP,backgroundColor:C.cyan,borderRadius:4},
  {label:'Small Delivered',data:itSD,backgroundColor:C.green,borderRadius:4},
  {label:'Big Delivered',data:itBD,backgroundColor:C.gold,borderRadius:4}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,stacked:true},x:{stacked:true}}}});

new Chart(g('iceSRevChart'),{type:'bar',data:{labels:itL,datasets:[{label:'Small Revenue',data:itSR,backgroundColor:C.blue,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v>=1e3?(v/1e3).toFixed(0)+'K':v;}}}}}});

new Chart(g('iceBRevChart'),{type:'bar',data:{labels:itL,datasets:[{label:'Big Revenue',data:itBR,backgroundColor:C.cyan,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v>=1e3?(v/1e3).toFixed(0)+'K':v;}}}}}});
</script>
</body>
</html>