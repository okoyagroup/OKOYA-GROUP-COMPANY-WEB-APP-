<?php
require_once 'config.php';

$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
if (!in_array($auth['role'], ['admin','ceo','general manager'])) { header('Location: secretary_dashboard.php'); exit; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += [
    'company_name'=>'OKOYA FOOD COMPANY LIMITED',
    'company_dept'=>'Staff Management & HR Department',
    'company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria',
    'company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'
];

try {
    $todayOrders=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_date=CURDATE()")->fetchColumn();
    $totalOrders=(int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $totalRevenue=(float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn();
    $todayRevenue=(float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='Paid' AND DATE(paid_at)=CURDATE()")->fetchColumn();
    $pendingCount=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn();
    $approvedCount=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Approved'")->fetchColumn();
    $deliveredCnt=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Delivered'")->fetchColumn();
    $totalClients=(int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $totalUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
    $unpaidAmount=(float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='Unpaid'")->fetchColumn();
} catch(Exception $e) { $todayOrders=$totalOrders=$totalRevenue=$todayRevenue=$pendingCount=$approvedCount=$deliveredCnt=$totalClients=$totalUsers=$unpaidAmount=0; }

$recentOrders=[];
try {
    $st=$pdo->query("SELECT o.order_no,o.total_amount,o.status,o.is_paid,o.order_date,o.kilograms,c.name AS client_name,p.name AS product_name FROM orders o LEFT JOIN clients c ON c.id=o.client_id LEFT JOIN products p ON p.id=o.product_id ORDER BY o.created_at DESC LIMIT 8");
    $recentOrders=$st->fetchAll();
} catch(Exception $ignore){}

$recentActivity=[];
try {
    $st=$pdo->query("SELECT a.*,u.full_name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 10");
    $recentActivity=$st->fetchAll();
} catch(Exception $ignore){}

$topProducts=[];
try {
    $st=$pdo->query("SELECT p.name,SUM(o.kilograms) AS total_kg,SUM(o.total_amount) AS total_rev,COUNT(*) AS order_count FROM orders o JOIN products p ON p.id=o.product_id GROUP BY p.id,p.name ORDER BY total_rev DESC LIMIT 4");
    $topProducts=$st->fetchAll();
} catch(Exception $ignore){}

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Admin Dashboard | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;--surface3:#e8edf2;
  --ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;
  --line:#e2e8f0;--line2:#cbd5e1;
  --green:#059669;--green2:#047857;--green-soft:#ecfdf5;--green-glow:rgba(5,150,105,.15);
  --gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--gold-glow:rgba(217,119,6,.12);
  --red:#dc2626;--red-soft:#fef2f2;--red-glow:rgba(220,38,38,.1);
  --blue:#2563eb;--blue-soft:#eff6ff;--blue-glow:rgba(37,99,235,.1);
  --purple:#7c3aed;--purple-soft:#f5f3ff;
  --shadow-xs:0 1px 2px rgba(0,0,0,.04);
  --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.07),0 2px 4px -2px rgba(0,0,0,.05);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.08),0 4px 6px -4px rgba(0,0,0,.04);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);
  --fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;
  --r:14px;--r-lg:20px;--r-xl:24px;
  --ease:cubic-bezier(.4,0,.2,1);--ease-spring:cubic-bezier(.34,1.56,.64,1);
}
[data-theme="dark"]{
  --bg:#0b1120;--surface:#151d2e;--surface2:#1c2740;--surface3:#243049;
  --ink:#f1f5f9;--ink2:#94a3b8;--ink3:#64748b;
  --line:#1e293b;--line2:#334155;
  --green:#34d399;--green2:#10b981;--green-soft:#064e3b;--green-glow:rgba(52,211,153,.12);
  --gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--gold-glow:rgba(251,191,36,.1);
  --red:#f87171;--red-soft:#450a0a;--red-glow:rgba(248,113,113,.1);
  --blue:#60a5fa;--blue-soft:#1e3a5f;--blue-glow:rgba(96,165,250,.1);
  --purple:#a78bfa;--purple-soft:#2e1065;
  --shadow-xs:0 1px 2px rgba(0,0,0,.2);
  --shadow-sm:0 1px 3px rgba(0,0,0,.25),0 1px 2px rgba(0,0,0,.2);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.3),0 2px 4px -2px rgba(0,0,0,.25);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.35),0 4px 6px -4px rgba(0,0,0,.25);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.4),0 8px 10px -6px rgba(0,0,0,.3);
}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.6;transition:background .4s var(--ease),color .4s var(--ease);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(ellipse 800px 500px at 85% -10%,var(--green-glow),transparent 70%),radial-gradient(ellipse 600px 400px at -10% 30%,var(--gold-glow),transparent 70%),radial-gradient(ellipse 500px 300px at 50% 100%,var(--blue-glow),transparent 70%)}
.container{max-width:1280px;margin:0 auto;padding:0 24px}
.mono{font-family:var(--fm)}
svg{flex:none}button{font-family:inherit;cursor:pointer;border:none;background:none}a{text-decoration:none;color:inherit}
::selection{background:color-mix(in srgb,var(--green) 25%,transparent)}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ===== ENTRANCE ANIMATIONS ===== */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
@keyframes slideRight{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
.anim-fade-up{animation:fadeUp .6s var(--ease) both}
.anim-fade-in{animation:fadeIn .5s var(--ease) both}
.anim-scale{animation:scaleIn .5s var(--ease-spring) both}
.d1{animation-delay:.05s}.d2{animation-delay:.1s}.d3{animation-delay:.15s}.d4{animation-delay:.2s}
.d5{animation-delay:.25s}.d6{animation-delay:.3s}.d7{animation-delay:.35s}.d8{animation-delay:.4s}

/* ===== TOPBAR ===== */
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 82%,transparent);backdrop-filter:blur(20px) saturate(1.4);border-bottom:1px solid var(--line);transition:background .4s var(--ease)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:42px;height:42px;border-radius:12px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line);transition:transform .3s var(--ease-spring)}
.logo-chip:hover{transform:scale(1.08) rotate(-3deg)}
.logo-chip img{width:34px;height:34px;object-fit:contain}
.brand-text strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.25;letter-spacing:-.2px}
.brand-text small{color:var(--ink2);font-size:10px;font-weight:600;letter-spacing:.2px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 10px;border-radius:99px;text-transform:uppercase}
.badge.admin{background:linear-gradient(135deg,var(--gold-soft),color-mix(in srgb,var(--gold) 15%,var(--surface)));color:var(--gold2);border:1px solid color-mix(in srgb,var(--gold) 20%,transparent)}
.badge.live{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 20%,transparent)}
.badge.live .dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:livePulse 2s ease-in-out infinite}
@keyframes livePulse{0%,100%{opacity:1;box-shadow:0 0 0 0 var(--green-glow)}50%{opacity:.6;box-shadow:0 0 0 6px transparent}}
.nav-btn{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:all .25s var(--ease)}
.nav-btn:hover{color:var(--green);border-color:var(--green);background:var(--green-soft);transform:translateY(-1px);box-shadow:var(--shadow-sm)}
.nav-btn svg{width:14px;height:14px}
.user-pill{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--line);border-radius:99px;padding:4px 14px 4px 4px;font-size:11px;font-weight:700;transition:all .25s var(--ease)}
.user-pill:hover{border-color:var(--green);box-shadow:0 0 0 3px var(--green-glow)}
.user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:11px;font-weight:800}
.theme-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:all .3s var(--ease)}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(180deg);box-shadow:0 0 0 3px var(--gold-glow)}
.theme-btn svg{width:16px;height:16px}
.logout-link{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:var(--red);padding:7px 14px;border-radius:10px;border:1px solid var(--red-soft);background:var(--red-soft);transition:all .25s var(--ease)}
.logout-link:hover{background:var(--red);color:#fff;border-color:var(--red);transform:translateY(-1px);box-shadow:0 4px 12px var(--red-glow)}
.logout-link svg{width:14px;height:14px}

/* ===== WELCOME BANNER ===== */
.welcome-banner{background:linear-gradient(135deg,var(--green) 0%,var(--green2) 50%,#065f46 100%);border-radius:var(--r-xl);padding:36px 40px;color:#fff;position:relative;overflow:hidden;box-shadow:var(--shadow-xl),0 0 0 1px rgba(255,255,255,.08) inset;margin-top:24px}
.welcome-banner::after{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08),transparent 70%);right:-60px;bottom:-100px;pointer-events:none}
.welcome-banner::before{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.05),transparent 70%);right:80px;top:-50px;pointer-events:none}
.wb-pattern{position:absolute;inset:0;opacity:.04;pointer-events:none;background-image:radial-gradient(circle at 1px 1px,#fff 1px,transparent 0);background-size:24px 24px}
.wb-content{position:relative;z-index:1}
.wb-greeting{font-family:var(--fd);font-size:clamp(24px,3.5vw,34px);font-weight:800;line-height:1.15;display:flex;align-items:center;gap:14px;letter-spacing:-.5px}
.wb-greeting svg{width:32px;height:32px;stroke:#fde68a;flex:none}
.wb-sub{font-size:15px;opacity:.85;font-weight:500;margin-top:8px;max-width:600px;line-height:1.6}
.wb-meta{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
.wb-tag{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;background:rgba(255,255,255,.12);backdrop-filter:blur(8px);padding:6px 14px;border-radius:99px;letter-spacing:.2px;border:1px solid rgba(255,255,255,.1);transition:all .25s var(--ease)}
.wb-tag:hover{background:rgba(255,255,255,.2);transform:translateY(-1px)}
.wb-tag svg{width:14px;height:14px;opacity:.8}

/* ===== STATS GRID ===== */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:24px}
.stat-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);padding:24px;box-shadow:var(--shadow-sm);transition:all .35s var(--ease);position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0;transition:height .3s var(--ease)}
.stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.stat-card:hover::before{height:4px}
.stat-card.accent-green::before{background:linear-gradient(90deg,var(--green),var(--green2))}
.stat-card.accent-gold::before{background:linear-gradient(90deg,var(--gold),var(--gold2))}
.stat-card.accent-blue::before{background:linear-gradient(90deg,var(--blue),#1d4ed8)}
.stat-card.accent-red::before{background:linear-gradient(90deg,var(--red),#b91c1c)}
.sc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.sc-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;transition:transform .3s var(--ease-spring)}
.stat-card:hover .sc-icon{transform:scale(1.1) rotate(-5deg)}
.sc-icon svg{width:22px;height:22px}
.accent-green .sc-icon{background:var(--green-soft);color:var(--green)}
.accent-gold .sc-icon{background:var(--gold-soft);color:var(--gold2)}
.accent-blue .sc-icon{background:var(--blue-soft);color:var(--blue)}
.accent-red .sc-icon{background:var(--red-soft);color:var(--red)}
.sc-val{font-family:var(--fd);font-size:30px;font-weight:800;display:block;line-height:1;letter-spacing:-.5px}
.sc-label{font-size:11px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.6px;margin-top:6px}
.sc-sub{font-size:11px;color:var(--ink3);font-weight:600;margin-top:8px;padding-top:8px;border-top:1px solid var(--line)}

/* ===== SECTION HEADERS ===== */
.section-head{display:flex;align-items:center;justify-content:space-between;margin-top:36px;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.section-head h2{font-family:var(--fd);font-size:20px;font-weight:800;display:flex;align-items:center;gap:10px;letter-spacing:-.3px}
.section-head h2 svg{width:22px;height:22px;stroke:var(--green)}
.section-head a{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--green);padding:7px 16px;border:1px solid var(--green-soft);border-radius:99px;transition:all .25s var(--ease)}
.section-head a:hover{background:var(--green);color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px var(--green-glow)}
.section-head a svg{width:14px;height:14px}

/* ===== NAV GRID ===== */
.nav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-top:16px}
.nav-card{background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:24px 18px;display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;transition:all .3s var(--ease);cursor:pointer;color:var(--ink);position:relative;overflow:hidden}
.nav-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--green-glow),transparent);opacity:0;transition:opacity .3s var(--ease)}
.nav-card:hover{border-color:var(--green);transform:translateY(-5px);box-shadow:var(--shadow-lg)}
.nav-card:hover::before{opacity:1}
.nav-card:hover .nc-icon{background:var(--green);border-color:var(--green);transform:scale(1.1)}
.nav-card:hover .nc-icon svg{stroke:#fff}
.nc-icon{width:52px;height:52px;border-radius:14px;background:var(--surface2);border:1px solid var(--line);display:grid;place-items:center;transition:all .3s var(--ease-spring);position:relative;z-index:1}
.nc-icon svg{width:24px;height:24px;stroke:var(--ink2);transition:stroke .3s var(--ease)}
.nc-title{font-family:var(--fd);font-size:13px;font-weight:700;position:relative;z-index:1;letter-spacing:-.1px}
.nc-desc{font-size:10.5px;color:var(--ink2);font-weight:600;line-height:1.45;position:relative;z-index:1}

/* ===== DASH GRID ===== */
.dash-grid{display:grid;grid-template-columns:1.5fr 1fr;gap:20px;margin-top:20px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);overflow:hidden;transition:box-shadow .3s var(--ease)}
.card:hover{box-shadow:var(--shadow-md)}
.card-header{padding:18px 22px;border-bottom:1px solid var(--line);font-family:var(--fd);font-weight:700;font-size:14px;display:flex;align-items:center;gap:10px;letter-spacing:-.1px}
.card-header svg{width:18px;height:18px;stroke:var(--green)}

