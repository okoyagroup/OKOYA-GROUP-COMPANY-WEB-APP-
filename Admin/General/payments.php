<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'],['admin','ceo','general manager']);
if (!$isAdmin) { header('Location: login.php'); exit; }   // payroll is admin-only

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Human Resources & Payroll Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}

/* Nigerian bank name → CBN BankCode mapping (fuzzy match in JS) */
$bankCodes = [
  'Access Bank'=>'044','Access Bank Plc'=>'044',
  'ACCESS (Diamond) BANK'=>'063','Access (Diamond) Bank'=>'063','Diamond Bank'=>'063','Diamond Bank Plc'=>'063',
  'AccessMoney'=>'044',
  'Citi Bank'=>'023','Citibank'=>'023','Citibank Nigeria'=>'023',
  'Ecobank'=>'050','Ecobank Bank'=>'050','Ecobank Nigeria'=>'050','Ecobank Xpress Account'=>'050',
  'FCMB'=>'214','First City Monument Bank'=>'214','FCMB Easy Account'=>'214',
  'Fidelity Bank'=>'070','Fidelity Bank Plc'=>'070','Fidelity Mobile'=>'070',
  'First Bank of Nigeria'=>'011','First Bank'=>'011','First Bank of Nigeria Plc'=>'011',
  'GTBank Plc'=>'058','GTBank'=>'058','Guaranty Trust Bank'=>'058','Gtbank'=>'058','GTMobile'=>'058',
  'Globus Bank'=>'027','Globus Bank Ltd'=>'027',
  'Heritage'=>'030','Heritage Bank'=>'030','Heritage Bank Plc'=>'030',
  'POLARIS BANK'=>'076','Polaris Bank'=>'076','Polaris Bank Limited'=>'076',
  'Providus Bank'=>'101','Providus Bank Limited'=>'101',
  'SUNTRUST BANK'=>'100','Suntrust Bank'=>'100','SunTrust Bank Nigeria'=>'100',
  'StanbicIBTC Bank'=>'221','Stanbic IBTC Bank'=>'221','Stanbic IBTC'=>'221','StanbicIBTC'=>'221',
  'StandardChartered'=>'068','Standard Chartered'=>'068','Standard Chartered Bank'=>'068',
  'Sterling Bank'=>'232','Sterling Bank Plc'=>'232',
  'TITAN TRUST BANK'=>'100','Titan Trust Bank'=>'100','Titan Bank'=>'100',
  'Taj Bank'=>'100','TajBank'=>'100',
  'Union Bank'=>'032','Union Bank of Nigeria'=>'032','Union Bank Plc'=>'032',
  'Unity Bank'=>'215','Unity Bank Plc'=>'215',
  'Wema Bank'=>'035','Wema Bank Plc'=>'035',
  'ZENITH BANK PLC'=>'057','Zenith Bank'=>'057','Zenith Bank Plc'=>'057',
  'Flutterwave Technology solutions Limited'=>'FLW',
  'ACCESS YELLO AND BETA'=>'044','Contec Global (Now Now)'=>'NOW','FET'=>'FET','FortisMobile'=>'FMT','GoMoney'=>'GOM','Hedonmark'=>'HED','Innovectives Kesh'=>'KES','Kegow'=>'KEG'
];

