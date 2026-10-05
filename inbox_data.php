<?php
if (!isset($_GET['chat'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing chat parameter']);
    exit;
}

session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
session_name('OKOYA_SESS');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
$auth = $_SESSION['auth'] ?? null;
if (!$auth || empty($auth['id'])) { echo json_encode(['error'=>'Unauthorized']); exit; }

$userId = (int)$auth['id'];
$action = $_GET['chat'];

try { $pdo->exec("UPDATE users SET last_seen=NOW() WHERE id=".$userId); } catch(Exception $ignore){}

switch ($action) {

    case 'users':
        try {
            $st = $pdo->prepare("SELECT u.id,u.full_name,u.staff_code,u.profile_photo,u.last_seen,(SELECT COUNT(*) FROM chat_messages WHERE sender_id=u.id AND receiver_id=? AND is_read=0) AS unread_count,(SELECT created_at FROM chat_messages WHERE (sender_id=u.id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.id) ORDER BY created_at DESC LIMIT 1) AS last_message_time FROM users u WHERE u.id!=? AND u.is_active=1 ORDER BY last_message_time DESC,u.full_name ASC");
            $st->execute([$userId,$userId,$userId,$userId]);
            $users = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach($users as &$u){
                $u['is_online']=!empty($u['last_seen'])&&(time()-strtotime($u['last_seen']))<120;
                $u['unread_count']=(int)$u['unread_count'];
                unset($u['last_seen']);
            }
            echo json_encode(['users'=>$users]);
        } catch(Exception $e){ echo json_encode(['users'=>[],'error'=>$e->getMessage()]); }
        break;

    case 'messages':
        $oid=(int)($_GET['uid']??0);
        if($oid<=0||$oid===$userId){echo json_encode(['messages'=>[]]);exit;}
        try {
            $st=$pdo->prepare("SELECT id,sender_id,receiver_id,message,attachment_path,attachment_type,attachment_name,is_read,created_at FROM chat_messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) ORDER BY created_at ASC LIMIT 200");
            $st->execute([$userId,$oid,$oid,$userId]);
            echo json_encode(['messages'=>$st->fetchAll(PDO::FETCH_ASSOC),'my_id'=>$userId]);
        } catch(Exception $e){ echo json_encode(['messages'=>[],'error'=>$e->getMessage()]); }
        break;

    case 'send':
        $rid=(int)($_POST['to']??0);
        $msg=trim($_POST['msg']??'');
        if($rid<=0||$rid===$userId){echo json_encode(['ok'=>false,'error'=>'Invalid recipient']);exit;}
        if($msg===''&&!isset($_FILES['file'])){echo json_encode(['ok'=>false,'error'=>'Empty message']);exit;}
        if(mb_strlen($msg)>2000){echo json_encode(['ok'=>false,'error'=>'Message too long']);exit;}

        $attPath=null;$attType=null;$attName=null;
        if(isset($_FILES['file'])&&$_FILES['file']['error']===UPLOAD_ERR_OK){
            $allowed=['image/jpeg','image/png','image/gif','image/webp','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain'];
            $maxSize=5*1024*1024;
            $finfo=finfo_open(FILEINFO_MIME_TYPE);
            $mime=finfo_file($finfo,$_FILES['file']['tmp_name']);
            finfo_close($finfo);
            if(!in_array($mime,$allowed)){echo json_encode(['ok'=>false,'error'=>'File type not allowed']);exit;}
            if($_FILES['file']['size']>$maxSize){echo json_encode(['ok'=>false,'error'=>'File too large (max 5MB)']);exit;}
            $ext=pathinfo($_FILES['file']['name'],PATHINFO_EXTENSION);
            $stored=uniqid('chat_',true).'.'.$ext;
            $uploadDir=__DIR__.'/chat_uploads/';
            if(!is_dir($uploadDir))mkdir($uploadDir,0755,true);
            if(move_uploaded_file($_FILES['file']['tmp_name'],$uploadDir.$stored)){
                $attPath='/chat_uploads/'.$stored;
                $attType=strpos($mime,'image/')===0?'image':'document';
                $attName=$_FILES['file']['name'];
            }
        }

        try {
            $pdo->prepare("INSERT INTO chat_messages(sender_id,receiver_id,message,attachment_path,attachment_type,attachment_name)VALUES(?,?,?,?,?,?)")->execute([$userId,$rid,$msg,$attPath,$attType,$attName]);
            echo json_encode(['ok'=>true]);
        } catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        break;

    case 'read':
        $fid=(int)($_POST['from']??0);
        if($fid>0){try{$pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0")->execute([$fid,$userId]);}catch(Exception $ignore){}}
        echo json_encode(['ok'=>true]);
        break;

    case 'count':
        try{$st=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE receiver_id=? AND is_read=0");$st->execute([$userId]);echo json_encode(['count'=>(int)$st->fetchColumn()]);}
        catch(Exception $e){echo json_encode(['count'=>0]);}
        break;

    default: echo json_encode(['error'=>'Unknown action']);
}