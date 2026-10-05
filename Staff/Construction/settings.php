<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function csrf():string{ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department'];
$logoUrl = './Client Order Form _ Okoya Food   mr wahab_files/logo_uigcps.jpg';

/* ensure users.profile_photo column exists */
try{ $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `profile_photo` LONGTEXT NULL AFTER `phone`"); }catch(Exception $ignore){}

/* ===== FETCH CURRENT USER ===== */
$me=null;
try{
    $st=$pdo->prepare("SELECT u.*, s.passport_photo AS staff_photo FROM users u LEFT JOIN staff s ON s.staff_code=u.staff_code WHERE u.id=?");
    $st->execute([$auth['id']]);
    $me=$st->fetch();
}catch(Exception $ignore){}
$photo = $me['profile_photo'] ?: ($me['staff_photo'] ?? '');

/* ===== UPDATE PROFILE PHOTO ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_photo') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch — refresh and try again.');
    else{
        $photoData=$_POST['profile_photo']??'';
        if($photoData && strlen($photoData)<700000){
            try{
                $pdo->prepare("UPDATE users SET profile_photo=? WHERE id=?")->execute([$photoData,$auth['id']]);
                try{ $pdo->prepare("UPDATE staff SET passport_photo=? WHERE staff_code=?")->execute([$photoData,$auth['staff_code']]); }catch(Exception $ignore){}
                try{ $pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'UPDATE_PHOTO','user',$auth['id'],'Profile photo updated']); }catch(Exception $ignore){}
                flash('success','Profile photo updated.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Please choose an image under 500KB.');
        header('Location: settings.php'); exit;
    }
}

/* ===== UPDATE PERSONAL INFO (name, phone, email) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_profile') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch — refresh and try again.');
    else{
        $name=trim($_POST['full_name']??'');
        $phone=trim($_POST['phone']??'');
        $email=strtolower(trim($_POST['email']??''));
        $curPw=$_POST['current_password']??'';
        $emailChanged = $me && strtolower($me['email'])!==$email;

        if(strlen($name)<3) flash('error','Full name must be at least 3 characters.');
        elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)) flash('error','Please enter a valid email address.');
        else{
            try{
                if($emailChanged){
                    /* email change requires current password */
                    if(!password_verify($curPw,$me['password_hash'])) flash('error','Enter your current password to change your email.');
                    else{
                        $st=$pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?");
                        $st->execute([$email,$auth['id']]);
                        if($st->fetch()) flash('error','That email is already in use by another account.');
                    }
                }
                if(!isset($_SESSION['flash'])){
                    $pdo->prepare("UPDATE users SET full_name=?,phone=?,email=? WHERE id=?")
                        ->execute([$name,$phone?:null,$email,$auth['id']]);
                    try{ $pdo->prepare("UPDATE staff SET full_name=?,phone=? WHERE staff_code=?")->execute([$name,$phone?:null,$auth['staff_code']]); }catch(Exception $ignore){}
                    /* refresh session */
                    $_SESSION['auth']['name']=$name;
                    $_SESSION['auth']['phone']=$phone;
                    $_SESSION['auth']['email']=$email;
                    try{ $pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'UPDATE_PROFILE','user',$auth['id'],'Profile updated']); }catch(Exception $ignore){}
                    flash('success','Profile updated successfully.');
                }
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        }
        header('Location: settings.php'); exit;
    }
}

