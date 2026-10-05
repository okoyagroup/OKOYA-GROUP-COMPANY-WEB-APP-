<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += [
    'company_name'=>'OKOYA FOOD COMPANY LIMITED',
    'company_dept'=>'Staff Management & HR Department',
    'company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria',
    'company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'
];

try {
    $totalStaff = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='Active'")->fetchColumn();
    $todayOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_date=CURDATE()")->fetchColumn();
    $pendingTasks = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn();
    $totalRevenue = (float)$pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments WHERE status='Paid'")->fetchColumn();
} catch(Exception $e) { $totalStaff=$todayOrders=$pendingTasks=$totalRevenue=0; }

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,0);}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Hub | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏢</text></svg>">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-600-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-700-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/sora@latest/latin-800-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-400-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-500-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-600-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/manrope@latest/latin-700-normal.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/fontsource/fonts/jetbrains-mono@latest/latin-500-normal.css" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;--surface3:#e8edf2;
  --ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;
  --line:#e2e8f0;--line2:#cbd5e1;
  --green:#059669;--green2:#047857;--green-soft:#ecfdf5;--green-glow:rgba(5,150,105,.12);
  --gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--gold-glow:rgba(217,119,6,.1);
  --red:#dc2626;--red-soft:#fef2f2;--red-glow:rgba(220,38,38,.08);
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
  --green:#34d399;--green2:#10b981;--green-soft:#064e3b;--green-glow:rgba(52,211,153,.1);
  --gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--gold-glow:rgba(251,191,36,.08);
  --red:#f87171;--red-soft:#450a0a;--red-glow:rgba(248,113,113,.08);
  --blue:#60a5fa;--blue-soft:#1e3a5f;--blue-glow:rgba(96,165,250,.08);
  --purple:#a78bfa;--purple-soft:#2e1065;
  --shadow-xs:0 1px 2px rgba(0,0,0,.2);
  --shadow-sm:0 1px 3px rgba(0,0,0,.25),0 1px 2px rgba(0,0,0,.2);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.3),0 2px 4px -2px rgba(0,0,0,.25);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.35),0 4px 6px -4px rgba(0,0,0,.25);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.4),0 8px 10px -6px rgba(0,0,0,.3);
}
html,body{min-height:100%}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.6;display:flex;flex-direction:column;transition:background .4s var(--ease),color .4s var(--ease);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(ellipse 800px 500px at 85% -10%,var(--green-glow),transparent 70%),radial-gradient(ellipse 600px 400px at -10% 40%,var(--gold-glow),transparent 70%)}
.container{max-width:1280px;margin:0 auto;padding:0 24px;width:100%}
svg{flex:none}button{font-family:inherit;cursor:pointer;border:none;background:none}a{text-decoration:none;color:inherit}
::selection{background:color-mix(in srgb,var(--green) 25%,transparent)}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ===== INITIAL PAGE LOAD ANIMATION ===== */
.init-loader{position:fixed;inset:0;z-index:99999;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .6s var(--ease),visibility .6s var(--ease)}
.init-loader.hidden{opacity:0;visibility:hidden;pointer-events:none}
.il-orbits{position:absolute;width:220px;height:220px;pointer-events:none}
.il-orbit{position:absolute;inset:0;border-radius:50%;border:2px solid transparent}
.il-o1{border-top-color:var(--green);border-right-color:var(--green);animation:ilSpin 2.4s linear infinite}
.il-o2{inset:18px;border-bottom-color:var(--gold);border-left-color:var(--gold);animation:ilSpin 3.2s linear infinite reverse}
.il-o3{inset:36px;border-top-color:var(--blue);border-right-color:var(--blue);animation:ilSpin 1.8s linear infinite;opacity:.5}
@keyframes ilSpin{to{transform:rotate(360deg)}}
.il-logo{width:88px;height:88px;border-radius:22px;background:var(--surface);border:1px solid var(--line);display:grid;place-items:center;overflow:hidden;margin-bottom:28px;animation:ilPulse 2s ease-in-out infinite;box-shadow:var(--shadow-lg);position:relative;z-index:1}
.il-logo img{width:72px;height:72px;object-fit:contain}
@keyframes ilPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 var(--green-glow)}50%{transform:scale(1.05);box-shadow:0 0 0 18px transparent}}
.il-bar-wrap{width:240px;height:3px;background:var(--line);border-radius:99px;overflow:hidden;margin-top:10px}
.il-bar{height:100%;width:0;background:linear-gradient(90deg,var(--green),var(--gold));border-radius:99px;animation:ilBar 1.8s ease-in-out forwards}
@keyframes ilBar{0%{width:0}35%{width:55%}70%{width:82%}100%{width:100%}}
.il-text{font-family:var(--fd);font-size:13px;font-weight:700;color:var(--ink2);margin-top:16px;letter-spacing:.5px;animation:ilTextFade 1.4s ease-in-out infinite alternate}
@keyframes ilTextFade{0%{opacity:.35}100%{opacity:1}}
.il-company{font-family:var(--fd);font-size:11px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--ink3);opacity:.6;margin-top:8px}

