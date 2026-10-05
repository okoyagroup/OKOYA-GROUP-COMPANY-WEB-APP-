<?php
require_once 'config.php';
$auth = $_SESSION['auth'] ?? null;
if (!$auth) { header('Location: login.php'); exit; }
$isAdmin = in_array($auth['role'], ['admin','ceo','general manager']);
if (!$isAdmin) { header('Location: secretary_dashboard.php'); exit; }

$company = [];
try { foreach ($pdo->query("SELECT setting_key,setting_value FROM company_settings") as $r) $company[$r['setting_key']]=$r['setting_value']; } catch(Exception $ignore){}
$company += ['company_name'=>'OKOYA FOOD COMPANY LIMITED','company_dept'=>'Staff Management & HR Department','company_address'=>'KM 7 Idi APA Community, Shaki, Oyo State, Nigeria'];

function e(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function fmtN($n):string{return '₦'.number_format((float)$n,2);}
function flash(string $type,string $msg):void{ $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function get_flash():?array{ $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

/* ===== SELF-HEALING DRIVERS TABLE ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `drivers` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`full_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(25) DEFAULT NULL,`is_active` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $existing=$pdo->query("SHOW COLUMNS FROM drivers")->fetchAll(PDO::FETCH_COLUMN);
    $needed=['email'=>'VARCHAR(120) DEFAULT NULL','license_number'=>'VARCHAR(60) DEFAULT NULL','license_class'=>'VARCHAR(30) DEFAULT NULL','license_expiry'=>'DATE DEFAULT NULL','date_of_birth'=>'DATE DEFAULT NULL','gender'=>"ENUM('Male','Female') DEFAULT NULL",'nin'=>'VARCHAR(20) DEFAULT NULL','address'=>'TEXT NULL','assigned_vehicle'=>'VARCHAR(120) DEFAULT NULL','vehicle_plate'=>'VARCHAR(30) DEFAULT NULL','employment_type'=>"ENUM('Full-Time','Contract','Part-Time') DEFAULT 'Full-Time'",'salary'=>'DECIMAL(12,2) DEFAULT 0','hire_date'=>'DATE DEFAULT NULL','status'=>"ENUM('Active','Suspended','Terminated') DEFAULT 'Active'",'notes'=>'TEXT NULL','profile_photo'=>'VARCHAR(255) DEFAULT NULL','bank_name'=>'VARCHAR(120) DEFAULT NULL','bank_account_no'=>'VARCHAR(30) DEFAULT NULL','bank_account_name'=>'VARCHAR(120) DEFAULT NULL','bank_code'=>'VARCHAR(10) DEFAULT NULL'];
    foreach($needed as $col=>$def){if(!in_array($col,$existing,true))$pdo->exec("ALTER TABLE `drivers` ADD COLUMN `$col` $def");}
} catch (Exception $ignore) {}
try {
    $ocols=$pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('transport_paid',$ocols))$pdo->exec("ALTER TABLE orders ADD COLUMN transport_paid TINYINT(1) DEFAULT 0 AFTER truck_price");
} catch (Exception $ignore) {}

define('DRIVER_UPLOAD_ROOT',rtrim($_SERVER['DOCUMENT_ROOT'],'/\\').DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'drivers');
function getDriverUploadDir(int $driverId):string{$dir=DRIVER_UPLOAD_ROOT.DIRECTORY_SEPARATOR.(int)$driverId;if(!is_dir($dir))@mkdir($dir,0775,true);return $dir;}

if(isset($_GET['serve_driver_photo'])){
    $photoId=(int)$_GET['serve_driver_photo'];if($photoId<=0){http_response_code(404);exit;}
    try{$st=$pdo->prepare("SELECT profile_photo FROM drivers WHERE id=? LIMIT 1");$st->execute([$photoId]);$drv=$st->fetch(PDO::FETCH_ASSOC);}catch(Exception $ignore){$drv=false;}
    if(!$drv||empty($drv['profile_photo'])){http_response_code(404);exit;}
    $fileName=basename(str_replace('\\','/',$drv['profile_photo']));$absPath=DRIVER_UPLOAD_ROOT.DIRECTORY_SEPARATOR.(int)$photoId.DIRECTORY_SEPARATOR.$fileName;$realPath=realpath($absPath);
    if(!$realPath||!is_file($realPath)){http_response_code(404);exit;}
    $mime='application/octet-stream';if(function_exists('finfo_open')){$finfo=@finfo_open(FILEINFO_MIME_TYPE);if($finfo){$detected=@finfo_file($finfo,$realPath);@finfo_close($finfo);if($detected)$mime=$detected;}}
    $allowedMimes=['image/jpeg','image/png','image/webp','image/gif'];if(!in_array($mime,$allowedMimes,true)){$info=@getimagesize($realPath);if(!empty($info['mime'])&&in_array($info['mime'],$allowedMimes,true))$mime=$info['mime'];else{http_response_code(415);exit;}}
    header('Content-Type: '.$mime);$size=@filesize($realPath);if($size!==false)header('Content-Length: '.$size);header('Cache-Control: public, max-age=86400');header('X-Content-Type-Options: nosniff');readfile($realPath);exit;
}
function driverPhotoUrl(int $id):string{return($_SERVER['SCRIPT_NAME']??'').'?serve_driver_photo='.$id;}

/* ===== TOGGLE TRANSPORT PAID/UNPAID ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_toggle_transport'){
    $orderId=(int)($_POST['order_id']??0);
    if($orderId>0){
        try{
            $pdo->prepare("UPDATE orders SET transport_paid=IF(transport_paid=1,0,1) WHERE id=?")->execute([$orderId]);
            flash('success','Transport payment status updated.');
        }catch(Exception $ex){flash('error','Failed: '.$ex->getMessage());}
        header('Location: manage_driver.php');exit;
    }
}

/* ===== ADD DRIVER ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_create'){
    $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');$email=trim($_POST['email']??'');$licNo=trim($_POST['license_number']??'');$licClass=$_POST['license_class']??'';$licExp=$_POST['license_expiry']??null;$dob=$_POST['date_of_birth']??null;$gender=$_POST['gender']??null;$nin=trim($_POST['nin']??'');$addr=trim($_POST['address']??'');$vehicle=trim($_POST['assigned_vehicle']??'');$plate=strtoupper(trim($_POST['vehicle_plate']??''));$empType=$_POST['employment_type']??'Full-Time';$salary=floatval($_POST['salary']??0);$hireDate=$_POST['hire_date']??null;$status=$_POST['status']??'Active';$notes=trim($_POST['notes']??'');
    $bankName=trim($_POST['drv_bank_name']??'');$bankAccNo=trim($_POST['drv_bank_acc_no']??'');$bankAccName=trim($_POST['drv_bank_acc_name']??'');$bankCode=trim($_POST['drv_bank_code']??'');
    $errs=[];if(strlen($name)<3)$errs[]='Full name required';if($phone===''&&$email==='')$errs[]='Phone or email required';if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$errs[]='Invalid email';if($licNo==='')$errs[]='License number required';
    if(empty($errs)){
        try{
            $pdo->prepare("INSERT INTO drivers (full_name,phone,email,license_number,license_class,license_expiry,date_of_birth,gender,nin,address,assigned_vehicle,vehicle_plate,employment_type,salary,hire_date,status,notes,bank_name,bank_account_no,bank_account_name,bank_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$phone?:null,$email?:null,$licNo,$licClass?:null,$licExp?:null,$dob?:null,$gender?:null,$nin?:null,$addr?:null,$vehicle?:null,$plate?:null,$empType,$salary,$hireDate?:null,$status,$notes?:null,$bankName?:null,$bankAccNo?:null,$bankAccName?:null,$bankCode?:null]);
            $newId=(int)$pdo->lastInsertId();
            if(isset($_FILES['profile_photo'])&&$_FILES['profile_photo']['error']===UPLOAD_ERR_OK){
                $tmp=$_FILES['profile_photo']['tmp_name'];$imgInfo=@getimagesize($tmp);$mime=$imgInfo['mime']??'';
                $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                if(isset($allowed[$mime])&&($_FILES['profile_photo']['size']<=5*1024*1024)){
                    $serverDir=getDriverUploadDir($newId);$fname=date('Ymd').'_'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
                    $destPath=$serverDir.DIRECTORY_SEPARATOR.$fname;
                    if(@move_uploaded_file($tmp,$destPath)){$dbPath='uploads/drivers/'.$newId.'/'.$fname;$pdo->prepare("UPDATE drivers SET profile_photo=? WHERE id=?")->execute([$dbPath,$newId]);}
                }
            }
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'CREATE_DRIVER','driver',$newId,"Created: $name"]);}catch(Exception $ignore){}
            flash('success',"Driver '$name' added.");header('Location: manage_drivers.php');exit;
        }catch(Exception $ex){flash('error','DB error: '.$ex->getMessage());}
    }else flash('error',implode('. ',$errs));
}

/* ===== UPDATE DRIVER ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_update'){
    $id=(int)($_POST['driver_id']??0);
    if($id>0){
        $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');$email=trim($_POST['email']??'');$licNo=trim($_POST['license_number']??'');$licClass=$_POST['license_class']??'';$licExp=$_POST['license_expiry']??null;$dob=$_POST['date_of_birth']??null;$gender=$_POST['gender']??null;$nin=trim($_POST['nin']??'');$addr=trim($_POST['address']??'');$vehicle=trim($_POST['assigned_vehicle']??'');$plate=strtoupper(trim($_POST['vehicle_plate']??''));$empType=$_POST['employment_type']??'Full-Time';$salary=floatval($_POST['salary']??0);$hireDate=$_POST['hire_date']??null;$status=$_POST['status']??'Active';$notes=trim($_POST['notes']??'');
        $bankName=trim($_POST['drv_bank_name']??'');$bankAccNo=trim($_POST['drv_bank_acc_no']??'');$bankAccName=trim($_POST['drv_bank_acc_name']??'');$bankCode=trim($_POST['drv_bank_code']??'');
        if(strlen($name)>=3){
            try{
                $pdo->prepare("UPDATE drivers SET full_name=?,phone=?,email=?,license_number=?,license_class=?,license_expiry=?,date_of_birth=?,gender=?,nin=?,address=?,assigned_vehicle=?,vehicle_plate=?,employment_type=?,salary=?,hire_date=?,status=?,notes=?,bank_name=?,bank_account_no=?,bank_account_name=?,bank_code=? WHERE id=?")
                    ->execute([$name,$phone?:null,$email?:null,$licNo,$licClass?:null,$licExp?:null,$dob?:null,$gender?:null,$nin?:null,$addr?:null,$vehicle?:null,$plate?:null,$empType,$salary,$hireDate?:null,$status,$notes?:null,$bankName?:null,$bankAccNo?:null,$bankAccName?:null,$bankCode?:null,$id]);
                if(isset($_FILES['profile_photo'])&&$_FILES['profile_photo']['error']===UPLOAD_ERR_OK){
                    $tmp=$_FILES['profile_photo']['tmp_name'];$imgInfo=@getimagesize($tmp);$mime=$imgInfo['mime']??'';
                    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                    if(isset($allowed[$mime])&&($_FILES['profile_photo']['size']<=5*1024*1024)){
                        $serverDir=getDriverUploadDir($id);$fname=date('Ymd').'_'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
                        $destPath=$serverDir.DIRECTORY_SEPARATOR.$fname;
                        if(@move_uploaded_file($tmp,$destPath)){$dbPath='uploads/drivers/'.$id.'/'.$fname;$pdo->prepare("UPDATE drivers SET profile_photo=? WHERE id=?")->execute([$dbPath,$id]);}
                    }
                }
                try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'UPDATE_DRIVER','driver',$id,"Updated: $name"]);}catch(Exception $ignore){}
                flash('success','Driver updated.');
            }catch(Exception $ex){flash('error','Update failed: '.$ex->getMessage());}
        }else flash('error','Full name required.');
        header('Location: manage_drivers.php');exit;
    }
}

/* ===== DELETE DRIVER ===== */
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='_delete'){
    $id=(int)($_POST['driver_id']??0);
    if($id>0){
        try{
            $photoDir=DRIVER_UPLOAD_ROOT.DIRECTORY_SEPARATOR.(int)$id;
            if(is_dir($photoDir)){$files=glob($photoDir.DIRECTORY_SEPARATOR.'*');if($files)foreach($files as $f)@unlink($f);@rmdir($photoDir);}
            $pdo->prepare("DELETE FROM drivers WHERE id=?")->execute([$id]);
            try{$pdo->prepare("INSERT INTO activity_logs(user_id,action,entity_type,entity_id,details)VALUES(?,?,?,?,?)")->execute([$auth['id'],'DELETE_DRIVER','driver',$id,"Deleted ID $id"]);}catch(Exception $ignore){}
            flash('success','Driver deleted.');
        }catch(Exception $ex){flash('error','Delete failed: '.$ex->getMessage());}
        header('Location: manage_drivers.php');exit;
    }
}

/* ===== FETCH DRIVERS ===== */
$search=trim($_GET['search']??'');$filterStatus=$_GET['status']??'';$where=[];$params=[];
if($search!==''){$where[]="(d.full_name LIKE ? OR d.phone LIKE ? OR d.vehicle_plate LIKE ? OR d.license_number LIKE ?)";array_push($params,"%$search%","%$search%","%$search%","%$search%");}
if($filterStatus!==''){$where[]="d.status=?";$params[]=$filterStatus;}
$wc=$where?'WHERE '.implode(' AND ',$where):'';
$drivers=[];try{$st=$pdo->prepare("SELECT d.* FROM drivers d $wc ORDER BY d.id DESC LIMIT 200");$st->execute($params);$drivers=$st->fetchAll();}catch(Exception $ex){}
$totalDrivers=count($drivers);$activeCount=0;foreach($drivers as $d)if(($d['status']??'Active')==='Active')$activeCount++;

/* ===== FETCH ALL ORDERS WITH DRIVER ===== */
$driverOrders=[];
try{
    $st=$pdo->query("SELECT o.id,o.order_no,o.order_type,o.order_date,o.kilograms,o.unit_price,o.total_amount,o.truck_price,o.transport_paid,o.departure_location,o.destination_location,o.status AS order_status,o.driver_id,c.name AS client_name,p.name AS product_name FROM orders o JOIN clients c ON c.id=o.client_id JOIN products p ON p.id=o.product_id WHERE o.driver_id IS NOT NULL ORDER BY o.created_at DESC LIMIT 500");
    $allOrders=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($allOrders as $ord){$did=(int)($ord['driver_id']??0);if($did>0)$driverOrders[$did][]=$ord;}
}catch(Exception $ignore){}

/* ===== COMPUTE CURRENT LOCATION ===== */
$driverCurrentLocation=[];
foreach($driverOrders as $did=>$orders){$driverCurrentLocation[$did]='Not on any order';foreach($orders as $o){if($o['order_status']==='Pending'||$o['order_status']==='Approved'){$dest=trim($o['destination_location']??'');if($dest!=='')$driverCurrentLocation[$did]=$dest;break;}}}

$flashData=get_flash();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Manage Drivers | <?php echo e($company['company_name']); ?></title>
<link rel="icon" href="logo.ico">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/sora@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/manrope@5.0.0/index.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.0/index.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--bg:#f3f5f0;--surface:#fff;--surface2:#f6f8f3;--ink:#182420;--ink2:#5f6e64;--line:#dfe5da;--green:#166e45;--green2:#0e5233;--green-soft:#e2f2e8;--gold:#d99a2b;--gold2:#b57e17;--gold-soft:#fbf1dd;--red:#c0392b;--red-soft:#fdecea;--blue:#2563eb;--blue-soft:#e8effd;--orange:#ea580c;--orange-soft:#fff7ed;--shadow:0 1px 2px rgba(20,30,25,.05),0 10px 28px rgba(20,30,25,.09);--shadow-sm:0 1px 2px rgba(20,30,25,.07);--fd:'Sora',sans-serif;--fb:'Manrope',sans-serif;--fm:'JetBrains Mono',monospace;--r:16px}[data-theme="dark"]{--bg:#0c1310;--surface:#141f19;--surface2:#182720;--ink:#e7efe9;--ink2:#8ea396;--line:#24382f;--green:#31c381;--green2:#22a468;--green-soft:#12352a;--gold:#f0b04a;--gold2:#d99a2b;--gold-soft:#3a2d13;--red:#ff6f61;--red-soft:#3c1712;--blue:#6ea1ff;--blue-soft:#152540;--orange:#fb923c;--orange-soft:#431407;--shadow:0 1px 2px rgba(0,0,0,.45),0 14px 36px rgba(0,0,0,.5);--shadow-sm:0 1px 2px rgba(0,0,0,.5)}body{font-family:var(--fb);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.55;transition:background .3s,color .3s}body::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(700px 420px at 88% -12%,color-mix(in srgb,var(--green) 14%,transparent),transparent 62%),radial-gradient(560px 380px at -12% 34%,color-mix(in srgb,var(--gold) 11%,transparent),transparent 60%)}.container{max-width:1280px;margin:0 auto;padding:0 20px}.mono{font-family:var(--fm)}svg{flex:none}button{font-family:inherit;cursor:pointer}input,select,textarea{font-family:inherit;font-size:15px;color:var(--ink)}a{text-decoration:none;color:inherit}.icon-svg{stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--surface) 85%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.topbar .container{display:flex;align-items:center;gap:12px;padding-top:10px;padding-bottom:10px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:12px;margin-right:auto}.logo-chip{width:44px;height:44px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:var(--shadow-sm);overflow:hidden;border:1px solid var(--line)}.logo-chip img{width:38px;height:38px;object-fit:contain}.brand strong{font-family:var(--fd);font-size:14px;font-weight:800;display:block;line-height:1.2}.brand small{color:var(--ink2);font-size:10.5px;font-weight:600}.back-link{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--ink2);padding:7px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);transition:.2s}.back-link:hover{color:var(--green);border-color:var(--green)}.back-link svg{width:14px;height:14px}.badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:800;letter-spacing:.8px;padding:6px 10px;border-radius:99px}.badge.admin{background:var(--gold-soft);color:var(--gold2)}.theme-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--line);background:var(--surface);color:var(--ink2);display:grid;place-items:center;transition:.25s;cursor:pointer}.theme-btn:hover{color:var(--gold);border-color:var(--gold);transform:rotate(18deg)}.theme-btn svg{width:17px;height:17px}
.page-header{padding:28px 0 8px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px}.ph-left h1{font-family:var(--fd);font-weight:800;font-size:clamp(22px,3vw,30px);display:flex;align-items:center;gap:10px}.ph-left h1 svg{width:26px;height:26px;stroke:var(--green)}.ph-left h1 em{font-style:normal;color:var(--green)}.ph-left p{color:var(--ink2);font-weight:600;margin-top:4px;font-size:13px}
.stats-row{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}.stat-pill{display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700}.stat-pill b{font-family:var(--fm);font-size:16px;font-weight:800}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:none;border-radius:12px;font-weight:800;font-size:13px;padding:0 18px;height:42px;transition:.2s;cursor:pointer}.btn svg{width:15px;height:15px}.btn-primary{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--green) 30%,transparent)}.btn-primary:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-ghost{background:var(--surface);border:1.5px solid var(--line);color:var(--ink)}.btn-ghost:hover{border-color:var(--green);color:var(--green)}.btn-danger{background:var(--red-soft);color:var(--red);border:1.5px solid transparent}.btn-danger:hover{background:var(--red);color:#fff}.btn-xls{background:linear-gradient(135deg,#1d4ed8,#1e40af);color:#fff;box-shadow:0 4px 12px color-mix(in srgb,var(--blue) 25%,transparent)}.btn-xls:hover{filter:brightness(1.07);transform:translateY(-1px)}.btn-sm{height:34px;padding:0 12px;font-size:11px;border-radius:9px}
.filter-bar{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:18px 0 14px;box-shadow:var(--shadow-sm)}.ff{display:flex;flex-direction:column;gap:4px;flex:1;min-width:140px}.ff label{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.ff input,.ff select{height:40px;border:1.5px solid var(--line);border-radius:10px;padding:0 12px;background:var(--surface2);font-size:13px;font-weight:600;outline:none;transition:.2s}.ff input:focus,.ff select:focus{border-color:var(--green);background:var(--surface)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line)}.card-head h2{font-family:var(--fd);font-size:14px;font-weight:800;display:flex;align-items:center;gap:7px}.card-head h2 svg{width:16px;height:16px;stroke:var(--green)}.count-pill{font-family:var(--fm);font-size:10px;font-weight:700;background:var(--green-soft);color:var(--green);padding:3px 9px;border-radius:99px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;min-width:900px}thead th{font-size:9px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--ink2);text-align:left;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--surface2);white-space:nowrap}tbody td{padding:10px 14px;border-bottom:1px solid var(--line);font-size:12px;font-weight:600;vertical-align:middle}tbody tr{transition:background .15s}tbody tr:hover{background:var(--surface2)}tbody tr:last-child td{border-bottom:none}
.drv-cell{display:flex;align-items:center;gap:10px}.drv-avatar{width:36px;height:36px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:13px;font-weight:800;flex:none;overflow:hidden}.drv-avatar img{width:100%;height:100%;object-fit:cover}.drv-name{font-weight:800;display:block;font-size:12px}.drv-sub{font-size:10px;color:var(--ink2);font-weight:600}
.st-pill{font-size:9px;font-weight:800;padding:2px 8px;border-radius:99px;letter-spacing:.3px;display:inline-block}.st-pill.active{background:var(--green-soft);color:var(--green)}.st-pill.suspended{background:var(--gold-soft);color:var(--gold2)}.st-pill.terminated{background:var(--red-soft);color:var(--red)}
.loc-badge{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:700;padding:3px 8px;border-radius:99px}.loc-badge.on-order{background:var(--green-soft);color:var(--green)}.loc-badge.no-order{background:var(--surface2);color:var(--ink2);border:1px solid var(--line)}
.actions-cell{display:flex;gap:4px;white-space:nowrap}.empty{padding:40px 20px;text-align:center;color:var(--ink2)}.empty svg{width:40px;height:40px;stroke:var(--ink2);margin:0 auto 8px;opacity:.5}.empty p{font-weight:700;font-size:13px}.empty small{font-weight:600;font-size:11px}
.modal-overlay{position:fixed;inset:0;background:rgba(8,14,11,.55);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px}.modal-overlay.open{display:flex;animation:fadein .25s}@keyframes fadein{from{opacity:0}to{opacity:1}}.modal{background:var(--surface);border:1px solid var(--line);border-radius:20px;max-width:700px;width:100%;max-height:92vh;overflow-y:auto;padding:0;position:relative;animation:pop .35s cubic-bezier(.2,1.4,.4,1)}@keyframes pop{from{transform:scale(.85);opacity:0}to{transform:scale(1);opacity:1}}.m-head{padding:18px 22px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--surface);z-index:2;border-radius:20px 20px 0 0}.m-head h3{font-family:var(--fd);font-size:17px;font-weight:800;display:flex;align-items:center;gap:7px}.m-head h3 svg{width:18px;height:18px;stroke:var(--green)}.m-x{width:30px;height:30px;border-radius:9px;border:1px solid var(--line);background:var(--surface2);color:var(--ink2);font-size:16px;cursor:pointer;display:grid;place-items:center}.m-x:hover{background:var(--red-soft);color:var(--red)}.m-body{padding:18px 22px 22px}
.form-section{margin-bottom:18px}.form-section:last-child{margin-bottom:0}.fs-title{font-family:var(--fd);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--green);margin-bottom:10px;padding-bottom:7px;border-bottom:1.5px solid var(--green-soft);display:flex;align-items:center;gap:7px}.fs-title svg{width:14px;height:14px;stroke:var(--green)}.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.span2{grid-column:1/-1}.field{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}.fl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.fl b{color:var(--red)}.ctrl{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1.5px solid var(--line);border-radius:11px;padding:0 12px;height:44px}.ctrl:focus-within{border-color:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 12%,transparent)}.ctrl input,.ctrl select,.ctrl textarea{flex:1;border:none;outline:none;background:transparent;height:100%;font-weight:600;width:100%;font-size:13px}.ctrl select{cursor:pointer;appearance:none}.ctrl textarea{padding:8px 0;height:auto;min-height:50px;resize:vertical}.ctrl.ta{height:auto;align-items:flex-start;padding-top:8px}
.photo-upload-area{display:flex;align-items:center;gap:14px;margin-bottom:12px}.photo-preview-box{width:72px;height:72px;border-radius:50%;border:2px dashed var(--line);display:grid;place-items:center;overflow:hidden;background:var(--surface2);flex:none}.photo-preview-box img{width:100%;height:100%;object-fit:cover}.photo-preview-box svg{width:24px;height:24px;stroke:var(--ink2);opacity:.4}.photo-upload-label{display:inline-flex;align-items:center;gap:5px;height:34px;font-size:10px;font-weight:700;padding:0 12px;border-radius:9px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink);cursor:pointer;transition:.2s;width:fit-content}.photo-upload-label:hover{border-color:var(--green);color:var(--green)}.photo-upload-label svg{width:13px;height:13px}.photo-hint{font-size:9px;color:var(--ink2);font-weight:600}
.dp-header{display:flex;align-items:center;gap:16px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line)}.dp-avatar{width:64px;height:64px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;font-family:var(--fd);font-size:24px;font-weight:800;flex:none;overflow:hidden}.dp-avatar img{width:100%;height:100%;object-fit:cover}.dp-info{flex:1}.dp-name{font-family:var(--fd);font-size:20px;font-weight:800}.dp-meta{font-size:11px;color:var(--ink2);font-weight:600;margin-top:3px;display:flex;gap:8px;flex-wrap:wrap}.dp-status{display:inline-flex;align-items:center;gap:4px;font-size:9px;font-weight:800;padding:2px 8px;border-radius:99px}.dp-status.on-order{background:var(--green-soft);color:var(--green)}.dp-status.available{background:var(--blue-soft);color:var(--blue)}.dp-location{display:flex;align-items:center;gap:6px;margin-top:8px;font-size:11px;font-weight:700}.dp-location.on-order{color:var(--green)}.dp-location.no-order{color:var(--ink2)}.dp-location svg{width:14px;height:14px}
.dp-orders-title{font-family:var(--fd);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--green);margin:16px 0 10px;padding-bottom:6px;border-bottom:1.5px solid var(--green-soft);display:flex;align-items:center;gap:6px}.dp-orders-title svg{width:14px;height:14px;stroke:var(--green)}
.dp-order-card{background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:10px}.dp-order-card:last-child{margin-bottom:0}.dp-order-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}.dp-order-no{font-family:var(--fm);font-size:12px;font-weight:700;color:var(--green)}.dp-order-type{font-size:8px;font-weight:800;padding:2px 7px;border-radius:99px;letter-spacing:.5px;text-transform:uppercase}.dp-order-type.outbound{background:var(--orange-soft);color:var(--orange)}.dp-order-type.inbound{background:var(--blue-soft);color:var(--blue)}
.dp-order-details{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:11px}.dp-d{display:flex;flex-direction:column;gap:1px}.dp-dl{font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.dp-dv{font-weight:700;color:var(--ink)}
.dp-route{display:flex;align-items:center;gap:8px;margin-top:8px;padding:8px 12px;background:color-mix(in srgb,var(--blue) 5%,var(--surface2));border:1px solid color-mix(in srgb,var(--blue) 12%,transparent);border-radius:10px;font-size:11px}.dp-rpt{flex:1;text-align:center}.dp-rlbl{font-size:7px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--ink2)}.dp-rloc{font-weight:700;color:var(--ink);margin-top:1px}.dp-rarrow{color:var(--blue);flex:none}.dp-rarrow svg{width:16px;height:16px}
.dp-pay-row{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:8px;border-top:1px solid var(--line)}.dp-pay-label{font-size:10px;font-weight:700;color:var(--ink2)}
.tp-toggle-btn{border:none;cursor:pointer;font-family:var(--fb);font-size:10px;font-weight:800;padding:4px 12px;border-radius:99px;transition:.2s}.tp-toggle-btn.paid{background:var(--green-soft);color:var(--green)}.tp-toggle-btn.unpaid{background:var(--red-soft);color:var(--red)}.tp-toggle-btn:hover{filter:brightness(.92)}
.export-bar{background:linear-gradient(135deg,var(--blue-soft),color-mix(in srgb,var(--purple-soft) 50%,var(--blue-soft)));border:1.5px solid color-mix(in srgb,var(--blue) 20%,transparent);border-radius:var(--r);padding:10px 18px;display:none;align-items:center;gap:10px;margin-bottom:12px;animation:bulkIn .3s ease}.export-bar.visible{display:flex}@keyframes bulkIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}.export-count{font-family:var(--fm);font-weight:800;font-size:13px;color:var(--blue);background:var(--surface);padding:3px 10px;border-radius:99px;border:1px solid color-mix(in srgb,var(--blue) 20%,transparent)}.export-label{font-size:11px;font-weight:700;color:var(--ink);flex:1}
.toasts{position:fixed;top:76px;right:18px;z-index:120;display:flex;flex-direction:column;gap:10px}.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--green);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:700;box-shadow:var(--shadow);animation:slidein .3s;max-width:380px}.toast.error{border-left-color:var(--red)}.toast.out{opacity:0;transform:translateX(20px);transition:.4s}@keyframes slidein{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
footer{margin-top:30px;border-top:1px solid var(--line);padding:18px 20px;text-align:center;color:var(--ink2);font-size:11px;font-weight:700}footer div+div{margin-top:2px;font-weight:600;opacity:.7}
@media(max-width:900px){.fgrid{grid-template-columns:1fr}}@media(max-width:768px){.topbar .badge{display:none}.brand strong{font-size:12px}.page-header{flex-direction:column;align-items:flex-start}.filter-bar{flex-direction:column}.stats-row{flex-direction:column}.dp-order-details{grid-template-columns:1fr}}@media(max-width:480px){.m-body{padding:14px}.actions-cell{flex-direction:column;gap:3px}.actions-cell .btn-sm{width:100%;justify-content:center}}
</style>
</head>
<body>
<header class="topbar"><div class="container"><div class="brand"><div class="logo-chip"><img src="logo.ico" alt="Logo"></div><div><strong><?php echo e($company['company_name']); ?></strong><small><?php echo e($company['company_dept']); ?></small></div></div><a href="admin_general_dashboard.php" class="back-link"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>Dashboard</a><span class="badge admin">ADMIN</span><button class="theme-btn" id="themeBtn" title="Toggle theme"><svg class="icon-svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.6 4.6l1.8 1.8M17.6 17.6l1.8 1.8M4.6 19.4l1.8-1.8M17.6 6.4l1.8-1.8"/></svg></button></div></header>
<div class="container">
  <div class="page-header"><div class="ph-left"><h1><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Manage <em>Drivers</em></h1><p>Track drivers, orders, transport payments &amp; export unpaid fees</p></div><button class="btn btn-primary" onclick="openCreateModal()"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Driver</button></div>
  <div class="stats-row"><div class="stat-pill"><b><?php echo $totalDrivers; ?></b> Total</div><div class="stat-pill" style="color:var(--green)"><b><?php echo $activeCount; ?></b> Active</div><div class="stat-pill" style="color:var(--gold2)"><b><?php echo $totalDrivers-$activeCount; ?></b> Inactive</div></div>
  <form method="get" class="filter-bar"><div class="ff"><label>Search</label><input type="text" name="search" placeholder="Name, phone, plate…" value="<?php echo e($search); ?>"></div><div class="ff" style="max-width:160px"><label>Status</label><select name="status"><option value="">All</option><option value="Active" <?php echo $filterStatus==='Active'?'selected':''; ?>>Active</option><option value="Suspended" <?php echo $filterStatus==='Suspended'?'selected':''; ?>>Suspended</option><option value="Terminated" <?php echo $filterStatus==='Terminated'?'selected':''; ?>>Terminated</option></select></div><div style="display:flex;gap:6px;align-items:end"><button type="submit" class="btn btn-primary">Filter</button><a href="manage_drivers.php" class="btn btn-ghost">Clear</a></div></form>
  <div class="export-bar" id="exportBar"><span class="export-count" id="exportCount">0</span><span class="export-label">unpaid transport fees selected</span><div style="display:flex;gap:6px"><button type="button" class="btn btn-xls btn-sm" id="btnExportTransport">Export Excel</button><button type="button" class="btn btn-ghost btn-sm" id="btnClearExport">Clear</button></div></div>
  <div class="card"><div class="card-head"><h2><svg class="icon-svg" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>All Drivers</h2><span class="count-pill"><?php echo count($drivers); ?> drivers</span></div>
    <div class="table-wrap"><table><thead><tr><th>Driver</th><th>Phone</th><th>Vehicle / Plate</th><th>Current Location</th><th>Active Orders</th><th>Status</th><th>Actions</th></tr></thead><tbody>
      <?php if(empty($drivers)): ?><tr><td colspan="7"><div class="empty"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><p>No drivers found</p><small>Add your first driver above.</small></div></td></tr>
      <?php else: foreach($drivers as $d): $did=(int)$d['id'];$initials=strtoupper(substr($d['full_name'],0,1));$words=explode(' ',trim($d['full_name']));if(count($words)>1)$initials=strtoupper(substr($words[0],0,1).substr(end($words),0,1));$hasPhoto=!empty($d['profile_photo']);$dOrders=$driverOrders[$did]??[];$activeOrders=array_filter($dOrders,function($o){return $o['order_status']==='Pending'||$o['order_status']==='Approved';});$isOnOrder=count($activeOrders)>0;$curLoc=$driverCurrentLocation[$did]??'Not on any order'; ?>
        <tr><td><div class="drv-cell"><div class="drv-avatar"><?php if($hasPhoto): ?><img src="<?php echo e(driverPhotoUrl($did)); ?>" alt="<?php echo e($d['full_name']); ?>"><?php else: echo $initials; endif; ?></div><div><span class="drv-name"><?php echo e($d['full_name']); ?></span><span class="drv-sub"><?php echo e($d['email']??''); ?></span></div></div></td><td class="mono"><?php echo e($d['phone']??'—'); ?></td><td><span style="font-weight:700;font-size:11px"><?php echo e($d['assigned_vehicle']??'—'); ?></span><br><span class="mono" style="font-size:10px;color:var(--green)"><?php echo e($d['vehicle_plate']??''); ?></span></td><td><span class="loc-badge <?php echo $isOnOrder?'on-order':'no-order'; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg><?php echo e($curLoc); ?></span></td><td><span style="font-family:var(--fm);font-weight:700;font-size:13px;color:<?php echo $isOnOrder?'var(--green)':'var(--ink2)'; ?>"><?php echo count($activeOrders); ?></span><span style="font-size:9px;color:var(--ink2);margin-left:3px">of <?php echo count($dOrders); ?></span></td><td><span class="st-pill <?php echo strtolower($d['status']??'active'); ?>"><?php echo e($d['status']??'Active'); ?></span></td><td><div class="actions-cell"><button class="btn btn-ghost btn-sm btn-profile" data-id="<?php echo $did; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>Profile</button><button class="btn btn-ghost btn-sm btn-edit" data-id="<?php echo $did; ?>"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button><button class="btn btn-danger btn-sm btn-del" data-id="<?php echo $did; ?>" data-name="<?php echo e($d['full_name']); ?>"><svg class="icon-svg" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button></div></td></tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </div>
</div>

<!-- PROFILE MODAL -->
<div class="modal-overlay" id="profileModal"><div class="modal" style="max-width:640px"><div class="m-head"><h3><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Driver Profile</h3><button class="m-x" onclick="closeModal('profileModal')">&times;</button></div><div class="m-body" id="profileBody"></div></div></div>

<!-- CREATE/EDIT MODAL -->
<div class="modal-overlay" id="driverModal"><div class="modal"><div class="m-head"><h3 id="modalTitle"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Add New Driver</h3><button class="m-x" onclick="closeModal('driverModal')">&times;</button></div><div class="m-body"><form method="post" id="driverForm" enctype="multipart/form-data"><input type="hidden" name="action" id="formAction" value="_create"><input type="hidden" name="driver_id" id="formId">
  <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Personal Info</div><div class="photo-upload-area"><div class="photo-preview-box" id="photoPreviewBox"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div><label class="photo-upload-label"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Choose Photo<input type="file" name="profile_photo" id="photoInput" accept="image/*" hidden onchange="previewDriverPhoto(this)"></label><span class="photo-hint">JPG/PNG/WEBP · Max 5MB</span></div></div><div class="fgrid"><div class="field span2"><label class="fl">Full Name <b>*</b></label><div class="ctrl"><input type="text" name="full_name" id="f_name" required placeholder="e.g. Musa Ibrahim"></div></div><div class="field"><label class="fl">Phone <b>*</b></label><div class="ctrl"><input type="tel" name="phone" id="f_phone" placeholder="0803 000 0000"></div></div><div class="field"><label class="fl">Email</label><div class="ctrl"><input type="email" name="email" id="f_email" placeholder="driver@email.com"></div></div><div class="field"><label class="fl">DOB</label><div class="ctrl"><input type="date" name="date_of_birth" id="f_dob"></div></div><div class="field"><label class="fl">Gender</label><div class="ctrl"><select name="gender" id="f_gender"><option value="">Select</option><option>Male</option><option>Female</option></select></div></div><div class="field"><label class="fl">NIN</label><div class="ctrl"><input type="text" name="nin" id="f_nin" maxlength="11" placeholder="NIN"></div></div><div class="field span2"><label class="fl">Address</label><div class="ctrl ta"><textarea name="address" id="f_address" rows="2" placeholder="Address"></textarea></div></div></div></div>
  <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>License</div><div class="fgrid"><div class="field"><label class="fl">License No <b>*</b></label><div class="ctrl"><input type="text" name="license_number" id="f_licno" required placeholder="License number"></div></div><div class="field"><label class="fl">Class</label><div class="ctrl"><select name="license_class" id="f_licclass"><option value="">Select</option><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option></select></div></div><div class="field"><label class="fl">Expiry</label><div class="ctrl"><input type="date" name="license_expiry" id="f_licexp"></div></div></div></div>
  <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Vehicle</div><div class="fgrid"><div class="field"><label class="fl">Vehicle</label><div class="ctrl"><input type="text" name="assigned_vehicle" id="f_vehicle" placeholder="e.g. Toyota Hilux"></div></div><div class="field"><label class="fl">Plate</label><div class="ctrl"><input type="text" name="vehicle_plate" id="f_plate" placeholder="ABC-123-XA" style="text-transform:uppercase"></div></div></div></div>
  <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg>Driver Bank Info</div><div class="fgrid"><div class="field"><label class="fl">Bank Name</label><div class="ctrl"><input type="text" name="drv_bank_name" id="f_dbank" placeholder="e.g. ZENITH BANK"></div></div><div class="field"><label class="fl">Account No</label><div class="ctrl"><input type="text" name="drv_bank_acc_no" id="f_daccno" placeholder="0123456789" maxlength="10"></div></div><div class="field span2"><label class="fl">Account Name</label><div class="ctrl"><input type="text" name="drv_bank_acc_name" id="f_daccname" placeholder="Account holder name"></div></div><div class="field"><label class="fl">Bank Code</label><div class="ctrl"><input type="text" name="drv_bank_code" id="f_dbcode" placeholder="e.g. 057" maxlength="6"></div></div></div></div>
  <div class="form-section"><div class="fs-title"><svg class="icon-svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>Employment</div><div class="fgrid"><div class="field"><label class="fl">Type</label><div class="ctrl"><select name="employment_type" id="f_emptype"><option>Full-Time</option><option>Contract</option><option>Part-Time</option></select></div></div><div class="field"><label class="fl">Salary (₦)</label><div class="ctrl"><input type="number" name="salary" id="f_salary" step="0.01" min="0" placeholder="0.00"></div></div><div class="field"><label class="fl">Hire Date</label><div class="ctrl"><input type="date" name="hire_date" id="f_hiredate" value="<?php echo date('Y-m-d'); ?>"></div></div><div class="field"><label class="fl">Status</label><div class="ctrl"><select name="status" id="f_status"><option>Active</option><option>Suspended</option><option>Terminated</option></select></div></div><div class="field span2"><label class="fl">Notes</label><div class="ctrl ta"><textarea name="notes" id="f_notes" rows="2" placeholder="Notes..."></textarea></div></div></div></div>
  <button type="submit" class="btn btn-primary" style="width:100%;height:48px;font-size:15px" id="submitBtn"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Driver</button>
</form></div></div></div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal"><div class="modal" style="max-width:400px"><div class="m-head"><h3 style="color:var(--red)"><svg class="icon-svg" viewBox="0 0 24 24" style="stroke:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Delete Driver?</h3><button class="m-x" onclick="closeModal('deleteModal')">&times;</button></div><div class="m-body" style="text-align:center"><p style="color:var(--ink2);font-size:12px;margin-bottom:14px">Permanently remove <strong id="del_name"></strong>?</p><form method="post"><input type="hidden" name="action" value="_delete"><input type="hidden" name="driver_id" id="del_id"><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div></form></div></div></div>

<footer><div>&copy; 2026 <?php echo e($company['company_name']); ?></div><div>Developed by Lawani Djamiou Alade</div></footer>
<div class="toasts" id="toastWrap"></div>

<script>
var $=function(id){return document.getElementById(id)};
var store={get:function(k,d){try{var v=localStorage.getItem(k);return v?JSON.parse(v):d}catch(e){return d}},set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
function setTheme(t){document.documentElement.dataset.theme=t;store.set('okoya_theme',t)}$('themeBtn').addEventListener('click',function(){setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark')});setTheme(store.get('okoya_theme','light'));
function toast(msg,type){var t=document.createElement('div');t.className='toast'+(type==='error'?' error':'');t.innerHTML='<span>'+(type==='error'?'⚠️':'✅')+'</span>'+msg;$('toastWrap').appendChild(t);setTimeout(function(){t.classList.add('out')},3400);setTimeout(function(){t.remove()},3900)}
function openModal(id){$(id).classList.add('open')}function closeModal(id){$(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open')})});
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(function(m){m.classList.remove('open')})});

var drivers=<?php echo json_encode(array_map(function($d){return['id'=>(int)$d['id'],'full_name'=>$d['full_name'],'phone'=>$d['phone']??'','email'=>$d['email']??'','license_number'=>$d['license_number']??'','license_class'=>$d['license_class']??'','license_expiry'=>$d['license_expiry']??'','date_of_birth'=>$d['date_of_birth']??'','gender'=>$d['gender']??'','nin'=>$d['nin']??'','address'=>$d['address']??'','assigned_vehicle'=>$d['assigned_vehicle']??'','vehicle_plate'=>$d['vehicle_plate']??'','employment_type'=>$d['employment_type']??'Full-Time','salary'=>(float)($d['salary']??0),'hire_date'=>$d['hire_date']??'','status'=>$d['status']??'Active','notes'=>$d['notes']??'','has_photo'=>!empty($d['profile_photo']),'bank_name'=>$d['bank_name']??'','bank_account_no'=>$d['bank_account_no']??'','bank_account_name'=>$d['bank_account_name']??'','bank_code'=>$d['bank_code']??''];},$drivers),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var driverOrders=<?php echo json_encode($driverOrders,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var driverCurrentLocation=<?php echo json_encode($driverCurrentLocation,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;

function previewDriverPhoto(input){var file=input.files[0];if(!file)return;if(file.size>5*1024*1024){toast('Photo must be under 5MB','error');input.value='';return}var reader=new FileReader();reader.onload=function(ev){$('photoPreviewBox').innerHTML='<img src="'+ev.target.result+'" alt="Preview">'};reader.readAsDataURL(file)}
function resetForm(){$('driverForm').reset();$('formAction').value='_create';$('formId').value='';$('modalTitle').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>Add New Driver';$('submitBtn').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Driver';$('f_hiredate').value=new Date().toISOString().slice(0,10);$('photoPreviewBox').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';$('photoInput').value=''}
function openCreateModal(){resetForm();openModal('driverModal')}
document.querySelectorAll('.btn-edit').forEach(function(b){b.addEventListener('click',function(){var d=drivers.find(function(x){return x.id==b.dataset.id});if(!d)return;$('formAction').value='_update';$('formId').value=d.id;$('modalTitle').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Driver';$('submitBtn').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Update Driver';$('f_name').value=d.full_name;$('f_phone').value=d.phone;$('f_email').value=d.email;$('f_dob').value=d.date_of_birth;$('f_gender').value=d.gender;$('f_nin').value=d.nin;$('f_address').value=d.address;$('f_licno').value=d.license_number;$('f_licclass').value=d.license_class;$('f_licexp').value=d.license_expiry;$('f_vehicle').value=d.assigned_vehicle;$('f_plate').value=d.vehicle_plate;$('f_emptype').value=d.employment_type;$('f_salary').value=d.salary>0?d.salary:'';$('f_hiredate').value=d.hire_date;$('f_status').value=d.status;$('f_notes').value=d.notes;$('f_dbank').value=d.bank_name||'';$('f_daccno').value=d.bank_account_no||'';$('f_daccname').value=d.bank_account_name||'';$('f_dbcode').value=d.bank_code||'';$('photoInput').value='';if(d.has_photo){$('photoPreviewBox').innerHTML='<img src="?serve_driver_photo='+d.id+'" alt="Photo">'}else{$('photoPreviewBox').innerHTML='<svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'}openModal('driverModal')})});
document.querySelectorAll('.btn-del').forEach(function(b){b.addEventListener('click',function(){var d=drivers.find(function(x){return x.id==b.dataset.id});if(!d)return;$('del_id').value=d.id;$('del_name').textContent=d.full_name;openModal('deleteModal')})});

/* ===== TRANSPORT FEE SELECTION FOR EXPORT ===== */
var selectedTransportIds={};
function updateExportBar(){var count=Object.keys(selectedTransportIds).length;$('exportCount').textContent=count;$('exportBar').classList.toggle('visible',count>0)}
function toggleTransportFee(orderId){if(selectedTransportIds[orderId]){delete selectedTransportIds[orderId]}else{selectedTransportIds[orderId]=true}updateExportBar()}
$('btnClearExport').addEventListener('click',function(){selectedTransportIds={};updateExportBar()});

/* ===== EXPORT UNPAID TRANSPORT FEES — Paid to DRIVER bank ===== */
$('btnExportTransport').addEventListener('click',function(){
  var ids=Object.keys(selectedTransportIds);if(!ids.length){toast('No unpaid fees selected','error');return}
  var rows=[];
  Object.keys(driverOrders).forEach(function(did){
    var drv=drivers.find(function(x){return String(x.id)===String(did)});if(!drv)return;
    (driverOrders[did]||[]).forEach(function(o){
      if(ids.indexOf(String(o.id))!==-1 && o.transport_paid!=1 && o.truck_price>0){
        rows.push({name:drv.full_name,acc_no:drv.bank_account_no||'',amount:Number(o.truck_price).toFixed(2),bank:drv.bank_name||'',bank_code:drv.bank_code||'',order_no:o.order_no});
      }
    });
  });
  if(!rows.length){toast('No valid unpaid transport fees found','error');return}
  var narration='Transport Payment - '+new Date().toLocaleDateString('en-NG',{month:'long',year:'numeric'});
  /* Excel in Salary Disbursement format matching doc.xlsx */
  var html='<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Transport Fees Disbursement</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--><style>body{font-family:Calibri,sans-serif;font-size:11pt}table{border-collapse:collapse;width:100%}th{background:#059669;color:#fff;font-size:9pt;font-weight:bold;padding:6pt 8pt;text-align:left;border:1pt solid #047857}td{padding:5pt 8pt;border:1pt solid #ddd;font-size:10pt}tr:nth-child(even){background:#f8fafc}.money{mso-number-format:"\\#\\,\\#\\#0\\.00";text-align:right}</style></head><body>';
  html+='<table><tr><th>Receiver Full Name</th><th>Account no/Mobile no/Card no</th><th>Card Client Id</th><th>Wallet Provider</th><th>Amount in NGN</th><th>Bank Name/Institution Name</th></tr>';
  rows.forEach(function(r){html+='<tr><td>'+esc(r.name)+'</td><td style="mso-number-format:\\@">'+esc(r.acc_no)+'</td><td></td><td></td><td class="money">'+r.amount+'</td><td>'+esc(r.bank)+'</td></tr>'});
  html+='</table></body></html>';
  var blob=new Blob(['\ufeff'+html],{type:'application/vnd.ms-excel'});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download='Salary-Disbursement-'+new Date().toISOString().slice(0,10)+'.xls';document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
  toast('Excel exported ('+rows.length+' transport fees)','success');
});

/* ===== DRIVER PROFILE ===== */
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]||c})}
function fmt(n){return '₦'+Number(n||0).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2})}
document.querySelectorAll('.btn-profile').forEach(function(b){b.addEventListener('click',function(){
  var d=drivers.find(function(x){return x.id==b.dataset.id});if(!d)return;
  var orders=driverOrders[d.id]||[];var activeOrders=orders.filter(function(o){return o.order_status==='Pending'||o.order_status==='Approved'});var isOnOrder=activeOrders.length>0;var curLoc=driverCurrentLocation[d.id]||'Not on any order';
  var initials=d.full_name?d.full_name.trim().charAt(0).toUpperCase():'?';var words=d.full_name?d.full_name.trim().split(' '):[];if(words.length>1)initials=(words[0][0]+words[words.length-1][0]).toUpperCase();
  var html='<div class="dp-header"><div class="dp-avatar">'+(d.has_photo?'<img src="?serve_driver_photo='+d.id+'" alt="">':initials)+'</div><div class="dp-info"><div class="dp-name">'+esc(d.full_name)+'</div><div class="dp-meta"><span>'+esc(d.phone||'')+'</span><span>'+esc(d.assigned_vehicle||'')+(d.vehicle_plate?' · '+esc(d.vehicle_plate):'')+'</span></div><div style="margin-top:6px"><span class="dp-status '+(isOnOrder?'on-order':'available')+'">'+(isOnOrder?'● On Active Order ('+activeOrders.length+')':'● Available')+'</div><div class="dp-location '+(isOnOrder?'on-order':'no-order')+'"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>'+esc(curLoc)+'</div>';
  if(d.bank_name||d.bank_account_no){html+='<div style="margin-top:8px;font-size:10px;color:var(--ink2)"><svg class="icon-svg" style="width:12px;height:12px;display:inline;vertical-align:middle;margin-right:3px" viewBox="0 0 24 24"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/></svg>'+esc(d.bank_name||'')+(d.bank_account_no?' · '+esc(d.bank_account_no):'')+'</div>';}
  html+='</div></div>';
  html+='<div class="dp-orders-title"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Assigned Orders ('+orders.length+')</div>';
  if(!orders.length){html+='<div class="dp-no-orders">No orders assigned yet.</div>';}
  else{orders.forEach(function(o){
    var isActive=o.order_status==='Pending'||o.order_status==='Approved';var tpPaid=o.transport_paid==1;var hasTruckPrice=o.truck_price>0;
    html+='<div class="dp-order-card" style="'+(isActive?'border-left:3px solid var(--green)':'')+'">';
    html+='<div class="dp-order-top"><span class="dp-order-no">'+esc(o.order_no)+'</span><span class="dp-order-type '+esc(o.order_type||'outbound')+'">'+esc((o.order_type||'outbound').toUpperCase())+'</span></div>';
    html+='<div class="dp-order-details"><div class="dp-d"><span class="dp-dl">Client</span><span class="dp-dv">'+esc(o.client_name||'—')+'</span></div><div class="dp-d"><span class="dp-dl">Product</span><span class="dp-dv">'+esc(o.product_name||'—')+'</span></div><div class="dp-d"><span class="dp-dl">Quantity</span><span class="dp-dv">'+Number(o.kilograms||0).toLocaleString()+' kg</span></div><div class="dp-d"><span class="dp-dl">Total</span><span class="dp-dv">'+fmt(o.total_amount)+'</span></div><div class="dp-d"><span class="dp-dl">Truck Price</span><span class="dp-dv">'+(hasTruckPrice?fmt(o.truck_price):'—')+'</span></div><div class="dp-d"><span class="dp-dl">Status</span><span class="dp-dv">'+esc(o.order_status)+'</span></div><div class="dp-d"><span class="dp-dl">Date</span><span class="dp-dv">'+esc(o.order_date||'—')+'</span></div></div>';
    if(o.departure_location||o.destination_location){html+='<div class="dp-route"><div class="dp-rpt"><div class="dp-rlbl">From</div><div class="dp-rloc">'+esc(o.departure_location||'—')+'</div></div><div class="dp-rarrow"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></div><div class="dp-rpt"><div class="dp-rlbl">To</div><div class="dp-rloc">'+esc(o.destination_location||'—')+'</div></div></div>';}
    /* Transport fee toggle button + export checkbox */
    html+='<div class="dp-pay-row"><span class="dp-pay-label">Transport Fee → Driver</span>';
    if(hasTruckPrice){
      html+='<div style="display:flex;align-items:center;gap:8px">';
      /* Toggle paid/unpaid button */
      html+='<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="_toggle_transport"><input type="hidden" name="order_id" value="'+o.id+'"><button type="submit" class="tp-toggle-btn '+(tpPaid?'paid':'unpaid')+'">'+(tpPaid?'✓ Paid':'✗ Unpaid')+'</button></form>';
      /* Export selection checkbox (only for unpaid) */
      if(!tpPaid){html+='<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:9px;font-weight:700;color:var(--blue)"><input type="checkbox" class="tp-check" data-oid="'+o.id+'" onchange="toggleTransportFee('+o.id+')" style="width:14px;height:14px;accent-color:var(--blue)">Select</label>';}
      html+='</div>';
    }else{html+='<span style="font-size:10px;color:var(--ink2)">No truck price</span>';}
    html+='</div></div>';
  })}
  $('profileBody').innerHTML=html;openModal('profileModal');
})});

var FLASH=<?php echo json_encode($flashData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
if(FLASH)window.addEventListener('DOMContentLoaded',function(){toast(FLASH.msg,FLASH.type==='success'?'success':'error')});
</script>
</body>
</html>
