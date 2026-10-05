<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once 'config.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function redirect(string $to): void { header('Location: ' . $to); exit; }
function log_activity(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    try { $pdo->prepare("INSERT INTO activity_logs (user_id,action,entity_type,entity_id,ip_address,details) VALUES (?,?,?,?,?,?)")->execute([$userId,$action,$entityType,$entityId,client_ip(),$details]); } catch (Exception $ignore) {}
}
$self = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += [
    'company_name'=>'OKOYA GROUP COMPANY LIMITED',
    'company_dept'=>'Staff Management & HR Department',
    'company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria',
    'company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'
];

function getDashboardForRole(string $role): string {
    $r = strtolower(trim($role));
    return match(true) {
        in_array($r, ['admin','ceo','general manager'], true) => 'Admin/admin_first_dashboard.php',
        in_array($r, ['secretary'], true)                     => 'Secretary/secretary_first_dashboard.php',
        in_array($r, ['staff','workers','cleaner'], true)     => 'Staff/staff_first_dashboard.php',
        default                                               => 'staff_first_dashboard.php',
    };
}

$auth = $_SESSION['auth'] ?? null;

if (isset($_GET['logout'])) {
    if ($auth) {
        log_activity($pdo, (int)$auth['id'], 'LOGOUT', 'user', (int)$auth['id']);
        try { $pdo->prepare("UPDATE users SET remember_token=NULL WHERE id=?")->execute([$auth['id']]); } catch(Exception $ignore){}
    }
    setcookie('okoya_remember','',time()-3600,'/');
    session_destroy();
    redirect($self);
}

if (!$auth && isset($_COOKIE['okoya_remember'])) {
    $parts = explode(':', $_COOKIE['okoya_remember'], 2);
    if (count($parts)===2 && $parts[0]!=='' && $parts[1]!=='') {
        $st = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.remember_token=? AND u.is_active=1");
        $st->execute([(int)$parts[0], $parts[1]]);
        if ($row=$st->fetch()) {
            session_regenerate_id(true);
            $_SESSION['auth']=['id'=>$row['id'],'name'=>$row['full_name'],'email'=>$row['email'],'staff_code'=>$row['staff_code'],'role'=>strtolower($row['role_name']),'role_name'=>$row['role_name'],'phone'=>$row['phone'],'last_login'=>$row['last_login_at'],'login_time'=>date('d M Y, H:i')];
            $auth=$_SESSION['auth'];
            log_activity($pdo,(int)$row['id'],'LOGIN_REMEMBER','user',(int)$row['id']);
            redirect(getDashboardForRole($row['role_name']));
        }
    }
}

if ($auth) { redirect(getDashboardForRole($auth['role_name'] ?? $auth['role'])); }

