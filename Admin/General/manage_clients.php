<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria','company_tagline'=>'POVERTY ERADICATION • REDUCE INEQUALITY • LEAVE NO ONE BEHIND'];

$purposes = ['Soya Beans Purchase','Cashew Nuts Purchase','Maize Purchase','Rice Purchase','General Goods Supply','Wholesale Order','Bulk Supply Contract','Partnership / MOU','Price Enquiry','Other'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== SELF-HEALING: ENSURE PROFILE PHOTO COLUMN EXISTS ===== */
try {
    $ccols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('profile_photo', $ccols)) $pdo->exec("ALTER TABLE clients ADD COLUMN profile_photo LONGTEXT NULL AFTER notes");
} catch (Exception $ignore) {}

/* ===== CREATE CLIENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_create') {
    $name=trim($_POST['name']??'');$coName=trim($_POST['company_name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');
    $addr=trim($_POST['address']??'');$state=trim($_POST['state']??'');$lga=trim($_POST['lga']??'');$purpose=trim($_POST['purpose']??'');
    $pDet=trim($_POST['purpose_details']??'');$expQty=$_POST['expected_qty']!==''?floatval($_POST['expected_qty']):null;
    $bName=trim($_POST['bank_name']??'');$bNo=trim($_POST['bank_account_no']??'');$bAcc=trim($_POST['bank_account_name']??'');$bTin=trim($_POST['bank_tin']??'');$notes=trim($_POST['notes']??'');
    $photo=$_POST['profile_photo']??'';
    $errs=[];
    if(strlen($name)<3)$errs[]='Client name is required (min 3 characters)';
    if($phone==='')$errs[]='Phone number is required';
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$errs[]='Email address is invalid';
    if($purpose==='')$errs[]='Please select what the client came for';
    if($bNo!==''&&!preg_match('/^\d{10}$/',$bNo))$errs[]='Account number must be exactly 10 digits';
    if(empty($errs)){
        try{
            $st=$pdo->prepare("SELECT id FROM clients WHERE name=? AND (phone=? OR (email<>'' AND email=?)) LIMIT 1");$st->execute([$name,$phone,$email]);
            if($st->fetch())$errs[]='A client with this name and contact already exists';
            else{
                $data=['name'=>$name,'company_name'=>$coName?:null,'email'=>$email?:null,'phone'=>$phone,'address'=>$addr?:null,'state'=>$state?:null,'lga'=>$lga?:null,'purpose'=>$purpose,'purpose_details'=>$pDet?:null,'expected_qty'=>$expQty,'bank_name'=>$bName?:null,'bank_account_no'=>$bNo?:null,'bank_account_name'=>$bAcc?:null,'bank_tin'=>$bTin?:null,'notes'=>$notes?:null,'profile_photo'=>$photo?:null,'status'=>'Active','created_by'=>$auth['id']];
                $tableCols=$pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);$cols=[];$vals=[];
                foreach($data as $c=>$val){if(in_array($c,$tableCols)){$cols[]="`$c`";$vals[]=$val;}}
                $pdo->prepare("INSERT INTO clients (".implode(',',$cols).") VALUES (".implode(',',array_fill(0,count($cols),'?')).")")->execute($vals);
                $cid=(int)$pdo->lastInsertId();
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'CREATE_CLIENT','client',$cid,"Created client: $name"]);}catch(Exception $ignore){}
                flash('success',"Client '$name' registered successfully!");header('Location: manage_clients.php');exit;
            }
        }catch(Exception $ex){$errs[]='Database error: '.$ex->getMessage();}
    }
    if(!empty($errs))flash('error',implode('. ',$errs));
}

/* ===== UPDATE CLIENT ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_update') {
    $id=(int)($_POST['client_id']??0);
    if($id>0){
        try{
            $name=trim($_POST['name']??'');$coName=trim($_POST['company_name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');
            $addr=trim($_POST['address']??'');$state=trim($_POST['state']??'');$lga=trim($_POST['lga']??'');$purpose=trim($_POST['purpose']??'');
            $pDet=trim($_POST['purpose_details']??'');$expQty=$_POST['expected_qty']!==''?floatval($_POST['expected_qty']):null;
            $bName=trim($_POST['bank_name']??'');$bNo=trim($_POST['bank_account_no']??'');$bAcc=trim($_POST['bank_account_name']??'');$bTin=trim($_POST['bank_tin']??'');$notes=trim($_POST['notes']??'');
            $photo=$_POST['profile_photo']??'';
            $data=['name'=>$name,'company_name'=>$coName?:null,'email'=>$email?:null,'phone'=>$phone,'address'=>$addr?:null,'state'=>$state?:null,'lga'=>$lga?:null,'purpose'=>$purpose,'purpose_details'=>$pDet?:null,'expected_qty'=>$expQty,'bank_name'=>$bName?:null,'bank_account_no'=>$bNo?:null,'bank_account_name'=>$bAcc?:null,'bank_tin'=>$bTin?:null,'notes'=>$notes?:null];
            if($photo!=='')$data['profile_photo']=$photo;
            $tableCols=$pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);$sets=[];$vals=[];
            foreach($data as $c=>$val){if(in_array($c,$tableCols)){$sets[]="`$c`=?";$vals[]=$val;}}
            $vals[]=$id;
            $pdo->prepare("UPDATE clients SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'UPDATE_CLIENT','client',$id,"Updated client: $name"]);}catch(Exception $ignore){}
            flash('success','Client updated successfully.');
        }catch(Exception $ex){flash('error','Update failed: '.$ex->getMessage());}
        header('Location: manage_clients.php');exit;
    }
}

/* ===== DELETE CLIENT (admin only) ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='_delete' && $isAdmin) {
    $id=(int)($_POST['client_id']??0);
    if($id>0){
        try{
            $st=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE client_id=?");$st->execute([$id]);
            $orderCount=(int)$st->fetchColumn();
            if($orderCount>0){
                flash('error',"Cannot delete this client. They have $orderCount order(s) linked. Delete or reassign those orders first.");
            }else{
                $pdo->prepare("DELETE FROM clients WHERE id=?")->execute([$id]);
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'DELETE_CLIENT','client',$id,"Deleted client ID $id"]);}catch(Exception $ignore){}
                flash('success','Client deleted.');
            }
        }catch(Exception $ex){flash('error','Delete failed: '.$ex->getMessage());}
        header('Location: manage_clients.php');exit;
    }
}

/* ===== FETCH CLIENTS ===== */
$search=trim($_GET['search']??'');$filterPurpose=$_GET['purpose']??'';
$where=[];$params=[];
if($search!==''){$where[]="(c.name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";array_push($params,"%$search%","%$search%","%$search%","%$search%");}
if($filterPurpose!==''){$where[]="c.purpose = ?";$params[]=$filterPurpose;}
$wc=$where?'WHERE '.implode(' AND ',$where):'';
$clients=[];
try{$st=$pdo->prepare("SELECT c.*, u.full_name AS created_by_name FROM clients c LEFT JOIN users u ON u.id=c.created_by $wc ORDER BY c.created_at DESC LIMIT 200");$st->execute($params);$clients=$st->fetchAll();}catch(Exception $ignore){}

