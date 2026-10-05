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
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

$selDate = $_GET['d'] ?? date('Y-m-d');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$selDate)) $selDate=date('Y-m-d');
$monthStr = date('Y-m', strtotime($selDate));

/* official start time — check-ins after this are auto-marked Late */
$startTime = $company['attendance_start_time'] ?? '08:00';
if(!preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$startTime)) $startTime='08:00';
$startShort = substr($startTime,0,5);

/* ===== ENSURE ATTENDANCE TABLE EXISTS ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `staff_id` INT UNSIGNED NOT NULL,
      `attendance_date` DATE NOT NULL,
      `status` ENUM('Present','Late','Absent','Leave') NOT NULL DEFAULT 'Present',
      `check_in` TIME NULL,
      `check_out` TIME NULL,
      `remarks` VARCHAR(255) NULL,
      `recorded_by` INT UNSIGNED NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uq_att` (`staff_id`,`attendance_date`),
      INDEX `idx_att_date` (`attendance_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $ignore){}

/* ===== MARK ATTENDANCE (upsert with auto Late detection) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_mark') {
    $sid=(int)($_POST['staff_id']??0);
    $date=$_POST['attendance_date']??date('Y-m-d');
    $status=$_POST['status']??'';
    if($sid>0 && in_array($status,['Present','Absent','Leave'])){
        $checkIn = preg_match('/^\d{2}:\d{2}/', $_POST['check_in']??'') ? substr($_POST['check_in'],0,8) : null;
        $checkOut= preg_match('/^\d{2}:\d{2}/', $_POST['check_out']??'') ? substr($_POST['check_out'],0,8) : null;
        $remarks = trim($_POST['remarks'] ?? '');

        /* auto Late/Regular calculation based on Enter time */
        $finalStatus=$status;
        if($status==='Present' && $checkIn!==null){
            if(strtotime("1970-01-01 $checkIn") > strtotime("1970-01-01 $startTime")) $finalStatus='Late';
            else $finalStatus='Present';
        }
        if($status!=='Present'){ $checkIn=null; $checkOut=null; }

        try{
            $pdo->prepare("INSERT INTO attendance (staff_id,attendance_date,status,check_in,check_out,remarks,recorded_by)
                            VALUES (?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE status=VALUES(status), check_in=VALUES(check_in),
                              check_out=VALUES(check_out), remarks=VALUES(remarks), recorded_by=VALUES(recorded_by)")
                ->execute([$sid,$date,$finalStatus,$checkIn,$checkOut,$remarks?:null,$auth['id']]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                ->execute([$auth['id'],'MARK_ATTENDANCE','staff',$sid,"$finalStatus on $date"]);}catch(Exception $ignore){}
            flash('success','Attendance saved — '.($finalStatus==='Late'?'marked Late (after '.$startShort.')':ucfirst(strtolower($finalStatus))).'.');
        }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        header('Location: mark_attendance.php?d='.$date); exit;
    }
}

/* ===== FETCH STAFF WITH PER-PERSON STATS ===== */
$staff=[]; $fetchError=null;
try{
    $sql="SELECT s.id, s.full_name, s.staff_code, s.position, s.department, s.passport_photo,
            (SELECT COUNT(*) FROM attendance a WHERE a.staff_id=s.id AND DATE_FORMAT(a.attendance_date,'%Y-%m')=? AND a.status='Present') AS present_days,
            (SELECT COUNT(*) FROM attendance a WHERE a.staff_id=s.id AND DATE_FORMAT(a.attendance_date,'%Y-%m')=? AND a.status='Late') AS late_days,
            (SELECT COUNT(*) FROM attendance a WHERE a.staff_id=s.id AND DATE_FORMAT(a.attendance_date,'%Y-%m')=? AND a.status='Absent') AS absent_days,
            (SELECT COUNT(*) FROM attendance a WHERE a.staff_id=s.id AND DATE_FORMAT(a.attendance_date,'%Y-%m')=? AND a.status='Leave') AS leave_days,
            (SELECT COUNT(*) FROM attendance a WHERE a.staff_id=s.id AND DATE_FORMAT(a.attendance_date,'%Y-%m')=?) AS marked_days,
            at.status AS today_status, at.check_in, at.check_out, at.remarks
          FROM staff s
          LEFT JOIN attendance at ON at.staff_id=s.id AND at.attendance_date=?
          WHERE (s.status='Active' OR s.status IS NULL OR s.status='')
          ORDER BY s.full_name ASC";
    $st=$pdo->prepare($sql);
    $st->execute([$monthStr,$monthStr,$monthStr,$monthStr,$monthStr,$selDate]);
    $staff=$st->fetchAll();
}catch(Exception $ex){ $fetchError=$ex->getMessage(); }