$oldLoginEmail='';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    if (($_POST['csrf']??'')!==csrf()) { flash('error','Security token mismatch. Please refresh.'); }
    elseif ($_POST['action']==='login') {
        $email=trim($_POST['email']??''); $pw=$_POST['password']??'';
        $oldLoginEmail=$email;
        $st=$pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE LOWER(u.email)=LOWER(?) LIMIT 1");
        $st->execute([$email]); $user=$st->fetch();
        if (!$user) { log_activity($pdo,null,'LOGIN_FAILED','user',null,'Unknown email: '.$email); flash('error','Invalid email or password.'); }
        elseif (!(int)$user['is_active']) { flash('error','Account disabled. Contact administrator.'); }
        elseif ($user['locked_until'] && strtotime($user['locked_until'])>time()) { flash('error','Account locked. Try again in '.ceil((strtotime($user['locked_until'])-time())/60).' minute(s).'); }
        elseif (!password_verify($pw,$user['password_hash'])) {
            $fails=(int)$user['failed_attempts']+1;
            if ($fails>=MAX_LOGIN_ATTEMPTS) {
                $pdo->prepare("UPDATE users SET failed_attempts=?, locked_until=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")->execute([$fails,LOCK_MINUTES,$user['id']]);
                log_activity($pdo,(int)$user['id'],'ACCOUNT_LOCKED','user',(int)$user['id']);
                flash('error','Too many attempts. Locked for '.LOCK_MINUTES.' minutes.');
            } else {
                $pdo->prepare("UPDATE users SET failed_attempts=? WHERE id=?")->execute([$fails,$user['id']]);
                log_activity($pdo,(int)$user['id'],'LOGIN_FAILED','user',(int)$user['id']);
                flash('error','Invalid credentials. '.(MAX_LOGIN_ATTEMPTS-$fails).' attempt(s) left.');
            }
        } else {
            session_regenerate_id(true);
            $rt=null;
            if (isset($_POST['remember'])) { $rt=bin2hex(random_bytes(32)); setcookie('okoya_remember',$user['id'].':'.$rt,time()+REMEMBER_DAYS*86400,'/','',false,true); }
            $pdo->prepare("UPDATE users SET failed_attempts=0, locked_until=NULL, last_login_at=NOW(), last_login_ip=?, remember_token=? WHERE id=?")->execute([client_ip(),$rt,$user['id']]);
            $_SESSION['auth']=['id'=>$user['id'],'name'=>$user['full_name'],'email'=>$user['email'],'staff_code'=>$user['staff_code'],'role'=>strtolower($user['role_name']),'role_name'=>$user['role_name'],'phone'=>$user['phone'],'last_login'=>$user['last_login_at'],'login_time'=>date('d M Y, H:i')];
            log_activity($pdo,(int)$user['id'],'LOGIN_SUCCESS','user',(int)$user['id']);
            flash('success','Welcome back, '.$user['full_name'].'!');
            redirect(getDashboardForRole($user['role_name']));
        }
    }
    elseif ($_POST['action']==='forgot') {
        $email=trim($_POST['email']??'');
        if (filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $st=$pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");$st->execute([$email]);
            if ($u=$st->fetch()) {
                $token=bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))")->execute([(int)$u['id'],password_hash($token,PASSWORD_DEFAULT)]);
                log_activity($pdo,(int)$u['id'],'PASSWORD_RESET_REQUESTED','user',(int)$u['id']);
            }
        }
        flash('info','If that email exists, a reset link has been generated.');
        redirect($self);
    }
}
$flash=get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Sign In | <?php echo e($company['company_name']); ?></title>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Okoya Group">
<link rel="apple-touch-icon" href="logo.ico">
<link rel="icon" type="image/x-icon" href="logo.ico">
<link rel="apple-touch-icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f8fafc;--surface:#ffffff;--surface2:#f1f5f9;
  --ink:#0f172a;--ink2:#64748b;--line:#e2e8f0;--line2:#cbd5e1;
  --primary:#059669;--primary2:#047857;--primary-soft:#ecfdf5;
  --accent:#f59e0b;--accent2:#d97706;--accent-soft:#fffbeb;
  --blue:#3b82f6;--blue2:#2563eb;--blue-soft:#eff6ff;
  --purple:#8b5cf6;--purple2:#7c3aed;--purple-soft:#f5f3ff;
  --red:#ef4444;--red-soft:#fef2f2;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);
  --shadow-sm:0 1px 2px rgba(0,0,0,.04);
  --shadow-lg:0 4px 6px rgba(0,0,0,.05),0 20px 50px rgba(0,0,0,.12);
  --fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;
  --r:14px;
}
[data-theme="dark"]{
  --bg:#0c1222;--surface:#1a2332;--surface2:#243044;
  --ink:#f1f5f9;--ink2:#94a3b8;--line:#2d3f56;--line2:#3d5068;
  --primary:#34d399;--primary2:#10b981;--primary-soft:#064e3b;
  --accent:#fbbf24;--accent2:#f59e0b;--accent-soft:#451a03;
  --blue:#60a5fa;--blue2:#3b82f6;--blue-soft:#1e3a5f;
  --purple:#a78bfa;--purple2:#8b5cf6;--purple-soft:#2e1065;
  --red:#f87171;--red-soft:#450a0a;
  --shadow:0 1px 3px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.3);
  --shadow-sm:0 1px 2px rgba(0,0,0,.2);
  --shadow-lg:0 4px 6px rgba(0,0,0,.2),0 20px 50px rgba(0,0,0,.4);
}
html,body{min-height:100%}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;display:flex;flex-direction:column;transition:background .3s,color .3s;-webkit-font-smoothing:antialiased}
svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:16px;color:var(--ink)}
::selection{background:color-mix(in srgb,var(--primary) 25%,transparent)}
.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ===== PREMIUM LOADING SCREEN ===== */
.loader-screen{position:fixed;inset:0;z-index:9999;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .6s ease,visibility .6s ease}
.loader-screen.hidden{opacity:0;visibility:hidden;pointer-events:none}
.ls-logo-wrap{position:relative;width:88px;height:88px;margin-bottom:32px}
.ls-logo{width:88px;height:88px;border-radius:22px;background:var(--surface);border:1px solid var(--line);display:grid;place-items:center;overflow:hidden;box-shadow:var(--shadow-lg);animation:logoEntrance .8s cubic-bezier(.34,1.56,.64,1) forwards;opacity:0;transform:scale(.5)}
@keyframes logoEntrance{to{opacity:1;transform:scale(1)}}
.ls-logo img{width:68px;height:68px;object-fit:contain}
.ls-ring{position:absolute;inset:-12px;border-radius:50%;border:2px solid transparent;border-top-color:var(--primary);border-right-color:var(--accent);animation:ringSpin 1.2s linear infinite;opacity:0;animation-delay:.4s;animation-fill-mode:forwards}
.ls-ring-2{position:absolute;inset:-24px;border-radius:50%;border:1.5px solid transparent;border-bottom-color:var(--blue);border-left-color:var(--purple);animation:ringSpin 2s linear infinite reverse;opacity:0;animation-delay:.6s;animation-fill-mode:forwards}
@keyframes ringSpin{0%{opacity:0;transform:rotate(0deg)}10%{opacity:1}100%{opacity:1;transform:rotate(360deg)}}
.ls-bar-wrap{width:200px;height:3px;background:var(--line);border-radius:99px;overflow:hidden;opacity:0;animation:barFadeIn .4s ease .8s forwards}
@keyframes barFadeIn{to{opacity:1}}
.ls-bar{height:100%;width:0;background:linear-gradient(90deg,var(--primary),var(--accent),var(--blue));border-radius:99px;animation:barFill 1.4s ease-in-out .8s forwards}
@keyframes barFill{0%{width:0}30%{width:45%}60%{width:75%}100%{width:100%}}
.ls-text{font-family:var(--fd);font-size:13px;font-weight:600;color:var(--ink2);letter-spacing:.3px;margin-top:20px;opacity:0;animation:textFadeUp .5s ease 1s forwards}
@keyframes textFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.ls-company{font-family:var(--fd);font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--ink2);opacity:0;margin-top:10px;animation:textFadeUp .5s ease 1.2s forwards}

