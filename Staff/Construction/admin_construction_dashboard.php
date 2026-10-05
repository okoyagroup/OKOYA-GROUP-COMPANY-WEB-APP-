<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA GROUP OF COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtN($n): string { return '₦'.number_format((float)$n, 2); }
function fmtCompact($n): string {
    $n=(float)$n;
    if($n>=1000000) return '₦'.number_format($n/1000000,1).'M';
    if($n>=1000) return '₦'.number_format($n/1000,1).'K';
    return '₦'.number_format($n,0);
}

/* ===== AUTO-CREATE CONSTRUCTION TABLES ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_projects (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      project_code VARCHAR(30) NOT NULL UNIQUE,
      name VARCHAR(150) NOT NULL,
      site VARCHAR(120) NULL,
      manager VARCHAR(120) NULL,
      budget DECIMAL(16,2) DEFAULT 0,
      spent DECIMAL(16,2) DEFAULT 0,
      progress INT DEFAULT 0,
      status ENUM('Planning','In Progress','On Hold','Completed','Cancelled') DEFAULT 'Planning',
      start_date DATE NULL,
      end_date DATE NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_sites (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      location VARCHAR(150) NULL,
      status ENUM('Active','Inactive') DEFAULT 'Active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_materials (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      quantity DECIMAL(12,2) DEFAULT 0,
      unit VARCHAR(20) DEFAULT 'units',
      status ENUM('In Stock','Low Stock','Out of Stock') DEFAULT 'In Stock',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_equipment (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      status ENUM('Available','In Use','Maintenance') DEFAULT 'Available',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS construction_workers (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      trade VARCHAR(80) NULL,
      status ENUM('On Site','Off Duty') DEFAULT 'On Site',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $ignore) {}

/* ===== STATS ===== */
$activeProjects=$inProgress=$completed=$totalBudget=$totalSpent=0; $sites=$workersOnSite=$equipmentAvail=$lowStock=0;
try{
    $activeProjects=(int)$pdo->query("SELECT COUNT(*) FROM construction_projects WHERE status IN ('Planning','In Progress')")->fetchColumn();
    $inProgress=(int)$pdo->query("SELECT COUNT(*) FROM construction_projects WHERE status='In Progress'")->fetchColumn();
    $completed=(int)$pdo->query("SELECT COUNT(*) FROM construction_projects WHERE status='Completed'")->fetchColumn();
    $totalBudget=(float)$pdo->query("SELECT IFNULL(SUM(budget),0) FROM construction_projects")->fetchColumn();
    $totalSpent=(float)$pdo->query("SELECT IFNULL(SUM(spent),0) FROM construction_projects")->fetchColumn();
    $sites=(int)$pdo->query("SELECT COUNT(*) FROM construction_sites WHERE status='Active'")->fetchColumn();
    $workersOnSite=(int)$pdo->query("SELECT COUNT(*) FROM construction_workers WHERE status='On Site'")->fetchColumn();
    $equipmentAvail=(int)$pdo->query("SELECT COUNT(*) FROM construction_equipment WHERE status='Available'")->fetchColumn();
    $lowStock=(int)$pdo->query("SELECT COUNT(*) FROM construction_materials WHERE status='Low Stock'")->fetchColumn();
}catch(Exception $ignore){}
$budgetUsedPct = $totalBudget>0 ? round($totalSpent/$totalBudget*100) : 0;

$projects=[];
try{$st=$pdo->query("SELECT * FROM construction_projects WHERE status IN ('Planning','In Progress','On Hold') ORDER BY progress DESC LIMIT 6");$projects=$st->fetchAll();}catch(Exception $ignore){}

$recent=[];
try{$st=$pdo->query("SELECT * FROM construction_projects ORDER BY created_at DESC LIMIT 6");$recent=$st->fetchAll();}catch(Exception $ignore){}

$materials=[];
try{ $materials=$pdo->query("SELECT * FROM construction_materials WHERE status='Low Stock' LIMIT 4")->fetchAll(); }catch(Exception $ignore){}