/* ===== TABLES ===== */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:600px}
thead th{font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink3);text-align:left;padding:14px 18px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:13px 18px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .2s var(--ease)}
tbody tr:hover{background:var(--surface2)}
tbody tr:last-child td{border-bottom:none}
.st-pill{font-size:9px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;display:inline-block;text-transform:uppercase}
.st-pill.pending{background:var(--gold-soft);color:var(--gold2);border:1px solid color-mix(in srgb,var(--gold) 20%,transparent)}
.st-pill.approved{background:var(--blue-soft);color:var(--blue);border:1px solid color-mix(in srgb,var(--blue) 20%,transparent)}
.st-pill.delivered{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 20%,transparent)}

/* ===== ACTIVITY FEED ===== */
.activity-item{display:flex;gap:14px;padding:16px 22px;border-bottom:1px solid var(--line);transition:background .2s var(--ease)}
.activity-item:hover{background:var(--surface2)}
.activity-item:last-child{border-bottom:none}
.ai-icon{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;flex:none;transition:transform .25s var(--ease-spring)}
.activity-item:hover .ai-icon{transform:scale(1.08)}
.ai-icon svg{width:18px;height:18px}
.ai-icon.create{background:var(--green-soft)}.ai-icon.create svg{stroke:var(--green)}
.ai-icon.login{background:var(--blue-soft)}.ai-icon.login svg{stroke:var(--blue)}
.ai-icon.logout{background:var(--gold-soft)}.ai-icon.logout svg{stroke:var(--gold2)}
.ai-icon.delete{background:var(--red-soft)}.ai-icon.delete svg{stroke:var(--red)}
.ai-icon.update{background:var(--purple-soft)}.ai-icon.update svg{stroke:var(--purple)}
.ai-body{flex:1;min-width:0}
.ai-body strong{font-size:13px;font-weight:700;display:block;letter-spacing:-.1px}
.ai-body small{font-size:11px;color:var(--ink2);font-weight:600}
.ai-time{font-size:10px;color:var(--ink3);font-weight:700;white-space:nowrap;font-family:var(--fm)}