#app{opacity:0;transition:opacity .5s ease}
#app.visible{opacity:1;animation:appReveal .6s ease forwards}
@keyframes appReveal{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ===== LAYOUT ===== */
.login-layout{display:grid;grid-template-columns:1fr 1fr;min-height:100vh;flex:1}

/* ===== LEFT PANEL ===== */
.login-left{background:linear-gradient(135deg,#059669 0%,#047857 30%,#065f46 60%,#064e3b 100%);color:#fff;padding:48px;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden}
.login-left::before{content:'';position:absolute;top:-20%;right:-10%;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(245,158,11,.18) 0%,transparent 70%);pointer-events:none;animation:glowPulse 6s ease-in-out infinite}
.login-left::after{content:'';position:absolute;bottom:-15%;left:-5%;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(59,130,246,.15) 0%,transparent 70%);pointer-events:none;animation:glowPulse 8s ease-in-out infinite 2s}
@keyframes glowPulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.15);opacity:1}}
.ll-decoration{position:absolute;top:0;left:0;right:0;bottom:0;pointer-events:none;overflow:hidden}
.ll-blob{position:absolute;border-radius:50%;opacity:.05;background:#fff}
.ll-blob-1{width:350px;height:350px;top:-100px;right:-80px;animation:blobFloat 10s ease-in-out infinite}
.ll-blob-2{width:250px;height:250px;bottom:60px;left:-60px;animation:blobFloat 14s ease-in-out infinite reverse}
.ll-blob-3{width:180px;height:180px;top:45%;right:15%;animation:blobFloat 16s ease-in-out infinite 3s}
@keyframes blobFloat{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(25px,-20px) scale(1.08)}66%{transform:translate(-15px,15px) scale(.95)}}
.ll-content{position:relative;z-index:1}
.ll-logo{display:flex;align-items:center;gap:14px;margin-bottom:40px;opacity:0;animation:slideInLeft .6s ease .3s forwards}
@keyframes slideInLeft{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
.ll-logo-img{width:52px;height:52px;background:rgba(255,255,255,.15);backdrop-filter:blur(12px);border-radius:14px;display:grid;place-items:center;overflow:hidden;flex:none;border:1px solid rgba(255,255,255,.25);transition:transform .3s ease}
.ll-logo-img:hover{transform:scale(1.05) rotate(-2deg)}
.ll-logo-img img{width:44px;height:44px;object-fit:contain}
.ll-logo-text{font-family:var(--fd);font-weight:800;font-size:16px;line-height:1.3}
.ll-logo-sub{font-size:11px;opacity:.7;font-weight:600;margin-top:2px}
.ll-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:28px;opacity:0;animation:slideInLeft .6s ease .5s forwards}
.ll-tags span{font-size:8px;font-weight:800;letter-spacing:1.2px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);padding:5px 12px;border-radius:99px;text-transform:uppercase;backdrop-filter:blur(4px);transition:background .2s}
.ll-tags span:hover{background:rgba(255,255,255,.2)}
.ll-title{font-family:var(--fd);font-weight:800;font-size:clamp(24px,3vw,38px);line-height:1.15;margin-bottom:14px;opacity:0;animation:slideInLeft .6s ease .6s forwards}
.ll-title em{font-style:normal;color:#fbbf24;text-shadow:0 0 30px rgba(251,191,36,.3)}
.ll-sub{font-size:14px;opacity:0;font-weight:500;line-height:1.7;margin-bottom:36px;max-width:420px;animation:slideInLeft .6s ease .7s forwards}
.ll-feats{display:flex;flex-direction:column;gap:14px;opacity:0;animation:slideInLeft .6s ease .8s forwards}
.ll-feat{display:flex;align-items:center;gap:14px;font-size:13px;font-weight:600;padding:10px 14px;border-radius:12px;transition:background .2s}
.ll-feat:hover{background:rgba(255,255,255,.08)}
.ll-feat-icon{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.1);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.12);display:grid;place-items:center;flex:none;transition:transform .3s}
.ll-feat:hover .ll-feat-icon{transform:scale(1.1) rotate(-3deg)}
.ll-feat-icon svg{width:18px;height:18px;stroke:#fbbf24}
.ll-footer{margin-top:auto;padding-top:32px;position:relative;z-index:1;opacity:0;animation:fadeIn .6s ease 1s forwards}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.ll-clock{font-family:var(--fm);font-size:13px;font-weight:600;opacity:.7;display:flex;align-items:center;gap:8px}
.ll-clock strong{opacity:1;font-size:15px}
.ll-addr{font-size:11px;opacity:.6;font-weight:600;display:flex;align-items:center;gap:6px;margin-top:6px}
.ll-addr svg{width:13px;height:13px}
.ll-copy{font-size:10px;opacity:.4;margin-top:8px}

/* ===== RIGHT PANEL ===== */
.login-right{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 24px;position:relative;background:var(--bg);min-height:100vh}
.login-right::before{content:'';position:absolute;top:0;right:0;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--primary) 6%,transparent),transparent 70%);pointer-events:none}
.theme-toggle{position:absolute;top:20px;right:20px;width:42px;height:42px;border-radius:12px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:all .3s ease;cursor:pointer;z-index:10}
.theme-toggle:hover{color:var(--primary);border-color:var(--primary);transform:rotate(180deg);box-shadow:0 0 20px color-mix(in srgb,var(--primary) 20%,transparent)}
.theme-toggle svg{width:18px;height:18px;stroke:currentColor}