function statusPill(string $s): string {
    $m=['Planning'=>['Planning','plan'],'In Progress'=>['In Progress','prog'],'On Hold'=>['On Hold','hold'],'Completed'=>['Completed','done'],'Cancelled'=>['Cancelled','cancel']];
    [$label,$cls]=$m[$s]??[$s,'plan'];
    return '<span class="pill p-'.$cls.'">'.e($label).'</span>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Construction Dashboard | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏗️</text></svg>">
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
  --bg:#f5f5f0;--surface:#ffffff;--surface2:#f0f0ea;--surface3:#e6e6de;
  --ink:#1a1f1c;--ink2:#525c56;--ink3:#8a9490;
  --line:#dcdcd4;--line2:#c8c8be;
  --amber:#d97706;--amber2:#b45309;--amber-soft:#fef3c7;--amber-glow:rgba(217,119,6,.12);
  --green:#059669;--green2:#047857;--green-soft:#ecfdf5;--green-glow:rgba(5,150,105,.1);
  --steel:#374151;--steel2:#1f2937;--steel-soft:#f3f4f6;
  --red:#dc2626;--red-soft:#fef2f2;--red-glow:rgba(220,38,38,.08);
  --blue:#2563eb;--blue-soft:#eff6ff;
  --shadow-xs:0 1px 2px rgba(0,0,0,.04);
  --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.07),0 2px 4px -2px rgba(0,0,0,.05);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.08),0 4px 6px -4px rgba(0,0,0,.04);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);
  --fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;
  --r:14px;--r-lg:20px;--r-xl:24px;
  --ease:cubic-bezier(.4,0,.2,1);--ease-spring:cubic-bezier(.34,1.56,.64,1);
  --hazard:repeating-linear-gradient(45deg,var(--amber) 0 10px,var(--steel) 10px 20px);
}
[data-theme="dark"]{
  --bg:#0c0f12;--surface:#161b20;--surface2:#1c2329;--surface3:#242d35;
  --ink:#e8eceb;--ink2:#8b9590;--ink3:#5c6660;
  --line:#263034;--line2:#334044;
  --amber:#fbbf24;--amber2:#f59e0b;--amber-soft:#451a03;--amber-glow:rgba(251,191,36,.1);
  --green:#34d399;--green2:#10b981;--green-soft:#064e3b;--green-glow:rgba(52,211,153,.08);
  --steel:#4b5563;--steel2:#374151;--steel-soft:#1f2937;
  --red:#f87171;--red-soft:#450a0a;--red-glow:rgba(248,113,113,.08);
  --blue:#60a5fa;--blue-soft:#1e3a5f;
  --shadow-xs:0 1px 2px rgba(0,0,0,.2);
  --shadow-sm:0 1px 3px rgba(0,0,0,.25),0 1px 2px rgba(0,0,0,.2);
  --shadow-md:0 4px 6px -1px rgba(0,0,0,.3),0 2px 4px -2px rgba(0,0,0,.25);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.35),0 4px 6px -4px rgba(0,0,0,.25);
  --shadow-xl:0 20px 25px -5px rgba(0,0,0,.4),0 8px 10px -6px rgba(0,0,0,.3);
}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.6;transition:background .4s var(--ease),color .4s var(--ease);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(ellipse 800px 500px at 85% -10%,var(--amber-glow),transparent 70%),radial-gradient(ellipse 600px 400px at -10% 40%,var(--green-glow),transparent 70%)}
.container{max-width:1280px;margin:0 auto;padding:0 24px}
.mono{font-family:var(--fm)}
svg{flex:none}button{font-family:inherit;cursor:pointer;border:none;background:none}a{text-decoration:none;color:inherit}
::selection{background:color-mix(in srgb,var(--amber) 25%,transparent)}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ===== ENTRANCE ANIMATIONS ===== */
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes scaleIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
@keyframes slideRight{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
@keyframes barGrow{from{width:0}to{width:var(--bar-w)}}
@keyframes pulseGlow{0%,100%{box-shadow:0 0 0 0 var(--amber-glow)}50%{box-shadow:0 0 0 8px transparent}}
@keyframes hazardScroll{from{background-position:0 0}to{background-position:28px 0}}
.anim-fade-up{animation:fadeUp .6s var(--ease) both}
.anim-fade-in{animation:fadeIn .5s var(--ease) both}
.anim-scale{animation:scaleIn .5s var(--ease-spring) both}
.anim-slide-right{animation:slideRight .5s var(--ease) both}
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
.badge.amber{background:linear-gradient(135deg,var(--amber-soft),color-mix(in srgb,var(--amber) 15%,var(--surface)));color:var(--amber2);border:1px solid color-mix(in srgb,var(--amber) 20%,transparent)}
.nav-btn{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:all .25s var(--ease)}
.nav-btn:hover{color:var(--amber);border-color:var(--amber);background:var(--amber-soft);transform:translateY(-1px);box-shadow:var(--shadow-sm)}
.nav-btn svg{width:14px;height:14px}
.theme-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:all .3s var(--ease)}
.theme-btn:hover{color:var(--amber);border-color:var(--amber);transform:rotate(180deg);box-shadow:0 0 0 3px var(--amber-glow)}
.theme-btn svg{width:16px;height:16px}