/* ===== PAGE TRANSITION LOADER ===== */
.page-loader{position:fixed;inset:0;z-index:99998;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .3s var(--ease),visibility .3s var(--ease)}
.page-loader.active{opacity:1;visibility:visible;pointer-events:all}
.pl-orbits{position:absolute;width:160px;height:160px;pointer-events:none}
.pl-orbit{position:absolute;inset:0;border-radius:50%;border:1.5px solid transparent}
.pl-o1{border-top-color:var(--green);border-right-color:var(--green);animation:ilSpin 2s linear infinite}
.pl-o2{inset:12px;border-bottom-color:var(--gold);border-left-color:var(--gold);animation:ilSpin 2.8s linear infinite reverse}
.pl-o3{inset:24px;border-top-color:var(--blue);border-right-color:var(--blue);animation:ilSpin 1.5s linear infinite;opacity:.5}
.pl-logo{width:64px;height:64px;border-radius:16px;background:var(--surface);border:1px solid var(--line);display:grid;place-items:center;overflow:hidden;margin-bottom:20px;animation:ilPulse 1.8s ease-in-out infinite;box-shadow:var(--shadow-md)}
.pl-logo img{width:52px;height:52px;object-fit:contain}
.pl-bar-wrap{width:180px;height:3px;background:var(--line);border-radius:99px;overflow:hidden}
.pl-bar{height:100%;width:0;background:linear-gradient(90deg,var(--green),var(--gold));border-radius:99px}
.pl-bar.animating{animation:ilBar 1.6s ease-in-out forwards}
.pl-text{font-family:var(--fd);font-size:12px;font-weight:700;color:var(--ink2);margin-top:12px;letter-spacing:.5px;animation:ilTextFade 1.2s ease-in-out infinite alternate}

#app{transition:opacity .4s var(--ease)}
#app.fade-out{opacity:0}
#app.fade-in{animation:appFadeIn .5s var(--ease) forwards}
@keyframes appFadeIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

/* ===== ENTRANCE ANIMATIONS ===== */
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
.anim-fade-up{animation:fadeUp .6s var(--ease) both}
.anim-scale{animation:scaleIn .5s var(--ease-spring) both}
.d1{animation-delay:.1s}.d2{animation-delay:.2s}.d3{animation-delay:.3s}.d4{animation-delay:.4s}

/* ===== TOPBAR ===== */
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 82%,transparent);backdrop-filter:blur(20px) saturate(1.4);border-bottom:1px solid var(--line);transition:background .4s var(--ease)}
.topbar .container{display:flex;align-items:center;gap:14px;padding-top:12px;padding-bottom:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:12px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line);transition:transform .3s var(--ease-spring)}
.logo-chip:hover{transform:scale(1.08) rotate(-3deg)}
.logo-chip img{width:36px;height:36px;object-fit:contain}
.brand-text strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.25;letter-spacing:-.2px}
.brand-text small{color:var(--ink2);font-size:10px;font-weight:600;letter-spacing:.2px}
.user-pill{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--line);border-radius:99px;padding:5px 14px 5px 5px;font-size:12px;font-weight:700;transition:all .25s var(--ease)}
.user-pill:hover{border-color:var(--green);box-shadow:0 0 0 3px var(--green-glow)}
.user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:11px;font-weight:800}
.theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:all .3s var(--ease)}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(180deg);box-shadow:0 0 0 3px var(--gold-glow)}
.theme-btn svg{width:16px;height:16px}
.logout-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:var(--red);padding:8px 16px;border-radius:10px;border:1px solid var(--red-soft);background:var(--red-soft);transition:all .25s var(--ease)}
.logout-link:hover{background:var(--red);color:#fff;border-color:var(--red);transform:translateY(-1px);box-shadow:0 4px 12px var(--red-glow)}
.logout-link svg{width:14px;height:14px}

/* ===== HERO ===== */
.hero-section{padding:48px 0 16px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--gold2);background:linear-gradient(135deg,var(--gold-soft),color-mix(in srgb,var(--gold) 12%,var(--surface)));border:1px solid color-mix(in srgb,var(--gold) 25%,transparent);padding:6px 18px;border-radius:99px;margin-bottom:18px}
.hero-badge svg{width:14px;height:14px;stroke:var(--gold2)}
.hero-title{font-family:var(--fd);font-weight:800;font-size:clamp(28px,4vw,44px);line-height:1.12;margin-bottom:12px;letter-spacing:-.5px}
.hero-title em{font-style:normal;color:var(--green)}
.hero-sub{color:var(--ink2);font-weight:500;font-size:16px;max-width:620px;margin:0 auto;line-height:1.6}

