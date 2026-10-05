<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Construction & Projects Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];
$logoUrl = './Client Order Form _ Okoya Food   mr jamal_files/logo_uigcps.jpg';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== ENSURE TABLE + EXTRA COLUMNS ===== */
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
    $pdo->exec("ALTER TABLE construction_projects ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER status");
    $pdo->exec("ALTER TABLE construction_projects ADD COLUMN IF NOT EXISTS priority ENUM('Low','Medium','High','Critical') DEFAULT 'Medium' AFTER description");
} catch(Exception $ignore){}

/* ===== UNIQUE PROJECT CODE ===== */
function genProjectCode(PDO $pdo): string {
    $yr=date('Y'); $seq=1;
    do {
        $code='CP-'.$yr.'-'.str_pad((string)$seq,3,'0',STR_PAD_LEFT);
        $st=$pdo->prepare("SELECT id FROM construction_projects WHERE project_code=?");
        $st->execute([$code]);
        if(!$st->fetch()) return $code;
        $seq++;
    } while($seq<1000);
    return 'CP-'.$yr.'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,5));
}

/* ===== FETCH SITES + MANAGERS FOR SUGGESTIONS ===== */
$sites=[]; try{ $sites=$pdo->query("SELECT name FROM construction_sites ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); }catch(Exception $ignore){}
$managers=[]; try{ $managers=$pdo->query("SELECT full_name FROM staff ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN); }catch(Exception $ignore){}

/* ===== CREATE PROJECT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_create_project') {
    if(($_POST['csrf']??'')!==csrf()) { flash('error','Security token mismatch — please try again.'); }
    else {
        $name=trim($_POST['name']??'');
        $site=trim($_POST['site']??'');
        $manager=trim($_POST['manager']??'');
        $budget=floatval($_POST['budget']??0);
        $desc=trim($_POST['description']??'');
        $priority=$_POST['priority']??'Medium';
        $status=$_POST['status']??'Planning';
        $start=$_POST['start_date']??'';
        $end=$_POST['end_date']??'';

        $errs=[];
        if(mb_strlen($name)<3) $errs[]='Project name is required (min 3 characters).';
        if($budget<=0) $errs[]='Budget must be greater than 0.';
        if($start && $end && strtotime($end)<strtotime($start)) $errs[]='End date cannot be before start date.';
        if(!in_array($status,['Planning','In Progress','On Hold','Completed','Cancelled'])) $status='Planning';
        if(!in_array($priority,['Low','Medium','High','Critical'])) $priority='Medium';

        if(empty($errs)){
            try{
                $code=genProjectCode($pdo);
                $pdo->prepare("INSERT INTO construction_projects
                    (project_code,name,site,manager,budget,spent,progress,status,description,priority,start_date,end_date)
                    VALUES (?,?,?,0+?,0,0,?,?,?,?,?,?)")
                    ->execute([$code,$name,$site?:null,$manager?:null,$budget,$status,$desc?:null,$priority,
                               $start?:null,$end?:null]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")
                    ->execute([$auth['id'],'CREATE_PROJECT','project',(int)$pdo->lastInsertId(),"Created project $code — $name"]);}catch(Exception $ignore){}
                flash('success','Project '.$code.' created successfully.');
                header('Location: construction_new_project.php?created='.urlencode($code)); exit;
            }catch(Exception $ex){ flash('error','Database error: '.$ex->getMessage()); }
        } else flash('error', implode(' ',$errs));
    }
}
$flash=get_flash();
$createdCode = $_GET['created'] ?? '';
$nextCode = genProjectCode($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Project | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f4f4f2;--surface:#fff;--surface2:#f7f7f4;
  --ink:#1c2226;--ink2:#626b70;--line:#e1e3dd;--line2:#ccd2c8;
  --green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;
  --amber:#d97a16;--amber2:#b45f0a;--amber-soft:#fdeed7;
  --steel:#2b3439;--steel2:#1f262a;
  --red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;
  --shadow:0 1px 2px rgba(28,34,38,.05),0 12px 32px rgba(28,34,38,.10);
  --shadow-sm:0 1px 2px rgba(28,34,38,.07);
  --fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;
  --r:16px;--hazard:repeating-linear-gradient(45deg,var(--amber) 0 12px,var(--steel) 12px 24px);
}
[data-theme="dark"]{
  --bg:#0d1214;--surface:#161d20;--surface2:#1a2428;
  --ink:#e8ecec;--ink2:#8b958f;--line:#263134;--line2:#334044;
  --green:#31c381;--green2:#22a468;--green-soft:#12352a;
  --amber:#f09a3a;--amber2:#d97a16;--amber-soft:#3a2a13;
  --steel:#39444a;--steel2:#2b3439;
  --red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;
  --shadow:0 1px 2px rgba(0,0,0,.45),0 16px 40px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5);
}
html{scroll-behavior:smooth}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;
  background:radial-gradient(800px 500px at 88% -10%,color-mix(in srgb,var(--amber) 13%,transparent),transparent 60%),
  radial-gradient(600px 400px at -10% 40%,color-mix(in srgb,var(--steel) 10%,transparent),transparent 60%)}
svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.container{max-width:1200px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}
::selection{background:color-mix(in srgb,var(--amber) 30%,transparent)}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:38px;height:38px;object-fit:contain}
.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}
.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}
.back-link:hover{color:var(--amber);border-color:var(--amber)}
.back-link svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}
.badge.amber{background:var(--amber-soft);color:var(--amber2)}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--amber);border-color:var(--amber);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

/* PAGE HEADER */
.page-header{margin:24px 0 20px}
.ph-strip{height:6px;background:var(--hazard);border-radius:99px;margin-bottom:18px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:26px;height:26px;stroke:var(--amber2)}
.page-header h1 em{font-style:normal;color:var(--amber2)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:5px;font-size:13.5px}

/* LAYOUT */
.layout{display:grid;grid-template-columns:1.55fr 1fr;gap:18px;align-items:start}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:16px}
.card-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--line)}
.card-head .ic{width:34px;height:34px;border-radius:10px;background:var(--amber-soft);color:var(--amber2);display:grid;place-items:center;flex:none}
.card-head .ic svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800}
.card-head p{font-size:11.5px;color:var(--ink2);font-weight:600}
.card-body{padding:20px}