/* ===== HERO ===== */
.hero{margin-top:24px;border-radius:var(--r-xl);overflow:hidden;position:relative;background:linear-gradient(135deg,var(--steel2) 0%,var(--steel) 50%,#111827 100%);color:#fff;box-shadow:var(--shadow-xl),0 0 0 1px rgba(255,255,255,.06) inset}
.hero-hazard{position:absolute;top:0;left:0;right:0;height:6px;background:var(--hazard);background-size:28px 28px;animation:hazardScroll 2s linear infinite}
.hero-pattern{position:absolute;inset:0;opacity:.03;pointer-events:none;background-image:radial-gradient(circle at 1px 1px,#fff 1px,transparent 0);background-size:20px 20px}
.hero-inner{position:relative;z-index:1;padding:36px 40px 32px;display:flex;align-items:center;gap:28px;flex-wrap:wrap}
.hero-text{flex:1;min-width:280px}
.hero-eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;background:rgba(217,119,6,.2);border:1px solid rgba(217,119,6,.35);color:#fde68a;padding:5px 14px;border-radius:99px;margin-bottom:14px}
.hero-eyebrow svg{width:14px;height:14px;stroke:#fde68a}
.hero h1{font-family:var(--fd);font-weight:800;font-size:clamp(24px,3.5vw,36px);line-height:1.12;letter-spacing:-.5px;margin-bottom:10px}
.hero h1 em{font-style:normal;color:#fde68a}
.hero-sub{font-size:15px;opacity:.8;font-weight:500;max-width:560px;line-height:1.6}
.hero-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.hero-tag{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;background:rgba(255,255,255,.1);backdrop-filter:blur(8px);padding:6px 14px;border-radius:99px;border:1px solid rgba(255,255,255,.12);transition:all .25s var(--ease)}
.hero-tag:hover{background:rgba(255,255,255,.18);transform:translateY(-1px)}
.hero-tag svg{width:14px;height:14px;opacity:.8}
.hero-budget{flex:none;min-width:260px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);border-radius:var(--r-lg);padding:22px 24px;backdrop-filter:blur(12px);position:relative;overflow:hidden}
.hero-budget::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--amber),#fbbf24)}
.hb-lbl{font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.7}
.hb-val{font-family:var(--fm);font-size:28px;font-weight:700;margin:8px 0 4px;letter-spacing:-.5px}
.hb-sub{font-size:12px;opacity:.75;font-weight:600}
.hb-bar{height:8px;border-radius:99px;background:rgba(255,255,255,.15);margin-top:14px;overflow:hidden}
.hb-bar div{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--amber),#fbbf24);transition:width 1.2s cubic-bezier(.4,0,.2,1)}
.hb-live{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:10px;font-weight:700;opacity:.7}
.hb-live .dot{width:6px;height:6px;border-radius:50%;background:#34d399;animation:pulseGlow 2s ease-in-out infinite}

/* ===== STATS ===== */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:24px}
.stat-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);padding:24px;box-shadow:var(--shadow-sm);transition:all .35s var(--ease);position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0;transition:height .3s var(--ease)}
.stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.stat-card:hover::before{height:4px}
.stat-card.accent-amber::before{background:linear-gradient(90deg,var(--amber),var(--amber2))}
.stat-card.accent-green::before{background:linear-gradient(90deg,var(--green),var(--green2))}
.stat-card.accent-blue::before{background:linear-gradient(90deg,var(--blue),#1d4ed8)}
.stat-card.accent-steel::before{background:linear-gradient(90deg,var(--steel),var(--steel2))}
.sc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.sc-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;transition:transform .3s var(--ease-spring)}
.stat-card:hover .sc-icon{transform:scale(1.1) rotate(-5deg)}
.sc-icon svg{width:22px;height:22px}
.accent-amber .sc-icon{background:var(--amber-soft);color:var(--amber2)}
.accent-green .sc-icon{background:var(--green-soft);color:var(--green)}
.accent-blue .sc-icon{background:var(--blue-soft);color:var(--blue)}
.accent-steel .sc-icon{background:var(--steel-soft);color:var(--steel)}
.sc-val{font-family:var(--fd);font-size:30px;font-weight:800;display:block;line-height:1;letter-spacing:-.5px}
.sc-label{font-size:11px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.6px;margin-top:6px}
.sc-sub{font-size:11px;color:var(--ink3);font-weight:600;margin-top:8px;padding-top:8px;border-top:1px solid var(--line)}

/* ===== SECTION HEADERS ===== */
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-top:36px;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.sec-head h2{font-family:var(--fd);font-size:20px;font-weight:800;display:flex;align-items:center;gap:10px;letter-spacing:-.3px}
.sec-head h2 svg{width:22px;height:22px;stroke:var(--amber2)}
.sec-head a{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--amber2);padding:7px 16px;border:1px solid var(--amber-soft);border-radius:99px;transition:all .25s var(--ease)}
.sec-head a:hover{background:var(--amber);color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px var(--amber-glow)}
.sec-head a svg{width:14px;height:14px}

/* ===== ACTION CARDS ===== */
.actions-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-top:16px}
.act-card{background:var(--surface);border:1.5px solid var(--line);border-radius:var(--r-lg);padding:24px 18px;display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;transition:all .3s var(--ease);cursor:pointer;color:var(--ink);position:relative;overflow:hidden}
.act-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--amber-glow),transparent);opacity:0;transition:opacity .3s var(--ease)}
.act-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--hazard);background-size:28px 28px;opacity:0;transition:opacity .3s var(--ease)}
.act-card:hover{border-color:var(--amber);transform:translateY(-5px);box-shadow:var(--shadow-lg)}
.act-card:hover::before{opacity:1}
.act-card:hover::after{opacity:1;animation:hazardScroll 2s linear infinite}
.act-card:hover .ac-icon{background:var(--amber);border-color:var(--amber);transform:scale(1.1)}
.act-card:hover .ac-icon svg{stroke:#fff}
.ac-icon{width:52px;height:52px;border-radius:14px;background:var(--surface2);border:1px solid var(--line);display:grid;place-items:center;transition:all .3s var(--ease-spring);position:relative;z-index:1}
.ac-icon svg{width:24px;height:24px;stroke:var(--ink2);transition:stroke .3s var(--ease)}
.ac-title{font-family:var(--fd);font-size:13px;font-weight:700;position:relative;z-index:1;letter-spacing:-.1px}
.ac-desc{font-size:10.5px;color:var(--ink2);font-weight:600;line-height:1.45;position:relative;z-index:1}

/* ===== DASH GRID ===== */
.dash-grid{display:grid;grid-template-columns:1.5fr 1fr;gap:20px;margin-top:20px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);overflow:hidden;transition:box-shadow .3s var(--ease)}
.card:hover{box-shadow:var(--shadow-md)}
.card-header{padding:18px 22px;border-bottom:1px solid var(--line);font-family:var(--fd);font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;letter-spacing:-.1px}
.card-header .ch-left{display:flex;align-items:center;gap:10px}
.card-header svg{width:18px;height:18px;stroke:var(--amber2)}
.count-pill{font-family:var(--fm);font-size:10px;font-weight:700;background:var(--amber-soft);color:var(--amber2);padding:3px 10px;border-radius:99px}