/* FETCH EMPLOYED STAFF (active, salary info) */
$staff=[];
try{
    $st=$pdo->query("SELECT s.*, r.name AS role_name
                     FROM staff s
                     LEFT JOIN users u ON u.id=s.user_id
                     LEFT JOIN roles r ON r.id=u.role_id
                     WHERE s.status='Active'
                     ORDER BY s.full_name ASC");
    $staff=$st->fetchAll();
}catch(Exception $ignore){}

/* stats */
$totalEmp=count($staff);
$totalSalary=0.0;$withBank=0;$noBank=0;
foreach($staff as $s){ $totalSalary+=(float)($s['salary']??0); if(!empty($s['bank_account_no']))$withBank++; else $noBank++; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Salary Payments | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<!-- SheetJS for genuine .xlsx export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
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
/* export bar */
.export-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:18px 20px;margin-top:20px;box-shadow:var(--shadow-sm)}
.export-bar h3{font-family:var(--fd);font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;margin-bottom:14px}
.export-bar h3 svg{width:17px;height:17px;stroke:var(--green)}
.export-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px}
.efield{display:flex;flex-direction:column;gap:5px}
.efield label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ctrl{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--line);border-radius:11px;padding:0 12px;height:44px;transition:.2s}
.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent);background:var(--surface)}
.ctrl input,.ctrl select{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%}
.ctrl select{cursor:pointer;appearance:none}
.export-actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn svg{width:15px;height:15px}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-excel{background:linear-gradient(135deg,#107c41,#0a5c2f);color:#fff;box-shadow:0 4px 12px rgba(16,124,65,.3)}
.btn-excel:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-csv{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#fff;box-shadow:0 4px 12px rgba(217,154,43,.3)}
.btn-csv:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
/* filter */
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:20px 0 16px;box-shadow:var(--shadow-sm)}
.ff{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}
.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ff input,.ff select{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}
.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:900px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.staff-cell{display:flex;align-items:center;gap:12px}
.staff-photo{width:40px;height:40px;border-radius:10px;object-fit:cover;border:1.5px solid var(--line);background:var(--surface2);flex:none}
.staff-photo.placeholder{display:grid;place-items:center;color:var(--ink2)}
.staff-photo.placeholder svg{width:20px;height:20px}
.sc-name{font-weight:800;display:block}.sc-sub{font-size:11px;color:var(--ink2);font-weight:600}
.st-pill{font-size:10px;font-weight:800;padding:4px 10px;border-radius:99px;display:inline-block}
.st-pill.bank{background:var(--green-soft);color:var(--green)}
.st-pill.nobank{background:var(--red-soft);color:var(--red)}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty svg{width:48px;height:48px;stroke:var(--ink2);margin:0 auto 10px;opacity:.5}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}
.sel-cb{width:18px;height:18px;accent-color:var(--green);cursor:pointer}
.empty-note{font-size:12px;color:var(--ink2);font-weight:600;margin-top:8px}
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.export-grid{grid-template-columns:1fr}}
@media(max-width:768px){.topbar .badge{display:none}.brand strong{font-size:12px}.stats{grid-template-columns:1fr 1fr;gap:10px}.filter-bar{flex-direction:column}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="admin_general_dashboard.php" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back</a>
  <span class="badge admin">PAYROLL</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div><h1><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Salary <em>Payments</em></h1><p>Review all employed staff salaries and export bulk payment files for bank disbursement.</p></div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><b><?php echo $totalEmp; ?></b><span>Employed Staff</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div><b><?php echo fmtN($totalSalary); ?></b><span>Total Monthly Payroll</span></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div><div><b><?php echo $withBank; ?></b><span>With Bank Details</span></div></div>
    <div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><div><b><?php echo $noBank; ?></b><span>Missing Bank Details</span></div></div>
  </div>

  <!-- EXPORT BAR -->
  <div class="export-bar">
    <h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export Bulk Payment File</h3>
    <div class="export-grid">
      <div class="efield"><label>Payment Period / Narration</label><div class="ctrl"><input type="text" id="narration" value="Salary Payment - <?php echo date('F Y'); ?>"></div></div>
      <div class="efield"><label>Include Staff</label><div class="ctrl"><select id="includeSel"><option value="selected">Only Selected Staff</option><option value="all" selected>All Employed Staff</option><option value="withbank">Only With Bank Details</option></select></div></div>
      <div class="efield"><label>File Format</label><div class="ctrl"><select id="formatNote" disabled><option>Choose an export button below</option></select></div></div>
    </div>
    <div class="export-actions">
      <button class="btn btn-csv" id="btnCsv"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Export CSV (Bulk Payment Template)</button>
      <button class="btn btn-excel" id="btnXlsx"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 13 6 6"/><path d="m15 13-6 6"/></svg>Export Excel (Bulk List Sample)</button>
      <button class="btn btn-ghost" id="btnPrint"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Payroll Sheet</button>
    </div>
    <div class="empty-note">Tip: tick the checkboxes below to select specific staff, or use "All Employed Staff". Files are formatted to match the bank's bulk upload templates exactly.</div>
  </div>

  <!-- FILTER -->
  <form method="get" class="filter-bar" id="filterForm">
    <div class="ff"><label>Search</label><input type="text" name="q" placeholder="Search name or staff ID…" value="<?php echo e($_GET['q']??''); ?>"></div>
    <div style="display:flex;gap:8px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button><a href="salary_payments.php" class="btn btn-ghost">Clear</a></div>
  </form>

  <!-- STAFF TABLE -->
  <div class="card">
    <div class="card-head">
      <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Employed Staff &amp; Salary Credentials</h2>
      <span class="count-pill"><?php echo count($staff); ?> staff</span>
    </div>
    <div class="table-wrap"><table id="staffTable">
      <thead><tr><th style="width:40px"><input type="checkbox" class="sel-cb" id="selAll"></th><th>Staff Member</th><th>Staff ID</th><th>Position</th><th>Department</th><th>Bank</th><th>Account No</th><th>Monthly Salary</th><th>Bank Status</th></tr></thead>
      <tbody>
        <?php if(empty($staff)): ?>
          <tr><td colspan="9"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><p>No employed staff found</p><small>Add staff in the Staff Directory to see them here.</small></div></td></tr>
        <?php else: foreach($staff as $s):
          $photo=!empty($s['passport_photo'])?$s['passport_photo']:'';
          $hasBank=!empty($s['bank_account_no']);
        ?>
          <tr>
            <td><input type="checkbox" class="sel-cb row-cb" data-id="<?php echo (int)$s['id']; ?>"></td>
            <td><div class="staff-cell"><?php if($photo): ?><img class="staff-photo" src="<?php echo e($photo); ?>" alt=""><?php else: ?><div class="staff-photo placeholder"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><?php endif; ?><div><span class="sc-name"><?php echo e($s['full_name']); ?></span><span class="sc-sub"><?php echo e($s['email']??''); ?></span></div></div></td>
            <td class="mono" style="color:var(--green);font-weight:700"><?php echo e($s['staff_code']); ?></td>
            <td><?php echo e($s['position']??'—'); ?></td>
            <td><?php echo e($s['department']??'—'); ?></td>
            <td><?php echo e($s['bank_name']??'—'); ?></td>
            <td class="mono"><?php echo e($s['bank_account_no']??'—'); ?></td>
            <td class="mono" style="color:var(--green);font-weight:700"><?php echo fmtN($s['salary']??0); ?></td>
            <td><?php echo $hasBank?'<span class="st-pill bank">Bank Ready</span>':'<span class="st-pill nobank">No Bank</span>'; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Payroll &amp; Salary Disbursement</div></footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+esc(msg);$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),4000);}

