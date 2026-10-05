<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }   // any authenticated user can view

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria','company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'];
$logoUrl = './Client Order Form _ Okoya Food   mr wahab_files/logo_uigcps.jpg';

/* ===== FILTERS ===== */
$search     = trim($_GET['search'] ?? '');
$filterDept = $_GET['department'] ?? '';
$filterStat = $_GET['status'] ?? '';
$filterRole = $_GET['role'] ?? '';

$where=[];$params=[];
if($search!==''){ $where[]="(s.full_name LIKE ? OR s.staff_code LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)"; array_push($params,"%$search%","%$search%","%$search%","%$search%"); }
if($filterDept!==''){ $where[]="s.department = ?"; $params[]=$filterDept; }
if($filterStat!==''){ $where[]="s.status = ?"; $params[]=$filterStat; }
if($filterRole!==''){ $where[]="LOWER(r.name) = LOWER(?)"; $params[]=$filterRole; }
$wc = $where ? 'WHERE '.implode(' AND ',$where) : '';

$staffList=[];
try{
    $st=$pdo->prepare("SELECT s.*, r.name AS role_name FROM staff s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN roles r ON r.id=u.role_id $wc ORDER BY s.full_name ASC LIMIT 500");
    $st->execute($params); $staffList=$st->fetchAll();
}catch(Exception $ignore){}