/* ===== SUMMARY FOR SELECTED DATE ===== */
$totActive=count($staff);
$markedToday=$presentToday=$lateToday=$absentToday=$leaveToday=0;
foreach($staff as $s){
    if($s['today_status']){ $markedToday++;
        if($s['today_status']==='Present')$presentToday++;
        elseif($s['today_status']==='Late')$lateToday++;
        elseif($s['today_status']==='Absent')$absentToday++;
        else $leaveToday++;
    }
}
$attRate = $markedToday>0 ? round(($presentToday+$lateToday)/$markedToday*100) : 0;
$flashData = get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Mark Attendance | <?php echo e($company['company_name']); ?></title>
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
.badge.role{background:var(--gold-soft);color:var(--gold2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

.page-header{padding:30px 0 6px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}
.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.ph-left h1 svg{width:28px;height:28px;stroke:var(--green)}
.ph-left h1 em{font-style:normal;color:var(--green)}
.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.alert{margin-top:14px;background:var(--red-soft);border:1px solid color-mix(in srgb,var(--red) 35%,transparent);color:var(--red);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700}

.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:18px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}
.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}
.stat .ic svg{width:21px;height:21px}
.stat .ic.g{background:var(--green-soft)}.stat .ic.g svg{stroke:var(--green)}
.stat .ic.y{background:var(--gold-soft)}.stat .ic.y svg{stroke:var(--gold2)}
.stat .ic.b{background:var(--blue-soft)}.stat .ic.b svg{stroke:var(--blue)}
.stat .ic.r{background:var(--red-soft)}.stat .ic.r svg{stroke:var(--red)}
.stat b{font-family:var(--fd);font-size:20px;font-weight:800;display:block;line-height:1.15}
.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.stat sub{font-size:10px;color:var(--ink2);font-weight:600}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn svg{width:15px;height:15px}

.date-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:20px 0 16px;box-shadow:var(--shadow-sm)}
.ff{display:flex;flex-direction:column;gap:5px;min-width:170px}
.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.ff input{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}
.ff input:focus{border-color:var(--green);background:var(--surface)}
.start-note{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:var(--ink2);align-self:center;background:var(--gold-soft);border:1px solid color-mix(in srgb,var(--gold) 30%,transparent);border-radius:99px;padding:7px 14px}
.start-note b{color:var(--gold2);font-family:var(--fm)}

.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}
.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:1060px}
thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}
tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}
tbody tr{transition:background .15s}
tbody tr:hover{background:var(--surface2)}
tbody tr:last-child td{border-bottom:none}
.st-cell{display:flex;align-items:center;gap:12px}
.st-av{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:14px;font-weight:800;flex:none;overflow:hidden}
.st-av img{width:100%;height:100%;object-fit:cover}
.st-name{font-weight:800;display:block}
.st-sub{font-size:11px;color:var(--ink2);font-weight:600}

/* monthly stat chips (dots, no emoji) */
.pstats{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ps-chip{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;padding:4px 9px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);white-space:nowrap}
.ps-dot{width:7px;height:7px;border-radius:50%;flex:none}
.ps-chip.p .ps-dot{background:var(--green)}
.ps-chip.l .ps-dot{background:var(--gold)}
.ps-chip.a .ps-dot{background:var(--red)}
.ps-chip.lv .ps-dot{background:var(--blue)}
.rate-wrap{display:flex;align-items:center;gap:8px;min-width:110px}
.rate-bar{flex:1;height:7px;border-radius:99px;background:var(--line);overflow:hidden;min-width:50px}
.rate-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--green),var(--gold))}
.rate-num{font-family:var(--fm);font-size:12px;font-weight:700;min-width:38px;text-align:right}