/* ===== STAFF DATA (embedded) ===== */
const staffData=<?php echo json_encode(array_map(function($s){return[
  'id'=>(int)$s['id'],'name'=>$s['full_name'],'staff_code'=>$s['staff_code'],
  'position'=>$s['position']??'','department'=>$s['department']??'',
  'bank_name'=>$s['bank_name']??'','bank_account_no'=>$s['bank_account_no']??'',
  'bank_account_name'=>$s['bank_account_name']??'','salary'=>(float)($s['salary']??0)
];},$staff),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

/* ===== BANK CODE MAP ===== */
const bankCodes=<?php echo json_encode($bankCodes,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
function findBankCode(bankName){
  if(!bankName)return '';
  const b=bankName.trim();
  if(bankCodes[b])return bankCodes[b];
  const lb=b.toLowerCase();
  for(const k in bankCodes){ if(k.toLowerCase()===lb)return bankCodes[k]; }
  for(const k in bankCodes){ if(lb.includes(k.toLowerCase())||k.toLowerCase().includes(lb))return bankCodes[k]; }
  return '';
}

/* select-all */
$('selAll').addEventListener('change',e=>{document.querySelectorAll('.row-cb').forEach(c=>c.checked=e.target.checked);});

function getExportList(){
  const mode=$('includeSel').value;
  let list=staffData;
  if(mode==='withbank')list=list.filter(s=>s.bank_account_no&&s.salary>0);
  else if(mode==='selected'){
    const ids=[...document.querySelectorAll('.row-cb:checked')].map(c=>+c.dataset.id);
    if(ids.length)list=list.filter(s=>ids.includes(s.id));
    else list=list.filter(s=>s.bank_account_no&&s.salary>0); // none selected → fallback to bank-ready
  } else {
    list=list.filter(s=>s.salary>0);
  }
  return list;
}

/* ===== CSV EXPORT (Bulk-Payment-Upload-Template.csv exact format) ===== */
$('btnCsv').addEventListener('click',()=>{
  const list=getExportList();
  if(!list.length){toast('No staff with salary & bank details to export.','error');return;}
  const narration=$('narration').value.trim()||'Salary Payment';
  const header=['Reciever Name','Reciever Account No','Amount','Sender Narration',"Receiever's Narration",'BankCode'];
  const rows=list.map(s=>[s.name,s.bank_account_no,s.salary.toFixed(2),narration,s.name,findBankCode(s.bank_name)]);
  const csvContent=[header.join(','),...rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(','))].join('\r\n');
  const blob=new Blob(["\uFEFF"+csvContent],{type:'text/csv;charset=utf-8;'});
  const a=document.createElement('a');a.href=URL.createObjectURL(blob);
  a.download='Bulk-Payment-Upload-'+new Date().toISOString().slice(0,10)+'.csv';a.click();URL.revokeObjectURL(a.href);
  toast('CSV exported ('+list.length+' staff).','success');
});

/* ===== XLSX EXPORT (Bulk List Excel Sample - Nigeria exact format) ===== */
$('btnXlsx').addEventListener('click',()=>{
  const list=getExportList();
  if(!list.length){toast('No staff with salary & bank details to export.','error');return;}
  const header=['Receiver Full Name','Account no/Mobile no/Card no','Card Client Id','Wallet Provider','Amount in NGN','Bank Name/Institution Name'];
  const rows=list.map(s=>[s.name,s.bank_account_no,'','',s.salary,s.bank_name]);
  const ws=XLSX.utils.aoa_to_sheet([
    ['Salary Disbursement Sample'],
    header,
    ...rows
  ]);
  ws['!cols']=[{wch:32},{wch:22},{wch:16},{wch:16},{wch:16},{wch:30}];
  const wb=XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb,ws,'Salary Disbursement');
  XLSX.writeFile(wb,'Bulk-List-Salary-'+new Date().toISOString().slice(0,10)+'.xlsx');
  toast('Excel exported ('+list.length+' staff).','success');
});

