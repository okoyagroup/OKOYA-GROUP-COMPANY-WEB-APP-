<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function csrf():string{ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department'];

try{ $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `profile_photo` LONGTEXT NULL AFTER `phone`"); }catch(Exception $ignore){}

/* ===== FETCH CURRENT USER ===== */
$me=null;
try{
    $st=$pdo->prepare("SELECT u.*, s.passport_photo AS staff_photo FROM users u LEFT JOIN staff s ON s.staff_code=u.staff_code WHERE u.id=?");
    $st->execute([$auth['id']]);
    $me=$st->fetch();
}catch(Exception $ignore){}
$photo = $me['profile_photo'] ?: ($me['staff_photo'] ?? '');

/* ===== ADMIN: CREATE USER ===== */
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_create_user') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $staffCode=trim($_POST['staff_code']??'');
        $email=strtolower(trim($_POST['new_email']??''));
        $pw=$_POST['new_password']??'';
        $role=trim($_POST['new_role']??'staff');
        $errs=[];
        if($staffCode==='')$errs[]='Select a staff member.';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))$errs[]='Valid email required.';
        if(strlen($pw)<6)$errs[]='Password must be at least 6 characters.';
        if(empty($errs)){
            try{
                $st=$pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?)");$st->execute([$email]);
                if($st->fetch())$errs[]='Email already in use.';
                else{
                    $st=$pdo->prepare("SELECT id FROM users WHERE staff_code=?");$st->execute([$staffCode]);
                    if($st->fetch())$errs[]='This staff member already has a login account.';
                }
                if(empty($errs)){
                    $hash=password_hash($pw,PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (staff_code,email,password_hash,role_id,is_active,created_at) SELECT ?,?,?,id,1,NOW() FROM roles WHERE name=? LIMIT 1")
                        ->execute([$staffCode,$email,$hash,$role]);
                    try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'CREATE_USER','user',(int)$pdo->lastInsertId(),"Created login for staff: $staffCode"]);}catch(Exception $ignore){}
                    flash('success','User account created successfully.');
                }
            }catch(Exception $ex){$errs[]='DB error: '.$ex->getMessage();}
        }
        if(!empty($errs))flash('error',implode(' ',$errs));
        header('Location: settings.php#users');exit;
    }
}

/* ===== ADMIN: UPDATE USER ===== */
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update_user') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $uid=(int)($_POST['user_id']??0);
        $email=strtolower(trim($_POST['edit_email']??''));
        $role=trim($_POST['edit_role']??'staff');
        $active=isset($_POST['edit_active'])?1:0;
        $newPw=trim($_POST['edit_password']??'');
        if($uid>0 && filter_var($email,FILTER_VALIDATE_EMAIL)){
            try{
                $st=$pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?");$st->execute([$email,$uid]);
                if($st->fetch())flash('error','Email already in use by another account.');
                else{
                    $roleId=null;
                    try{$st2=$pdo->prepare("SELECT id FROM roles WHERE name=?");$st2->execute([$role]);$rr=$st2->fetch();if($rr)$roleId=$rr['id'];}catch(Exception $ignore){}
                    if($newPw!==''){
                        $hash=password_hash($newPw,PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET email=?,role_id=?,is_active=?,password_hash=? WHERE id=?")->execute([$email,$roleId,$active,$hash,$uid]);
                    }else{
                        $pdo->prepare("UPDATE users SET email=?,role_id=?,is_active=? WHERE id=?")->execute([$email,$roleId,$active,$uid]);
                    }
                    try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'UPDATE_USER','user',$uid,"Updated user ID $uid"]);}catch(Exception $ignore){}
                    flash('success','User updated.');
                }
            }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
        }else flash('error','Valid email required.');
        header('Location: settings.php#users');exit;
    }
}

/* ===== ADMIN: TOGGLE USER ACTIVE ===== */
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_toggle_user') {
    $uid=(int)($_POST['user_id']??0);
    if($uid>0 && $uid!=$auth['id']){
        try{
            $pdo->prepare("UPDATE users SET is_active=1-is_active WHERE id=?")->execute([$uid]);
            flash('success','User status toggled.');
        }catch(Exception $ex){flash('error','Toggle failed.');}
    }else flash('error','Cannot toggle your own account.');
    header('Location: settings.php#users');exit;
}