/* ===== PROJECT LIST ===== */
.proj-list{padding:10px 22px 22px}
.proj-item{padding:16px 0;border-bottom:1px solid var(--line);transition:background .2s var(--ease)}
.proj-item:last-child{border-bottom:none}
.proj-item:hover{background:var(--surface2);margin:0 -22px;padding:16px 22px;border-radius:8px}
.proj-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.pn{font-family:var(--fd);font-size:14px;font-weight:800;letter-spacing:-.2px}
.pm{font-size:11px;color:var(--ink2);font-weight:600;margin-top:3px}
.pill{font-size:9px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;white-space:nowrap;text-transform:uppercase}
.p-prog{background:var(--amber-soft);color:var(--amber2);border:1px solid color-mix(in srgb,var(--amber) 20%,transparent)}
.p-plan{background:var(--blue-soft);color:var(--blue);border:1px solid color-mix(in srgb,var(--blue) 20%,transparent)}
.p-hold{background:var(--steel-soft);color:var(--ink2);border:1px solid var(--line)}
.p-done{background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 20%,transparent)}
.p-cancel{background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb,var(--red) 20%,transparent)}
.proj-bar{height:8px;border-radius:99px;background:var(--surface2);overflow:hidden}
.proj-bar div{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--amber),#fbbf24);transition:width .8s cubic-bezier(.4,0,.2,1)}
.proj-meta{display:flex;justify-content:space-between;margin-top:8px;font-size:11px;font-weight:700;color:var(--ink2)}
.proj-meta b{color:var(--amber2);font-family:var(--fm)}