/* ===== PRINT PAYROLL SHEET ===== */
$('btnPrint').addEventListener('click',()=>{
  const list=staffData;
  if(!list.length){toast('No staff to print.','error');return;}
  const tot=list.reduce((a,s)=>a+s.salary,0);
  const rows=list.map(s=>'<tr><td>'+esc(s.staff_code)+'</td><td>'+esc(s.name)+'</td><td>'+esc(s.position)+'</td><td>'+esc(s.department)+'</td><td>'+esc(s.bank_name||'—')+'</td><td>'+esc(s.bank_account_no||'—')+'</td><td style="text-align:right">₦'+Number(s.salary).toLocaleString()+'</td></tr>').join('');
  const w=window.open('','_blank');
  w.document.write('<html><head><title>Payroll Sheet</title><style>body{font-family:Arial,sans-serif;font-size:11px;color:#1a231d;padding:20px}h1{font-size:16px;color:#166e45;margin:0 0 4px}.co{font-size:10px;color:#5a655c;margin-bottom:12px}table{width:100%;border-collapse:collapse;margin-top:8px}th{background:#166e45;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:.5px;text-align:left;padding:6px;border:1px solid #166e45}td{padding:5px 6px;border:1px solid #cfd8cd}tr:nth-child(even) td{background:#fafcf9}.tot{font-weight:bold}.foot{margin-top:14px;font-size:9px;color:#9aa79c}</style></head><body>');
  w.document.write('<h1><?php echo e($company['company_name']); ?></h1><div class="co"><?php echo e($company['company_address']); ?> &middot; Payroll Sheet &middot; '+new Date().toLocaleDateString()+'</div>');
  w.document.write('<table><thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Department</th><th>Bank</th><th>Account No</th><th style="text-align:right">Monthly Salary</th></tr></thead><tbody>'+rows+'<tr class="tot"><td colspan="6" style="text-align:right;font-weight:bold">TOTAL</td><td style="text-align:right;font-weight:bold">₦'+Number(tot).toLocaleString()+'</td></tr></tbody></table>');
  w.document.write('<div class="foot">Total Monthly Payroll: ₦'+Number(tot).toLocaleString()+' &middot; '+list.length+' staff &middot; Generated '+new Date().toLocaleString()+'</div>');
  w.document.write('</body></html>');w.document.close();w.focus();setTimeout(()=>w.print(),300);
});
</script>
</body>
</html>