/* ===== ADMIN: DELETE USER ===== */
if ($isAdmin && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete_user') {
    $uid=(int)($_POST['user_id']??0);
    if($uid>0 && $uid!=$auth['id']){
        try{
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'DELETE_USER','user',$uid,"Deleted user ID $uid"]);}catch(Exception $ignore){}
            flash('success','User deleted.');
        }catch(Exception $ex){flash('error','Delete failed.');}
    }else flash('error','Cannot delete your own account.');
    header('Location: settings.php#users');exit;
}

/* ===== UPDATE PROFILE PHOTO ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_photo') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $photoData=$_POST['profile_photo']??'';
        if($photoData && strlen($photoData)<700000){
            try{
                $pdo->prepare("UPDATE users SET profile_photo=? WHERE id=?")->execute([$photoData,$auth['id']]);
                try{ $pdo->prepare("UPDATE staff SET passport_photo=? WHERE staff_code=?")->execute([$photoData,$auth['staff_code']]); }catch(Exception $ignore){}
                flash('success','Profile photo updated.');
            }catch(Exception $ex){ flash('error','Error: '.$ex->getMessage()); }
        } else flash('error','Please choose an image under 500KB.');
        header('Location: settings.php'); exit;
    }
}

/* ===== UPDATE PERSONAL INFO ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_profile') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');$email=strtolower(trim($_POST['email']??''));$curPw=$_POST['current_password']??'';
        $emailChanged=$me&&strtolower($me['email'])!==$email;
        if(strlen($name)<3)flash('error','Full name must be at least 3 characters.');
        elseif(!filter_var($email,FILTER_VALIDATE_EMAIL))flash('error','Valid email required.');
        else{
            try{
                if($emailChanged){
                    if(!password_verify($curPw,$me['password_hash']))flash('error','Enter current password to change email.');
                    else{$st=$pdo->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?");$st->execute([$email,$auth['id']]);if($st->fetch())flash('error','Email already in use.');}
                }
                if(!isset($_SESSION['flash'])){
                    $pdo->prepare("UPDATE users SET full_name=?,phone=?,email=? WHERE id=?")->execute([$name,$phone?:null,$email,$auth['id']]);
                    try{$pdo->prepare("UPDATE staff SET full_name=?,phone=? WHERE staff_code=?")->execute([$name,$phone?:null,$auth['staff_code']]);}catch(Exception $ignore){}
                    $_SESSION['auth']['name']=$name;$_SESSION['auth']['phone']=$phone;$_SESSION['auth']['email']=$email;
                    flash('success','Profile updated.');
                }
            }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
        }
        header('Location: settings.php');exit;
    }
}

/* ===== CHANGE PASSWORD ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_password') {
    if(($_POST['csrf']??'')!==csrf()) flash('error','Security token mismatch.');
    else{
        $cur=$_POST['current_password']??'';$new=$_POST['new_password']??'';$new2=$_POST['new_password2']??'';
        if(!password_verify($cur,$me['password_hash']))flash('error','Current password is incorrect.');
        elseif(strlen($new)<8)flash('error','New password must be at least 8 characters.');
        elseif($new!==$new2)flash('error','Passwords do not match.');
        elseif($new===$cur)flash('error','New password must differ from current.');
        else{
            try{
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$auth['id']]);
                flash('success','Password changed.');
            }catch(Exception $ex){flash('error','Error: '.$ex->getMessage());}
        }
        header('Location: settings.php');exit;
    }
}

/* ===== ADMIN: FETCH ALL USERS + STAFF LIST ===== */
$allUsers=[];$staffList=[];$rolesList=[];
if($isAdmin){
    try{
        $st=$pdo->query("SELECT u.id,u.staff_code,u.email,u.is_active,u.created_at,u.last_login_at,r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY u.id ASC");
        $allUsers=$st->fetchAll();
    }catch(Exception $ignore){}
    try{
        /* FIXED: Use status='Active' instead of is_active=1, and position instead of designation */
        $st=$pdo->query("SELECT staff_code,full_name,department,position,salary,bank_name,bank_account_no,bank_account_name FROM staff WHERE status='Active' ORDER BY full_name ASC");
        $staffList=$st->fetchAll();
    }catch(Exception $ignore){}
    try{
        $st=$pdo->query("SELECT name FROM roles ORDER BY name ASC");
        $rolesList=$st->fetchAll(PDO::FETCH_COLUMN);
    }catch(Exception $ignore){}
}