$totClients=0;$newMonth=0;$statesCovered=0;$withBank=0;
try{
    $totClients=(int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $newMonth=(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
    $statesCovered=(int)$pdo->query("SELECT COUNT(DISTINCT state) FROM clients WHERE state IS NOT NULL AND state<>''")->fetchColumn();
    $withBank=(int)$pdo->query("SELECT COUNT(*) FROM clients WHERE bank_name IS NOT NULL AND bank_name<>''")->fetchColumn();
}catch(Exception $ignore){}

$flashData=get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Manage Clients | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--line2:#c8d3c5;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}.container{max-width:1240px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px;margin-right:auto}.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:38px;height:38px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--green);border-color:var(--green)}.back-link svg{width:14px;height:14px}.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}.badge.admin{background:var(--gold-soft);color:var(--gold2)}.badge.staff{background:var(--green-soft);color:var(--green)}.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}.theme-btn svg{width:17px;height:17px}
.page-header{padding:30px 0 6px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}.ph-left h1 svg{width:28px;height:28px;stroke:var(--green)}.ph-left h1 em{font-style:normal;color:var(--green)}.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:18px}.stat{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;display:flex;gap:13px;align-items:center;box-shadow:var(--shadow-sm);transition:.25s}.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow)}.stat .ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex:none}.stat .ic.g{background:var(--green-soft);color:var(--green)}.stat .ic.y{background:var(--gold-soft);color:var(--gold2)}.stat .ic.b{background:var(--blue-soft);color:var(--blue)}.stat .ic.r{background:var(--red-soft);color:var(--red)}.stat .ic svg{width:20px;height:20px}.stat b{font-family:var(--fd);font-size:21px;font-weight:800;display:block;line-height:1.15}.stat span{font-size:10.5px;font-weight:700;color:var(--ink2);letter-spacing:.4px;text-transform:uppercase}
.layout{display:grid;grid-template-columns:1.55fr 1fr;gap:18px;align-items:start;margin-top:20px}.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;border-bottom:1px solid var(--line)}.card-head h2{font-family:var(--fd);font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}.card-head h2 svg{width:17px;height:17px;stroke:var(--green)}.count-pill{font-family:var(--fm);font-size:11px;font-weight:700;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}
.form-section{margin-bottom:22px}.form-section:last-of-type{margin-bottom:0}.fs-title{font-family:var(--fd);font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--green);margin-bottom:12px;padding-bottom:8px;border-bottom:1.5px solid var(--green-soft);display:flex;align-items:center;gap:8px}.fs-title svg{width:15px;height:15px;stroke:var(--green)}.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.span2{grid-column:1/-1}.field{display:flex;flex-direction:column;gap:5px}.fl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.fl b{color:var(--red)}.ctrl{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--line);border-radius:11px;padding:0 12px;height:44px;transition:.2s}.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent);background:var(--surface)}.ctrl.invalid{border-color:var(--red)!important;animation:shake .3s}@keyframes shake{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:14px}.ctrl select{cursor:pointer;appearance:none}.ctrl textarea{padding:10px 0;height:auto;min-height:50px;resize:vertical}.ctrl.ta{height:auto;align-items:flex-start;padding-top:2px}.form-body{padding:20px}
/* photo upload */
.photo-upload{display:flex;align-items:center;gap:16px;padding:4px 0 14px}.photo-preview{width:84px;height:84px;border-radius:18px;border:2px dashed var(--line2);display:grid;place-items:center;overflow:hidden;background:var(--surface2);flex:none}.photo-preview svg{width:32px;height:32px;stroke:var(--ink2);opacity:.5}.photo-preview img{width:100%;height:100%;object-fit:cover}.photo-actions{display:flex;flex-direction:column;gap:6px}.photo-actions label{display:inline-flex;align-items:center;gap:6px;height:36px;font-size:11px;font-weight:700;padding:0 14px;border-radius:10px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink);cursor:pointer;transition:.2s}.photo-actions label:hover{border-color:var(--green);color:var(--green)}.photo-actions label svg{width:14px;height:14px}.photo-actions .rm-photo{display:inline-flex;align-items:center;gap:6px;height:30px;font-size:10px;font-weight:700;padding:0 12px;border-radius:9px;border:1px solid transparent;background:var(--red-soft);color:var(--red);cursor:pointer;transition:.2s;width:fit-content}.photo-actions .rm-photo:hover{background:var(--red);color:#fff}.photo-actions .rm-photo svg{width:12px;height:12px}.photo-hint{font-size:10px;color:var(--ink2);font-weight:600}
.side-stick{position:sticky;top:78px;display:flex;flex-direction:column;gap:16px}.pv-card{padding:20px}.pvc-avatar{width:84px;height:84px;border-radius:20px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:26px;font-weight:800;margin-bottom:12px;overflow:hidden;border:2px solid var(--green-soft)}.pvc-avatar img{width:100%;height:100%;object-fit:cover}.pvc-name{font-family:var(--fd);font-size:17px;font-weight:800;line-height:1.2}.pvc-co{font-size:12px;color:var(--ink2);font-weight:700;margin-top:2px}.pvc-chip{display:inline-block;margin-top:8px;font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;background:var(--green-soft);color:var(--green);padding:4px 11px;border-radius:99px}.pvc-rows{margin-top:14px;display:flex;flex-direction:column;gap:7px}.pvc-row{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:var(--ink2)}.pvc-row svg{width:13px;height:13px;stroke:var(--green);flex:none}.pvc-row b{color:var(--ink);font-weight:700}.pvc-hint{margin-top:16px;padding:12px 14px;background:var(--surface2);border:1px dashed var(--line2);border-radius:11px;font-size:11px;font-weight:600;color:var(--ink2);line-height:1.5}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn-ghost:hover{border-color:var(--green);color:var(--green)}.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}.btn-danger:hover{background:var(--red);color:#fff}.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}.btn svg{width:15px;height:15px}.btn-xlarge{width:100%;height:50px;font-size:15px;margin-top:18px}
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:24px 0 16px;box-shadow:var(--shadow-sm)}.ff{display:flex;flex-direction:column;gap:5px;flex:1;min-width:160px}.ff label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.ff input,.ff select{height:42px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:14px;font-weight:600;outline:none;transition:.2s}.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;min-width:960px}thead th{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:12px 16px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}tbody td{padding:11px 16px;border-bottom:1px solid var(--line);font-size:13px;font-weight:600;vertical-align:middle}tbody tr{transition:background .15s;cursor:pointer}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}.cl-cell{display:flex;align-items:center;gap:12px}.cl-av{width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:14px;font-weight:800;flex:none;overflow:hidden}.cl-av img{width:100%;height:100%;object-fit:cover}.cl-name{font-weight:800;display:block}.cl-sub{font-size:11px;color:var(--ink2);font-weight:600}.purpose-pill{font-size:9.5px;font-weight:800;padding:4px 10px;border-radius:99px;background:var(--blue-soft);color:var(--blue);display:inline-block;white-space:nowrap}.actions-cell{display:flex;gap:6px;white-space:nowrap}.empty{padding:50px 20px;text-align:center;color:var(--ink2)}.empty svg{width:48px;height:48px;stroke:var(--ink2);margin:0 auto 10px;opacity:.5;display:block}.empty p{font-weight:700}.empty small{font-weight:600;font-size:12px}
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}.modal-overlay.open{display:flex;animation:fadein .25s}@keyframes fadein{from{opacity:0}to{opacity:1}}.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:720px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}.vm-hero{background:linear-gradient(130deg,var(--green),var(--green2));border-radius:20px 20px 0 0;padding:24px 28px;display:flex;gap:16px;align-items:center;color:#fff;position:relative;overflow:hidden}.vm-hero::after{content:'';position:absolute;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06);right:-30px;top:-40px}.vm-av{width:64px;height:64px;border-radius:14px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.7);display:grid;place-items:center;font-family:var(--fd);font-size:22px;font-weight:800;flex:none;overflow:hidden}.vm-av img{width:100%;height:100%;object-fit:cover}.vm-id h3{font-family:var(--fd);font-size:20px;font-weight:800;line-height:1.15}.vm-id .pos{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;opacity:.9;margin:3px 0 8px}.vm-chips{display:flex;gap:7px;flex-wrap:wrap}.vm-chips span{font-size:9px;font-weight:800;letter-spacing:.7px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);padding:4px 10px;border-radius:99px}.vm-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;font-size:16px;cursor:pointer;display:grid;place-items:center;z-index:2}.vm-body{padding:22px 28px 26px}.vm-cols{display:grid;grid-template-columns:1fr 1fr;gap:10px}.vm-sec{border:1px solid var(--line);border-radius:12px;overflow:hidden}.vm-sec.wide{grid-column:1/-1}.vm-sec h4{background:var(--surface2);border-bottom:1px solid var(--line);color:var(--green);font-size:10px;font-weight:800;letter-spacing:1px;padding:8px 14px;text-transform:uppercase}.vm-sec-body{padding:8px 14px}.vm-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dotted var(--line);font-size:12.5px;line-height:1.4}.vm-row:last-child{border-bottom:none}.vm-row span{color:var(--ink2);font-weight:600;flex:none;width:42%}.vm-row b{font-weight:700;text-align:right;word-break:break-word}.vm-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.vm-actions .btn{height:46px}
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}footer{margin-top:34px;border-top:1px solid var(--line);padding:22px 20px 30px;text-align:center;color:var(--ink2);font-size:12px;font-weight:700}
#printSheet{display:none}
@media(max-width:1020px){.layout{grid-template-columns:1fr}.side-stick{position:static}.stats{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column;align-items:flex-start}}
@media(max-width:768px){.fgrid{grid-template-columns:1fr}.filter-bar{flex-direction:column}.ff{min-width:100%}.topbar .badge{display:none}.brand strong{font-size:12px}.vm-cols{grid-template-columns:1fr}.vm-hero{padding:20px}.vm-body{padding:18px}.vm-actions{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr;gap:10px}.photo-upload{flex-direction:column;text-align:center}}
@media(max-width:480px){.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div id="app">
<header class="topbar"><div class="container"><div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div><a href="<?php echo $isAdmin?'admin_general_dashboard.php':'secretary_general_dashboard.php'; ?>" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a><span class="badge <?php echo $isAdmin?'admin':'staff'; ?>"><?php echo strtoupper(e($auth['role'])); ?></span><button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button></div></header>
<div class="container">
  <div class="page-header"><div class="ph-left"><h1><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Manage <em>Clients</em></h1><p>Register new clients, record their purpose of visit and banking details</p></div></div>
  <div class="stats">
    <div class="stat"><div class="ic g"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><div><b><?php echo number_format($totClients); ?></b><span>Total Clients</span></div></div>
    <div class="stat"><div class="ic b"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div><div><b><?php echo number_format($newMonth); ?></b><span>New This Month</span></div></div>
    <div class="stat"><div class="ic y"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div><div><b><?php echo number_format($statesCovered); ?></b><span>States Covered</span></div></div>
    <div class="stat"><div class="ic r"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg></div><div><b><?php echo number_format($withBank); ?></b><span>With Bank Details</span></div></div>
  </div>
  <div class="layout">
    <div class="card">
      <div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Register New Client</h2></div>
      <form method="post" id="clientForm" class="form-body" novalidate><input type="hidden" name="action" value="_create"><input type="hidden" name="profile_photo" id="f_photo_data">
        <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Profile Picture</div>
          <div class="photo-upload"><div class="photo-preview" id="f_photo_preview"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div class="photo-actions"><label><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Upload Photo<input type="file" accept="image/*" hidden onchange="previewPhoto(this,'f_photo_preview','f_photo_data','pvAvatar')"></label><button type="button" class="rm-photo" id="f_photo_rm" style="display:none"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Remove</button><span class="photo-hint">JPG/PNG, max 500KB. Optional but recommended.</span></div></div>
        </div>
        <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Client Information</div><div class="fgrid"><div class="field span2"><label class="fl">Client Full Name <b>*</b></label><div class="ctrl"><input name="name" id="f_name" placeholder="e.g. Adaeze Okonkwo" required></div></div><div class="field"><label class="fl">Company / Business</label><div class="ctrl"><input name="company_name" id="f_company" placeholder="e.g. Okonkwo Agro Ltd"></div></div><div class="field"><label class="fl">Phone <b>*</b></label><div class="ctrl"><input name="phone" id="f_phone" placeholder="0803 000 0000" required></div></div><div class="field"><label class="fl">Email</label><div class="ctrl"><input name="email" id="f_email" type="email" placeholder="client@email.com"></div></div><div class="field"><label class="fl">State</label><div class="ctrl"><input name="state" id="f_state" placeholder="e.g. Oyo"></div></div><div class="field"><label class="fl">LGA</label><div class="ctrl"><input name="lga" id="f_lga" placeholder="Local Government"></div></div><div class="field span2"><label class="fl">Address</label><div class="ctrl ta"><textarea name="address" id="f_address" rows="2" placeholder="Full address"></textarea></div></div></div></div>
        <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>Purpose of Visit</div><div class="fgrid"><div class="field span2"><label class="fl">Purpose <b>*</b></label><div class="ctrl"><select name="purpose" id="f_purpose" required><option value="">Select purpose...</option><?php foreach($purposes as $p): ?><option value="<?php echo e($p); ?>"><?php echo e($p); ?></option><?php endforeach; ?></select></div></div><div class="field"><label class="fl">Expected Quantity (kg)</label><div class="ctrl"><input name="expected_qty" id="f_qty" type="number" step="0.01" placeholder="e.g. 5000"></div></div><div class="field"><label class="fl">&nbsp;</label><div class="ctrl" style="background:var(--green-soft);border-color:color-mix(in srgb,var(--green) 30%,transparent)"><span style="font-size:12px;font-weight:800;color:var(--green)" id="estValue">Est. Value: ₦0.00</span></div></div><div class="field span2"><label class="fl">Additional Details</label><div class="ctrl ta"><textarea name="purpose_details" id="f_pdetails" rows="2" placeholder="Grade required, delivery location, timeline..."></textarea></div></div></div></div>
        <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg>Bank Information</div><div class="fgrid"><div class="field"><label class="fl">Bank Name</label><div class="ctrl"><input name="bank_name" id="f_bank" placeholder="e.g. GTBank"></div></div><div class="field"><label class="fl">Account Number</label><div class="ctrl"><input name="bank_account_no" id="f_accno" inputmode="numeric" maxlength="10" placeholder="0123456789"></div></div><div class="field span2"><label class="fl">Account Name</label><div class="ctrl"><input name="bank_account_name" id="f_accname" placeholder="Account holder name"></div></div><div class="field"><label class="fl">Tax ID (TIN)</label><div class="ctrl"><input name="bank_tin" id="f_tin" placeholder="Optional"></div></div><div class="field"><label class="fl">Notes</label><div class="ctrl"><input name="notes" id="f_notes" placeholder="Optional remarks"></div></div></div></div>
        <button type="submit" class="btn btn-primary btn-xlarge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Register Client</button>
      </form>
    </div>
    <div class="side-stick"><div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>Live Client Card</h2></div><div class="pv-card"><div class="pvc-avatar" id="pvAvatar"><span id="pvAvatarText">?</span></div><div class="pvc-name" id="pvName">Client Name</div><div class="pvc-co" id="pvCompany">Company / Business</div><span class="pvc-chip" id="pvPurpose">Purpose</span><div class="pvc-rows"><div class="pvc-row"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.12-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.1 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg><b id="pvPhone">—</b></div><div class="pvc-row"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg><b id="pvEmail">—</b></div><div class="pvc-row"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><b id="pvState">—</b></div><div class="pvc-row"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11"/></svg><b id="pvBank">—</b></div><div class="pvc-row"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6 21h12l-2-13H8L6 21Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg><b id="pvQty">—</b></div></div><div class="pvc-hint">The client card updates live as you type. Fill all sections then click Register Client to save.</div></div></div></div>
  </div>
  <form method="get" class="filter-bar"><div class="ff"><label>Search Clients</label><input type="text" name="search" placeholder="Name, company, phone, email..." value="<?php echo e($search); ?>"></div><div class="ff" style="max-width:220px"><label>Purpose</label><select name="purpose"><option value="">All Purposes</option><?php foreach($purposes as $p): ?><option value="<?php echo e($p); ?>" <?php echo $filterPurpose===$p?'selected':''; ?>><?php echo e($p); ?></option><?php endforeach; ?></select></div><div style="display:flex;gap:8px;align-items:end"><button type="submit" class="btn btn-primary"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button><a href="manage_clients.php" class="btn btn-ghost">Clear</a></div></form>
  <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Registered Clients</h2><span class="count-pill"><?php echo count($clients); ?> records</span></div><div class="table-wrap"><table><thead><tr><th>Client</th><th>Contact</th><th>Purpose</th><th>Qty (kg)</th><th>Bank</th><th>Registered By</th><th>Date</th><th>Actions</th></tr></thead><tbody>
    <?php if(empty($clients)): ?><tr><td colspan="8"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg><p>No clients found</p><small>Register your first client using the form above.</small></div></td></tr>
    <?php else: foreach($clients as $i=>$c): $initials=strtoupper(substr(trim($c['name']),0,1));$words=explode(' ',trim($c['name']));if(count($words)>1)$initials=strtoupper(substr($words[0],0,1).substr(end($words),0,1)); ?>
      <tr data-idx="<?php echo $i; ?>"><td><div class="cl-cell"><div class="cl-av"><?php if(!empty($c['profile_photo'])): ?><img src="<?php echo e($c['profile_photo']); ?>" alt=""><?php else: ?><?php echo e($initials); ?><?php endif; ?></div><div><span class="cl-name"><?php echo e($c['name']); ?></span><span class="cl-sub"><?php echo e($c['company_name']??''); ?></span></div></div></td><td style="font-size:12px"><?php echo e($c['phone']??'—'); ?><br><span style="color:var(--ink2)"><?php echo e($c['email']??''); ?></span></td><td><span class="purpose-pill"><?php echo e($c['purpose']??'—'); ?></span></td><td class="mono"><?php echo $c['expected_qty']?number_format((float)$c['expected_qty']):'—'; ?></td><td style="font-size:12px"><?php echo e($c['bank_name']??'—'); ?></td><td style="font-size:12px"><?php echo e($c['created_by_name']??'—'); ?></td><td style="font-size:12px;color:var(--ink2)"><?php echo date('d M Y',strtotime($c['created_at'])); ?></td><td><div class="actions-cell"><button class="btn btn-ghost btn-sm btn-view" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>View</button><button class="btn btn-ghost btn-sm btn-edit" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button><button class="btn btn-ghost btn-sm btn-cprint" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print</button><?php if($isAdmin): ?><button class="btn btn-danger btn-sm btn-del" data-idx="<?php echo $i; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button><?php endif; ?></div></td></tr>
    <?php endforeach; endif; ?>
  </tbody></table></div></div>
</div>
<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?> — Client Profile Management
    <h5>Developped by Lawani Djamiou Alade </h5>
        </div></footer>

<!-- VIEW MODAL -->
<div class="modal-overlay" id="viewModal"><div class="modal"><div class="vm-hero" id="vmHero"></div><div class="vm-body" id="vmBody"></div></div></div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal"><div class="modal"><div class="vm-hero" style="background:linear-gradient(130deg,var(--gold),var(--gold2))"><button class="vm-close" onclick="closeModal('editModal')">&times;</button><div class="vm-av" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.7)" id="edAvatar">?</div><div class="vm-id"><h3>Edit Client</h3><div class="pos">Update client information</div></div></div><div class="vm-body"><form method="post" id="editForm"><input type="hidden" name="action" value="_update"><input type="hidden" name="client_id" id="ed_id"><input type="hidden" name="profile_photo" id="ed_photo_data">
  <div class="photo-upload" style="padding-top:0"><div class="photo-preview" id="ed_photo_preview"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div class="photo-actions"><label><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Change Photo<input type="file" accept="image/*" hidden onchange="previewPhoto(this,'ed_photo_preview','ed_photo_data','edAvatar')"></label><span class="photo-hint">JPG/PNG, max 500KB. Leave as-is to keep current photo.</span></div></div>
  <div class="vm-cols"><div class="vm-sec"><h4>Contact Information</h4><div class="vm-sec-body"><div class="fgrid"><div class="field span2"><label class="fl">Full Name <b>*</b></label><div class="ctrl"><input name="name" id="ed_name" required></div></div><div class="field"><label class="fl">Company</label><div class="ctrl"><input name="company_name" id="ed_company"></div></div><div class="field"><label class="fl">Phone <b>*</b></label><div class="ctrl"><input name="phone" id="ed_phone" required></div></div><div class="field"><label class="fl">Email</label><div class="ctrl"><input name="email" id="ed_email" type="email"></div></div><div class="field"><label class="fl">State</label><div class="ctrl"><input name="state" id="ed_state"></div></div><div class="field"><label class="fl">LGA</label><div class="ctrl"><input name="lga" id="ed_lga"></div></div><div class="field span2"><label class="fl">Address</label><div class="ctrl ta"><textarea name="address" id="ed_address" rows="2"></textarea></div></div></div></div></div><div class="vm-sec"><h4>Purpose of Visit</h4><div class="vm-sec-body"><div class="fgrid"><div class="field span2"><label class="fl">Purpose <b>*</b></label><div class="ctrl"><select name="purpose" id="ed_purpose" required><option value="">Select...</option><?php foreach($purposes as $p): ?><option value="<?php echo e($p); ?>"><?php echo e($p); ?></option><?php endforeach; ?></select></div></div><div class="field"><label class="fl">Expected Qty (kg)</label><div class="ctrl"><input name="expected_qty" id="ed_qty" type="number" step="0.01"></div></div><div class="field span2"><label class="fl">Details</label><div class="ctrl ta"><textarea name="purpose_details" id="ed_pdetails" rows="2"></textarea></div></div></div></div></div><div class="vm-sec wide"><h4>Bank Information</h4><div class="vm-sec-body"><div class="fgrid"><div class="field"><label class="fl">Bank Name</label><div class="ctrl"><input name="bank_name" id="ed_bank"></div></div><div class="field"><label class="fl">Account No</label><div class="ctrl"><input name="bank_account_no" id="ed_accno" maxlength="10"></div></div><div class="field span2"><label class="fl">Account Name</label><div class="ctrl"><input name="bank_account_name" id="ed_accname"></div></div><div class="field"><label class="fl">Tax ID</label><div class="ctrl"><input name="bank_tin" id="ed_tin"></div></div><div class="field"><label class="fl">Notes</label><div class="ctrl"><input name="notes" id="ed_notes"></div></div></div></div></div></div><button type="submit" class="btn btn-primary btn-xlarge"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Changes</button></form></div></div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px"><div class="card-head"><h2 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Client?</h2><button class="vm-close" style="position:static" onclick="closeModal('deleteModal')">&times;</button></div><div class="vm-body" style="text-align:center"><p style="color:var(--ink2);font-size:13px;margin-bottom:16px">This will permanently remove <strong id="del_name"></strong>.</p><form method="post"><input type="hidden" name="action" value="_delete"><input type="hidden" name="client_id" id="del_id"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div></form></div></div></div>

<div class="toasts" id="toastWrap"></div>
<div id="printSheet" aria-hidden="true"></div>

<script>
const $=id=>document.getElementById(id);
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const v=s=>(s===null||s===undefined||String(s).trim()==='')?'&mdash;':esc(s);
const store={get(k,d){try{const v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t);}
$('themeBtn').addEventListener('click',()=>setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));
setTheme(store.get('okoya_theme','light'));
function toast(msg,type='success'){const t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'\u26A0\uFE0F':'\u2705')+'</span>'+esc(msg);$('toastWrap').appendChild(t);setTimeout(()=>t.classList.add('out'),3400);setTimeout(()=>t.remove(),3900);}
function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))});