/* ===== BUDGET CARD ===== */
.budget-card{padding:22px}
.budget-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
.budget-row .lbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.budget-row .val{font-family:var(--fm);font-size:18px;font-weight:700;color:var(--amber2);letter-spacing:-.3px}
.budget-bar{height:10px;border-radius:99px;background:var(--surface2);overflow:hidden;margin:12px 0 10px}
.budget-bar div{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--amber),#fbbf24);transition:width 1s cubic-bezier(.4,0,.2,1)}
.budget-note{font-size:11px;color:var(--ink3);font-weight:600;line-height:1.5}

/* ===== MATERIALS ===== */
.mat-list{padding:8px 22px 18px}
.mat-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);transition:background .2s var(--ease)}
.mat-item:last-child{border-bottom:none}
.mat-item:hover{background:var(--surface2);margin:0 -22px;padding:12px 22px;border-radius:8px}
.mn{font-size:13px;font-weight:700;letter-spacing:-.1px}
.mat-item small{display:block;font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px}
.low-pill{font-size:9px;font-weight:800;padding:4px 10px;border-radius:99px;background:var(--amber-soft);color:var(--amber2);white-space:nowrap;border:1px solid color-mix(in srgb,var(--amber) 20%,transparent);text-transform:uppercase;letter-spacing:.3px}

/* ===== TABLE ===== */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:820px}
thead th{font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink3);text-align:left;padding:14px 18px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:14px 18px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .2s var(--ease)}
tbody tr:hover{background:var(--surface2)}
tbody tr:last-child td{border-bottom:none}
.pcode{font-family:var(--fm);font-weight:700;color:var(--amber2);font-size:12px}
.empty{padding:50px 20px;text-align:center;color:var(--ink3)}
.empty svg{width:48px;height:48px;stroke:var(--ink3);margin:0 auto 12px;opacity:.4;display:block}
.empty p{font-weight:700;font-size:14px;color:var(--ink2)}
.empty small{font-weight:600;font-size:12px;display:block;margin-top:4px}

/* ===== FOOTER ===== */
footer{margin-top:40px;border-top:1px solid var(--line);padding:28px 24px 36px;text-align:center;color:var(--ink3);font-size:11px;font-weight:600}
footer div+div{margin-top:4px}
footer .dev-credit{font-weight:700;color:var(--ink2);margin-top:8px;font-size:12px}
footer .dev-credit span{color:var(--amber)}