$flashData = get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Settings | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--bg:#f8fafc;--surface:#fff;--surface2:#f1f5f9;--ink:#0f172a;--ink2:#64748b;--line:#e2e8f0;--green:#059669;--green2:#047857;--green-soft:#ecfdf5;--gold:#d97706;--gold2:#b45309;--gold-soft:#fffbeb;--red:#dc2626;--red-soft:#fef2f2;--blue:#2563eb;--blue-soft:#eff6ff;--shadow:0 1px 3px rgba(0,0,0,.06),0 8px 24px rgba(0,0,0,.06);--shadow-sm:0 1px 2px rgba(0,0,0,.04);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:14px}[data-theme="dark"]{--bg:#0c1222;--surface:#1a2332;--surface2:#243044;--ink:#f1f5f9;--ink2:#94a3b8;--line:#2d3f56;--green:#34d399;--green2:#10b981;--green-soft:#064e3b;--gold:#fbbf24;--gold2:#f59e0b;--gold-soft:#451a03;--red:#f87171;--red-soft:#450a0a;--blue:#60a5fa;--blue-soft:#1e3a5f;--shadow:0 1px 3px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.3);--shadow-sm:0 1px 2px rgba(0,0,0,.2)}body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 10%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 8%,transparent),transparent 60%)}.container{max-width:1200px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:10px;margin-right:auto}.logo-chip{width:38px;height:38px;border-radius:10px;background:var(--surface);display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:32px;height:32px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:13px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--ink2);padding:6px 12px;border-radius:8px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--green);border-color:var(--green)}.back-link svg{width:13px;height:13px}.badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;letter-spacing:.8px;padding:5px 9px;border-radius:99px}.badge.admin{background:var(--gold-soft);color:var(--gold2)}.theme-btn{width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}.theme-btn svg{width:15px;height:15px}
.page-header{padding:28px 0 6px}.page-header h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}.page-header h1 svg{width:26px;height:26px;stroke:var(--green)}.page-header h1 em{font-style:normal;color:var(--green)}.page-header p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.tabs{display:flex;gap:4px;margin-top:18px;border-bottom:2px solid var(--line);overflow-x:auto}.tab{padding:10px 18px;font-size:12px;font-weight:800;color:var(--ink2);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.2s;white-space:nowrap;text-transform:uppercase;letter-spacing:.5px}.tab:hover{color:var(--ink)}.tab.active{color:var(--green);border-bottom-color:var(--green)}
.tab-content{display:none;margin-top:18px}.tab-content.active{display:block}
.layout{display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start}.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 18px;border-bottom:1px solid var(--line);font-family:var(--fd);font-size:13px;font-weight:800}.card-head svg{width:16px;height:16px;stroke:var(--green)}
.profile-card{padding:22px 18px;text-align:center}.avatar-wrap{position:relative;width:100px;height:100px;margin:0 auto 12px}.avatar{width:100px;height:100px;border-radius:24px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:32px;font-weight:800;overflow:hidden;border:3px solid var(--surface);box-shadow:var(--shadow)}.avatar img{width:100%;height:100%;object-fit:cover}.avatar-edit{position:absolute;bottom:-4px;right:-4px;width:32px;height:32px;border-radius:50%;background:var(--gold);color:#fff;display:grid;place-items:center;border:2px solid var(--surface);cursor:pointer;transition:.2s;box-shadow:var(--shadow-sm)}.avatar-edit:hover{transform:scale(1.1)}.avatar-edit svg{width:14px;height:14px}.profile-name{font-family:var(--fd);font-size:17px;font-weight:800}.profile-role{display:inline-block;margin-top:5px;font-size:9px;font-weight:800;letter-spacing:.7px;background:var(--gold-soft);color:var(--gold2);padding:3px 10px;border-radius:99px;text-transform:uppercase}.pm-row{display:flex;justify-content:space-between;gap:8px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:11px;margin-top:6px}.pm-row span{color:var(--ink2);font-weight:700;flex:none}.pm-row b{font-weight:700;text-align:right;word-break:break-all}
.forms{display:flex;flex-direction:column;gap:16px}.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 18px}.span2{grid-column:1/-1}.field{display:flex;flex-direction:column;gap:4px}.fl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.fl b{color:var(--red)}.ctrl{display:flex;align-items:center;gap:7px;background:var(--surface2);border:1.5px solid var(--line);border-radius:10px;padding:0 11px;height:44px;transition:.2s}.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 10%,transparent);background:var(--surface)}.ctrl input,.ctrl select{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:13px}.ctrl select{cursor:pointer;appearance:none}.pw-eye{border:none;background:none;color:var(--ink2);display:grid;place-items:center;padding:4px;border-radius:6px;cursor:pointer}.pw-eye:hover{color:var(--green)}.pw-eye svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.hint{font-size:10px;color:var(--ink2);font-weight:600;margin-top:3px}.pw-meter{display:flex;gap:5px;margin-top:6px;align-items:center}.pw-meter i{height:4px;flex:1;border-radius:99px;background:var(--line);transition:.3s}.pw-meter b{font-size:10px;font-weight:800;min-width:50px;text-align:right;color:var(--ink2)}.pw-meter[data-s="1"] i:nth-child(-n+1){background:var(--red)}.pw-meter[data-s="2"] i:nth-child(-n+2){background:var(--gold)}.pw-meter[data-s="3"] i:nth-child(-n+3){background:var(--gold)}.pw-meter[data-s="4"] i:nth-child(-n+4){background:var(--green)}.pw-meter[data-s="1"] b{color:var(--red)}.pw-meter[data-s="2"] b,.pw-meter[data-s="3"] b{color:var(--gold2)}.pw-meter[data-s="4"] b{color:var(--green)}
.card-actions{padding:0 18px 18px;display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:10px;font-weight:800;font-size:12px;padding:0 16px;height:40px;transition:.2s;cursor:pointer}.btn svg{width:14px;height:14px}.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--green) 25%,transparent)}.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn-ghost:hover{border-color:var(--green);color:var(--green)}.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}.btn-danger:hover{background:var(--red);color:#fff}.btn-sm{height:30px;padding:0 10px;font-size:10px;border-radius:8px}.btn-xls{background:linear-gradient(135deg,#1d4ed8,#1e40af);color:#fff;box-shadow:0 3px 10px color-mix(in srgb,var(--blue) 25%,transparent)}.btn-xls:hover{filter:brightness(1.07);transform:translateY(-1px)}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;min-width:800px}thead th{font-size:8px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}tbody td{padding:10px 12px;border-bottom:1px solid var(--line);font-size:11px;font-weight:600;vertical-align:middle}tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}.st-on{font-size:8px;font-weight:800;padding:2px 8px;border-radius:99px;background:var(--green-soft);color:var(--green);text-transform:uppercase}.st-off{font-size:8px;font-weight:800;padding:2px 8px;border-radius:99px;background:var(--red-soft);color:var(--red);text-transform:uppercase}.actions-cell{display:flex;gap:4px;white-space:nowrap}.actions-cell .btn{flex:none}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}.modal-overlay.open{display:flex;animation:fadein .25s}@keyframes fadein{from{opacity:0}to{opacity:1}}.modal{background:var(--surface);border:1px solid var(--line);border-radius:18px;max-width:520px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.15);animation:pop .35s cubic-bezier(.34,1.56,.64,1)}@keyframes pop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}.m-head{padding:16px 20px 12px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}.m-head h3{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:7px}.m-head h3 svg{width:16px;height:16px;stroke:var(--green)}.m-x{width:28px;height:28px;border-radius:8px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:14px;cursor:pointer;display:grid;place-items:center}.m-x:hover{background:var(--red-soft);color:var(--red)}.m-body{padding:16px 20px 20px}
.toasts{position:fixed;top:70px;right:16px;z-index:120;display:flex;flex-direction:column;gap:8px}.toast{display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--green);border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:340px}.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:24px;border-top:1px solid var(--line);padding:16px 20px 24px;text-align:center;color:var(--ink2);font-size:10px;font-weight:700}footer div+div{margin-top:2px;font-weight:600;opacity:.7}
@media(max-width:900px){.layout{grid-template-columns:1fr}}@media(max-width:600px){.fgrid{grid-template-columns:1fr}.tabs{gap:2px}.tab{padding:8px 12px;font-size:10px}}
</style>
</head>
<body>
<header class="topbar"><div class="container"><div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div><a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a><?php if($isAdmin): ?><span class="badge admin">ADMIN</span><?php endif; ?><button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button></div></header>
<div class="container">
  <div class="page-header"><h1><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Account <em>Settings</em></h1><p>Manage profile, password<?php if($isAdmin): ?>, user accounts &amp; salary disbursement<?php endif; ?></p></div>

  <div class="tabs">
    <div class="tab active" data-tab="profile">My Profile</div>
    <div class="tab" data-tab="security">Security</div>
    <?php if($isAdmin): ?><div class="tab" data-tab="users" id="tabUsers">User Management</div><?php endif; ?>
  </div>

  <!-- TAB: MY PROFILE -->
  <div class="tab-content active" id="tab-profile">
    <div class="layout">
      <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>My Profile</div>
        <div class="profile-card">
          <div class="avatar-wrap"><div class="avatar" id="avatarPreview"><?php if($photo): ?><img src="<?php echo e($photo); ?>" alt=""><?php else: echo strtoupper(substr($auth['name'],0,1)); endif; ?></div><label class="avatar-edit" title="Change photo"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg><input type="file" accept="image/*" hidden id="photoInput"></label></div>
          <div class="profile-name"><?php echo e($auth['name']); ?></div><span class="profile-role"><?php echo e($auth['role_name']??$auth['role']); ?></span>
          <div style="margin-top:14px"><div class="pm-row"><span>Staff ID</span><b class="mono"><?php echo e($auth['staff_code']); ?></b></div><div class="pm-row"><span>Email</span><b><?php echo e($auth['email']); ?></b></div><div class="pm-row"><span>Phone</span><b><?php echo e($auth['phone']??'—'); ?></b></div><div class="pm-row"><span>Last Login</span><b><?php echo $me['last_login_at']?e(date('d M Y, H:i',strtotime($me['last_login_at']))):'—'; ?></b></div></div>
        </div>
      </div>
      <div class="forms">
        <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Profile Picture</div>
          <form method="post"><input type="hidden" name="action" value="_photo"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="profile_photo" id="photoData"><div class="fgrid"><div class="field span2"><span class="hint">Click camera icon on avatar, choose photo (JPG/PNG, max 500KB), then Save.</span></div></div><div class="card-actions"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Photo</button></div></form>
        </div>
        <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Personal Information</div>
          <form method="post"><input type="hidden" name="action" value="_profile"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><div class="fgrid"><div class="field span2"><label class="fl">Full Name <b>*</b></label><div class="ctrl"><input name="full_name" value="<?php echo e($me['full_name']??''); ?>" required></div></div><div class="field"><label class="fl">Phone</label><div class="ctrl"><input name="phone" value="<?php echo e($me['phone']??''); ?>" placeholder="0803 000 0000"></div></div><div class="field"><label class="fl">Email <b>*</b></label><div class="ctrl"><input name="email" type="email" value="<?php echo e($me['email']??''); ?>" required></div></div><div class="field span2"><label class="fl">Current Password <span style="text-transform:none;font-weight:600">(required to change email)</span></label><div class="ctrl"><input type="password" name="current_password" id="profPw" placeholder="Current password"><button type="button" class="pw-eye" data-target="profPw"></button></div></div></div><div class="card-actions"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button></div></form>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB: SECURITY -->
  <div class="tab-content" id="tab-security">
    <div class="layout">
      <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Password Security</div>
        <div style="padding:18px"><p style="font-size:12px;color:var(--ink2);line-height:1.6">Use a strong password with at least 8 characters including uppercase letters, numbers, and special characters. Never share your password.</p></div>
      </div>
      <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Change Password</div>
        <form method="post" id="pwForm"><input type="hidden" name="action" value="_password"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><div class="fgrid"><div class="field span2"><label class="fl">Current Password <b>*</b></label><div class="ctrl"><input type="password" name="current_password" id="curPw" required><button type="button" class="pw-eye" data-target="curPw"></button></div></div><div class="field"><label class="fl">New Password <b>*</b></label><div class="ctrl"><input type="password" name="new_password" id="newPw" minlength="8" required><button type="button" class="pw-eye" data-target="newPw"></button></div><div class="pw-meter" id="pwMeter" data-s="0"><i></i><i></i><i></i><i></i><b id="pwLabel">—</b></div></div><div class="field"><label class="fl">Confirm <b>*</b></label><div class="ctrl"><input type="password" name="new_password2" id="newPw2" minlength="8" required><button type="button" class="pw-eye" data-target="newPw2"></button></div></div></div><div class="card-actions"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Update Password</button></div></form>
      </div>
    </div>
  </div>

  <?php if($isAdmin): ?>
  <!-- TAB: USER MANAGEMENT -->
  <div class="tab-content" id="tab-users">
    <!-- CREATE USER -->
    <div class="card" style="margin-bottom:16px"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Create New User Account</div>
      <form method="post"><input type="hidden" name="action" value="_create_user"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>">
        <div class="fgrid">
          <div class="field span2"><label class="fl">Assign Staff Member <b>*</b></label><div class="ctrl"><select name="staff_code" required><option value="">Select staff member...</option><?php foreach($staffList as $s): ?><option value="<?php echo e($s['staff_code']); ?>"><?php echo e($s['full_name']); ?> — <?php echo e($s['department']??''); ?> / <?php echo e($s['position']??''); ?></option><?php endforeach; ?></select></div></div>
          <div class="field"><label class="fl">Email <b>*</b></label><div class="ctrl"><input type="email" name="new_email" placeholder="user@okoya.com" required></div></div>
          <div class="field"><label class="fl">Password <b>*</b></label><div class="ctrl"><input type="password" name="new_password" minlength="6" placeholder="Min 6 chars" required><button type="button" class="pw-eye" data-target=""></button></div></div>
          <div class="field"><label class="fl">Role <b>*</b></label><div class="ctrl"><select name="new_role" required><?php foreach($rolesList as $rn): ?><option value="<?php echo e($rn); ?>"><?php echo e(ucfirst($rn)); ?></option><?php endforeach; ?></select></div></div>
        </div>
        <div class="card-actions"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Create Account</button></div>
      </form>
    </div>


    <!-- ALL USERS TABLE -->
    <div class="card"><div class="card-head"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>All User Accounts <span style="margin-left:auto;font-family:var(--fm);font-size:10px;background:var(--green-soft);color:var(--green);padding:2px 8px;border-radius:99px"><?php echo count($allUsers); ?></span></div>
      <div class="tbl-wrap"><table><thead><tr><th>ID</th><th>Staff Code</th><th>Email</th><th>Role</th><th>Status</th><th>Password</th><th>Last Login</th><th>Actions</th></tr></thead><tbody>
        <?php foreach($allUsers as $u): ?>
        <tr>
          <td class="mono"><?php echo $u['id']; ?></td>
          <td class="mono"><?php echo e($u['staff_code']); ?></td>
          <td><?php echo e($u['email']); ?></td>
          <td><span style="font-size:9px;font-weight:800;padding:2px 8px;border-radius:99px;background:var(--blue-soft);color:var(--blue);text-transform:uppercase"><?php echo e($u['role_name']??'—'); ?></span></td>
          <td><span class="<?php echo $u['is_active']?'st-on':'st-off'; ?>"><?php echo $u['is_active']?'Active':'Inactive'; ?></span></td>
          <td><button type="button" class="btn btn-ghost btn-sm btn-reset-pw" data-uid="<?php echo $u['id']; ?>" data-email="<?php echo e($u['email']); ?>" data-role="<?php echo e($u['role_name']??'staff'); ?>" data-active="<?php echo $u['is_active']; ?>" style="font-size:9px;height:26px;padding:0 8px;white-space:nowrap"><svg class="icon-svg" viewBox="0 0 24 24" style="width:12px;height:12px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Reset PW</button></td>
          <td style="font-size:10px;color:var(--ink2)"><?php echo $u['last_login_at']?date('d M Y',strtotime($u['last_login_at'])):'Never'; ?></td>
          <td><div class="actions-cell">
            <button class="btn btn-ghost btn-sm btn-edit-user" data-uid="<?php echo $u['id']; ?>" data-email="<?php echo e($u['email']); ?>" data-role="<?php echo e($u['role_name']??''); ?>" data-active="<?php echo $u['is_active']; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
            <?php if($u['id']!=$auth['id']): ?>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="_toggle_user"><input type="hidden" name="user_id" value="<?php echo $u['id']; ?>"><button type="submit" class="btn btn-ghost btn-sm" title="<?php echo $u['is_active']?'Deactivate':'Activate'; ?>"><?php echo $u['is_active']?'⏸':'▶'; ?></button></form>
            <button class="btn btn-danger btn-sm btn-del-user" data-uid="<?php echo $u['id']; ?>" data-email="<?php echo e($u['email']); ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
            <?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>

  <!-- EDIT USER MODAL -->
  <div class="modal-overlay" id="editUserModal"><div class="modal"><div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit User</h3><button class="m-x" onclick="closeModal('editUserModal')">&times;</button></div><div class="m-body">
    <form method="post"><input type="hidden" name="action" value="_update_user"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="user_id" id="eu_id">
      <div class="fgrid">
        <div class="field span2"><label class="fl">Email <b>*</b></label><div class="ctrl"><input type="email" name="edit_email" id="eu_email" required></div></div>
        <div class="field"><label class="fl">Role</label><div class="ctrl"><select name="edit_role" id="eu_role"><?php foreach($rolesList as $rn): ?><option value="<?php echo e($rn); ?>"><?php echo e(ucfirst($rn)); ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="fl">Status</label><div class="ctrl"><select name="edit_active" id="eu_active"><option value="1">Active</option><option value="0">Inactive</option></select></div></div>
        <div class="field span2"><label class="fl">New Password <span style="text-transform:none;font-weight:600">(leave blank to keep current)</span></label><div class="ctrl"><input type="password" name="edit_password" placeholder="Leave blank to keep"><button type="button" class="pw-eye" data-target=""></button></div></div>
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn btn-primary" style="width:100%;height:44px"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button></div>
    </form>
  </div></div></div>

  <!-- DELETE USER MODAL -->
  <div class="modal-overlay" id="deleteUserModal"><div class="modal" style="max-width:400px"><div class="m-head"><h3 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete User?</h3><button class="m-x" onclick="closeModal('deleteUserModal')">&times;</button></div><div class="m-body" style="text-align:center"><p style="color:var(--ink2);font-size:12px;margin-bottom:14px">Permanently delete account for <strong id="del_user_email"></strong>?</p><form method="post"><input type="hidden" name="action" value="_delete_user"><input type="hidden" name="user_id" id="del_user_id"><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteUserModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div></form></div></div></div>

  <!-- RESET PASSWORD MODAL -->
  <div class="modal-overlay" id="resetPwModal"><div class="modal" style="max-width:420px"><div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Reset Password</h3><button class="m-x" onclick="closeModal('resetPwModal')">&times;</button></div><div class="m-body">
    <p style="font-size:12px;color:var(--ink2);margin-bottom:14px">Set a new password for <strong id="rp_email"></strong></p>
    <form method="post"><input type="hidden" name="action" value="_update_user"><input type="hidden" name="csrf" value="<?php echo e(csrf()); ?>"><input type="hidden" name="user_id" id="rp_uid"><input type="hidden" name="edit_email" id="rp_email_hidden"><input type="hidden" name="edit_role" id="rp_role_hidden"><input type="hidden" name="edit_active" id="rp_active_hidden">
      <div class="field" style="margin-bottom:14px"><label class="fl">New Password <b>*</b></label><div class="ctrl"><input type="password" name="edit_password" id="rp_pw" minlength="6" required placeholder="Min 6 characters"><button type="button" class="pw-eye" data-target="rp_pw"></button></div></div>
      <button type="submit" class="btn btn-primary" style="width:100%;height:44px"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Set New Password</button>
    </form>
  </div></div></div>
  <?php endif; ?>