/* stats (global, unfiltered) */
$totAll=$totActive=0; $deptSet=[];
try{
    $totAll=(int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
    $totActive=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='Active'")->fetchColumn();
    $deptSet=$pdo->query("SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department<>'' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
}catch(Exception $ignore){}

/* role list for filter */
$rolesList=[];
try{ $rolesList=$pdo->query("SELECT name FROM roles ORDER BY id")->fetchAll(PDO::FETCH_COLUMN); }catch(Exception $ignore){}
if(empty($rolesList)) $rolesList=['Admin','CEO','General Manager','Secretary','Staff','Workers','Cleaner'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function roleSlug(?string $r):string{
    $r=strtolower((string)$r);
    if($r==='general manager')return 'gm';
    return in_array($r,['admin','ceo','secretary','staff','workers','cleaner'])?$r:'staff';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Staff Directory | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--line2:#c8d3c5;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}
.container{max-width:1240px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
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
.badge.view{background:var(--blue-soft);color:var(--blue)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

/* page header */
.page-header{padding:30px 0 10px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}
.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.ph-left h1 svg{width:28px;height:28px;stroke:var(--green)}
.ph-left h1 em{font-style:normal;color:var(--green)}
.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.ph-note{display:inline-flex;align-items:center;gap:7px;margin-top:8px;font-size:11px;font-weight:800;color:var(--blue);background:var(--blue-soft);padding:5px 12px;border-radius:99px;letter-spacing:.4px}
.ph-note svg{width:12px;height:12px}

/* stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:18px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:19px;flex:none}
.stat .ic.g{background:var(--green-soft)}
.stat .ic.y{background:var(--gold-soft)}
.stat .ic.b{background:var(--blue-soft)}
.stat .ic.r{background:var(--red-soft)}
.stat b{font-family:var(--fd);font-size:21px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}

/* filter bar */
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:18px 0;box-shadow:var(--shadow-sm)}
.ff{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}
.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ff input,.ff select{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}
.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
.btn svg{width:15px;height:15px}

/* table */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:960px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s;cursor:pointer}
tbody tr:hover{background:var(--surface2)}
tbody tr:last-child td{border-bottom:none}
.staff-cell{display:flex;align-items:center;gap:12px}
.staff-photo{width:42px;height:42px;border-radius:11px;object-fit:cover;border:1.5px solid var(--line);background:var(--surface2);flex:none}
.staff-photo.placeholder{display:grid;place-items:center;color:var(--ink2)}
.staff-photo.placeholder svg{width:20px;height:20px}
.sc-name{font-weight:800;display:block}
.sc-sub{font-size:11px;color:var(--ink2);font-weight:600}
.role-pill{font-size:9.5px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.5px;text-transform:uppercase;display:inline-block}
.rp-admin,.rp-ceo{background:var(--gold-soft);color:var(--gold2)}
.rp-gm{background:var(--blue-soft);color:var(--blue)}
.rp-secretary,.rp-staff{background:var(--green-soft);color:var(--green)}
.rp-workers{background:var(--red-soft);color:var(--red)}
.rp-cleaner{background:var(--surface2);color:var(--ink2);border:1px solid var(--line)}
.st-pill{font-size:10px;font-weight:800;padding:4px 10px;border-radius:99px;display:inline-block}
.st-pill.active{background:var(--green-soft);color:var(--green)}
.st-pill.suspended{background:var(--gold-soft);color:var(--gold2)}
.st-pill.resigned{background:var(--red-soft);color:var(--red)}
.actions-cell{display:flex;gap:6px;white-space:nowrap}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty svg{width:48px;height:48px;stroke:var(--ink2);margin:0 auto 10px;opacity:.5;display:block}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}

/* view modal */
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
@keyframes fadein{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:760px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.vm-hero{background:linear-gradient(130deg,var(--green),var(--green2));border-radius:20px 20px 0 0;padding:24px 28px;display:flex;gap:18px;align-items:center;color:#fff;position:relative;overflow:hidden}
.vm-hero::after{content:'';position:absolute;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06);right:-30px;top:-40px}
.vm-photo{width:78px;height:92px;border-radius:12px;object-fit:cover;border:3px solid rgba(255,255,255,.85);flex:none;background:#fff}
.vm-photo-ph{width:78px;height:92px;border-radius:12px;border:3px solid rgba(255,255,255,.85);flex:none;display:grid;place-items:center;background:rgba(255,255,255,.12)}
.vm-photo-ph svg{width:34px;height:34px;stroke:#fff}
.vm-id h3{font-family:var(--fd);font-size:20px;font-weight:800;line-height:1.15}
.vm-id .pos{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;opacity:.9;margin:3px 0 8px}
.vm-chips{display:flex;gap:7px;flex-wrap:wrap}
.vm-chips span{font-size:9px;font-weight:800;letter-spacing:.7px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);padding:4px 10px;border-radius:99px}
.vm-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;font-size:16px;cursor:pointer;display:grid;place-items:center;z-index:2}
.vm-body{padding:22px 28px 26px}
.vm-cols{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.vm-sec{border:1px solid var(--line);border-radius:12px;overflow:hidden}
.vm-sec.wide{grid-column:1/-1}
.vm-sec h4{background:var(--surface2);border-bottom:1px solid var(--line);color:var(--green);font-size:10px;font-weight:800;letter-spacing:1px;padding:8px 14px;text-transform:uppercase}
.vm-sec-body{padding:8px 14px}
.vm-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dotted var(--line);font-size:12.5px;line-height:1.4}
.vm-row:last-child{border-bottom:none}
.vm-row span{color:var(--ink2);font-weight:600;flex:none;width:44%}
.vm-row b{font-weight:700;text-align:right;word-break:break-word}
.vm-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
.vm-actions .btn{height:46px}

.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.info{border-left-color:var(--blue)}
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

  /* profile print */
  .psp{font-size:8.5pt;color:#1a231d}
  .psp-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #166e45;padding-bottom:8pt}
  .psp-logo{width:52pt;height:52pt;object-fit:contain;background:#fff;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;flex:none}
  .psp-co{flex:1;min-width:0}
  .psp-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#166e45;letter-spacing:.3pt;line-height:1.15}
  .psp-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .psp-co .tag{font-size:5.5pt;font-weight:800;letter-spacing:1.2pt;color:#b57e17;margin-top:3pt}
  .psp-doc{text-align:right;flex:none}
  .psp-doc h2{font-size:8pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#166e45;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .psp-doc p{font-size:6pt;color:#7a857c;margin-top:3pt}
  .psp-doc .ref{font-family:var(--fm);font-weight:700;color:#166e45;font-size:7pt}
  .psp-summary{display:flex;gap:11pt;margin:9pt 0;border:1pt solid #cfd8cd;border-radius:6pt;padding:9pt;background:#fafcf9;page-break-inside:avoid}
  .psp-photo{width:66pt;height:80pt;object-fit:cover;border:2pt solid #166e45;border-radius:4pt;flex:none}
  .psp-photo-ph{width:66pt;height:80pt;border:2pt solid #166e45;border-radius:4pt;flex:none;display:flex;align-items:center;justify-content:center;font-size:6pt;color:#9aa79c;text-align:center;line-height:1.4;background:#fff}
  .psp-id{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center}
  .psp-id h3{font-family:var(--fd);font-size:13pt;font-weight:800;line-height:1.15}
  .psp-id .pos{font-size:7.5pt;font-weight:800;color:#166e45;text-transform:uppercase;letter-spacing:.6pt;margin:2pt 0 4pt}
  .psp-idmeta{display:flex;gap:6pt;flex-wrap:wrap}
  .psp-chip{font-size:6.5pt;font-weight:800;padding:2.5pt 7pt;border-radius:99pt;letter-spacing:.4pt}
  .psp-chip.id{background:#e2f2e8;color:#166e45;font-family:var(--fm);letter-spacing:1pt}
  .psp-chip.st{background:#e8effd;color:#2563eb}
  .psp-cols{display:grid;grid-template-columns:1fr 1fr;gap:7pt}
  .psp-sec{border:1pt solid #cfd8cd;border-radius:5pt;overflow:hidden;page-break-inside:avoid}
  .psp-sec.wide{grid-column:1/-1}
  .psp-sec h4{background:#166e45;color:#fff;font-size:6.5pt;font-weight:800;letter-spacing:1.2pt;padding:4pt 8pt;text-transform:uppercase}
  .psp-sec-body{padding:5pt 8pt}
  .psp-row{display:flex;justify-content:space-between;gap:8pt;padding:2.5pt 0;border-bottom:1pt dotted #dde5dd;font-size:7.5pt;line-height:1.4}
  .psp-row:last-child{border-bottom:none}
  .psp-row span{color:#667268;font-weight:600;flex:none;width:44%}
  .psp-row b{font-weight:700;text-align:right;word-break:break-word}
  .psp-foot{margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}

  /* roster print */
  .rl-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #166e45;padding-bottom:8pt;margin-bottom:10pt}
  .rl-logo{width:48pt;height:48pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .rl-co{flex:1}
  .rl-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#166e45;line-height:1.15}
  .rl-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .rl-doc{text-align:right}
  .rl-doc h2{font-size:8pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#166e45;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .rl-doc p{font-size:6pt;color:#7a857c;margin-top:3pt}
  .rl-summary{font-size:7pt;color:#5a655c;margin-bottom:7pt}
  .rl-summary b{color:#166e45}
  .rl-table{width:100%;border-collapse:collapse;font-size:7pt}
  .rl-table th{background:#166e45;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.6pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #166e45}
  .rl-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .rl-table tr:nth-child(even) td{background:#fafcf9}
  .rl-foot{margin-top:8pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.filter-bar{flex-direction:column}.ff{min-width:100%}.topbar .badge{display:none}.brand strong{font-size:12px}.vm-cols{grid-template-columns:1fr}.vm-hero{padding:20px}.vm-body{padding:18px}.vm-actions{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr;gap:10px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}.vm-actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $auth['role']==='admin'?'admin_general_dashboard.php':'secretary_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a>
  <span class="badge view"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>VIEW ONLY</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-left">
      <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Staff <em>Directory</em></h1>
      <p>Company-wide staff information — browse and view staff records</p>
      <span class="vm-note"></svg>READ-ONLY ACCESS — RECORDS CANNOT BE EDITED HERE</span>
    </div>
    <button class="btn btn-ghost" id="btnPrintRoster"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="10" width="8" height="8"/></svg>Print Staff List</button>
  </div>

  

  <form method="get" class="filter-bar">
    <div class="ff"><label>Search</label><input type="text" name="search" placeholder="Name, Staff ID, phone, email..." value="<?php echo e($search); ?>"></div>
    <div class="ff" style="max-width:190px"><label>Department</label><select name="department"><option value="">All Departments</option><?php foreach($deptSet as $dp): ?><option value="<?php echo e($dp); ?>" <?php echo $filterDept===$dp?'selected':''; ?>><?php echo e($dp); ?></option><?php endforeach; ?></select></div>
    <div class="ff" style="max-width:170px"><label>Role</label><select name="role"><option value="">All Roles</option><?php foreach($rolesList as $rl): ?><option value="<?php echo e($rl); ?>" <?php echo strcasecmp($filterRole,$rl)===0?'selected':''; ?>><?php echo e($rl); ?></option><?php endforeach; ?></select></div>
    <div class="ff" style="max-width:160px"><label>Status</label><select name="status"><option value="">All Statuses</option><option value="Active" <?php echo $filterStat==='Active'?'selected':''; ?>>Active</option><option value="Suspended" <?php echo $filterStat==='Suspended'?'selected':''; ?>>Suspended</option><option value="Resigned" <?php echo $filterStat==='Resigned'?'selected':''; ?>>Resigned</option></select></div>
    <div style="display:flex;gap:8px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button><a href="staff_directory.php" class="btn btn-ghost">Clear</a></div>
  </form>

  <div class="card">
    <div class="card-head">
      <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>All Staff Members</h2>
      <span class="count-pill"><?php echo count($staffList); ?> records</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Staff Member</th><th>Staff ID</th><th>Role</th><th>Department</th><th>Position</th><th>Phone</th><th>Hired</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($staffList)): ?>
            <tr><td colspan="9"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg><p>No staff members found</p><small>No records match your search or filters.</small></div></td></tr>
          <?php else: foreach($staffList as $i=>$s): $photoSrc=!empty($s['passport_photo'])?$s['passport_photo']:''; ?>
            <tr data-idx="<?php echo $i; ?>">
              <td><div class="staff-cell"><?php if($photoSrc): ?><img class="staff-photo" src="<?php echo e($photoSrc); ?>" alt=""><?php else: ?><div class="staff-photo placeholder"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><?php endif; ?><div><span class="sc-name"><?php echo e($s['full_name']); ?></span><span class="sc-sub"><?php echo e($s['email']??''); ?></span></div></div></td>
              <td class="mono" style="color:var(--green);font-weight:700"><?php echo e($s['staff_code']); ?></td>
              <td><span class="role-pill rp-<?php echo roleSlug($s['role_name']??'staff'); ?>"><?php echo e($s['role_name']??'Staff'); ?></span></td>
              <td><?php echo e($s['department']??'&mdash;'); ?></td>
              <td><?php echo e($s['position']??'&mdash;'); ?></td>
              <td style="font-size:12px"><?php echo e($s['phone']??'&mdash;'); ?></td>
              <td style="font-size:12px;color:var(--ink2)"><?php echo !empty($s['hire_date'])?date('d M Y',strtotime($s['hire_date'])):'&mdash;'; ?></td>
              <td><span class="st-pill <?php echo strtolower($s['status']??'active'); ?>"><?php echo e($s['status']??'Active'); ?></span></td>
              <td><div class="actions-cell">
                <button class="btn btn-ghost btn-sm btn-view" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>View</button>
                <button class="btn btn-ghost btn-sm btn-pprint" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print</button>
              </div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal"><div class="modal">
  <div class="vm-hero" id="vmHero"></div>
  <div class="vm-body" id="vmBody"></div>
</div></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Staff Directory (Read-Only)</div></footer>
<div class="toasts" id="toastWrap"></div>
<div id="printSheet"></div>

<script>
const $=id=>document.getElementById(id);
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'&mdash;':String(s);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='info'){const t=document.createElement('div');t.className='toast '+type;t.innerHTML='<span>'+(type==='info'?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3200);setTimeout(()=>t.remove(),3700);}

const staffData=<?php echo json_encode(array_map(function($s){return['full_name'=>$s['full_name'],'phone'=>$s['phone']??'','email'=>$s['email']??'','staff_code'=>$s['staff_code'],'department'=>$s['department']??'','position'=>$s['position']??'','role'=>$s['role_name']??'Staff','employment_type'=>$s['employment_type']??'Full-Time','salary'=>$s['salary']??null,'status'=>$s['status']??'Active','date_of_birth'=>$s['date_of_birth']??'','gender'=>$s['gender']??'','nin'=>$s['nin']??'','nationality'=>$s['nationality']??'','state_of_origin'=>$s['state_of_origin']??'','lga'=>$s['lga']??'','marital_status'=>$s['marital_status']??'','religion'=>$s['religion']??'','blood_group'=>$s['blood_group']??'','genotype'=>$s['genotype']??'','disability'=>$s['disability']??'','highest_education'=>$s['highest_education']??'','institution'=>$s['institution']??'','graduation_year'=>$s['graduation_year']??'','field_of_study'=>$s['field_of_study']??'','certifications'=>$s['certifications']??'','prev_company'=>$s['prev_company']??'','prev_position'=>$s['prev_position']??'','prev_duration'=>$s['prev_duration']??'','prev_responsibilities'=>$s['prev_responsibilities']??'','guarantor_name'=>$s['guarantor_name']??'','guarantor_phone'=>$s['guarantor_phone']??'','guarantor_address'=>$s['guarantor_address']??'','guarantor_relationship'=>$s['guarantor_relationship']??'','guarantor_occupation'=>$s['guarantor_occupation']??'','bank_name'=>$s['bank_name']??'','bank_account_no'=>$s['bank_account_no']??'','bank_account_name'=>$s['bank_account_name']??'','tax_id'=>$s['tax_id']??'','pension_pin'=>$s['pension_pin']??'','nhis_number'=>$s['nhis_number']??'','emergency_contact_name'=>$s['emergency_contact_name']??'','emergency_contact_phone'=>$s['emergency_contact_phone']??'','emergency_contact_relation'=>$s['emergency_contact_relation']??'','address'=>$s['address']??'','next_of_kin'=>$s['next_of_kin']??'','next_of_kin_phone'=>$s['next_of_kin_phone']??'','notes'=>$s['notes']??'','passport_photo'=>$s['passport_photo']??'','hire_date'=>$s['hire_date']??''];},$staffList),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const cn='<?php echo addslashes($company['company_name']); ?>';
const ca='<?php echo addslashes($company['company_address']); ?>';
const logo='<?php echo e($logoUrl); ?>';
const phSVG='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

/* ===== VIEW MODAL ===== */
function openView(i){
  const s=staffData[i];if(!s)return;
  const photo=s.passport_photo?'<img class="vm-photo" src="'+s.passport_photo+'" alt="">':'<div class="vm-photo-ph">'+phSVG+'</div>';
  $('vmHero').innerHTML='<button class="vm-close" onclick="closeView()">&times;</button>'+photo+
    '<div class="vm-id"><h3>'+v(s.full_name)+'</h3><div class="pos">'+v(s.position)+(s.department&&s.department!=='&mdash;'?' — '+s.department:'')+'</div>'+
    '<div class="vm-chips"><span>'+s.staff_code+'</span><span>'+(s.role||'Staff').toUpperCase()+'</span><span>'+(s.status||'Active').toUpperCase()+'</span><span>'+(s.employment_type||'Full-Time').toUpperCase()+'</span></div></div>';
  const row=(l,val)=>'<div class="vm-row"><span>'+l+'</span><b>'+v(val)+'</b></div>';
  $('vmBody').innerHTML=
    '<div class="vm-cols">'+
      '<div class="vm-sec"><h4>Personal Information</h4><div class="vm-sec-body">'+row('Date of Birth',s.date_of_birth)+row('Gender',s.gender)+row('NIN',s.nin)+row('Nationality',s.nationality)+row('State of Origin',s.state_of_origin)+row('LGA',s.lga)+row('Marital Status',s.marital_status)+row('Religion',s.religion)+row('Blood Group',s.blood_group)+row('Genotype',s.genotype)+row('Address',s.address)+'</div></div>'+
      '<div class="vm-sec"><h4>Employment</h4><div class="vm-sec-body">'+row('Staff ID',s.staff_code)+row('Role',s.role)+row('Position',s.position)+row('Department',s.department)+row('Employment Type',s.employment_type)+row('Monthly Salary',s.salary?'₦'+Number(s.salary).toLocaleString():null)+row('Hire Date',s.hire_date)+row('Status',s.status)+row('Phone',s.phone)+row('Email',s.email)+'</div></div>'+
      '<div class="vm-sec"><h4>Education</h4><div class="vm-sec-body">'+row('Highest Education',s.highest_education)+row('Institution',s.institution)+row('Field of Study',s.field_of_study)+row('Graduation Year',s.graduation_year)+row('Certifications',s.certifications)+'</div></div>'+
      '<div class="vm-sec"><h4>Previous Experience</h4><div class="vm-sec-body">'+row('Company',s.prev_company)+row('Position',s.prev_position)+row('Duration',s.prev_duration)+row('Responsibilities',s.prev_responsibilities)+'</div></div>'+
      '<div class="vm-sec"><h4>Guarantor</h4><div class="vm-sec-body">'+row('Name',s.guarantor_name)+row('Relationship',s.guarantor_relationship)+row('Occupation',s.guarantor_occupation)+row('Phone',s.guarantor_phone)+row('Address',s.guarantor_address)+'</div></div>'+
      '<div class="vm-sec"><h4>Banking &amp; Statutory</h4><div class="vm-sec-body">'+row('Bank',s.bank_name)+row('Account No',s.bank_account_no)+row('Account Name',s.bank_account_name)+row('Tax ID',s.tax_id)+row('Pension PIN',s.pension_pin)+row('NHIS',s.nhis_number)+'</div></div>'+
      '<div class="vm-sec wide"><h4>Emergency Contact &amp; Next of Kin</h4><div class="vm-sec-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">'+row('Emergency Contact',s.emergency_contact_name)+row('EC Phone',s.emergency_contact_phone)+row('EC Relationship',s.emergency_contact_relation)+row('Next of Kin',s.next_of_kin)+row('NOK Phone',s.next_of_kin_phone)+'</div></div>'+
      (s.notes&&s.notes!=='&mdash;'?'<div class="vm-sec wide"><h4>Notes</h4><div class="vm-sec-body" style="font-size:12.5px;color:var(--ink2);line-height:1.5">'+v(s.notes)+'</div></div>':'')+
    '</div>'+
    '<div class="vm-actions"><button class="btn btn-ghost" onclick="closeView()">Close</button><button class="btn btn-primary" onclick="printProfile('+i+')"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Full Profile</button></div>';
  $('viewModal').classList.add('open');
}
function closeView(){$('viewModal').classList.remove('open')}
$('viewModal').addEventListener('click',e=>{if(e.target===$('viewModal'))closeView();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeView();});
document.querySelectorAll('.btn-view').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();openView(+b.dataset.idx);}));
document.querySelectorAll('tbody tr[data-idx]').forEach(tr=>tr.addEventListener('click',()=>openView(+tr.dataset.idx)));

/* ===== PROFILE PRINT ===== */
function printProfile(i){
  const s=staffData[i];if(!s)return;
  const row=(l,val)=>'<div class="psp-row"><span>'+l+'</span><b>'+v(val)+'</b></div>';
  const docId='SP-'+s.staff_code+'-'+new Date().toISOString().slice(0,10).replace(/-/g,'');
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  const photoHtml=s.passport_photo?'<img class="psp-photo" src="'+s.passport_photo+'" alt="">':'<div class="psp-photo-ph">PASSPORT<br>PHOTOGRAPH</div>';
  $('printSheet').innerHTML=
  '<div class="psp">'+
    '<div class="psp-head"><img class="psp-logo" src="'+logo+'" alt="">'+
      '<div class="psp-co"><h1>'+cn+'</h1><p>'+ca+'</p><p>Staff Management &amp; Human Resources Department</p><div class="tag">POVERTY ERADICATION &middot; REDUCE INEQUALITY &middot; LEAVE NO ONE BEHIND</div></div>'+
      '<div class="psp-doc"><h2>OFFICIAL STAFF PROFILE</h2><p>Ref: <span class="ref">'+docId+'</span></p><p>Generated: '+genDate+'</p></div>'+
    '</div>'+
    '<div class="psp-summary">'+photoHtml+
      '<div class="psp-id"><h3>'+v(s.full_name)+'</h3><div class="pos">'+v(s.position)+' &mdash; '+v(s.department)+'</div>'+
      '<div class="psp-idmeta"><span class="psp-chip id">'+s.staff_code+'</span><span class="psp-chip st">'+(s.role||'STAFF').toUpperCase()+'</span><span class="psp-chip st">'+(s.status||'ACTIVE').toUpperCase()+'</span></div></div>'+
    '</div>'+
    '<div class="psp-cols">'+
      '<div class="psp-sec"><h4>A. Personal Information</h4><div class="psp-sec-body">'+row('Full Name',s.full_name)+row('Date of Birth',s.date_of_birth)+row('Gender',s.gender)+row('NIN',s.nin)+row('Nationality',s.nationality)+row('State of Origin',s.state_of_origin)+row('LGA',s.lga)+row('Marital Status',s.marital_status)+row('Religion',s.religion)+row('Blood Group / Genotype',(s.blood_group&&s.blood_group!=='')?(s.blood_group+' / '+(s.genotype||'—')):null)+row('Residential Address',s.address)+'</div></div>'+
      '<div class="psp-sec"><h4>B. Employment Details</h4><div class="psp-sec-body">'+row('Staff ID',s.staff_code)+row('Role',s.role)+row('Position',s.position)+row('Department',s.department)+row('Employment Type',s.employment_type)+row('Monthly Salary',s.salary?'₦'+Number(s.salary).toLocaleString():null)+row('Hire Date',s.hire_date)+row('Status',s.status)+row('Phone',s.phone)+row('Email',s.email)+'</div></div>'+
      '<div class="psp-sec"><h4>C. Education &amp; Qualifications</h4><div class="psp-sec-body">'+row('Highest Education',s.highest_education)+row('Institution',s.institution)+row('Field of Study',s.field_of_study)+row('Graduation Year',s.graduation_year)+row('Certifications',s.certifications)+'</div></div>'+
      '<div class="psp-sec"><h4>D. Previous Work Experience</h4><div class="psp-sec-body">'+row('Previous Company',s.prev_company)+row('Position Held',s.prev_position)+row('Duration',s.prev_duration)+row('Responsibilities',s.prev_responsibilities)+'</div></div>'+
      '<div class="psp-sec"><h4>E. Guarantor Details</h4><div class="psp-sec-body">'+row('Guarantor Name',s.guarantor_name)+row('Relationship',s.guarantor_relationship)+row('Occupation',s.guarantor_occupation)+row('Phone',s.guarantor_phone)+row('Address',s.guarantor_address)+'</div></div>'+
      '<div class="psp-sec"><h4>F. Banking &amp; Statutory</h4><div class="psp-sec-body">'+row('Bank',s.bank_name)+row('Account No',s.bank_account_no)+row('Account Name',s.bank_account_name)+row('Tax ID (TIN)',s.tax_id)+row('Pension PIN',s.pension_pin)+row('NHIS Number',s.nhis_number)+'</div></div>'+
      '<div class="psp-sec wide"><h4>G. Emergency Contact &amp; Next of Kin</h4><div class="psp-sec-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 14pt">'+row('Emergency Contact',s.emergency_contact_name)+row('EC Phone',s.emergency_contact_phone)+row('EC Relationship',s.emergency_contact_relation)+row('Next of Kin',s.next_of_kin)+row('NOK Phone',s.next_of_kin_phone)+'</div></div>'+
    '</div>'+
    (s.notes&&s.notes!=='&mdash;'?'<div class="psp-sec wide" style="margin-top:7pt"><h4>H. Additional Notes</h4><div class="psp-sec-body" style="font-size:7.5pt;color:#444;line-height:1.55">'+v(s.notes)+'</div></div>':'')+
    '<div class="psp-foot"><span>'+cn+' &mdash; CONFIDENTIAL STAFF RECORD</span><span>Doc Ref: '+docId+' &middot; Generated '+genDate+'</span></div>'+
  '</div>';
  window.print();
}
document.querySelectorAll('.btn-pprint').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();printProfile(+b.dataset.idx);}));

/* ===== ROSTER (STAFF LIST) PRINT ===== */
$('btnPrintRoster').addEventListener('click',()=>{
  if(!staffData.length){toast('No staff records to print.','info');return;}
  let rows='';
  staffData.forEach((s,i)=>{
    rows+='<tr><td>'+(i+1)+'</td><td>'+v(s.full_name)+'</td><td>'+s.staff_code+'</td><td>'+v(s.role)+'</td><td>'+v(s.department)+'</td><td>'+v(s.position)+'</td><td>'+v(s.phone)+'</td><td>'+v(s.hire_date)+'</td><td>'+v(s.status)+'</td></tr>';
  });
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  $('printSheet').innerHTML=
  '<div class="rl-head"><img class="rl-logo" src="'+logo+'" alt="">'+
    '<div class="rl-co"><h1>'+cn+'</h1><p>'+ca+'</p><p>Staff Management &amp; Human Resources Department</p></div>'+
    '<div class="rl-doc"><h2>OFFICIAL STAFF LIST</h2><p>Generated: '+genDate+'</p><p>Records: '+staffData.length+'</p></div>'+
  '</div>'+
  '<div class="rl-summary">Total staff on record: <b>'+staffData.length+'</b> &middot; Active: <b>'+staffData.filter(s=>(s.status||'Active')==='Active').length+'</b> &middot; Suspended: <b>'+staffData.filter(s=>s.status==='Suspended').length+'</b> &middot; Resigned: <b>'+staffData.filter(s=>s.status==='Resigned').length+'</b></div>'+
  '<table class="rl-table"><thead><tr><th>#</th><th>Full Name</th><th>Staff ID</th><th>Role</th><th>Department</th><th>Position</th><th>Phone</th><th>Hired</th><th>Status</th></tr></thead><tbody>'+rows+'</tbody></table>'+
  '<div class="rl-foot"><span>'+cn+' &mdash; CONFIDENTIAL &middot; FOR INTERNAL USE ONLY</span><span>Prepared by: ______________________ &nbsp;&nbsp; Signature: ______________________</span></div>';
  window.print();
});

/* read-only notice on first load */
window.addEventListener('DOMContentLoaded',()=>{ /* silent — badge already visible */ });
</script>
</body>
</html>