/* ===== CHANGE PASSWORD ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_password') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch — refresh and try again.');
    else{
        $cur=$_POST['current_password']??'';
        $new=$_POST['new_password']??'';
        $new2=$_POST['new_password2']??'';
        if(!password_verify($cur,$me['password_hash'])) flash('error','Current password is incorrect.');
        elseif(strlen($new)<8) flash('error','New password must be at least 8 characters.');
        elseif($new!==$new2) flash('error','New passwords do not match.');
        elseif($new===$cur) flash('error','New password must be different from the current one.');
        else{
            try{
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$auth['id']]);
                try{ $pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'PASSWORD_CHANGED','user',$auth['id'],'Password changed']); }catch(Exception $ignore){}
                flash('success','Password changed successfully.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        }
        header('Location: settings.php'); exit;
    }
}

$flashData = get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Account Settings | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
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
:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--line2:#c8d3c5;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}
[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}
body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}
.container{max-width:1100px;margin:0 auto;padding:0 20px}
.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap;max-width:1100px}
.brand{display:flex;align-items:center;gap:12px;margin-right:auto}
.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}
.logo-chip img{width:38px;height:38px;object-fit:contain}
.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}
.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}
.back-link:hover{color:var(--green);border-color:var(--green)}
.back-link svg{width:14px;height:14px}
.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}
.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}
.theme-btn svg{width:17px;height:17px}

.page-header{padding:30px 0 6px}
.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}
.page-header h1 svg{width:28px;height:28px;stroke:var(--green)}
.page-header h1 em{font-style:normal;color:var(--green)}
.page-header p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}

.layout{display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start;margin-top:20px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}
.card-head{display:flex;align-items:center;gap:8px;padding:15px 20px;border-bottom:1px solid var(--line);font-family:var(--fd);font-size:14px;font-weight:800}
.card-head svg{width:17px;height:17px;stroke:var(--green)}

/* profile card */
.profile-card{padding:26px 20px;text-align:center}
.avatar-wrap{position:relative;width:110px;height:110px;margin:0 auto 14px}
.avatar{width:110px;height:110px;border-radius:26px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:36px;font-weight:800;overflow:hidden;border:3px solid var(--surface);box-shadow:var(--shadow)}
.avatar img{width:100%;height:100%;object-fit:cover}
.avatar-edit{position:absolute;bottom:-6px;right:-6px;width:36px;height:36px;border-radius:50%;background:var(--gold);color:#fff;display:grid;place-items:center;border:2px solid var(--surface);cursor:pointer;transition:.2s;box-shadow:var(--shadow-sm)}
.avatar-edit:hover{transform:scale(1.1)}
.avatar-edit svg{width:16px;height:16px}
.profile-name{font-family:var(--fd);font-size:19px;font-weight:800}
.profile-role{display:inline-block;margin-top:6px;font-size:10px;font-weight:800;letter-spacing:.7px;background:var(--gold-soft);color:var(--gold2);padding:4px 12px;border-radius:99px;text-transform:uppercase}
.profile-meta{margin-top:16px;display:flex;flex-direction:column;gap:8px;text-align:left}
.pm-row{display:flex;justify-content:space-between;gap:10px;background:var(--surface2);border:1px solid var(--line);border-radius:10px;padding:9px 13px;font-size:12px}
.pm-row span{color:var(--ink2);font-weight:700;flex:none}
.pm-row b{font-weight:700;text-align:right;word-break:break-all}

/* forms */
.forms{display:flex;flex-direction:column;gap:18px}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:18px 20px}
.span2{grid-column:1/-1}
.field{display:flex;flex-direction:column;gap:5px}
.fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--line);border-radius:11px;padding:0 12px;height:46px;transition:.2s}
.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent);background:var(--surface)}
.ctrl input{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:14px}
.pw-eye{border:none;background:none;color:var(--ink2);display:grid;place-items:center;padding:4px;border-radius:8px;transition:.15s;cursor:pointer}
.pw-eye:hover{color:var(--green)}
.pw-eye svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.hint{font-size:11px;color:var(--ink2);font-weight:600;margin-top:4px}
.pw-meter{display:flex;gap:6px;margin-top:8px;align-items:center}
.pw-meter i{height:5px;flex:1;border-radius:99px;background:var(--line);transition:.3s}
.pw-meter b{font-size:10.5px;font-weight:800;min-width:56px;text-align:right;color:var(--ink2)}
.pw-meter[data-s="1"] i:nth-child(-n+1){background:var(--red)}
.pw-meter[data-s="2"] i:nth-child(-n+2){background:var(--gold)}
.pw-meter[data-s="3"] i:nth-child(-n+3){background:var(--gold)}
.pw-meter[data-s="4"] i:nth-child(-n+4){background:var(--green)}
.pw-meter[data-s="1"] b{color:var(--red)}
.pw-meter[data-s="2"] b,.pw-meter[data-s="3"] b{color:var(--gold2)}
.pw-meter[data-s="4"] b{color:var(--green)}
.card-actions{padding:0 20px 20px;display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:44px;transition:.2s;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}
.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}
.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}
.btn-ghost:hover{border-color:var(--green);color:var(--green)}
.btn svg{width:15px;height:15px}