.login-card{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow-lg);overflow:hidden;position:relative;opacity:0;animation:cardEntrance .7s cubic-bezier(.34,1.56,.64,1) .2s forwards}
@keyframes cardEntrance{from{opacity:0;transform:translateY(30px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
.login-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--primary),var(--accent),var(--blue),var(--purple));border-radius:24px 24px 0 0}
.lc-header{padding:36px 32px 0;text-align:center}
.lc-header h2{font-family:var(--fd);font-size:26px;font-weight:800;margin-bottom:6px;color:var(--ink)}
.lc-header p{color:var(--ink2);font-size:13px;font-weight:500}
.lc-body{padding:28px 32px 32px}
.role-note{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--primary-soft),color-mix(in srgb,var(--blue-soft) 50%,var(--primary-soft)));border:1px solid color-mix(in srgb,var(--primary) 15%,transparent);color:var(--primary);border-radius:12px;padding:11px 14px;font-size:12px;font-weight:700;margin-bottom:22px;animation:noteSlide .5s ease .4s both}
@keyframes noteSlide{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.role-note svg{width:16px;height:16px;stroke:var(--primary);flex:none}
.field{margin-bottom:18px;opacity:0;animation:fieldFadeIn .4s ease both}
.field:nth-child(2){animation-delay:.5s}
.field:nth-child(3){animation-delay:.6s}
@keyframes fieldFadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.fl{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2);margin-bottom:6px}
.fl b{color:var(--red)}
.ctrl{display:flex;align-items:center;gap:10px;background:var(--surface2);border:1.5px solid var(--line);border-radius:12px;padding:0 14px;height:50px;transition:all .25s ease}
.ctrl:focus-within{border-color:var(--primary);box-shadow:0 0 0 4px color-mix(in srgb,var(--primary) 12%,transparent);background:var(--surface);transform:translateY(-1px)}
.ctrl.invalid{border-color:var(--red)!important;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}
.ctrl svg{flex:none;width:18px;height:18px;stroke:var(--ink2);transition:stroke .2s}
.ctrl:focus-within svg{stroke:var(--primary)}
.ctrl input{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;min-width:0}
.pw-eye{border:none;background:none;color:var(--ink2);display:grid;place-items:center;padding:4px;border-radius:6px;transition:all .2s;cursor:pointer}
.pw-eye:hover{color:var(--primary);background:var(--primary-soft)}
.pw-eye svg{width:18px;height:18px;stroke:currentColor}
.form-row{display:flex;justify-content:space-between;align-items:center;margin:4px 0 24px;font-size:12px;font-weight:700;gap:10px;flex-wrap:wrap;opacity:0;animation:fieldFadeIn .4s ease .7s both}
.link{color:var(--primary);font-weight:700;text-decoration:none;transition:color .2s}.link:hover{color:var(--primary2);text-decoration:underline}
.remember{display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;font-weight:700;font-size:12px;position:relative}
.remember input{position:absolute;opacity:0}
.remember .trk{position:relative;display:inline-block;width:38px;height:22px;border-radius:99px;background:var(--line2);transition:all .3s ease;flex:none}
.remember .trk::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:var(--shadow-sm);transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.remember input:checked~.trk{background:var(--primary)}
.remember input:checked~.trk::after{left:19px;transform:scale(1.1)}
.btn-submit{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;border:none;border-radius:14px;font-weight:800;font-size:15px;padding:16px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 4px 14px color-mix(in srgb,var(--primary) 30%,transparent);transition:all .3s ease;cursor:pointer;position:relative;overflow:hidden;opacity:0;animation:btnEntrance .5s ease .8s both}
@keyframes btnEntrance{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.btn-submit::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.2),transparent);transition:left .6s ease}
.btn-submit:hover::before{left:100%}
.btn-submit:hover{transform:translateY(-3px);box-shadow:0 8px 25px color-mix(in srgb,var(--primary) 40%,transparent)}
.btn-submit:active{transform:translateY(-1px) scale(.99)}
.btn-submit svg{width:18px;height:18px;stroke:currentColor}
.lc-footer{padding:14px 32px;border-top:1px solid var(--line);text-align:center;font-size:11px;color:var(--ink2);font-weight:600;background:var(--surface2)}
.lc-footer span{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
.lc-footer svg{width:11px;height:11px;stroke:var(--primary);stroke-width:2.5}

/* ===== PAGE FOOTER ===== */
.page-footer{width:100%;max-width:440px;margin-top:20px;padding:16px 0;text-align:center;border-top:1px solid var(--line);opacity:0;animation:fadeIn .5s ease 1s forwards}
.page-footer .pf-dev{font-size:11px;font-weight:700;color:var(--ink2);letter-spacing:.3px}
.page-footer .pf-dev span{color:var(--primary);font-weight:800}
.page-footer .pf-copy{font-size:10px;color:var(--ink2);opacity:.5;margin-top:4px}

/* ===== TOASTS ===== */
.toasts{position:fixed;top:20px;right:20px;z-index:200;display:flex;flex-direction:column;gap:10px}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--primary);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow-lg);animation:toastIn .4s cubic-bezier(.34,1.56,.64,1);max-width:360px;backdrop-filter:blur(8px)}
@keyframes toastIn{from{opacity:0;transform:translateX(40px) scale(.9)}to{opacity:1;transform:translateX(0) scale(1)}}
.toast.error{border-left-color:var(--red)}.toast.info{border-left-color:var(--blue)}
.toast.out{opacity:0;transform:translateX(20px);transition:.4s ease}