/* FORM */
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.span2{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:6px}
.fl{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:9px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 13px;height:48px;transition:.2s}
.ctrl:focus-within{border-color:var(--amber);box-shadow:0 0 0 4px color-mix(in srgb,var(--amber) 12%,transparent);background:var(--surface)}
.ctrl.invalid{border-color:var(--red)!important;animation:shake .3s}
@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
.ctrl svg{flex:none;stroke:var(--ink2);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;min-width:0}
.ctrl select{cursor:pointer;appearance:none}
.ctrl textarea{padding:11px 0;height:auto;min-height:60px;resize:vertical}
.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}
.code-input{background:var(--amber-soft)!important;color:var(--amber2)!important;font-family:var(--fm)!important;font-weight:700!important;letter-spacing:1px}
.hint{font-size:11px;color:var(--ink2);font-weight:600;margin-top:2px}

/* PRIORITY SELECT visual */
.prio-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.prio{position:relative}
.prio input{position:absolute;opacity:0;inset:0;cursor:pointer;z-index:2}
.prio-label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:11px 6px;border-radius:12px;border:1.5px solid var(--line);background:var(--surface2);transition:.18s;text-align:center}
.prio-label:hover{border-color:var(--line2)}
.prio-label b{font-family:var(--fd);font-size:11.5px;font-weight:800}
.prio-label small{font-size:9.5px;color:var(--ink2);font-weight:700}
.prio input:checked+.prio-label{border-color:var(--amber);background:var(--amber-soft);box-shadow:0 0 0 3px color-mix(in srgb,var(--amber) 12%,transparent)}
.prio input:checked+.prio-label b{color:var(--amber2)}

/* SIDE PREVIEW */
.side{position:sticky;top:78px;display:flex;flex-direction:column;gap:16px}
.pv-code{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,var(--amber),var(--amber2));color:#fff;border-radius:14px;padding:16px 18px;position:relative;overflow:hidden}
.pv-code::before{content:'';position:absolute;top:0;left:0;right:0;height:5px;background:var(--hazard)}
.pv-code .lbl{font-size:9px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;opacity:.8}
.pv-code .val{font-family:var(--fm);font-size:20px;font-weight:700;margin-top:3px}
.pv-code .auto{font-size:9px;opacity:.7;font-weight:700}
.pv-list{padding:6px 20px 18px}
.pv-item{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid var(--line)}
.pv-item:last-child{border-bottom:none}
.pv-item span{font-size:11px;font-weight:700;color:var(--ink2);text-transform:uppercase;letter-spacing:.4px}
.pv-item b{font-size:13px;font-weight:700;text-align:right}
.pv-budget{background:var(--amber-soft);border:1.5px dashed var(--amber);border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center}
.pv-budget span{font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--amber2)}
.pv-budget b{font-family:var(--fm);font-size:19px;font-weight:700;color:var(--amber2)}