@media(max-width:1020px){.stats-grid{grid-template-columns:repeat(2,1fr)}.dash-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .hero-inner{flex-direction:column;align-items:stretch;padding:28px 24px 24px}
  .hero-budget{min-width:auto;width:100%}
  .stats-grid{grid-template-columns:1fr 1fr;gap:10px}
  .stat-card{padding:18px}.stat-card .sc-val{font-size:24px}
  .actions-grid{grid-template-columns:repeat(2,1fr)}
  .topbar .badge{display:none}.brand-text strong{font-size:12px}
  .sec-head h2{font-size:17px}
  .container{padding:0 16px}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr}
  .actions-grid{grid-template-columns:1fr}
  .hero h1{font-size:24px}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <a href="../staff_first_dashboard.php" class="nav-btn" title="Back to Departments">
      <svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Departments
    </a>
    <div class="brand">
      <div class="logo-chip"><img src="logo.ico" alt="Logo"></div>
      <div class="brand-text">
        <strong><?php echo e($company['company_name']); ?></strong>
        <small><?php echo e($company['company_dept']); ?></small>
      </div>
    </div>
    <span class="badge amber">CONSTRUCTION</span>
    <button class="theme-btn" id="themeBtn" title="Toggle theme" aria-label="Toggle dark mode">
      <svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>
    </button>
  </div>
</header>