/* ===== MODAL ===== */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:150;padding:20px}
.modal-overlay.open{display:flex;animation:overlayIn .3s ease}
@keyframes overlayIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--surface);border:1px solid var(--line);border-radius:24px;max-width:400px;width:100%;padding:28px;text-align:center;position:relative;box-shadow:var(--shadow-lg);animation:modalPop .4s cubic-bezier(.34,1.56,.64,1)}
@keyframes modalPop{from{opacity:0;transform:scale(.85) translateY(20px)}to{opacity:1;transform:scale(1) translateY(0)}}
.m-x{position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:16px;cursor:pointer;display:grid;place-items:center;transition:all .2s}
.m-x:hover{background:var(--red-soft);color:var(--red);border-color:var(--red)}
.modal h3{font-family:var(--fd);font-size:20px;font-weight:800;margin-top:8px}
.m-sub{color:var(--ink2);font-size:13px;font-weight:500;margin:6px 0 18px}
.modal .ctrl{margin-bottom:16px}
.btn-modal{display:flex;align-items:center;justify-content:center;width:100%;border:none;border-radius:14px;font-weight:800;font-size:14px;padding:14px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;cursor:pointer;transition:all .3s;box-shadow:0 4px 14px color-mix(in srgb,var(--primary) 25%,transparent)}
.btn-modal:hover{transform:translateY(-2px);box-shadow:0 6px 20px color-mix(in srgb,var(--primary) 35%,transparent)}