</div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?></div><div>Developed by Lawani Djamiou Alade</div></footer>
<div class="toasts" id="toastWrap"></div>

<script>
const $=id=>document.getElementById(id);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}

/* Tabs */
document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.tab-content').forEach(x=>x.classList.remove('active'));t.classList.add('active');$('tab-'+t.dataset.tab).classList.add('active');}));
if(window.location.hash==='#users'){document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.tab-content').forEach(x=>x.classList.remove('active'));var ut=document.querySelector('[data-tab="users"]');if(ut){ut.classList.add('active');$('tab-users').classList.add('active');}}

/* Photo */
$('photoInput').addEventListener('change',function(){const f=this.files[0];if(!f)return;if(f.size>512000){toast('Photo must be under 500KB','error');this.value='';return;}const rd=new FileReader();rd.onload=e=>{$('avatarPreview').innerHTML='<img src="'+e.target.result+'" alt="">';$('photoData').value=e.target.result;toast('Photo selected — press Save.','info');};rd.readAsDataURL(f);});

/* Password eyes */
const eyeOn='<svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOff='<svg viewBox="0 0 24 24"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 8 10 8a13.2 13.2 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="M2 2l20 20"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>';
document.querySelectorAll('.pw-eye').forEach(b=>{b.innerHTML=eyeOn;b.addEventListener('click',()=>{const inp=b.closest('.ctrl').querySelector('input[type="password"],input[type="text"]');if(!inp)return;const show=inp.type==='password';inp.type=show?'text':'password';b.innerHTML=show?eyeOff:eyeOn;});});

/* Password strength */
function scorePw(v){if(!v)return 0;let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;return Math.max(1,Math.min(4,s));}
const pwL=['—','Weak','Fair','Good','Strong'];
var newPwEl=$('newPw');if(newPwEl)newPwEl.addEventListener('input',()=>{const s=scorePw($('newPw').value);$('pwMeter').dataset.s=s;$('pwLabel').textContent=pwL[s];});
var pwForm=$('pwForm');if(pwForm)pwForm.addEventListener('submit',e=>{if($('newPw').value!==$('newPw2').value){e.preventDefault();toast('Passwords do not match.','error');}else if($('newPw').value.length<8){e.preventDefault();toast('Min 8 characters.','error');}});

<?php if($isAdmin): ?>
/* Modals */
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* Edit user */
document.querySelectorAll('.btn-edit-user').forEach(b=>b.addEventListener('click',()=>{
  $('eu_id').value=b.dataset.uid;$('eu_email').value=b.dataset.email;$('eu_role').value=b.dataset.role;$('eu_active').value=b.dataset.active;
  $('editUserModal').classList.add('open');
}));

/* Delete user */
document.querySelectorAll('.btn-del-user').forEach(b=>b.addEventListener('click',()=>{
  $('del_user_id').value=b.dataset.uid;$('del_user_email').textContent=b.dataset.email;
  $('deleteUserModal').classList.add('open');
}));

/* Reset password */
document.querySelectorAll('.btn-reset-pw').forEach(b=>b.addEventListener('click',()=>{
  $('rp_uid').value=b.dataset.uid;
  $('rp_email').textContent=b.dataset.email;
  $('rp_email_hidden').value=b.dataset.email;
  $('rp_role_hidden').value=b.dataset.role||'staff';
  $('rp_active_hidden').value=b.dataset.active||'1';
  $('rp_pw').value='';
  $('resetPwModal').classList.add('open');
}));

/* Salary Disbursement Export — matches doc.xlsx format exactly */
var staffSalaries=<?php echo json_encode(array_map(function($s){return['name'=>$s['full_name'],'acc_no'=>$s['bank_account_no']??'','amount'=>(float)($s['salary']??0),'bank'=>$s['bank_name']??''];},$staffList),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

$('btnExportSalary').addEventListener('click',function(){
  if(!staffSalaries.length){toast('No active staff found.','error');return;}
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})};
  var h='<!DOCTYPE html><html><head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Salary Disbursement</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--><style>body{font-family:Calibri,sans-serif;font-size:11pt}table{border-collapse:collapse;width:100%}th{background:#059669;color:#fff;font-size:9pt;font-weight:bold;padding:6pt 8pt;text-align:left;border:1pt solid #047857}td{padding:5pt 8pt;border:1pt solid #ddd;font-size:10pt}tr:nth-child(even){background:#f8fafc}.money{mso-number-format:"\\#\\,\\#\\#0\\.00";text-align:right}</style></head><body>';
  h+='<table><tr><th>Receiver Full Name</th><th>Account no/Mobile no/Card no</th><th>Card Client Id</th><th>Wallet Provider</th><th>Amount in NGN</th><th>Bank Name/Institution Name</th></tr>';
  staffSalaries.forEach(function(s){
    h+='<tr><td>'+esc(s.name)+'</td><td style="mso-number-format:\\@">'+esc(s.acc_no)+'</td><td></td><td></td><td class="money">'+Number(s.amount).toFixed(2)+'</td><td>'+esc(s.bank)+'</td></tr>';
  });
  h+='</table></body></html>';
  var blob=new Blob(['\ufeff'+h],{type:'application/vnd.ms-excel'});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download='Salary-Disbursement-'+new Date().toISOString().slice(0,10)+'.xls';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
  toast('Salary disbursement exported ('+staffSalaries.length+' staff)','success');
});
<?php endif; ?>

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>