/* PHOTO UPLOAD */
function previewPhoto(input,previewId,dataId,avatarId){
  const file=input.files[0];if(!file)return;
  if(file.size>512000){toast('Photo must be under 500KB','error');input.value='';return;}
  const reader=new FileReader();
  reader.onload=function(ev){
    $(previewId).innerHTML='<img src="'+ev.target.result+'">';
    $(dataId).value=ev.target.result;
    if(avatarId){var av=$(avatarId);if(av){av.innerHTML='<img src="'+ev.target.result+'" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';}}
    var rm=$('f_photo_rm');if(rm&&previewId==='f_photo_preview')rm.style.display='inline-flex';
  };
  reader.readAsDataURL(file);
}
$('f_photo_rm').addEventListener('click',function(){
  $('f_photo_preview').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
  $('f_photo_data').value='';$('pvAvatar').innerHTML='<span id="pvAvatarText">'+initials($('f_name').value)+'</span>';this.style.display='none';
});

const clients=<?php echo json_encode(array_map(function($c){return['id'=>(int)$c['id'],'name'=>$c['name'],'company_name'=>$c['company_name']??'','email'=>$c['email']??'','phone'=>$c['phone']??'','address'=>$c['address']??'','state'=>$c['state']??'','lga'=>$c['lga']??'','purpose'=>$c['purpose']??'','purpose_details'=>$c['purpose_details']??'','expected_qty'=>$c['expected_qty']??null,'bank_name'=>$c['bank_name']??'','bank_account_no'=>$c['bank_account_no']??'','bank_account_name'=>$c['bank_account_name']??'','bank_tin'=>$c['bank_tin']??'','notes'=>$c['notes']??'','profile_photo'=>$c['profile_photo']??'','status'=>$c['status']??'Active','created_by'=>$c['created_by_name']??'','created_at'=>$c['created_at']??''];},$clients),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const prices={'soya':500,'cashew':1200,'maize':350,'rice':600};
const cn='<?php echo addslashes($company['company_name']); ?>';
const ca='<?php echo addslashes($company['company_address']); ?>';
const logoAbs='<?php echo (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/logo.ico'; ?>';

function initials(name){const w=String(name||'').trim().split(/\s+/);if(!w[0])return'?';return(w[0][0]+(w.length>1?w[w.length-1][0]:'')).toUpperCase();}

function updatePreview(){
  const name=$('f_name').value,co=$('f_company').value,pur=$('f_purpose').value;
  if(!$('f_photo_data').value){var at=$('pvAvatarText');if(at)at.textContent=initials(name);else $('pvAvatar').innerHTML='<span id="pvAvatarText">'+initials(name)+'</span>';}
  $('pvName').textContent=name||'Client Name';$('pvCompany').textContent=co||'Company / Business';$('pvPurpose').textContent=pur||'Purpose';
  $('pvPhone').textContent=$('f_phone').value||'—';$('pvEmail').textContent=$('f_email').value||'—';$('pvState').textContent=[$('f_state').value,$('f_lga').value].filter(Boolean).join(', ')||'—';
  $('pvBank').textContent=$('f_bank').value?($('f_bank').value+($('f_accno').value?' \u2022\u2022\u2022 '+$('f_accno').value.slice(-4):'')):'—';
  const qty=parseFloat($('f_qty').value)||0;$('pvQty').textContent=qty?qty.toLocaleString()+' kg':'—';
  const key={'Soya Beans Purchase':'soya','Maize Purchase':'maize','Rice Purchase':'rice','Cashew Nuts Purchase':'cashew'}[pur];
  $('estValue').textContent='Est. Value: '+(key&&qty?'\u20A6'+(qty*(prices[key]||0)).toLocaleString():'\u20A60.00');
}
['f_name','f_company','f_phone','f_email','f_state','f_lga','f_bank','f_accno','f_qty'].forEach(id=>$(id).addEventListener('input',updatePreview));
$('f_purpose').addEventListener('change',updatePreview);updatePreview();

function mark(el){el.closest('.ctrl').classList.add('invalid')}
$('clientForm').addEventListener('submit',ev=>{
  document.querySelectorAll('.ctrl.invalid').forEach(c=>c.classList.remove('invalid'));let ok=true,msg='';
  if($('f_name').value.trim().length<3){mark($('f_name'));ok=false;msg='Client name is required.';}
  else if(!$('f_phone').value.trim()){mark($('f_phone'));ok=false;msg='Phone number is required.';}
  else if($('f_email').value.trim()&&!/^\S+@\S+\.\S+$/.test($('f_email').value.trim())){mark($('f_email'));ok=false;msg='Email is invalid.';}
  else if(!$('f_purpose').value){mark($('f_purpose'));ok=false;msg='Please select the purpose of visit.';}
  else if($('f_accno').value.trim()&&!/^\d{10}$/.test($('f_accno').value.trim())){mark($('f_accno'));ok=false;msg='Account number must be 10 digits.';}
  if(!ok){ev.preventDefault();toast(msg,'error');}
});

function openView(i){
  const c=clients[i];if(!c)return;
  const avHtml=c.profile_photo?'<div class="vm-av"><img src="'+c.profile_photo+'" alt=""></div>':'<div class="vm-av">'+initials(c.name)+'</div>';
  $('vmHero').innerHTML='<button class="vm-close" onclick="closeModal(\'viewModal\')">&times;</button>'+avHtml+'<div class="vm-id"><h3>'+v(c.name)+'</h3><div class="pos">'+v(c.company_name)+'</div><div class="vm-chips"><span>'+v(c.purpose)+'</span><span>'+(c.status||'ACTIVE')+'</span></div></div>';
  const row=(l,val)=>'<div class="vm-row"><span>'+l+'</span><b>'+v(val)+'</b></div>';
  $('vmBody').innerHTML='<div class="vm-cols"><div class="vm-sec"><h4>Contact Information</h4><div class="vm-sec-body">'+row('Full Name',c.name)+row('Company',c.company_name)+row('Phone',c.phone)+row('Email',c.email)+row('State',c.state)+row('LGA',c.lga)+row('Address',c.address)+'</div></div><div class="vm-sec"><h4>Purpose of Visit</h4><div class="vm-sec-body">'+row('Purpose',c.purpose)+row('Expected Qty',c.expected_qty?Number(c.expected_qty).toLocaleString()+' kg':null)+row('Details',c.purpose_details)+row('Registered',c.created_at)+row('By',c.created_by)+'</div></div><div class="vm-sec wide"><h4>Bank Information</h4><div class="vm-sec-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">'+row('Bank',c.bank_name)+row('Account No',c.bank_account_no)+row('Account Name',c.bank_account_name)+row('Tax ID',c.bank_tin)+'</div></div>'+(c.notes?'<div class="vm-sec wide"><h4>Notes</h4><div class="vm-sec-body" style="font-size:12.5px;color:var(--ink2)">'+v(c.notes)+'</div></div>':'')+'</div><div class="vm-actions"><button class="btn btn-ghost" onclick="closeModal(\'viewModal\')">Close</button><button class="btn btn-primary" onclick="printClient('+i+')"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Profile</button></div>';
  $('viewModal').classList.add('open');
}

function openEdit(i){
  const c=clients[i];if(!c)return;
  $('ed_id').value=c.id;$('ed_name').value=c.name||'';$('ed_company').value=c.company_name||'';$('ed_phone').value=c.phone||'';$('ed_email').value=c.email||'';$('ed_state').value=c.state||'';$('ed_lga').value=c.lga||'';$('ed_address').value=c.address||'';$('ed_purpose').value=c.purpose||'';$('ed_qty').value=c.expected_qty||'';$('ed_pdetails').value=c.purpose_details||'';$('ed_bank').value=c.bank_name||'';$('ed_accno').value=c.bank_account_no||'';$('ed_accname').value=c.bank_account_name||'';$('ed_tin').value=c.bank_tin||'';$('ed_notes').value=c.notes||'';$('ed_photo_data').value=c.profile_photo||'';
  if(c.profile_photo){$('ed_photo_preview').innerHTML='<img src="'+c.profile_photo+'">';$('edAvatar').innerHTML='<img src="'+c.profile_photo+'" alt="">';}
  else{$('ed_photo_preview').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';$('edAvatar').textContent=initials(c.name);}
  $('editModal').classList.add('open');
}

/* Print client profile - iframe-based */
function printClient(i){
  const c=clients[i];if(!c)return;
  const row=(l,val)=>'<div style="display:flex;justify-content:space-between;gap:8pt;padding:2.5pt 0;border-bottom:1pt dotted #dde5dd;font-size:7.5pt;line-height:1.4"><span style="color:#667268;font-weight:600;flex:none;width:42%">'+l+'</span><b style="font-weight:700;text-align:right;word-break:break-word">'+v(val)+'</b></div>';
  const docId='CP-'+c.id+'-'+new Date().toISOString().slice(0,10).replace(/-/g,'');
  const genDate=new Date().toLocaleString('en-NG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  const ini=initials(c.name);
  const avBlock=c.profile_photo?'<img src="'+c.profile_photo+'" style="width:52pt;height:52pt;border-radius:8pt;object-fit:cover;border:1.5pt solid #166e45;flex:none">':'<div style="width:52pt;height:52pt;border-radius:8pt;background:#166e45;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16pt;font-weight:800;flex:none">'+ini+'</div>';
  var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Client Profile — '+esc(c.name)+'</title>';
  html+='<style>@page{size:A4;margin:10mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#1a231d;font-size:8.5pt;background:#fff}</style>';
  html+='</head><body>';
  html+='<div style="display:flex;gap:10pt;align-items:center;border-bottom:2.5pt solid #166e45;padding-bottom:8pt;margin-bottom:10pt">';
  html+='<img src="'+logoAbs+'" alt="Logo" style="width:52pt;height:52pt;object-fit:contain;border:1pt solid #d5dcd3;border-radius:5pt;padding:3pt;background:#fff;flex:none">';
  html+='<div style="flex:1"><div style="font-family:Arial,sans-serif;font-size:13pt;font-weight:800;color:#166e45;line-height:1.15">'+esc(cn)+'</div><div style="font-size:7pt;color:#5a655c;margin-top:2pt">'+esc(ca)+'</div><div style="font-size:6pt;color:#5a655c;margin-top:1pt">Client Relations &amp; Records Department</div><div style="font-size:5.5pt;font-weight:800;letter-spacing:1.2pt;color:#b57e17;margin-top:3pt;text-transform:uppercase">POVERTY ERADICATION &middot; REDUCE INEQUALITY &middot; LEAVE NO ONE BEHIND</div></div>';
  html+='<div style="text-align:right;flex:none"><div style="font-size:8pt;font-weight:800;letter-spacing:1.2pt;color:#fff;background:#166e45;padding:3.5pt 9pt;border-radius:3pt;display:inline-block">OFFICIAL CLIENT PROFILE</div><div style="font-size:6pt;color:#7a857c;margin-top:3pt">Ref: <span style="font-family:monospace;font-weight:700;color:#166e45;font-size:7pt">'+docId+'</span></div><div style="font-size:6pt;color:#7a857c;margin-top:1pt">Generated: '+genDate+'</div></div>';
  html+='</div>';
  html+='<div style="display:flex;gap:11pt;margin:9pt 0;border:1pt solid #cfd8cd;border-radius:6pt;padding:9pt;background:#fafcf9;page-break-inside:avoid;align-items:center">'+avBlock;
  html+='<div style="flex:1"><div style="font-size:13pt;font-weight:800;line-height:1.15">'+v(c.name)+'</div><div style="font-size:7.5pt;font-weight:800;color:#166e45;text-transform:uppercase;letter-spacing:.6pt;margin:2pt 0 4pt">'+v(c.company_name)+'</div><div style="display:flex;gap:5pt;flex-wrap:wrap"><span style="font-size:6pt;font-weight:800;letter-spacing:.5pt;background:#e2f2e8;color:#166e45;padding:2.5pt 7pt;border-radius:99pt;text-transform:uppercase">'+v(c.purpose)+'</span><span style="font-size:6pt;font-weight:800;letter-spacing:.5pt;background:#e2f2e8;color:#166e45;padding:2.5pt 7pt;border-radius:99pt;text-transform:uppercase">'+esc(c.status||'ACTIVE')+'</span></div></div>';
  html+='</div>';
  html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:7pt">';
  html+='<div style="border:1pt solid #cfd8cd;border-radius:5pt;overflow:hidden;page-break-inside:avoid"><div style="background:#166e45;color:#fff;font-size:6.5pt;font-weight:800;letter-spacing:1.2pt;padding:4pt 8pt;text-transform:uppercase">A. Contact Information</div><div style="padding:5pt 8pt">'+row('Full Name',c.name)+row('Company / Business',c.company_name)+row('Phone',c.phone)+row('Email',c.email)+row('State',c.state)+row('LGA',c.lga)+row('Address',c.address)+'</div></div>';
  html+='<div style="border:1pt solid #cfd8cd;border-radius:5pt;overflow:hidden;page-break-inside:avoid"><div style="background:#166e45;color:#fff;font-size:6.5pt;font-weight:800;letter-spacing:1.2pt;padding:4pt 8pt;text-transform:uppercase">B. Purpose of Visit</div><div style="padding:5pt 8pt">'+row('Purpose',c.purpose)+row('Expected Quantity',c.expected_qty?Number(c.expected_qty).toLocaleString()+' kg':null)+row('Details',c.purpose_details)+row('Registered On',c.created_at)+row('Registered By',c.created_by)+'</div></div>';
  html+='<div style="border:1pt solid #cfd8cd;border-radius:5pt;overflow:hidden;page-break-inside:avoid;grid-column:1/-1"><div style="background:#166e45;color:#fff;font-size:6.5pt;font-weight:800;letter-spacing:1.2pt;padding:4pt 8pt;text-transform:uppercase">C. Bank Information</div><div style="padding:5pt 8pt;display:grid;grid-template-columns:1fr 1fr;gap:0 14pt">'+row('Bank Name',c.bank_name)+row('Account Number',c.bank_account_no)+row('Account Name',c.bank_account_name)+row('Tax ID (TIN)',c.bank_tin)+'</div></div>';
  if(c.notes){html+='<div style="border:1pt solid #cfd8cd;border-radius:5pt;overflow:hidden;page-break-inside:avoid;grid-column:1/-1"><div style="background:#166e45;color:#fff;font-size:6.5pt;font-weight:800;letter-spacing:1.2pt;padding:4pt 8pt;text-transform:uppercase">D. Notes</div><div style="padding:5pt 8pt;font-size:7.5pt;color:#444;line-height:1.55">'+v(c.notes)+'</div></div>';}
  html+='</div>';
  html+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12pt;margin-top:16pt;page-break-inside:avoid">';
  html+='<div style="text-align:center"><div style="height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt"></div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block">Client Signature</span><small style="font-size:5.5pt;color:#9aa79c;display:block;margin-top:1pt">Name &amp; Date</small></div>';
  html+='<div style="text-align:center"><div style="height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt"></div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block">Registered By</span><small style="font-size:5.5pt;color:#9aa79c;display:block;margin-top:1pt">Signature &amp; Date</small></div>';
  html+='<div style="text-align:center"><div style="height:34pt;border-bottom:1.5pt solid #444;margin-bottom:3pt"></div><span style="font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.8pt;color:#5a655c;display:block">Authorised By</span><small style="font-size:5.5pt;color:#9aa79c;display:block;margin-top:1pt">Signature &amp; Date</small></div>';
  html+='</div>';
  html+='<div style="margin-top:10pt;border-top:1pt solid #dde5dd;padding-top:5pt;display:flex;justify-content:space-between;font-size:5.5pt;color:#9aa79c"><span style="color:#c0392b;font-weight:800;letter-spacing:.5pt">CONFIDENTIAL &mdash; INTERNAL USE ONLY</span><span>'+esc(cn)+' &mdash; Doc Ref: '+docId+'</span><span>'+genDate+'</span></div>';
  html+='</body></html>';
  var old=document.getElementById('printFrame');if(old)old.remove();
  var iframe=document.createElement('iframe');iframe.id='printFrame';
  iframe.style.position='fixed';iframe.style.right='0';iframe.style.bottom='0';iframe.style.width='0';iframe.style.height='0';iframe.style.border='0';
  document.body.appendChild(iframe);
  var doc=iframe.contentWindow.document;
  doc.open();doc.write(html);doc.close();
  iframe.contentWindow.focus();
  toast('Preparing print...','success');
  setTimeout(function(){iframe.contentWindow.print();setTimeout(function(){iframe.remove();},1000);},500);
}

document.querySelectorAll('.btn-view').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();openView(+b.dataset.idx);}));
document.querySelectorAll('.btn-edit').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();openEdit(+b.dataset.idx);}));
document.querySelectorAll('.btn-cprint').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();printClient(+b.dataset.idx);}));
document.querySelectorAll('tbody tr[data-idx]').forEach(tr=>tr.addEventListener('click',()=>openView(+tr.dataset.idx)));
document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click',ev=>{ev.stopPropagation();const c=clients[+b.dataset.idx];if(!c)return;$('del_id').value=c.id;$('del_name').textContent=c.name;$('deleteModal').classList.add('open');}));
$('deleteModal').addEventListener('click',e=>{if(e.target===$('deleteModal'))closeModal('deleteModal')});

const FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',()=>toast(FLASH.msg,FLASH.type==='success'?'success':'error'));
</script>
</body>
</html>