@media(max-width:1000px){
  .login-layout{grid-template-columns:1fr}
  .login-left{padding:32px 24px}
  .ll-feats{display:none}
  .ll-sub{margin-bottom:20px}
  .ll-footer{margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,.1)}
  .login-right{padding:32px 20px;min-height:auto}
}
@media(max-width:600px){
  .login-left{padding:24px 20px}
  .ll-logo{margin-bottom:24px}
  .ll-logo-img{width:46px;height:46px}
  .ll-logo-img img{width:38px;height:38px}
  .ll-title{font-size:22px}
  .login-right{padding:24px 16px}
  .lc-header{padding:24px 20px 0}
  .lc-header h2{font-size:22px}
  .lc-body{padding:20px}
  .lc-footer{padding:12px 20px}
  .page-footer{margin-top:16px;padding:12px 0}
  .toasts{top:auto;bottom:16px;left:12px;right:12px;align-items:stretch}
  .toast{max-width:none}
  .theme-toggle{top:12px;right:12px;width:38px;height:38px}
}
@media(max-width:400px){.form-row{flex-direction:column;align-items:flex-start;gap:12px}}
</style>
</head>
<body>
   <script>
(function(){
  var base='https://okoya.thsite.top';
  var m={"name":"Okoya Group Company Limited","short_name":"Okoya Group","description":"Secure access for all authorized personnel.","start_url":base+"/","scope":base+"/","display":"standalone","orientation":"any","dir":"ltr","lang":"en","theme_color":"#059669","background_color":"#f8fafc","id":"/okoya-group-pwa","categories":["business","productivity"],"icons":[{"src":base+"/icon-64.png","type":"image/png","sizes":"64x64","purpose":"any"},{"src":base+"/icon-192.png","type":"image/png","sizes":"192x192","purpose":"any"},{"src":base+"/icon-192-maskable.png","type":"image/png","sizes":"192x192","purpose":"maskable"},{"src":base+"/LargeTile.scale-100.png","type":"image/png","sizes":"310x310","purpose":"any"},{"src":base+"/icon-512.png","type":"image/png","sizes":"512x512","purpose":"any"},{"src":base+"/icon-512-maskable.png","type":"image/png","sizes":"512x512","purpose":"maskable"}]};
  var b=new Blob([JSON.stringify(m)],{type:'application/manifest+json'});
  var u=URL.createObjectURL(b);
  var l=document.createElement('link');
  l.rel='manifest';
  l.href=u;
  document.head.appendChild(l);
})();
</script>



<!-- LOADING SCREEN -->
<div class="loader-screen" id="loaderScreen">
  <div class="ls-logo-wrap">
    <div class="ls-logo"><img src="logo.ico" alt="Okoya Group Logo"></div>
    <div class="ls-ring"></div>
    <div class="ls-ring-2"></div>
  </div>
  <div class="ls-bar-wrap"><div class="ls-bar"></div></div>
  <div class="ls-text">Initializing secure environment…</div>
  <div class="ls-company"><?php echo e($company['company_name']); ?></div>
