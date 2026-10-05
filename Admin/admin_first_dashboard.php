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
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
.anim-fade-up{animation:fadeUp .6s var(--ease) both}
.anim-scale{animation:scaleIn .5s var(--ease-spring) both}
.d1{animation-delay:.1s}.d2{animation-delay:.2s}.d3{animation-delay:.3s}.d4{animation-delay:.4s}
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
.hero-section{padding:48px 0 16px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--gold2);background:linear-gradient(135deg,var(--gold-soft),color-mix(in srgb,var(--gold) 12%,var(--surface)));border:1px solid color-mix(in srgb,var(--gold) 25%,transparent);padding:6px 18px;border-radius:99px;margin-bottom:18px}
.hero-badge svg{width:14px;height:14px;stroke:var(--gold2)}
.hero-title{font-family:var(--fd);font-weight:800;font-size:clamp(28px,4vw,44px);line-height:1.12;margin-bottom:12px;letter-spacing:-.5px}
.hero-title em{font-style:normal;color:var(--green)}
.hero-sub{color:var(--ink2);font-weight:500;font-size:16px;max-width:620px;margin:0 auto;line-height:1.6}
.dept-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;padding:36px 0 48px}
.dept-card{position:relative;background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-xl);padding:36px 32px;display:flex;flex-direction:column;gap:18px;transition:all .35s var(--ease);overflow:hidden;cursor:pointer;text-decoration:none;color:var(--ink)}
.dept-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--r-xl) var(--r-xl) 0 0;transition:height .35s var(--ease)}
.dept-card::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--green-glow),transparent);opacity:0;transition:opacity .35s var(--ease)}
.dept-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-xl);border-color:transparent}
.dept-card:hover::before{height:6px}
.dept-card:hover::after{opacity:1}
.dept-card:active{transform:translateY(-2px) scale(.99)}
.dept-card.unavailable{opacity:.65;cursor:not-allowed}
.dept-card.unavailable:hover{transform:none;box-shadow:var(--shadow-sm);border-color:var(--line)}
.dept-card.unavailable::after{display:none}
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
.dc-title{font-family:var(--fd);font-size:22px;font-weight:800;line-height:1.2;letter-spacing:-.3px;position:relative;z-index:1;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.dc-unavailable{display:inline-block;font-size:9px;font-weight:800;color:var(--red);background:var(--red-soft);padding:3px 10px;border-radius:99px;letter-spacing:.4px;text-transform:uppercase;border:1px solid color-mix(in srgb,var(--red) 15%,transparent)}
.dc-desc{font-size:14px;color:var(--ink2);font-weight:500;line-height:1.6;position:relative;z-index:1}
.dc-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto;padding-top:14px;border-top:1px solid var(--line);position:relative;z-index:1}
.dc-stat{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:5px 12px;border-radius:99px;background:var(--surface2);border:1px solid var(--line);transition:all .25s var(--ease)}
.dc-stat svg{width:13px;height:13px}
.dept-card:hover .dc-stat{border-color:color-mix(in srgb,var(--green) 20%,transparent);background:color-mix(in srgb,var(--green) 5%,var(--surface2))}
.dc-arrow{position:absolute;bottom:32px;right:28px;width:40px;height:40px;border-radius:12px;background:var(--surface2);border:1px solid var(--line);display:grid;place-items:center;transition:all .3s var(--ease);color:var(--ink2);z-index:1}
.dc-arrow svg{width:18px;height:18px}
.dept-card:hover .dc-arrow{background:var(--green);color:#fff;border-color:var(--green);transform:translateX(4px)}
.dept-card.unavailable:hover .dc-arrow{background:var(--surface2);color:var(--ink2);border-color:var(--line);transform:none}
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

<div class="init-loader" id="initLoader">
  <div class="il-orbits"><div class="il-orbit il-o1"></div><div class="il-orbit il-o2"></div><div class="il-orbit il-o3"></div></div>
  <div class="il-logo"><img src="logo.ico" alt="Logo"></div>
  <div class="il-bar-wrap"><div class="il-bar"></div></div>
  <div class="il-text">Preparing your workspace…</div>
  <div class="il-company"><?php echo e($company['company_name']); ?></div>
</div>

<div class="page-loader" id="pageLoader">
  <div class="pl-orbits"><div class="pl-orbit pl-o1"></div><div class="pl-orbit pl-o2"></div><div class="pl-orbit pl-o3"></div></div>
  <div class="pl-logo"><img src="logo.ico" alt="Logo"></div>
  <div class="pl-bar-wrap"><div class="pl-bar" id="plBar"></div></div>
  <div class="pl-text" id="plText">Loading…</div>
</div>

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
    <div class="hero-badge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4 8 4v14"/><path d="M9 21v-4h6v4"/></svg>Department Hub</div>
    <h1 class="hero-title">Select Your <em>Department</em></h1>
    <p class="hero-sub">Choose a department below to access its dedicated workspace, tools, and resources.</p>
  </section>

  <div class="dept-grid">
    <a href="General/admin_general_dashboard.php" class="dept-card general anim-scale d1">
      <div class="dc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4 8 4v14"/><path d="M9 21v-4h6v4"/><path d="M9 10h1"/><path d="M14 10h1"/><path d="M9 14h1"/><path d="M14 14h1"/></svg></div>
      <div class="dc-title">General Department</div>
      <div class="dc-desc">Company administration, HR management, staff coordination, order processing, and executive operations.</div>
      <div class="dc-stats">
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Orders</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Staff</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Reports</span>
      </div>
      <div class="dc-arrow"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>
    </a>

    <a href="Construction/admin_construction_dashboard.php" class="dept-card construction anim-scale d2">
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

    <div class="dept-card waste unavailable anim-scale d3">
      <div class="dc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg></div>
      <div class="dc-title">Waste Department <span class="dc-unavailable">Coming Soon</span></div>
      <div class="dc-desc">Waste collection, recycling operations, disposal logistics, environmental compliance, and sanitation services.</div>
      <div class="dc-stats">
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>Recycling</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Collections</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Tonnage</span>
      </div>
      <div class="dc-arrow"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div>
    </div>

    <a href="Maintenance/admin_maintenance_dashboard.php" class="dept-card maintenance anim-scale d4">
      <div class="dc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
      <div class="dc-title">Maintenance Department</div>
      <div class="dc-desc">Equipment servicing, facility repairs, preventive maintenance schedules, vehicle fleet management, and safety inspections.</div>
      <div class="dc-stats">
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>Repairs</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Fleet</span>
        <span class="dc-stat"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Equipment</span>
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
</div>

<?php if (!empty($_SESSION['auth'])): ?>
<div id="chatWidget">
  <button class="cfab" id="chatFab" title="Messages">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span class="cbadge" id="chatBadge">0</span>
  </button>
  <div class="cpanel" id="chatPanel">
    <div class="cpanel-head">
      <div class="cpanel-left">
        <button class="cback" id="chatBack"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg></button>
        <div class="cpanel-info"><strong id="chatTitle">Messages</strong><small id="chatSub">Select a conversation</small></div>
      </div>
      <button class="cclose" id="chatClose"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="cusers" id="chatUsers"></div>
    <div class="cmsgs" id="chatMsgs"></div>
    <div class="cinput-wrap" id="chatInputWrap">
      <input type="file" id="chatFile" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt">
      <button class="cattach" id="chatAttachBtn" title="Attach file"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
      <div class="cpreview" id="chatPreview"></div>
      <input type="text" id="chatInput" placeholder="Type a message..." maxlength="2000" autocomplete="off">
      <button class="csend" id="chatSend"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
    </div>
  </div>
</div>
<style>
#chatWidget{position:fixed;bottom:24px;right:24px;z-index:9999;font-family:'Manrope',sans-serif}
.cfab{width:58px;height:58px;border-radius:50%;background:linear-gradient(135deg,#059669 0%,#047857 100%);color:#fff;border:none;display:grid;place-items:center;cursor:pointer;box-shadow:0 6px 24px rgba(5,150,105,.4),0 0 0 0 rgba(5,150,105,.3);transition:all .3s cubic-bezier(.34,1.56,.64,1);position:relative;animation:cfabPulse 3s ease-in-out infinite}
@keyframes cfabPulse{0%,100%{box-shadow:0 6px 24px rgba(5,150,105,.4),0 0 0 0 rgba(5,150,105,.3)}50%{box-shadow:0 6px 24px rgba(5,150,105,.4),0 0 0 12px rgba(5,150,105,0)}}
.cfab:hover{transform:scale(1.12) rotate(-5deg);box-shadow:0 8px 32px rgba(5,150,105,.5)}
.cbadge{position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:10px;font-weight:800;min-width:22px;height:22px;border-radius:99px;display:none;align-items:center;justify-content:center;padding:0 6px;border:2.5px solid #fff;font-family:'Sora',sans-serif}
.cpanel{position:absolute;bottom:72px;right:0;width:400px;height:580px;background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 25px 50px rgba(0,0,0,.15),0 0 0 1px rgba(0,0,0,.03);overflow:hidden;display:none;flex-direction:column;animation:cpanelIn .35s cubic-bezier(.34,1.56,.64,1)}
@keyframes cpanelIn{from{opacity:0;transform:translateY(24px) scale(.94)}to{opacity:1;transform:translateY(0) scale(1)}}
.cpanel-head{padding:18px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(180deg,#fff 0%,#fafbfc 100%);flex-shrink:0}
.cpanel-left{display:flex;align-items:center;gap:12px}
.cback{width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;display:none;place-items:center;cursor:pointer;color:#64748b;transition:all .2s}
.cback:hover{background:#059669;color:#fff;border-color:#059669;transform:translateX(-2px)}
.cpanel-info strong{font-family:'Sora',sans-serif;font-size:15px;font-weight:800;color:#0f172a;display:block;line-height:1.2;letter-spacing:-.2px}
.cpanel-info small{font-size:11px;color:#94a3b8;font-weight:600}
.cclose{width:34px;height:34px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;cursor:pointer;color:#64748b;transition:all .2s}
.cclose:hover{background:#fef2f2;color:#ef4444;border-color:#fecaca}
.cusers{flex:1;overflow-y:auto;padding:10px}
.cuser{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;cursor:pointer;transition:all .2s;border:1.5px solid transparent;margin-bottom:4px}
.cuser:hover{background:#f8fafc;border-color:#e2e8f0}
.cuser.active{background:#ecfdf5;border-color:#059669}
.cuser-av{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#059669,#047857);color:#fff;display:grid;place-items:center;font-family:'Sora',sans-serif;font-size:15px;font-weight:800;flex-shrink:0;position:relative;overflow:hidden}
.cuser-av img{width:100%;height:100%;object-fit:cover}
.cuser-av .sdot{position:absolute;bottom:2px;right:2px;width:11px;height:11px;border-radius:50%;border:2.5px solid #fff}
.sdot.on{background:#22c55e}.sdot.off{background:#cbd5e1}
.cuser-info{flex:1;min-width:0}
.cuser-name{font-size:14px;font-weight:700;color:#0f172a;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cuser-meta{font-size:11px;color:#94a3b8;font-weight:600;margin-top:3px}
.cuser-unread{background:#ef4444;color:#fff;font-size:10px;font-weight:800;min-width:22px;height:22px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 6px;flex-shrink:0;font-family:'Sora',sans-serif}
.cmsgs{flex:1;overflow-y:auto;padding:20px;display:none;flex-direction:column;gap:10px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%)}
.cmsg{max-width:80%;padding:12px 16px;border-radius:18px;font-size:13.5px;font-weight:600;line-height:1.55;word-wrap:break-word;animation:cmsgIn .3s ease;position:relative}
@keyframes cmsgIn{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
.cmsg.mine{align-self:flex-end;background:linear-gradient(135deg,#059669,#047857);color:#fff;border-bottom-right-radius:6px;box-shadow:0 2px 8px rgba(5,150,105,.2)}
.cmsg.theirs{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-bottom-left-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.cmsg-time{font-size:9.5px;opacity:.65;margin-top:5px;font-weight:700;text-align:right}
.cmsg-img{max-width:220px;border-radius:12px;margin-bottom:6px;cursor:pointer;transition:transform .2s}
.cmsg-img:hover{transform:scale(1.02)}
.cmsg-doc{display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(0,0,0,.06);border-radius:10px;margin-bottom:6px;text-decoration:none;color:inherit;transition:background .2s}
.cmsg.mine .cmsg-doc{background:rgba(255,255,255,.18)}
.cmsg-doc:hover{background:rgba(0,0,0,.1)}
.cmsg-doc svg{width:24px;height:24px;flex-shrink:0}
.cmsg-doc-info{flex:1;min-width:0}
.cmsg-doc-name{font-size:12px;font-weight:700;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cempty{text-align:center;padding:50px 20px;color:#94a3b8;font-size:13px;font-weight:600}
.cempty svg{width:48px;height:48px;stroke:#cbd5e1;margin:0 auto 12px;display:block}
.cinput-wrap{padding:12px 14px;border-top:1px solid #f1f5f9;display:none;align-items:flex-end;gap:8px;background:#fff;flex-shrink:0}
.cattach{width:40px;height:40px;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;cursor:pointer;color:#64748b;transition:all .2s;flex-shrink:0}
.cattach:hover{background:#ecfdf5;color:#059669;border-color:#059669}
.cpreview{display:none;align-items:center;gap:8px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:8px;font-size:11px;font-weight:700;color:#166534;width:100%}
.cpreview svg{width:16px;height:16px;flex-shrink:0}
.cpreview span{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cpreview button{background:none;border:none;color:#dc2626;cursor:pointer;font-weight:800;font-size:14px;padding:0 4px}
.cinput-wrap input[type="text"]{flex:1;height:42px;border:1.5px solid #e2e8f0;border-radius:14px;padding:0 16px;font-size:13.5px;font-weight:600;font-family:'Manrope',sans-serif;outline:none;transition:all .2s;background:#f8fafc;min-width:0}
.cinput-wrap input[type="text"]:focus{border-color:#059669;background:#fff;box-shadow:0 0 0 3px rgba(5,150,105,.1)}
.csend{width:42px;height:42px;border-radius:14px;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;display:grid;place-items:center;cursor:pointer;transition:all .2s;flex-shrink:0}
.csend:hover{transform:scale(1.08);box-shadow:0 4px 14px rgba(5,150,105,.35)}
.csend:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none}
@media(max-width:480px){#chatWidget{bottom:16px;right:16px}.cpanel{width:calc(100vw - 32px);height:70vh;bottom:70px;border-radius:20px}.cfab{width:52px;height:52px}}
</style>
<script>
(function(){
  var API='/inbox_data.php';
  var cur=null,poll=null,btimer=null,isOpen=false,pendingFile=null;
  var g=function(id){return document.getElementById(id);};
  var fab=g('chatFab'),panel=g('chatPanel'),bdg=g('chatBadge'),uList=g('chatUsers'),mArea=g('chatMsgs'),iWrap=g('chatInputWrap'),inp=g('chatInput'),sBtn=g('chatSend'),bBtn=g('chatBack'),cBtn=g('chatClose'),ttl=g('chatTitle'),sub=g('chatSub'),fInp=g('chatFile'),aBtn=g('chatAttachBtn'),pvw=g('chatPreview');
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function fmtT(ts){if(!ts)return'';var d=new Date(ts),n=new Date(),df=n-d;if(df<60000)return'Just now';if(df<3600000)return Math.floor(df/60000)+'m';if(df<86400000)return d.toLocaleTimeString('en-NG',{hour:'2-digit',minute:'2-digit',hour12:true});return d.toLocaleDateString('en-NG',{day:'numeric',month:'short'});}
  function fmtSz(b){if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB';}
  fab.onclick=function(){isOpen=!isOpen;panel.style.display=isOpen?'flex':'none';if(isOpen)loadU();};
  cBtn.onclick=function(){isOpen=false;panel.style.display='none';};
  function loadU(){
    fetch(API+'?chat=users',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.users||!d.users.length){uList.innerHTML='<div class="cempty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>No users available</div>';return;}
      var h='';for(var i=0;i<d.users.length;i++){var u=d.users[i];var ini=u.full_name.split(' ').map(function(w){return w[0];}).join('').substring(0,2).toUpperCase();var ac=cur&&cur.id==u.id?' active':'';var sc=u.is_online?'on':'off';var ur=u.unread_count>0?'<span class="cuser-unread">'+u.unread_count+'</span>':'';var av=u.profile_photo?'<img src="'+esc(u.profile_photo)+'" alt="">':esc(ini);var mt=esc(u.staff_code||'');if(u.last_message_time)mt+=' \u00B7 '+fmtT(u.last_message_time);h+='<div class="cuser'+ac+'" data-id="'+u.id+'" data-name="'+esc(u.full_name)+'"><div class="cuser-av">'+av+'<span class="sdot '+sc+'"></span></div><div class="cuser-info"><span class="cuser-name">'+esc(u.full_name)+'</span><span class="cuser-meta">'+mt+'</span></div>'+ur+'</div>';}
      uList.innerHTML=h;var items=uList.querySelectorAll('.cuser');for(var j=0;j<items.length;j++){items[j].onclick=function(){openC(parseInt(this.dataset.id),this.dataset.name);};}
    }).catch(function(e){console.error(e);uList.innerHTML='<div class="cempty">Failed to load</div>';});
  }
  function openC(uid,name){cur={id:uid,name:name};uList.style.display='none';mArea.style.display='flex';iWrap.style.display='flex';bBtn.style.display='grid';ttl.textContent=name;sub.textContent='Online';inp.value='';clrF();inp.focus();loadM();mkR();stP();}
  bBtn.onclick=function(){cur=null;spP();mArea.style.display='none';iWrap.style.display='none';uList.style.display='block';bBtn.style.display='none';ttl.textContent='Messages';sub.textContent='Select a conversation';loadU();};
  function loadM(){
    if(!cur)return;fetch(API+'?chat=messages&uid='+cur.id,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.messages)return;var h='';if(!d.messages.length){h='<div class="cempty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>No messages yet</div>';}
      else{for(var i=0;i<d.messages.length;i++){var m=d.messages[i];var mine=parseInt(m.sender_id)===d.my_id;var cls=mine?'mine':'theirs';var att='';if(m.attachment_path){if(m.attachment_type==='image'){att='<img class="cmsg-img" src="'+esc(m.attachment_path)+'" alt="" onclick="window.open(this.src)">';}else{att='<a class="cmsg-doc" href="'+esc(m.attachment_path)+'" target="_blank" download="'+esc(m.attachment_name||'file')+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><div class="cmsg-doc-info"><span class="cmsg-doc-name">'+esc(m.attachment_name||'Document')+'</span></div></a>';}}var txt=m.message?esc(m.message):'';h+='<div class="cmsg '+cls+'">'+att+(txt?'<div>'+txt+'</div>':'')+'<div class="cmsg-time">'+fmtT(m.created_at)+'</div></div>';}}
      mArea.innerHTML=h;mArea.scrollTop=mArea.scrollHeight;}).catch(function(e){console.error(e);});
  }
  function sendM(){var msg=inp.value.trim();if((!msg&&!pendingFile)||!cur)return;inp.value='';sBtn.disabled=true;var f=new FormData();f.append('to',cur.id);f.append('msg',msg);if(pendingFile)f.append('file',pendingFile);clrF();fetch(API+'?chat=send',{method:'POST',body:f,credentials:'same-origin'}).then(function(){loadM();}).catch(function(e){console.error(e);});sBtn.disabled=false;inp.focus();}
  sBtn.onclick=sendM;inp.onkeydown=function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendM();}};
  aBtn.onclick=function(){fInp.click();};
  fInp.onchange=function(){if(!fInp.files.length)return;var file=fInp.files[0];if(file.size>5242880){alert('File too large. Max 5MB.');fInp.value='';return;}pendingFile=file;pvw.style.display='flex';pvw.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg><span>'+esc(file.name)+' ('+fmtSz(file.size)+')</span><button onclick="window._clrF()">\u00D7</button>';fInp.value='';};
  window._clrF=clrF;function clrF(){pendingFile=null;pvw.style.display='none';pvw.innerHTML='';}
  function mkR(){if(!cur)return;var f=new FormData();f.append('from',cur.id);fetch(API+'?chat=read',{method:'POST',body:f,credentials:'same-origin'}).catch(function(){});}
  function stP(){spP();poll=setInterval(loadM,2000);}function spP(){if(poll){clearInterval(poll);poll=null;}}
  function updB(){fetch(API+'?chat=count',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){if(d.count>0){bdg.textContent=d.count>99?'99+':d.count;bdg.style.display='flex';}else{bdg.style.display='none';}}).catch(function(){});}
  btimer=setInterval(updB,5000);updB();
})();
</script>
<?php endif; ?>

<script>
var $=function(id){return document.getElementById(id);};
var store={get:function(k,d){try{var v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
var sunSVG='<svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
var moonSVG='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>';
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);$('themeBtn').innerHTML=t==='dark'?sunSVG:moonSVG;}
$('themeBtn').addEventListener('click',function(){setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark');});
setTheme(store.get('okoya_theme','light'));
window.addEventListener('load',function(){setTimeout(function(){$('initLoader').classList.add('hidden');var app=$('app');if(app)app.classList.add('fade-in');},1800);});
(function(){
  document.addEventListener('click',function(e){
    var link=e.target.closest('a[href]');if(!link)return;
    if(link.closest('.unavailable')){e.preventDefault();return;}
    var href=link.getAttribute('href');
    if(!href||href==='#'||href.startsWith('#')||href.startsWith('javascript:')||link.hasAttribute('download')||link.getAttribute('target')==='_blank')return;
    if(href.startsWith('http://')||href.startsWith('https://')){try{if(new URL(href).origin!==window.location.origin)return;}catch(err){return;}}
    if(e.ctrlKey||e.metaKey||e.shiftKey)return;
    e.preventDefault();
    var loader=$('pageLoader'),bar=$('plBar'),text=$('plText'),app=$('app');
    var dest=href.split('/').pop().replace(/\.php$/,'').replace(/[_-]/g,' ');
    text.textContent='Navigating to '+dest+'…';
    bar.classList.remove('animating');void bar.offsetWidth;bar.classList.add('animating');
    loader.classList.add('active');if(app)app.classList.add('fade-out');
    setTimeout(function(){window.location.href=href;},600);
  });
  window.addEventListener('pageshow',function(){var l=$('pageLoader'),a=$('app');if(l)l.classList.remove('active');if(a)a.classList.remove('fade-out');});
  window.addEventListener('popstate',function(){var l=$('pageLoader'),a=$('app');if(l)l.classList.remove('active');if(a)a.classList.remove('fade-out');});
})();
</script>
</body>
</html>