/* ACTIONS */
.form-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:13px;font-weight:800;font-size:14px;padding:15px;transition:.2s;cursor:pointer}
.btn svg{width:17px;height:17px}
.btn-primary{background:linear-gradient(135deg,var(--amber),var(--amber2));color:#fff;box-shadow:0 10px 24px color-mix(in srgb,var(--amber) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-2px)}
.btn-primary:disabled{opacity:.7;cursor:not-allowed;transform:none}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--amber);color:var(--amber2)}
.spinner{width:17px;height:17px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-primary.loading .spinner{display:block}
.btn-primary.loading .bl,.btn-primary.loading svg{display:none}

/* TOAST */
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--amber);border-radius:12px;padding:13px 17px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}

footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}

/* RESPONSIVE */
@media(max-width:1000px){.layout{grid-template-columns:1fr}.side{position:static}}
@media(max-width:768px){
  .fgrid{grid-template-columns:1fr}
  .topbar .badge{display:none}
  .brand strong{font-size:13px}
  .prio-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:480px){.form-actions{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="admin_construction_dashboard.php" class="back-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Construction Dashboard</a>
  <span class="badge amber">🏗️ CONSTRUCTION</span>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <div class="ph-strip"></div>
    <h1><svg viewBox="0 0 24 24" fill="none"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/><path d="M9 20v-4h4v4"/></svg>Create <em>Project</em></h1>
    <p>Register a new construction project — details, timeline, budget and assignment.</p>
  </div>

  <div class="layout">
    <!-- FORM -->
    <form method="post" id="projForm" novalidate>
      <input type="hidden" name="action" value="_create_project">
      <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">

      <!-- SECTION 1 -->
      <div class="card">
        <div class="card-head"><div class="ic"><svg viewBox="0 0 24 24"><path d="M2 20h20"/><path d="M5 20V8l7-4 7 4v12"/></svg></div><div><h2>Project Details</h2><p>Core information about the project</p></div></div>
        <div class="card-body">
          <div class="fgrid">
            <div class="field span2"><label class="fl">Project Name <b>*</b></label>
              <div class="ctrl"><input name="name" id="f_name" placeholder="e.g. Warehouse Expansion — Block B" required></div></div>
            <div class="field"><label class="fl">Project Code</label>
              <div class="ctrl"><input value="<?php echo e($nextCode); ?>" class="code-input" readonly tabindex="-1"></div>
              <span class="hint">Auto-generated and unique.</span></div>
            <div class="field"><label class="fl">Priority</label>
              <div class="prio-grid">
                <label class="prio"><input type="radio" name="priority" value="Low"><span class="prio-label"><b>Low</b><small>Flexible</small></span></label>
                <label class="prio"><input type="radio" name="priority" value="Medium" checked><span class="prio-label"><b>Medium</b><small>Standard</small></span></label>
                <label class="prio"><input type="radio" name="priority" value="High"><span class="prio-label"><b>High</b><small>Urgent</small></span></label>
                <label class="prio"><input type="radio" name="priority" value="Critical"><span class="prio-label"><b>Critical</b><small>Immediate</small></span></label>
              </div>
            </div>
            <div class="field span2"><label class="fl">Description / Scope</label>
              <div class="ctrl ta"><textarea name="description" rows="3" placeholder="Describe the scope of work, objectives, and deliverables…"></textarea></div></div>
          </div>
        </div>
      </div>

      <!-- SECTION 2 -->
      <div class="card">
        <div class="card-head"><div class="ic"><svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div><div><h2>Site &amp; Assignment</h2><p>Where and who is responsible</p></div></div>
        <div class="card-body">
          <div class="fgrid">
            <div class="field"><label class="fl">Site / Location</label>
              <div class="ctrl"><input name="site" id="f_site" list="siteList" placeholder="Select or type a site"></div>
              <datalist id="siteList"><?php foreach($sites as $s): ?><option value="<?php echo e($s); ?>"><?php endforeach; ?></datalist></div>
            <div class="field"><label class="fl">Project Manager</label>
              <div class="ctrl"><input name="manager" id="f_manager" list="mgrList" placeholder="Assign a manager"></div>
              <datalist id="mgrList"><?php foreach($managers as $m): ?><option value="<?php echo e($m); ?>"><?php endforeach; ?></datalist></div>
            <div class="field"><label class="fl">Start Date</label>
              <div class="ctrl"><input name="start_date" id="f_start" type="date" value="<?php echo date('Y-m-d'); ?>"></div></div>
            <div class="field"><label class="fl">Expected End Date</label>
              <div class="ctrl"><input name="end_date" id="f_end" type="date"></div></div>
          </div>
        </div>
      </div>

      <!-- SECTION 3 -->
      <div class="card">
        <div class="card-head"><div class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-.8.9-1.5 3-1.5s3 .7 3 1.5-.9 1.5-3 1.5-3 .7-3 1.5.9 1.5 3 1.5 3-.7 3-1.5"/></svg></div><div><h2>Budget &amp; Status</h2><p>Financial allocation and starting state</p></div></div>
        <div class="card-body">
          <div class="fgrid">
            <div class="field"><label class="fl">Total Budget (₦) <b>*</b></label>
              <div class="ctrl"><input name="budget" id="f_budget" inputmode="decimal" placeholder="0.00" required></div></div>
            <div class="field"><label class="fl">Initial Status</label>
              <div class="ctrl"><select name="status">
                <option value="Planning" selected>Planning</option>
                <option value="In Progress">In Progress</option>
                <option value="On Hold">On Hold</option>
              </select></div></div>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button type="button" class="btn btn-ghost" id="btnReset"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>Reset</button>
        <button type="submit" class="btn btn-primary" id="btnSubmit"><span class="spinner"></span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg><span class="bl">Create Project</span></button>
      </div>
    </form>

    <!-- SIDE PREVIEW -->
    <div class="side">
      <div class="card">
        <div class="card-head"><div class="ic"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div><div><h2>Live Preview</h2><p>Updates as you type</p></div></div>
        <div class="card-body" style="padding-top:14px">
          <div class="pv-code"><div><div class="lbl">Project Code</div><div class="val"><?php echo e($nextCode); ?></div><div class="auto">AUTO-GENERATED</div></div></div>
          <div class="pv-list">
            <div class="pv-item"><span>Project</span><b id="pvName">—</b></div>
            <div class="pv-item"><span>Site</span><b id="pvSite">—</b></div>
            <div class="pv-item"><span>Manager</span><b id="pvManager">—</b></div>
            <div class="pv-item"><span>Timeline</span><b id="pvTimeline">—</b></div>
            <div class="pv-item"><span>Status</span><b id="pvStatus">Planning</b></div>
          </div>
          <div class="pv-budget"><span>Total Budget</span><b id="pvBudget">₦0.00</b></div>
        </div>
      </div>
    </div>
  </div>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Create Project</div></footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'—':String(s);
const fmtN=n=>'₦'+Number(n||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}

/* live preview */
function fmtD(d){if(!d)return'—';const dt=new Date(d+'T00:00:00');return isNaN(dt)?'—':dt.toLocaleDateString('en-NG',{day:'2-digit',month:'short',year:'numeric'});}
function updatePreview(){
  $('pvName').textContent=v($('f_name').value);
  $('pvSite').textContent=v($('f_site').value);
  $('pvManager').textContent=v($('f_manager').value);
  $('pvTimeline').textContent=fmtD($('f_start').value)+' → '+fmtD($('f_end').value);
  $('pvBudget').textContent=fmtN($('f_budget').value);
}
['f_name','f_site','f_manager','f_start','f_end','f_budget'].forEach(id=>{
  $(id).addEventListener('input',()=>{const c=$(id).closest('.ctrl');if(c)c.classList.remove('invalid');updatePreview();});
});
document.querySelector('select[name="status"]').addEventListener('change',e=>{$('pvStatus').textContent=e.target.value;});
updatePreview();

/* validation + loading */
function mark(el){el.closest('.ctrl').classList.add('invalid')}
$('projForm').addEventListener('submit',e=>{
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  let ok=true,msg='';
  if($('f_name').value.trim().length<3){mark($('f_name'));ok=false;msg='Project name is required.';}
  else if(!(parseFloat($('f_budget').value)>0)){mark($('f_budget'));ok=false;msg='Budget must be greater than 0.';}
  else if($('f_end').value && $('f_start').value && $('f_end').value<$('f_start').value){mark($('f_end'));ok=false;msg='End date cannot be before start date.';}
  if(!ok){e.preventDefault();toast(msg,'error');return;}
  const b=$('btnSubmit');b.classList.add('loading');b.disabled=true;
});

$('btnReset').addEventListener('click',()=>{
  $('projForm').reset();
  document.querySelector('input[name="priority"][value="Medium"]').checked=true;
  document.querySelector('select[name="status"]').value='Planning';
  $('f_start').value=new Date().toISOString().slice(0,10);
  $('pvStatus').textContent='Planning';
  updatePreview();
  toast('Form reset.','info');
});

/* success/error flash from PHP */
const FLASH=<?php echo json_encode(get_flash()??$flash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
<?php if($flash): ?>
window.addEventListener('DOMContentLoaded',()=>toast(<?php echo json_encode($flash['msg'],JSON_HEX_TAG|JSON_HEX_QUOT); ?>,'<?php echo $flash['type']; ?>'));
<?php endif; ?>
</script>
</body>
</html>