/* ===== DEPARTMENT GRID ===== */
.dept-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;padding:36px 0 48px}
.dept-card{position:relative;background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-xl);padding:36px 32px;display:flex;flex-direction:column;gap:18px;transition:all .35s var(--ease);overflow:hidden;cursor:pointer;text-decoration:none;color:var(--ink)}
.dept-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--r-xl) var(--r-xl) 0 0;transition:height .35s var(--ease)}
.dept-card::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--green-glow),transparent);opacity:0;transition:opacity .35s var(--ease)}
.dept-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-xl);border-color:transparent}
.dept-card:hover::before{height:6px}
.dept-card:hover::after{opacity:1}
.dept-card:active{transform:translateY(-2px) scale(.99)}
.dept-card.general::before{background:linear-gradient(90deg,var(--green),var(--green2))}
.dept-card.construction::before{background:linear-gradient(90deg,var(--gold),var(--gold2))}
.dept-card.waste::before{background:linear-gradient(90deg,var(--blue),#1d4ed8)}
.dept-card.maintenance::before{background:linear-gradient(90deg,var(--red),#b91c1c)}
.dc-icon{width:68px;height:68px;border-radius:18px;display:grid;place-items:center;flex:none;transition:all .35s var(--ease-spring);position:relative;z-index:1}
.dept-card:hover .dc-icon{transform:scale(1.12) rotate(-5deg)}
.dc-icon svg{width:32px;height:32px}
.general .dc-icon{background:var(--green-soft);color:var(--green)}
.construction .dc-icon{background:var(--gold-soft);color:var(--gold2)}
.waste .dc-icon{background:var(--blue-soft);color:var(--blue)}
.maintenance .dc-icon{background:var(--red-soft);color:var(--red)}
.dc-title{font-family:var(--fd);font-size:22px;font-weight:800;line-height:1.2;letter-spacing:-.3px;position:relative;z-index:1}
.dc-desc{font-size:14px;color:var(--ink2);font-weight:500;line-height:1.6;position:relative;z-index:1}
.dc-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto;padding-top:14px;border-top:1px solid var(--line);position:relative;z-index:1}
.dc-stat{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:5px 12px;border-radius:99px;background:var(--surface2);border:1px solid var(--line);transition:all .25s var(--ease)}
.dc-stat svg{width:13px;height:13px}
.dept-card:hover .dc-stat{border-color:color-mix(in srgb,var(--green) 20%,transparent);background:color-mix(in srgb,var(--green) 5%,var(--surface2))}
.dc-arrow{position:absolute;bottom:32px;right:28px;width:40px;height:40px;border-radius:12px;background:var(--surface2);border:1px solid var(--line);display:grid;place-items:center;transition:all .3s var(--ease);color:var(--ink2);z-index:1}
.dc-arrow svg{width:18px;height:18px}
.dept-card:hover .dc-arrow{background:var(--green);color:#fff;border-color:var(--green);transform:translateX(4px)}

/* ===== FOOTER ===== */
footer{margin-top:auto;border-top:1px solid var(--line);padding:28px 24px 36px;text-align:center;color:var(--ink3);font-size:11px;font-weight:600}
footer div+div{margin-top:4px}
footer .dev-credit{font-weight:700;color:var(--ink2);margin-top:8px;font-size:12px}
footer .dev-credit span{color:var(--green)}

@media(max-width:900px){.dept-grid{grid-template-columns:1fr}}
@media(max-width:600px){
  .hero-section{padding:32px 0 10px}
  .hero-title{font-size:28px}
  .dept-card{padding:28px 24px}
  .dc-title{font-size:20px}
  .topbar .brand-text strong{font-size:13px}
  .il-logo{width:72px;height:72px}
  .il-logo img{width:58px;height:58px}
  .il-orbits{width:180px;height:180px}
  .container{padding:0 16px}
}
</style>
</head>
<body>

<!-- INITIAL PAGE LOAD ANIMATION -->
<div class="init-loader" id="initLoader">
  <div class="il-orbits">
    <div class="il-orbit il-o1"></div>
    <div class="il-orbit il-o2"></div>
    <div class="il-orbit il-o3"></div>
  </div>
  <div class="il-logo"><img src="logo.ico" alt="Logo"></div>
  <div class="il-bar-wrap"><div class="il-bar"></div></div>
  <div class="il-text">Preparing your workspace…</div>
  <div class="il-company"><?php echo e($company['company_name']); ?></div>
</div>

<!-- PAGE TRANSITION LOADER -->
<div class="page-loader" id="pageLoader">
  <div class="pl-orbits">
    <div class="pl-orbit pl-o1"></div>
    <div class="pl-orbit pl-o2"></div>
    <div class="pl-orbit pl-o3"></div>
  </div>
  <div class="pl-logo"><img src="logo.ico" alt="Logo"></div>
  <div class="pl-bar-wrap"><div class="pl-bar" id="plBar"></div></div>
  <div class="pl-text" id="plText">Loading…</div>
</div>

<!-- MAIN APP -->
<div id="app">

<header class="topbar">
  <div class="container">
    <div class="brand">
      <div class="logo-chip"><img src="logo.ico" alt="Logo"></div>
      <div class="brand-text">
        <strong><?php echo e($company['company_name']); ?></strong>
        <small><?php echo e($company['company_dept']); ?></small>
      </div>
    </div>
    <div class="user-pill">
      <div class="user-avatar"><?php echo strtoupper(substr($auth['name'],0,1)); ?></div>
      <?php echo e(explode(' ',$auth['name'])[0]); ?>
    </div>
    <button class="theme-btn" id="themeBtn" title="Toggle theme" aria-label="Toggle dark mode">
      <svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>
    </button>
    <a href="../index.php?logout=1" class="logout-link">
      <svg class="icon-svg" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout
    </a>
  </div>
</header>

<div class="container">
  <section class="hero-section anim-fade-up">
    <div class="hero-badge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg>Construction Hub</div>
    <h1 class="hero-title">Construction <em>Department</em></h1>
    <p class="hero-sub">Access construction projects, site management, material procurement, and worker coordination from a unified platform.</p>
  </section>

  <!-- Department Cards -->
  <div class="dept-grid">

    <!-- CONSTRUCTION DEPARTMENT -->
    <a href="Construction/admin_construction_dashboard.php" class="dept-card construction anim-scale d1">
      <div class="dc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg></div>
      <div class="dc-title">Construction Department</div>
      <div class="dc-desc">Building projects, site management, material procurement, contractor coordination, and project timelines.</div>
      <div class="dc-stats">
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg>Projects</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>Materials</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Workers</span>
      </div>
      <div class="dc-arrow"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>
    </a>

  </div>
</div>

<footer>
  <div>&copy; 2026 <?php echo e($company['company_name']); ?></div>
  <div>Enterprise HR &amp; Operations System</div>
  <div class="dev-credit">Developed by <span>Lawani Djamiou Alade</span></div>
</footer>

</div><!-- /#app -->

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};

/* ===== THEME ===== */
const sunSVG='<svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
const moonSVG='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>';
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);$('themeBtn').innerHTML=t==='dark'?sunSVG:moonSVG;}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

/* ===== INITIAL LOAD ANIMATION ===== */
window.addEventListener('load', function(){
  setTimeout(function(){
    $('initLoader').classList.add('hidden');
    var app=$('app');
    if(app) app.classList.add('fade-in');
  }, 1800);
});

/* ===== PAGE TRANSITION SYSTEM ===== */
(function(){
  document.addEventListener('click', function(e){
    var link = e.target.closest('a[href]');
    if(!link) return;
    var href = link.getAttribute('href');
    if(!href || href==='#' || href.startsWith('#') || href.startsWith('javascript:') || link.hasAttribute('download') || link.getAttribute('target')==='_blank') return;
    if(href.startsWith('http://') || href.startsWith('https://')){
      try { if(new URL(href).origin !== window.location.origin) return; } catch(err){ return; }
    }
    if(e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();

    var loader = $('pageLoader');
    var bar = $('plBar');
    var text = $('plText');
    var app = $('app');

    var dest = href.split('/').pop().replace(/\.php$/,'').replace(/[_-]/g,' ');
    text.textContent = 'Navigating to ' + dest + '…';

    bar.classList.remove('animating');
    void bar.offsetWidth;
    bar.classList.add('animating');
    loader.classList.add('active');

    if(app) app.classList.add('fade-out');

    setTimeout(function(){
      window.location.href = href;
    }, 600);
  });

  window.addEventListener('pageshow', function(){
    var loader = $('pageLoader');
    var app = $('app');
    if(loader) loader.classList.remove('active');
    if(app) app.classList.remove('fade-out');
  });

  window.addEventListener('popstate', function(){
    var loader = $('pageLoader');
    var app = $('app');
    if(loader) loader.classList.remove('active');
    if(app) app.classList.remove('fade-out');
  });
})();
</script>
</body>
</html>