.day-status{font-size:10px;font-weight:800;padding:4px 10px;border-radius:99px;letter-spacing:.4px;display:inline-block;white-space:nowrap}
.ds-present{background:var(--green-soft);color:var(--green)}
.ds-late{background:var(--gold-soft);color:var(--gold2)}
.ds-absent{background:var(--red-soft);color:var(--red)}
.ds-leave{background:var(--blue-soft);color:var(--blue)}
.ds-none{background:var(--surface2);color:var(--ink2);border:1px dashed var(--line2)}
.remark-chip{display:block;margin-top:4px;font-size:10.5px;color:var(--ink2);font-weight:600;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* mark form in table */
.mark-form{display:flex;flex-direction:column;gap:6px;min-width:230px}
.mf-row{display:flex;gap:6px}
.mf-row .mf-field{flex:1;display:flex;flex-direction:column;gap:2px}
.mf-row .mf-field label{font-size:9px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;color:var(--ink2)}
.mf-input{height:36px;border:1.5px solid var(--line);border-radius:9px;padding:0 9px;background:var(--surface2);font-size:13px;font-weight:600;outline:none;width:100%;transition:.2s}
.mf-input:focus{border-color:var(--green);background:var(--surface)}
.mf-time-wrap{position:relative}
.late-tag{position:absolute;right:7px;top:50%;transform:translateY(-50%);font-size:8.5px;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.4px;pointer-events:none}
.late-tag.ontime{background:var(--green-soft);color:var(--green)}
.late-tag.late{background:var(--gold-soft);color:var(--gold2)}
.mf-save{height:38px;border:none;border-radius:9px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;font-weight:800;font-size:12px;cursor:pointer;transition:.15s;width:100%}
.mf-save:hover{filter:brightness(1.07)}
.empty{padding:50px 20px;text-align:center;color:var(--ink2)}
.empty .big{font-size:42px;margin-bottom:10px}
.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}

.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}