</div>

<!-- MAIN APP -->
<div id="app">
<div class="login-layout">

  <!-- LEFT PANEL -->
  <div class="login-left">
    <div class="ll-decoration">
      <div class="ll-blob ll-blob-1"></div>
      <div class="ll-blob ll-blob-2"></div>
      <div class="ll-blob ll-blob-3"></div>
    </div>
    <div class="ll-content">
      <div class="ll-logo">
        <div class="ll-logo-img"><img src="logo.ico" alt="Okoya Group Logo"></div>
        <div>
          <div class="ll-logo-text"><?php echo e($company['company_name']); ?></div>
          <div class="ll-logo-sub"><?php echo e($company['company_dept']); ?></div>
        </div>
      </div>
      <div class="ll-tags"><?php foreach(explode('•',$company['company_tagline']) as $tag): ?><span><?php echo e(trim($tag)); ?></span><?php endforeach; ?></div>
      <div class="ll-title">Enterprise <em>Staff Portal</em><br>&amp; Order Management</div>
      <div class="ll-sub">Secure access for all authorized personnel. Manage orders, logistics, HR operations, and departmental workflows from a unified platform.</div>
      <div class="ll-feats">
        <div class="ll-feat"><div class="ll-feat-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg></div>Real-time order tracking</div>
        <div class="ll-feat"><div class="ll-feat-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg></div>Digital signatures &amp; approvals</div>
        <div class="ll-feat"><div class="ll-feat-icon"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>Logistics &amp; dispatch management</div>
        <div class="ll-feat"><div class="ll-feat-icon"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>Role-based access control</div>
      </div>
      <div class="ll-footer">
        <div class="ll-clock"><svg class="icon-svg" width="14" height="14" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><strong id="clockTime">--:--:--</strong><span id="clockDate">Loading…</span></div>
        <div class="ll-addr"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><?php echo e($company['company_address']); ?></div>
        <div class="ll-copy">&copy; 2026 <?php echo e($company['company_name']); ?>. All rights reserved.</div>
      </div>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="login-right">
    <button class="theme-toggle" id="themeBtn" title="Toggle theme" aria-label="Toggle dark mode">
      <svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>
    </button>

    <div class="login-card">
      <div class="lc-header">
        <h2>Welcome Back</h2>
        <p>Sign in to your Okoya Group account</p>
      </div>
      <div class="lc-body">
        <form method="post" id="loginForm" novalidate>
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
          <div class="role-note">
            <svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
            Your role is detected automatically
          </div>
          <div class="field">
            <span class="fl">Email Address <b>*</b></span>
            <div class="ctrl">
              <svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
              <input type="email" name="email" id="loginEmail" placeholder="you@okoyagroup.com" value="<?php echo e($oldLoginEmail); ?>" autocomplete="email" required>
            </div>
          </div>
          <div class="field">
            <span class="fl">Password <b>*</b></span>
            <div class="ctrl">
              <svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" name="password" id="loginPassword" placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="pw-eye" data-target="loginPassword" aria-label="Show password"></button>
            </div>
          </div>
          <div class="form-row">
            <label class="remember"><input type="checkbox" name="remember"><span class="trk"></span>Remember me</label>
            <a href="#" class="link" id="forgotLink">Forgot password?</a>
          </div>
          <button type="submit" class="btn-submit">
            <svg class="icon-svg" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></svg>
            Sign In Securely
          </button>
        </form>
      </div>
      <div class="lc-footer">
        <span>
          <svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Protected by enterprise-grade security
        </span>
      </div>
    </div>

    <footer class="page-footer">
      <div class="pf-dev">Developed by <span>Lawani Djamiou Alade</span></div>
      <div class="pf-copy">&copy; 2026 <?php echo e($company['company_name']); ?>. All rights reserved.</div>
    </footer>
  </div>

</div>
</div><!-- /#app -->