.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}
.toast.error{border-left-color:var(--red)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s}
@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
@media(max-width:600px){.fgrid{grid-template-columns:1fr}.topbar .brand small{display:none}}
</style>
</head>
<body>
<header class="topbar"><div class="container">
  <div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div>
  <a href="<?php echo in_array($auth['role'],['admin','ceo','general manager'])?'admin_general_dashboard.php':'admin_construction_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a>
  <button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button>
</div></header>

<div class="container">
  <div class="page-header">
    <h1><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Account <em>Settings</em></h1>
    <p>Manage your profile picture, personal details, and password</p>
  </div>

  <div class="layout">
    <!-- LEFT: PROFILE CARD -->
    <div class="card">
      <div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>My Profile</div>
      <div class="profile-card">
        <div class="avatar-wrap">
          <div class="avatar" id="avatarPreview">
            <?php if($photo): ?><img src="<?php echo e($photo); ?>" alt=""><?php else: echo strtoupper(substr($auth['name'],0,1)); endif; ?>
          </div>
          <label class="avatar-edit" title="Change photo">
            <svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <input type="file" accept="image/*" hidden id="photoInput">
          </label>
        </div>
        <div class="profile-name"><?php echo e($auth['name']); ?></div>
        <span class="profile-role"><?php echo e($auth['role_name'] ?? $auth['role']); ?></span>
        <div class="profile-meta">
          <div class="pm-row"><span>Staff ID</span><b class="mono"><?php echo e($auth['staff_code']); ?></b></div>
          <div class="pm-row"><span>Email</span><b><?php echo e($auth['email']); ?></b></div>
          <div class="pm-row"><span>Phone</span><b><?php echo e($auth['phone']??'—'); ?></b></div>
          <div class="pm-row"><span>Last Login</span><b><?php echo $me['last_login_at']?e(date('d M Y, H:i',strtotime($me['last_login_at']))):'—'; ?></b></div>
          <div class="pm-row"><span>Last Login IP</span><b class="mono"><?php echo e($me['last_login_ip']??'—'); ?></b></div>
          <div class="pm-row"><span>Member Since</span><b><?php echo $me['created_at']?e(date('d M Y',strtotime($me['created_at']))):'—'; ?></b></div>
        </div>
      </div>
    </div>

    <!-- RIGHT: FORMS -->
    <div class="forms">

      <!-- PHOTO -->
      <div class="card">
        <div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Profile Picture</div>
        <form method="post">
          <input type="hidden" name="action" value="_photo">
          <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
          <input type="hidden" name="profile_photo" id="photoData">
          <div class="fgrid">
            <div class="field span2">
              <span class="hint">Click the camera icon on your avatar to choose a new photo (JPG/PNG, max 500KB), then press Save Photo.</span>
            </div>
          </div>
          <div class="card-actions">
            <button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Photo</button>
          </div>
        </form>
      </div>

      <!-- PERSONAL INFO -->
      <div class="card">
        <div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Personal Information</div>
        <form method="post">
          <input type="hidden" name="action" value="_profile">
          <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
          <div class="fgrid">
            <div class="field span2"><label class="fl">Full Name <b>*</b></label><div class="ctrl"><input name="full_name" value="<?php echo e($me['full_name']??''); ?>" required></div></div>
            <div class="field"><label class="fl">Phone</label><div class="ctrl"><input name="phone" value="<?php echo e($me['phone']??''); ?>" placeholder="0803 000 0000"></div></div>
            <div class="field"><label class="fl">Email <b>*</b></label><div class="ctrl"><input name="email" type="email" value="<?php echo e($me['email']??''); ?>" required></div></div>
            <div class="field span2"><label class="fl">Current Password <span style="text-transform:none;font-weight:600">(required only to change email)</span></label>
              <div class="ctrl"><input type="password" name="current_password" id="profPw" placeholder="Enter current password"><button type="button" class="pw-eye" data-target="profPw"></button></div>
            </div>
          </div>
          <div class="card-actions">
            <button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button>
          </div>
        </form>
      </div>

      <!-- CHANGE PASSWORD -->
      <div class="card">
        <div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Change Password</div>
        <form method="post" id="pwForm">
          <input type="hidden" name="action" value="_password">
          <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
          <div class="fgrid">
            <div class="field span2"><label class="fl">Current Password <b>*</b></label>
              <div class="ctrl"><input type="password" name="current_password" id="curPw" required><button type="button" class="pw-eye" data-target="curPw"></button></div>
            </div>
            <div class="field"><label class="fl">New Password <b>*</b></label>
              <div class="ctrl"><input type="password" name="new_password" id="newPw" minlength="8" required><button type="button" class="pw-eye" data-target="newPw"></button></div>
              <div class="pw-meter" id="pwMeter" data-s="0"><i></i><i></i><i></i><i></i><b id="pwLabel">—</b></div>
            </div>
            <div class="field"><label class="fl">Confirm New Password <b>*</b></label>
              <div class="ctrl"><input type="password" name="new_password2" id="newPw2" minlength="8" required><button type="button" class="pw-eye" data-target="newPw2"></button></div>
            </div>
          </div>
          <div class="card-actions">
            <button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Update Password</button>
          </div>
        </form>
      </div>

    </div>
  </div>
