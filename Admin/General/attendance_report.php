<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'],['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Human Resources & Attendance','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

/* ensure attendance table exists */
try{ $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,staff_id INT UNSIGNED NOT NULL,attendance_date DATE NOT NULL,status ENUM('Present','Late','Absent','Leave') DEFAULT 'Present',check_in TIME NULL,check_out TIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_att(staff_id,attendance_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }catch(Exception $ignore){}

/* ===== 14-DAY TREND ===== */
$trend=[];
try{
    $rows=$pdo->query("SELECT attendance_date,
        SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN status='Late' THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN status='Absent' THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN status='Leave' THEN 1 ELSE 0 END) AS leav
        FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY attendance_date ORDER BY attendance_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)$trend[$r['attendance_date']]=$r;
}catch(Exception $ignore){}
$trendLabels=[];$tPresent=[];$tLate=[];$tAbsent=[];$tLeave=[];
for($i=13;$i>=0;$i--){
    $d=date('Y-m-d',strtotime("-$i days"));
    $trendLabels[]=date('d M',strtotime($d));
    $r=$trend[$d]??null;
    $tPresent[]=(int)($r['present']??0);$tLate[]=(int)($r['late']??0);
    $tAbsent[]=(int)($r['absent']??0);$tLeave[]=(int)($r['leav']??0);
}