#printSheet{display:none}
@media print{
  @page{size:A4;margin:11mm}
  body{background:#fff!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body::before,#app,.toasts,.topbar{display:none!important}
  #printSheet{display:block!important;color:#1a231d;font-family:var(--fb)}
  .as-head{display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #166e45;padding-bottom:8pt;margin-bottom:10pt}
  .as-logo{width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none}
  .as-co{flex:1}
  .as-co h1{font-family:var(--fd);font-size:13pt;font-weight:800;color:#166e45;line-height:1.15}
  .as-co p{font-size:7pt;color:#5a655c;margin:1.5pt 0 0}
  .as-doc{text-align:right}
  .as-doc h2{font-size:8.5pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#166e45;padding:3.5pt 9pt;border-radius:3pt;display:inline-block}
  .as-doc p{font-size:6.5pt;color:#7a857c;margin-top:3pt}
  .as-sum{display:grid;grid-template-columns:repeat(5,1fr);gap:6pt;margin:9pt 0}
  .as-sumbox{border:1pt solid #cfd8cd;border-radius:5pt;padding:6pt 8pt;text-align:center;background:#fafcf9}
  .as-sumbox b{font-family:var(--fm);font-size:11pt;font-weight:700;display:block;color:#166e45}
  .as-sumbox span{font-size:5.5pt;font-weight:800;letter-spacing:.8pt;color:#667268;text-transform:uppercase}
  .as-table{width:100%;border-collapse:collapse;font-size:7pt;margin-top:6pt}
  .as-table th{background:#166e45;color:#fff;font-size:6pt;font-weight:800;letter-spacing:.5pt;text-transform:uppercase;text-align:left;padding:4.5pt 6pt;border:1pt solid #166e45}
  .as-table td{padding:4pt 6pt;border:1pt solid #cfd8cd;line-height:1.35}
  .as-table tr:nth-child(even) td{background:#fafcf9}
  .as-sigs{display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:18pt;page-break-inside:avoid}
  .as-sig{text-align:center}
  .as-sigline{height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt}
  .as-sig span{font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block}
  .as-foot{margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c}
}
@media(max-width:1020px){.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.date-bar{flex-direction:column}.topbar .badge{display:none}.brand strong{font-size:12px}.stats{grid-template-columns:1fr 1fr;gap:10px}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a>
  <span class="badge role"><?php echo strtoupper(e($auth['role'])); ?></span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-left">
      <h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Mark Daily <em>Attendance</em></h1>
      <p>Record attendance with Enter / Exit times — the system automatically marks Late after <?php echo e($startShort); ?></p>
    </div>
    <button class="btn btn-ghost" id="btnPrintSheet"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Attendance Sheet</button>
  </div>

  <?php if($fetchError): ?>
    <div class="alert">Staff query error: <?php echo e($fetchError); ?></div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><b><?php echo $totActive; ?></b><span>Active Staff</span><sub>on register</sub></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="m9 13 2 2 4-4"/></svg></div><div><b><?php echo $markedToday; ?></b><span>Marked Today</span><sub><?php echo e(date('d M Y',strtotime($selDate))); ?></sub></div></div>
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 13.01 9 11.01"/></svg></div><div><b><?php echo $presentToday; ?></b><span>Present</span><sub>on time</sub></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div><b><?php echo $lateToday; ?></b><span>Late</span><sub>after <?php echo e($startShort); ?></sub></div></div>
    <div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div><div><b><?php echo $absentToday; ?></b><span>Absent</span><sub>not present</sub></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div><div><b><?php echo $attRate; ?>%</b><span>Attendance Rate</span><sub>of marked staff</sub></div></div>
  </div>

  <form method="get" class="date-bar">
    <div class="ff"><label>Attendance Date</label><input type="date" name="d" value="<?php echo e($selDate); ?>"></div>
    <div style="display:flex;gap:8px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Load Date</button><a href="mark_attendance.php" class="btn btn-ghost">Today</a></div>
    <span class="start-note">Official start: <b><?php echo e($startShort); ?></b> — Enter times after this are auto-marked Late</span>
  </form>

  <div class="card">
    <div class="card-head">
      <h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Staff Attendance — <?php echo e(date('l, d M Y',strtotime($selDate))); ?></h2>
      <span class="count-pill"><?php echo $markedToday; ?>/<?php echo $totActive; ?> marked</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Staff Member</th><th>Position / Dept</th><th>Monthly Stats</th><th>Rate</th><th>Today</th><th>Mark Attendance</th></tr></thead>
        <tbody>
          <?php if(empty($staff)): ?>
            <tr><td colspan="6"><div class="empty"><div class="big">📋</div><p>No active staff found</p><small>Add staff in the Staff Directory first — they will appear here automatically.</small></div></td></tr>
          <?php else: foreach($staff as $s):
            $p=(int)$s['present_days'];$l=(int)$s['late_days'];$a=(int)$s['absent_days'];$lv=(int)$s['leave_days'];$m=(int)$s['marked_days'];
            $rate=$m>0?round(($p+$l)/$m*100):0;
            $initials=strtoupper(substr(trim($s['full_name']),0,1));
            $words=explode(' ',trim($s['full_name'])); if(count($words)>1)$initials=strtoupper(substr($words[0],0,1).substr(end($words),0,1));
            $ds=$s['today_status'];
            $ci=$s['check_in']?substr($s['check_in'],0,5):'';
            $co=$s['check_out']?substr($s['check_out'],0,5):'';
          ?>
            <tr>
              <td><div class="st-cell">
                <div class="st-av"><?php if(!empty($s['passport_photo'])): ?><img src="<?php echo e($s['passport_photo']); ?>" alt=""><?php else: echo $initials; endif; ?></div>
                <div><span class="st-name"><?php echo e($s['full_name']); ?></span><span class="st-sub"><?php echo e($s['staff_code']); ?></span></div>
              </div></td>
              <td style="font-size:12px"><?php echo e($s['position']??'—'); ?><br><span style="color:var(--ink2)"><?php echo e($s['department']??''); ?></span></td>
              <td><div class="pstats">
                <span class="ps-chip p"><span class="ps-dot"></span><?php echo $p; ?> P</span>
                <span class="ps-chip l"><span class="ps-dot"></span><?php echo $l; ?> L</span>
                <span class="ps-chip a"><span class="ps-dot"></span><?php echo $a; ?> A</span>
                <span class="ps-chip lv"><span class="ps-dot"></span><?php echo $lv; ?> Lv</span>
              </div></td>
              <td><div class="rate-wrap"><div class="rate-bar"><div class="rate-fill" style="width:<?php echo $rate; ?>%"></div></div><span class="rate-num"><?php echo $rate; ?>%</span></div></td>
              <td>
                <?php if($ds): ?><span class="day-status ds-<?php echo strtolower($ds); ?>"><?php echo e($ds); ?></span>
                <?php else: ?><span class="day-status ds-none">Not marked</span><?php endif; ?>
                <?php if($ci||$co): ?><span class="remark-chip mono">In <?php echo e($ci?:'—'); ?> · Out <?php echo e($co?:'—'); ?></span><?php endif; ?>
                <?php if(!empty($s['remarks'])): ?><span class="remark-chip" title="<?php echo e($s['remarks']); ?>">📝 <?php echo e($s['remarks']); ?></span><?php endif; ?>
              </td>
              <td>
                <form method="post" class="mark-form">
                  <input type="hidden" name="action" value="_mark">
                  <input type="hidden" name="staff_id" value="<?php echo (int)$s['id']; ?>">
                  <input type="hidden" name="attendance_date" value="<?php echo e($selDate); ?>">
                  <div class="mf-row">
                    <div class="mf-field"><label>Status</label>
                      <select name="status" class="mf-input">
                        <option value="Present" <?php echo in_array($ds,['Present','Late'])?'selected':''; ?>>Present</option>
                        <option value="Absent" <?php echo $ds==='Absent'?'selected':''; ?>>Absent</option>
                        <option value="Leave" <?php echo $ds==='Leave'?'selected':''; ?>>Leave</option>
                      </select>
                    </div>
                    <div class="mf-field"><label>Enter Time</label>
                      <div class="mf-time-wrap">
                        <input type="time" name="check_in" class="mf-input cin" value="<?php echo e($ci); ?>" data-start="<?php echo e($startShort); ?>">
                        <span class="late-tag" style="display:none"></span>
                      </div>
                    </div>
                    <div class="mf-field"><label>Exit Time</label><input type="time" name="check_out" class="mf-input" value="<?php echo e($co); ?>"></div>
                  </div>
                  <div class="mf-row">
                    <div class="mf-field"><label>Note</label><input type="text" name="remarks" class="mf-input" placeholder="Optional note..." value="<?php echo e($s['remarks']??''); ?>"></div>
                  </div>
                  <button type="submit" class="mf-save">Save Attendance</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Daily Attendance Register</div></footer>
<div class="toasts" id="toastWrap"></div>
<div id="printSheet"></div>

<script>
const $=id=>document.getElementById(id);
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'&mdash;':String(s);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}

/* live Late / On-time indicator on Enter time inputs */
function checkLate(input){
  const tag=input.closest('.mf-time-wrap').querySelector('.late-tag');
  const start=input.dataset.start||'08:00';
  const val=input.value;
  if(!val){tag.style.display='none';return;}
  const late=val>start;
  tag.style.display='block';
  tag.className='late-tag '+(late?'late':'ontime');
  tag.textContent=late?'Late':'On time';
}
document.querySelectorAll('.cin').forEach(i=>{checkLate(i);i.addEventListener('input',()=>checkLate(i));});

/* disable time fields when Absent/Leave selected */
document.querySelectorAll('.mark-form select[name="status"]').forEach(sel=>{
  const form=sel.closest('.mark-form');
  const sync=()=>{const off=sel.value!=='Present';form.querySelectorAll('input[type="time"]').forEach(t=>{t.disabled=off;if(off)t.value='';});};
  sel.addEventListener('change',sync);sync();
});

const staffData=<?php echo json_encode(array_map(function($s){return['name'=>$s['full_name'],'code'=>$s['staff_code'],'position'=>$s['position']??'','dept'=>$s['department']??'','p'=>(int)$s['present_days'],'l'=>(int)$s['late_days'],'a'=>(int)$s['absent_days'],'lv'=>(int)$s['leave_days'],'m'=>(int)$s['marked_days'],'status'=>$s['today_status']??'','in'=>$s['check_in']?substr($s['check_in'],0,5):'','out'=>$s['check_out']?substr($s['check_out'],0,5):'','remarks'=>$s['remarks']??''];},$staff),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const cn='<?php echo addslashes($company['company_name']); ?>';
const ca='<?php echo addslashes($company['company_address']); ?>';
const logo='<?php echo e($logoUrl); ?>';
const selDate='<?php echo e($selDate); ?>';
const startShort='<?php echo e($startShort); ?>';
const sum={marked:<?php echo $markedToday; ?>,present:<?php echo $presentToday; ?>,late:<?php echo $lateToday; ?>,absent:<?php echo $absentToday; ?>,leave:<?php echo $leaveToday; ?>,rate:<?php echo $attRate; ?>,total:<?php echo $totActive; ?>};

$('btnPrintSheet').addEventListener('click',()=>{
  if(!staffData.length){toast('No staff to print.','error');return;}
  const rows=staffData.map(s=>{
    const m=s.m>0?Math.round((s.p+s.l)/s.m*100):0;
    return '<tr><td>'+s.code+'</td><td>'+v(s.name)+'</td><td>'+v(s.dept)+'</td><td>'+(s.status||'Not marked')+'</td><td>'+(s.in||'—')+'</td><td>'+(s.out||'—')+'</td><td>'+v(s.remarks)+'</td><td>'+s.p+' / '+s.l+' / '+s.a+' / '+s.lv+'</td><td>'+m+'%</td></tr>';
  }).join('');
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  $('printSheet').innerHTML=
  '<div style="font-size:8.5pt;color:#1a231d">'+
    '<div class="as-head"><img class="as-logo" src="'+logo+'" alt="">'+
      '<div class="as-co"><h1>'+cn+'</h1><p>'+ca+'</p><p>Staff Management &amp; Human Resources Department</p></div>'+
      '<div class="as-doc"><h2>DAILY ATTENDANCE SHEET</h2><p>Date: '+selDate+' &middot; Official start: '+startShort+'</p><p>Generated: '+genDate+'</p></div>'+
    '</div>'+
    '<div class="as-sum">'+
      '<div class="as-sumbox"><b>'+sum.total+'</b><span>Active Staff</span></div>'+
      '<div class="as-sumbox"><b>'+sum.marked+'</b><span>Marked</span></div>'+
      '<div class="as-sumbox"><b>'+(sum.present+sum.late)+'</b><span>Present</span></div>'+
      '<div class="as-sumbox"><b>'+sum.late+'</b><span>Late</span></div>'+
      '<div class="as-sumbox"><b>'+sum.rate+'%</b><span>Attendance Rate</span></div>'+
    '</div>'+
    '<table class="as-table"><thead><tr>'+
      ['Staff ID','Name','Department','Status','Enter','Exit','Note','P / L / A / Lv (Month)','Rate'].map(h=>'<th>'+h+'</th>').join('')+'</tr></thead><tbody>'+rows+'</tbody></table>'+
    '<div class="as-sigs">'+
      '<div class="as-sig"><div class="as-sigline"></div><span>Recorded By (Secretary)</span></div>'+
      '<div class="as-sig"><div class="as-sigline"></div><span>Authorised By (HR / Manager)</span></div>'+
    '</div>'+
    '<div class="as-foot"><span>'+cn+' — DAILY ATTENDANCE REGISTER (CONFIDENTIAL)</span><span>Generated '+genDate+'</span></div>'+
  '</div>';
  window.print();
});

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>