<!-- FORGOT MODAL -->
<div class="modal-overlay" id="forgotOverlay">
  <div class="modal">
    <button class="m-x" id="forgotClose" aria-label="Close">&times;</button>
    <div style="width:48px;height:48px;margin:0 auto 12px;border-radius:14px;background:var(--primary-soft);display:grid;place-items:center">
      <svg width="24" height="24" class="icon-svg" style="stroke:var(--primary)" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <h3>Reset Password</h3>
    <p class="m-sub">Enter your registered email and a secure reset token will be generated.</p>
    <form method="post">
      <input type="hidden" name="action" value="forgot">
      <input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
      <div class="ctrl">
        <svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
        <input type="email" name="email" id="forgotEmail" placeholder="you@okoyagroup.com" required>
      </div>
      <button class="btn-modal" type="submit">Generate Reset Token</button>
    </form>
  </div>
</div>

<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const FLASH=<?php echo json_encode($flash,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

/* ===== LOADING SCREEN ===== */
window.addEventListener('load',function(){
  setTimeout(function(){
    $('loaderScreen').classList.add('hidden');
    $('app').classList.add('visible');
  },1800);
});

/* ===== TOAST SYSTEM ===== */
function toast(msg,type='success'){
  const icons={success:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',error:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',info:'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'};
  const t=document.createElement('div');t.className='toast '+type;
  t.innerHTML='<span>'+(icons[type]||'')+'</span>'+esc(msg);
  $('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);
}

/* ===== THEME ===== */
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
const sunSVG='<svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg>';
const moonSVG='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>';
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);$('themeBtn').innerHTML=t==='dark'?sunSVG:moonSVG;}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));

/* ===== CLOCK ===== */
function tick(){const now=new Date();try{$('clockTime').textContent=new Intl.DateTimeFormat('en-NG',{timeZone:'Africa/Lagos',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(now);$('clockDate').textContent=new Intl.DateTimeFormat('en-NG',{timeZone:'Africa/Lagos',weekday:'short',day:'2-digit',month:'short',year:'numeric'}).format(now);}catch(err){$('clockTime').textContent=now.toLocaleTimeString();$('clockDate').textContent=now.toLocaleDateString();}}
tick();setInterval(tick,1000);

/* ===== PASSWORD TOGGLE ===== */
const eyeOn='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOff='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.2 13.2 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="M2 2l20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
document.querySelectorAll('.pw-eye').forEach(b=>{b.innerHTML=eyeOn;b.addEventListener('click',()=>{const i=$(b.dataset.target);const s=i.type==='password';i.type=s?'text':'password';b.innerHTML=s?eyeOff:eyeOn;});});

/* ===== FORM VALIDATION ===== */
function mark(el){el.closest('.ctrl').classList.add('invalid')}
const validEmail=v=>/^\S+@\S+\.\S+$/.test(v);
$('loginForm').addEventListener('submit',e=>{
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));
  let ok=true,msg='';
  const em=$('loginEmail'),pw=$('loginPassword');
  if(!em.value.trim()||!validEmail(em.value.trim())){mark(em);ok=false;msg='Please enter a valid email address.';}
  else if(!pw.value||pw.value.length<6){mark(pw);ok=false;msg='Please enter your password.';}
  if(!ok){e.preventDefault();toast(msg,'error');}
});
document.querySelectorAll('#loginForm input').forEach(i=>{i.addEventListener('input',()=>{const c=i.closest('.ctrl');if(c)c.classList.remove('invalid');});});

/* ===== FORGOT MODAL ===== */
function openForgot(){$('forgotOverlay').classList.add('open');$('forgotEmail').focus();}
function closeForgot(){$('forgotOverlay').classList.remove('open');}
$('forgotLink').addEventListener('click',e=>{e.preventDefault();openForgot();});
$('forgotClose').addEventListener('click',closeForgot);
$('forgotOverlay').addEventListener('click',e=>{if(e.target===$('forgotOverlay'))closeForgot();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeForgot();});

/* ===== FLASH MESSAGES ===== */
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':(FLASH.type==='error'?'error':'info')));

/* ===== SERVICE WORKER REGISTRATION ===== */
if('serviceWorker' in navigator){
  window.addEventListener('load',function(){
    navigator.serviceWorker.register('/sw.js')
      .then(function(reg){console.log('SW registered:',reg.scope)})
      .catch(function(err){console.error('SW registration failed:',err)});
  });
}

/* ===== PWA INSTALL PROMPT ===== */
let deferredPrompt;
window.addEventListener('beforeinstallprompt',function(e){
  e.preventDefault();
  deferredPrompt=e;
  setTimeout(function(){
    if(deferredPrompt){
      toast('Tap to install Okoya Group app','info');
    }
  },3000);
});

</script>
</body>
</html>