</div>

    <footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Account Settings</div></br>
    <div> <h5>Developped By Lawani Djamiou Alade</h5> </div>
</footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}

/* photo preview */
$('photoInput').addEventListener('change',function(){
  const f=this.files[0];if(!f)return;
  if(f.size>512000){toast('Photo must be under 500KB','error');this.value='';return;}
  const rd=new FileReader();
  rd.onload=e=>{
    $('avatarPreview').innerHTML='<img src="'+e.target.result+'" alt="">';
    $('photoData').value=e.target.result;
    toast('Photo selected — press Save Photo.','info');
  };
  rd.readAsDataURL(f);
});

/* show/hide password */
const eyeOn='<svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOff='<svg viewBox="0 0 24 24"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.2 13.2 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="M2 2l20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
document.querySelectorAll('.pw-eye').forEach(b=>{
  b.innerHTML=eyeOn;
  b.addEventListener('click',()=>{const i=$(b.dataset.target);const show=i.type==='password';i.type=show?'text':'password';b.innerHTML=show?eyeOff:eyeOn;});
});

/* password strength */
function scorePw(v){if(!v)return 0;let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;return Math.max(1,Math.min(4,s));}
const pwL=['—','Weak','Fair','Good','Strong'];
$('newPw').addEventListener('input',()=>{const s=scorePw($('newPw').value);$('pwMeter').dataset.s=s;$('pwLabel').textContent=pwL[s];});

/* password match check */
$('pwForm').addEventListener('submit',e=>{
  if($('newPw').value!==$('newPw2').value){e.preventDefault();toast('New passwords do not match.','error');}
  else if($('newPw').value.length<8){e.preventDefault();toast('New password must be at least 8 characters.','error');}
});

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>