<div class="container">

  <!-- HERO -->
  <div class="hero anim-fade-up">
    <div class="hero-hazard"></div>
    <div class="hero-pattern"></div>
    <div class="hero-inner">
      <div class="hero-text">
        <span class="hero-eyebrow"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg>Construction &amp; Projects</span>
        <h1>Welcome back, <em><?php echo e(explode(' ',$auth['name'])[0]); ?></em></h1>
        <p class="hero-sub">Monitor active projects, sites, materials and workforce across all construction operations.</p>
        <div class="hero-meta">
          <span class="hero-tag"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php echo e($auth['staff_code']); ?></span>
          <span class="hero-tag"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><?php echo date('l, d M Y'); ?></span>
          <span class="hero-tag"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span id="liveClock">--:--</span> WAT</span>
          <span class="hero-tag"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg><?php echo $inProgress; ?> In Progress</span>
        </div>
      </div>
      <div class="hero-budget anim-scale d2">
        <div class="hb-lbl">Total Project Budget</div>
        <div class="hb-val"><?php echo fmtCompact($totalBudget); ?></div>
        <div class="hb-sub"><?php echo fmtCompact($totalSpent); ?> spent &middot; <?php echo $budgetUsedPct; ?>% used</div>
        <div class="hb-bar"><div style="width:<?php echo $budgetUsedPct; ?>%"></div></div>
        <div class="hb-live"><span class="dot"></span>Live budget tracking</div>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card accent-amber anim-fade-up d1">
      <div class="sc-top"><div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg></div></div>
      <span class="sc-val"><?php echo $activeProjects; ?></span>
      <span class="sc-label">Active Projects</span>
      <div class="sc-sub"><?php echo $completed; ?> completed</div>
    </div>
    <div class="stat-card accent-green anim-fade-up d2">
      <div class="sc-top"><div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div></div>
      <span class="sc-val"><?php echo $sites; ?></span>
      <span class="sc-label">Active Sites</span>
      <div class="sc-sub">Operational</div>
    </div>
    <div class="stat-card accent-blue anim-fade-up d3">
      <div class="sc-top"><div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div></div>
      <span class="sc-val"><?php echo $workersOnSite; ?></span>
      <span class="sc-label">Workers On Site</span>
      <div class="sc-sub">Present today</div>
    </div>
    <div class="stat-card accent-steel anim-fade-up d4">
      <div class="sc-top"><div class="sc-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div></div>
      <span class="sc-val"><?php echo $equipmentAvail; ?></span>
      <span class="sc-label">Equipment Available</span>
      <div class="sc-sub"><?php echo $lowStock; ?> low-stock items</div>
    </div>
  </div>

  <!-- QUICK ACTIONS -->
  <div class="sec-head anim-fade-up d5">
    <h2><svg class="icon-svg" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Quick Actions</h2>
  </div>
  <div class="actions-grid">
    <a href="construction_sites.php" class="act-card anim-scale d1">
      <div class="ac-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div>
      <div class="ac-title">Sites</div>
      <div class="ac-desc">Manage active sites</div>
    </a>
    <a href="construction_reports.php" class="act-card anim-scale d2">
      <div class="ac-icon"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <div class="ac-title">Reports</div>
      <div class="ac-desc">Budget &amp; progress</div>
    </a>
    <a href="settings.php" class="act-card anim-scale d3">
      <div class="ac-icon"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
      <div class="ac-title">System Settings</div>
      <div class="ac-desc">Company config &amp; preferences</div>
    </a>
  </div>

  <!-- PROJECTS + SIDE -->
  <div class="dash-grid anim-fade-up d6">
    <div class="card">
      <div class="card-header"><div class="ch-left"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg>Active Projects</div><span class="count-pill"><?php echo count($projects); ?></span></div>
      <div class="proj-list">
        <?php if(empty($projects)): ?>
          <div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg><p>No active projects yet</p><small>Create a project from the New Project action and it will appear here.</small></div>
        <?php else: foreach($projects as $p): ?>
          <div class="proj-item">
            <div class="proj-top">
              <div><div class="pn"><?php echo e($p['name']); ?></div><div class="pm"><?php echo e($p['site']??'—'); ?> &middot; <?php echo e($p['manager']??'—'); ?></div></div>
              <?php echo statusPill($p['status']); ?>
            </div>
            <div class="proj-bar"><div style="width:<?php echo (int)$p['progress']; ?>%"></div></div>
            <div class="proj-meta"><span>Progress <b><?php echo (int)$p['progress']; ?>%</b></span><span><?php echo fmtCompact($p['spent']); ?> / <?php echo fmtCompact($p['budget']); ?></span></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:20px">
      <div class="card">
        <div class="card-header"><div class="ch-left"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>Budget Overview</div></div>
        <div class="budget-card">
          <div class="budget-row"><span class="lbl">Total Budget</span><span class="val"><?php echo fmtCompact($totalBudget); ?></span></div>
          <div class="budget-row"><span class="lbl">Spent</span><span class="val"><?php echo fmtCompact($totalSpent); ?></span></div>
          <div class="budget-bar"><div style="width:<?php echo $budgetUsedPct; ?>%"></div></div>
          <div class="budget-note"><?php echo $budgetUsedPct; ?>% of total budget used across <?php echo $activeProjects; ?> active project(s).</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="ch-left"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>Low Stock Alerts</div><span class="count-pill"><?php echo count($materials); ?></span></div>
        <div class="mat-list">
          <?php if(empty($materials)): ?>
            <div class="empty" style="padding:24px 20px"><p>All materials in stock</p><small>Low-stock items will be flagged here automatically.</small></div>
          <?php else: foreach($materials as $m): ?>
            <div class="mat-item">
              <div><span class="mn"><?php echo e($m['name']); ?></span><small><?php echo number_format((float)$m['quantity']); ?> <?php echo e($m['unit']); ?> left</small></div>
              <span class="low-pill"><?php echo e($m['status']); ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- RECENT PROJECTS TABLE -->
  <div class="sec-head anim-fade-up d7">
    <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/></svg>Recent Projects</h2>
    <a href="construction_projects.php"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>View All</a>
  </div>
  <div class="card anim-fade-up d8">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Code</th><th>Project</th><th>Site</th><th>Budget</th><th>Spent</th><th>Progress</th><th>Status</th><th>Deadline</th></tr></thead>
        <tbody>
          <?php if(empty($recent)): ?>
            <tr><td colspan="8"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg><p>No projects recorded yet</p><small>Projects you create will be listed here.</small></div></td></tr>
          <?php else: foreach($recent as $p): ?>
            <tr>
              <td class="pcode"><?php echo e($p['project_code']); ?></td>
              <td style="font-weight:800;letter-spacing:-.1px"><?php echo e($p['name']); ?></td>
              <td><?php echo e($p['site']??'—'); ?></td>
              <td class="mono"><?php echo fmtCompact($p['budget']); ?></td>
              <td class="mono"><?php echo fmtCompact($p['spent']); ?></td>
              <td><div class="proj-bar" style="min-width:90px"><div style="width:<?php echo (int)$p['progress']; ?>%"></div></div></td>
              <td><?php echo statusPill($p['status']); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo $p['end_date']?date('d M Y',strtotime($p['end_date'])):'—'; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<footer>
  <div>&copy; 2026 <?php echo e($company['company_name']); ?></div>
  <div>Construction &amp; Projects Dashboard</div>
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