/* ===== PRODUCT BARS ===== */
.product-bars{padding:20px 22px}
.pb-item{margin-bottom:16px}
.pb-item:last-child{margin-bottom:0}
.pb-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
.pb-name{font-size:13px;font-weight:700;letter-spacing:-.1px}
.pb-rev{font-family:var(--fm);font-size:12px;font-weight:700;color:var(--green)}
.pb-track{height:8px;border-radius:99px;background:var(--surface2);overflow:hidden}
.pb-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--green),var(--gold));transition:width .8s cubic-bezier(.4,0,.2,1)}
.pb-meta{display:flex;justify-content:space-between;margin-top:5px;font-size:10px;color:var(--ink3);font-weight:600}

/* ===== SYSTEM OVERVIEW ===== */
.sys-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:0 22px 22px}
.sys-item{background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:16px;text-align:center;transition:all .25s var(--ease)}
.sys-item:hover{border-color:var(--green);transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.sys-item .sv{font-family:var(--fd);font-size:24px;font-weight:800;line-height:1.1;letter-spacing:-.3px}
.sys-item .sl{font-size:9px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.6px;margin-top:4px}

/* ===== FOOTER ===== */
footer{margin-top:40px;border-top:1px solid var(--line);padding:28px 24px 36px;text-align:center;color:var(--ink3);font-size:11px;font-weight:600}
footer div+div{margin-top:4px}
footer .dev-credit{font-weight:700;color:var(--ink2);margin-top:8px;font-size:12px}
footer .dev-credit span{color:var(--green)}

@media(max-width:1020px){.stats-grid{grid-template-columns:repeat(2,1fr)}.dash-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .stats-grid{grid-template-columns:1fr 1fr;gap:10px}
  .stat-card{padding:18px}.stat-card .sc-val{font-size:24px}
  .nav-grid{grid-template-columns:repeat(2,1fr)}
  .welcome-banner{padding:24px 22px}.wb-meta{gap:8px}
  .topbar .badge{display:none}.brand-text strong{font-size:12px}
  .section-head h2{font-size:17px}
  .container{padding:0 16px}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr}
  .nav-grid{grid-template-columns:1fr}
  .user-pill span{display:none}
  .sys-grid{grid-template-columns:1fr}
  .wb-greeting{font-size:22px}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <a href="../admin_first_dashboard.php" class="nav-btn" title="Back to Department Hub">
      <svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back
    </a>
    <div class="brand">
      <div class="logo-chip"><img src="logo.ico" alt="Logo"></div>
      <div class="brand-text">
        <strong><?php echo e($company['company_name']); ?></strong>
        <small><?php echo e($company['company_dept']); ?></small>
      </div>
    </div>
    <span class="badge admin">ADMIN</span>
    <span class="badge live"><span class="dot"></span>ONLINE</span>
    <div class="user-pill">
      <div class="user-avatar"><?php echo strtoupper(substr($auth['name'],0,1)); ?></div>
      <span><?php echo e(explode(' ',$auth['name'])[0]); ?></span>
    </div>
    <button class="theme-btn" id="themeBtn" title="Toggle theme" aria-label="Toggle dark mode">
      <svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>
    </button>
    <a href="../../index.php?logout=1" class="logout-link">
      <svg class="icon-svg" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout
    </a>
  </div>
</header>

<div class="container">

  <!-- Welcome Banner -->
  <div class="welcome-banner anim-fade-up">
    <div class="wb-pattern"></div>
    <div class="wb-content">
      <div class="wb-greeting">
        <svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
        Welcome back, <?php echo e(explode(' ',$auth['name'])[0]); ?>
      </div>
      <div class="wb-sub">Full administrative access to the Okoya Food enterprise management system.</div>
      <div class="wb-meta">
        <span class="wb-tag"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><?php echo e($auth['staff_code']); ?></span>
        <span class="wb-tag"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><?php echo date('l, d M Y'); ?></span>
        <span class="wb-tag"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span id="liveClock">--:--</span> WAT</span>
        <span class="wb-tag"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Admin Session</span>
      </div>
    </div>
  </div>

  <!-- Primary Stats -->
  <div class="stats-grid">
    <div class="stat-card accent-green anim-fade-up d1">
      <div class="sc-top">
        <div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg></div>
      </div>
      <span class="sc-val"><?php echo number_format($todayOrders); ?></span>
      <span class="sc-label">Today's Orders</span>
      <div class="sc-sub"><?php echo number_format($totalOrders); ?> total all time</div>
    </div>
    <div class="stat-card accent-gold anim-fade-up d2">
      <div class="sc-top">
        <div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
      </div>
      <span class="sc-val"><?php echo fmtN($todayRevenue); ?></span>
      <span class="sc-label">Today's Revenue</span>
      <div class="sc-sub"><?php echo fmtN($totalRevenue); ?> total collected</div>
    </div>
    <div class="stat-card accent-blue anim-fade-up d3">
      <div class="sc-top">
        <div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      </div>
      <span class="sc-val"><?php echo number_format($pendingCount); ?></span>
      <span class="sc-label">Pending Orders</span>
      <div class="sc-sub"><?php echo number_format($approvedCount); ?> approved awaiting dispatch</div>
    </div>
    <div class="stat-card accent-red anim-fade-up d4">
      <div class="sc-top">
        <div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
      </div>
      <span class="sc-val"><?php echo fmtN($unpaidAmount); ?></span>
      <span class="sc-label">Outstanding</span>
      <div class="sc-sub"><?php echo number_format($deliveredCnt); ?> delivered successfully</div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="section-head anim-fade-up d5">
    <h2><svg class="icon-svg" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Quick Actions</h2>
  </div>
  <div class="nav-grid">
    <a href="order.php" class="nav-card anim-scale d1">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>
      <div class="nc-title">Create New Order</div>
      <div class="nc-desc">Register a new client order</div>
    </a>
    <a href="order_register.php" class="nav-card anim-scale d2">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <div class="nc-title">Order Register</div>
      <div class="nc-desc">View, modify &amp; delete orders</div>
    </a>
    <a href="ice_block_register.php" class="nav-card anim-scale d3">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <div class="nc-title">Ice Block Production</div>
      <div class="nc-desc">Daily productions &amp; inventory</div>
    </a>
    <a href="staff_directory.php" class="nav-card anim-scale d4">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
      <div class="nc-title">Create Staff</div>
      <div class="nc-desc">Add new company members</div>
    </a>
    <a href="staff_list.php" class="nav-card anim-scale d5">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <div class="nc-title">Staff Directory</div>
      <div class="nc-desc">Employee records &amp; HR</div>
    </a>
    <a href="manage_clients.php" class="nav-card anim-scale d6">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <div class="nc-title">Client Directory</div>
      <div class="nc-desc">Manage client contacts</div>
    </a>
    <a href="manage_products.php" class="nav-card anim-scale d7">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div>
      <div class="nc-title">Product Catalog</div>
      <div class="nc-desc">Products, prices &amp; inventory</div>
    </a>
    <a href="manage_driver.php" class="nav-card anim-scale d8">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      <div class="nc-title">Manage Drivers</div>
      <div class="nc-desc">Create &amp; manage fleet drivers</div>
    </a>
    <a href="logistics.php" class="nav-card anim-scale d1">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
      <div class="nc-title">Logistics</div>
      <div class="nc-desc">Drivers, vehicles &amp; dispatch</div>
    </a>
    <a href="payments.php" class="nav-card anim-scale d2">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
      <div class="nc-title">Salary Payment</div>
      <div class="nc-desc">Track &amp; reconcile payments</div>
    </a>
    <a href="mark_attendance.php" class="nav-card anim-scale d3">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
      <div class="nc-title">Mark Attendance</div>
      <div class="nc-desc">Record daily staff attendance</div>
    </a>
    <a href="reports.php" class="nav-card anim-scale d4">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <div class="nc-title">Sales Reports</div>
      <div class="nc-desc">Revenue analytics &amp; exports</div>
    </a>
    <a href="attendance_report.php" class="nav-card anim-scale d5">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></div>
      <div class="nc-title">Attendance Report</div>
      <div class="nc-desc">Weekly &amp; monthly summaries</div>
    </a>
    <a href="settings.php" class="nav-card anim-scale d6">
      <div class="nc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
      <div class="nc-title">System Settings</div>
      <div class="nc-desc">Company config &amp; preferences</div>
    </a>
  </div>

    </br>
    </br>
    </br>

<footer>
  <div>&copy; 2026 <?php echo e($company['company_name']); ?></div>
  <div>Enterprise HR &amp; Operations System</div>
  <div class="dev-credit">Developed by <span>Lawani Djamiou Alade</span></div>
</footer>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
const sunSVG='<svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
const moonSVG='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>';
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);$('themeBtn').innerHTML=t==='dark'?sunSVG:moonSVG;}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function tick(){const now=new Date();try{$('liveClock').textContent=new Intl.DateTimeFormat('en-NG',{timeZone:'Africa/Lagos',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(now);}catch(e){$('liveClock').textContent=now.toLocaleTimeString();}}
tick();setInterval(tick,1000);
</script>
</body>
</html>