/* ===== TODAY DISTRIBUTION ===== */
$todayDist=['Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0];
try{
    $rows=$pdo->query("SELECT status,COUNT(*) AS cnt FROM attendance WHERE attendance_date=CURDATE() GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)$todayDist[$r['status']]=(int)$r['cnt'];
}catch(Exception $ignore){}
$todayMarked=array_sum($todayDist);
$todayPresent=$todayDist['Present']+$todayDist['Late'];
$todayRate=$todayMarked>0?round($todayPresent/$todayMarked*100):0;

/* ===== DEPARTMENT ATTENDANCE (today) ===== */
$deptRows=[];
try{
    $deptRows=$pdo->query("SELECT IFNULL(NULLIF(s.department,''),'General') AS dept,
        COUNT(a.id) AS marked,
        SUM(CASE WHEN a.status IN ('Present','Late') THEN 1 ELSE 0 END) AS present
        FROM attendance a LEFT JOIN staff s ON s.id=a.staff_id
        WHERE a.attendance_date=CURDATE() GROUP BY dept ORDER BY marked DESC")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $ignore){}
$deptLabels=[];$deptPresent=[];$deptMarked=[];
foreach($deptRows as $d){$deptLabels[]=$d['dept'];$deptPresent[]=(int)$d['present'];$deptMarked[]=(int)$d['marked'];}

/* ===== INDIVIDUAL ATTENDANCE (this month) ===== */
$individuals=[];
try{
    $individuals=$pdo->query("SELECT s.id,s.full_name,s.staff_code,s.department,s.position,s.passport_photo,
        COUNT(a.id) AS total_days,
        SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN a.status='Late' THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN a.status='Absent' THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN a.status='Leave' THEN 1 ELSE 0 END) AS leav
        FROM staff s LEFT JOIN attendance a ON a.staff_id=s.id AND MONTH(a.attendance_date)=MONTH(CURDATE()) AND YEAR(a.attendance_date)=YEAR(CURDATE())
        WHERE s.status='Active' GROUP BY s.id ORDER BY s.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $ignore){}

/* daily records this month (for individual modal) */
$monthly=[];
try{
    $rows=$pdo->query("SELECT staff_id,attendance_date,status FROM attendance WHERE MONTH(attendance_date)=MONTH(CURDATE()) AND YEAR(attendance_date)=YEAR(CURDATE())")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)$monthly[$r['staff_id']][]= ['d'=>$r['attendance_date'],'s'=>$r['status']];
}catch(Exception $ignore){}

$totalActive=count($individuals);
$monthPresent=0;$monthLate=0;$monthAbsent=0;$monthLeave=0;$monthMarked=0;
foreach($individuals as $ind){$monthPresent+=(int)$ind['present'];$monthLate+=(int)$ind['late'];$monthAbsent+=(int)$ind['absent'];$monthLeave+=(int)$ind['leav'];$monthMarked+=(int)$ind['total_days'];}
$monthRate=$monthMarked>0?round(($monthPresent+$monthLate)/$monthMarked*100):0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Reports | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
/* stats */
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
/* chart cards */
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.card-body{padding:20px}
.chart-wrap{position:relative;height:300px}
.chart-wrap.sm{height:260px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
/* legend chips */
.legend-chips{display:flex;gap:14px;flex-wrap:wrap;margin-top:14px;justify-content:center}
.lc{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2)}
.lc i{width:12px;height:12px;border-radius:3px;display:inline-block}
/* rate ring */
.rate-ring-wrap{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;padding:10px 0}
.rate-big{font-family:var(--fd);font-size:40px;font-weight:800;color:var(--green)}
/* individual table */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:760px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.staff-cell{display:flex;align-items:center;gap:12px}
.staff-photo{width:40px;height:40px;border-radius:10px;object-fit:cover;border:1.5px solid var(--line);background:var(--surface2);flex:none}
.staff-photo.placeholder{display:grid;place-items:center;color:var(--ink2)}
.staff-photo.placeholder svg{width:20px;height:20px}
.sc-name{font-weight:800;display:block}.sc-sub{font-size:11px;color:var(--ink2);font-weight:600}
.rate-bar{height:9px;border-radius:99px;background:var(--line);overflow:hidden;min-width:80px}
.rate-bar div{height:100%;border-radius:99px}
.mini-pills{display:flex;gap:5px;flex-wrap:wrap}
.mp{font-size:10px;font-weight:800;padding:3px 8px;border-radius:99px}
.mp.p{background:var(--green-soft);color:var(--green)}
.mp.l{background:var(--gold-soft);color:var(--gold2)}
.mp.a{background:var(--red-soft);color:var(--red)}
.mp.lv{background:var(--blue-soft);color:var(--blue)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 16px;height:40px;transition:.2s;cursor:pointer}
.btn svg{width:15px;height:15px}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff}
.btn-primary:hover{filter:brightness(1.07)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
/* filter */
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:20px 0 16px;box-shadow:var(--shadow-sm)}
.ff{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}
.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ff input,.ff select{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}
.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty svg{width:48px;height:48px;stroke:var(--ink2);margin:0 auto 10px;opacity:.5}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}
/* modal */
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}
.modal-overlay.open{display:flex;animation:fadein .25s}
@keyframes fadein{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:600px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}
@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}
.m-header{padding:22px 26px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--surface);z-index:2}
.m-header h3{font-family:var(--fd);font-size:18px;font-weight:800;display:flex;align-items:center;gap:9px}
.m-x{width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:17px;cursor:pointer;display:grid;place-items:center}
.m-body{padding:22px 26px 26px}
/* calendar grid */
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:12px}
.cal-day{aspect-ratio:1;border-radius:8px;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--surface2);border:1px solid var(--line);color:var(--ink2)}
.cal-day.present{background:var(--green-soft);color:var(--green)}
.cal-day.late{background:var(--gold-soft);color:var(--gold2)}
.cal-day.absent{background:var(--red-soft);color:var(--red)}
.cal-day.leave{background:var(--blue-soft);color:var(--blue)}
.cal-day.empty{background:transparent;border-color:transparent}
.cal-legend{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:768px){.topbar .badge{display:none}.brand strong{font-size:12px}.stats{grid-template-columns:1fr 1fr;gap:10px}.filter-bar{flex-direction:column}.chart-wrap{height:240px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $isAdmin?'admin_general_dashboard.php':'staff_first_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Back</a>
  <span class="badge admin">REPORTS</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div><h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Attendance <em>Reports</em></h1><p>General and individual attendance analytics, trends, and statistics.</p></div>
    <button class="btn btn-ghost" onclick="window.print()"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Report</button>
  </div>

  <!-- GENERAL STATS -->
  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><b><?php echo $totalActive; ?></b><span>Active Staff</span></div></div>
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div><div><b><?php echo $todayPresent; ?></b><span>Present Today</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><b><?php echo $todayRate; ?>%</b><span>Today's Rate</span></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg></div><div><b><?php echo $monthRate; ?>%</b><span>Monthly Rate</span></div></div>
  </div>

  <!-- TREND CHART -->
  <div class="card" style="margin-top:20px">
    <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 13 3 3 7-8"/></svg>Attendance Trend — Last 14 Days</h2></div>
    <div class="card-body"><div class="chart-wrap"><canvas id="trendChart"></canvas></div>
      <div class="legend-chips">
        <span class="lc"><i style="background:var(--green)"></i>Present</span>
        <span class="lc"><i style="background:var(--gold)"></i>Late</span>
        <span class="lc"><i style="background:var(--red)"></i>Absent</span>
        <span class="lc"><i style="background:var(--blue)"></i>Leave</span>
      </div>
    </div>
  </div>

  <!-- DISTRIBUTION + DEPARTMENT -->
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>Today's Distribution</h2></div>
      <div class="card-body">
        <div class="chart-wrap sm"><canvas id="distChart"></canvas></div>
        <div class="rate-ring-wrap"><div class="rate-big"><?php echo $todayRate; ?>%</div><span style="font-size:12px;font-weight:700;color:var(--ink2)">Attendance rate today (<?php echo $todayMarked; ?> marked)</span></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="8" width="3" height="10"/><rect x="12" y="5" width="3" height="13"/><rect x="17" y="11" width="3" height="7"/></svg>Attendance by Department (Today)</h2></div>
      <div class="card-body"><div class="chart-wrap sm"><canvas id="deptChart"></canvas></div></div>
    </div>
  </div>

  <!-- INDIVIDUAL ATTENDANCE -->
  <div class="filter-bar" style="margin-top:20px">
    <div class="ff"><label>Search Staff</label><input type="text" id="indSearch" placeholder="Search name or staff ID…"></div>
    <div class="ff" style="max-width:200px"><label>Department</label><select id="indDept"><option value="">All Departments</option><?php $depts=[];foreach($individuals as $ind){$d=$ind['department']?:'General';if(!in_array($d,$depts))$depts[]=$d;}foreach($depts as $d): ?><option><?php echo e($d); ?></option><?php endforeach; ?></select></div>
    <div style="display:flex;gap:8px;align-items:end"><span style="font-size:12px;font-weight:700;color:var(--ink2)"><?php echo count($individuals); ?> staff this month</span></div>
  </div>

  <div class="card">
    <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Individual Attendance — This Month</h2></div>
    <div class="table-wrap"><table id="indTable">
      <thead><tr><th>Staff Member</th><th>Department</th><th>Days Marked</th><th>Attendance</th><th>Rate</th><th>Breakdown</th><th>Action</th></tr></thead>
      <tbody>
        <?php if(empty($individuals)): ?>
          <tr><td colspan="7"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><p>No staff records</p><small>Add staff and mark attendance to see reports.</small></div></td></tr>
        <?php else: foreach($individuals as $ind):
          $tot=(int)$ind['total_days'];$p=(int)$ind['present'];$l=(int)$ind['late'];$a=(int)$ind['absent'];$lv=(int)$ind['leav'];
          $rate=$tot>0?round(($p+$l)/$tot*100):0;
          $barColor=$rate>=80?'var(--green)':($rate>=50?'var(--gold)':'var(--red)');
          $photo=!empty($ind['passport_photo'])?$ind['passport_photo']:'';
        ?>
          <tr data-name="<?php echo strtolower(e($ind['full_name'].' '.$ind['staff_code'])); ?>" data-dept="<?php echo e($ind['department']?:'General'); ?>">
            <td><div class="staff-cell"><?php if($photo): ?><img class="staff-photo" src="<?php echo e($photo); ?>" alt=""><?php else: ?><div class="staff-photo placeholder"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><?php endif; ?><div><span class="sc-name"><?php echo e($ind['full_name']); ?></span><span class="sc-sub"><?php echo e($ind['staff_code']); ?> · <?php echo e($ind['position']??''); ?></span></div></div></td>
            <td><?php echo e($ind['department']?:'General'); ?></td>
            <td class="mono" style="font-weight:700"><?php echo $tot; ?></td>
            <td><div class="rate-bar"><div style="width:<?php echo $rate; ?>%;background:<?php echo $barColor; ?>"></div></div></td>
            <td class="mono" style="font-weight:700;color:<?php echo $barColor; ?>"><?php echo $rate; ?>%</td>
            <td><div class="mini-pills"><span class="mp p">P <?php echo $p; ?></span><span class="mp l">L <?php echo $l; ?></span><span class="mp a">A <?php echo $a; ?></span><span class="mp lv">Lv <?php echo $lv; ?></span></div></td>
            <td><button class="btn btn-ghost btn-sm btn-view" data-id="<?php echo (int)$ind['id']; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>View</button></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<!-- INDIVIDUAL MODAL -->
<div class="modal-overlay" id="indModal"><div class="modal">
  <div class="m-header"><h3 id="indModalTitle">Staff Attendance</h3><button class="m-x" onclick="closeModal('indModal')">&times;</button></div>
  <div class="m-body" id="indModalBody"></div>
</div></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Attendance Reports</div></footer>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function openModal(id){$(id).classList.add('open')}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* chart colors */
const C={green:'#166e45',gold:'#d99a2b',red:'#c0392b',blue:'#2563eb',greenL:'#31c381',goldL:'#f0b04a',redL:'#ff6f61',blueL:'#6ea1ff'};
const isDark=document.documentElement.dataset.theme==='dark';
const gCol=isDark?C.greenL:C.green, goldCol=isDark?C.goldL:C.gold, rCol=isDark?C.redL:C.red, bCol=isDark?C.blueL:C.blue;
Chart.defaults.font.family="'Manrope',sans-serif";
Chart.defaults.color=isDark?'#8ea396':'#5f6e64';

/* Trend chart */
new Chart($('trendChart'),{type:'line',data:{labels:<?php echo json_encode($trendLabels); ?>,datasets:[
  {label:'Present',data:<?php echo json_encode($tPresent); ?>,borderColor:gCol,backgroundColor:gCol+'33',fill:true,tension:.35,borderWidth:2,pointRadius:3},
  {label:'Late',data:<?php echo json_encode($tLate); ?>,borderColor:goldCol,backgroundColor:goldCol+'22',fill:true,tension:.35,borderWidth:2,pointRadius:3},
  {label:'Absent',data:<?php echo json_encode($tAbsent); ?>,borderColor:rCol,backgroundColor:rCol+'22',fill:true,tension:.35,borderWidth:2,pointRadius:3},
  {label:'Leave',data:<?php echo json_encode($tLeave); ?>,borderColor:bCol,backgroundColor:bCol+'22',fill:true,tension:.35,borderWidth:2,pointRadius:3}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

/* Distribution donut */
new Chart($('distChart'),{type:'doughnut',data:{labels:['Present','Late','Absent','Leave'],datasets:[{data:[<?php echo $todayDist['Present']; ?>,<?php echo $todayDist['Late']; ?>,<?php echo $todayDist['Absent']; ?>,<?php echo $todayDist['Leave']; ?>],backgroundColor:[gCol,goldCol,rCol,bCol],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom'}}}});

/* Department bar */
new Chart($('deptChart'),{type:'bar',data:{labels:<?php echo json_encode($deptLabels); ?>,datasets:[
  {label:'Marked',data:<?php echo json_encode($deptMarked); ?>,backgroundColor:(isDark?'#31493d':'#dfe5da'),borderRadius:6},
  {label:'Present',data:<?php echo json_encode($deptPresent); ?>,backgroundColor:gCol,borderRadius:6}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});

/* individual data for modal */
const individuals=<?php echo json_encode(array_map(function($i){return['id'=>(int)$i['id'],'name'=>$i['full_name'],'code'=>$i['staff_code'],'dept'=>$i['department']?:'General','pos'=>$i['position']??'','tot'=>(int)$i['total_days'],'p'=>(int)$i['present'],'l'=>(int)$i['late'],'a'=>(int)$i['absent'],'lv'=>(int)$i['leav']];},$individuals),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const monthly=<?php echo json_encode($monthly,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

function statusCls(s){return s==='Present'?'present':(s==='Late'?'late':(s==='Absent'?'absent':'leave'));}
document.querySelectorAll('.btn-view').forEach(b=>b.addEventListener('click',()=>{
  const ind=individuals.find(x=>x.id==b.dataset.id);if(!ind)return;
  const recs=monthly[ind.id]||[];
  const map={};recs.forEach(r=>{map[r.d]=r.s;});
  const now=new Date();const y=now.getFullYear(),m=now.getMonth();
  const daysInMonth=new Date(y,m+1,0).getDate();
  const firstDow=new Date(y,m,1).getDay();
  let cal='';
  for(let i=0;i<firstDow;i++)cal+='<div class="cal-day empty"></div>';
  for(let d=1;d<=daysInMonth;d++){
    const ds=y+'-'+String(m+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const st=map[ds];
    cal+='<div class="cal-day '+(st?statusCls(st):'')+'" title="'+ds+(st?' — '+st:'')+'">'+d+'</div>';
  }
  const rate=ind.tot>0?Math.round((ind.p+ind.l)/ind.tot*100):0;
  $('indModalTitle').textContent=ind.name+' — Attendance';
  $('indModalBody').innerHTML=
    '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px"><span class="mp p">Present: '+ind.p+'</span><span class="mp l">Late: '+ind.l+'</span><span class="mp a">Absent: '+ind.a+'</span><span class="mp lv">Leave: '+ind.lv+'</span></div>'+
    '<div style="font-size:13px;font-weight:700;color:var(--ink2);margin-bottom:4px">'+ind.pos+' · '+ind.dept+' · Attendance rate: <b style="color:var(--green)">'+rate+'%</b> ('+ind.tot+' days marked)</div>'+
    '<div class="cal">'+cal+'</div>'+
    '<div class="cal-legend"><span class="lc"><i style="background:var(--green)"></i>Present</span><span class="lc"><i style="background:var(--gold)"></i>Late</span><span class="lc"><i style="background:var(--red)"></i>Absent</span><span class="lc"><i style="background:var(--blue)"></i>Leave</span></div>';
  openModal('indModal');
}));

/* filter individuals */
function filterInd(){
  const q=$('indSearch').value.toLowerCase();const d=$('indDept').value;
  document.querySelectorAll('#indTable tbody tr').forEach(tr=>{
    const matchQ=!q||tr.dataset.name.includes(q);
    const matchD=!d||tr.dataset.dept===d;
    tr.style.display=(matchQ&&matchD)?'':'none';
  });
}
$('indSearch').addEventListener('input',filterInd);
$('indDept').addEventListener('change',filterInd);
</script>
</body>
</html>