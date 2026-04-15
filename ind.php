<?php
// ── PChat ind.php v3 — Fixed ──
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// FIX: Read action from JSON body OR query string OR POST (handles all cases including multipart)
$body = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
} elseif (strpos($contentType, 'multipart/form-data') !== false || strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
    $body = $_POST;
}
// Action can come from: JSON body, POST field, or GET query string
$action = $body['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

function getAuthUser(): array {
    $token = $_COOKIE['pchat_token'] ?? '';
    if (!$token) $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!$token) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strpos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if (!$token) jsonOut(['success'=>false,'message'=>'Not authenticated.','redirect'=>'login.html']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id,name,email,status,profile_pic FROM users WHERE session_token=? AND is_verified=1 AND is_banned=0");
    $st->execute([trim($token)]);
    $user = $st->fetch();
    if (!$user) {
        if (!empty($_COOKIE['pchat_token'])) setcookie('pchat_token','',time()-3600,'/');
        jsonOut(['success'=>false,'message'=>'Session expired.','redirect'=>'login.html']);
    }
    $pdo->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$user['id']]);
    return $user;
}

switch ($action) {
    case 'me':             getMe();                break;
    case 'chat_list':      getChatList();          break;
    case 'messages':       getMessages($body);     break;
    case 'send':           sendMessage($body);     break;
    case 'search':         searchUsers($body);     break;
    case 'update_profile': updateProfile($body);   break;
    case 'complaint':      submitComplaint($body); break;
    case 'logout':         doLogout();             break;
    case 'typing':         setTyping($body);       break;
    case 'typing_status':  getTyping($body);       break;
    case 'upload_media':   uploadMedia();          break;
    case 'upload_avatar':  uploadAvatar();         break;
    case 'mark_delivered': markDelivered($body);   break;
    case 'delete_msg':     deleteMessage($body);   break;
    case 'edit_msg':       editMessage($body);     break;
    case 'mark_read':      markRead($body);        break;
    case 'ping':           jsonOut(['success'=>true,'ts'=>time()]); break;
    default: jsonOut(['success'=>false,'message'=>'Unknown action: '.$action]);
}

function getMe(): void {
    $user = getAuthUser();
    jsonOut(['success'=>true,'user'=>$user]);
}

function getChatList(): void {
    $user = getAuthUser();
    $uid  = $user['id'];
    $pdo  = getDB();
    $sql = "SELECT
                peer.id AS peer_id, peer.name AS peer_name, peer.email AS peer_email,
                peer.last_seen, peer.status AS peer_status, peer.profile_pic AS peer_avatar,
                lm.message AS last_msg, lm.created_at AS last_time, lm.msg_type AS last_msg_type,
                CASE WHEN TIMESTAMPDIFF(SECOND, COALESCE(peer.last_seen, '2000-01-01'), NOW()) < 90 THEN 1 ELSE 0 END AS online,
                (SELECT COUNT(*) FROM messages WHERE sender_id=peer.id AND receiver_id=? AND is_read=0) AS unread
            FROM users peer
            INNER JOIN messages lm ON lm.id=(
                SELECT id FROM messages
                WHERE (sender_id=? AND receiver_id=peer.id) OR (sender_id=peer.id AND receiver_id=?)
                ORDER BY id DESC LIMIT 1
            )
            WHERE peer.id!=?
            ORDER BY lm.created_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$uid,$uid,$uid,$uid]);
    jsonOut(['success'=>true,'chats'=>$st->fetchAll()]);
}

function getMessages(array $b): void {
    $user    = getAuthUser();
    $uid     = $user['id'];
    $peerId  = (int)($b['peer_id'] ?? 0);
    $afterId = (int)($b['after_id'] ?? 0);
    if (!$peerId) jsonOut(['success'=>false,'message'=>'peer_id required.']);
    $pdo = getDB();
    // Check columns exist safely
    $cols = "m.id, m.sender_id, m.receiver_id, m.message, m.msg_type, m.is_read, m.is_delivered, m.reply_to, m.is_edited, m.created_at";
    $st  = $pdo->prepare("SELECT $cols,
        s.name AS sender_name, s.profile_pic AS sender_avatar,
        rm.message AS reply_text, ru.name AS reply_sender
        FROM messages m
        JOIN users s ON s.id=m.sender_id
        LEFT JOIN messages rm ON rm.id=m.reply_to
        LEFT JOIN users ru ON ru.id=rm.sender_id
        WHERE ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)) AND m.id>?
        ORDER BY m.id ASC LIMIT 300");
    $st->execute([$uid,$peerId,$peerId,$uid,$afterId]);
    $msgs = $st->fetchAll();
    $pdo->prepare("UPDATE messages SET is_read=1, is_delivered=1 WHERE sender_id=? AND receiver_id=? AND is_read=0")->execute([$peerId,$uid]);
    jsonOut(['success'=>true,'messages'=>$msgs]);
}

function sendMessage(array $b): void {
    $user    = getAuthUser();
    $uid     = $user['id'];
    $peerId  = (int)($b['peer_id'] ?? 0);
    $message = trim($b['message'] ?? '');
    $msgType = $b['msg_type'] ?? 'text';
    $replyTo = (int)($b['reply_to'] ?? 0) ?: null;
    if (!$peerId) jsonOut(['success'=>false,'message'=>'peer_id required.']);
    if (!$message) jsonOut(['success'=>false,'message'=>'Message required.']);
    if (strlen($message) > 10000) jsonOut(['success'=>false,'message'=>'Message too long.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id FROM users WHERE id=? AND is_verified=1 AND is_banned=0");
    $st->execute([$peerId]);
    if (!$st->fetch()) jsonOut(['success'=>false,'message'=>'User not found or banned.']);
    try {
        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,message,msg_type,reply_to,is_delivered) VALUES (?,?,?,?,?,0)")
            ->execute([$uid,$peerId,$message,$msgType,$replyTo]);
    } catch (\Exception $e) {
        // Fallback if new columns don't exist yet
        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)")
            ->execute([$uid,$peerId,$message]);
    }
    $msgId = (int)$pdo->lastInsertId();
    jsonOut(['success'=>true,'msg_id'=>$msgId,'timestamp'=>date('Y-m-d H:i:s')]);
}

function setTyping(array $b): void {
    $user   = getAuthUser();
    $uid    = $user['id'];
    $peerId = (int)($b['peer_id'] ?? 0);
    if (!$peerId) jsonOut(['success'=>true]);
    $pdo = getDB();
    try {
        $pdo->prepare("INSERT INTO typing_status (user_id,peer_id,typing_until) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 4 SECOND))
            ON DUPLICATE KEY UPDATE typing_until=DATE_ADD(NOW(),INTERVAL 4 SECOND)")
            ->execute([$uid,$peerId]);
    } catch (\Exception $e) { /* table may not exist */ }
    jsonOut(['success'=>true]);
}

function getTyping(array $b): void {
    $user   = getAuthUser();
    $uid    = $user['id'];
    $peerId = (int)($b['peer_id'] ?? 0);
    if (!$peerId) jsonOut(['success'=>true,'typing'=>false]);
    $pdo = getDB();
    try {
        $st = $pdo->prepare("SELECT 1 FROM typing_status WHERE user_id=? AND peer_id=? AND typing_until>NOW()");
        $st->execute([$peerId,$uid]);
        jsonOut(['success'=>true,'typing'=>(bool)$st->fetch()]);
    } catch (\Exception $e) {
        jsonOut(['success'=>true,'typing'=>false]);
    }
}

function uploadMedia(): void {
    $user = getAuthUser();
    $uid  = $user['id'];
    if (empty($_FILES['file'])) jsonOut(['success'=>false,'message'=>'No file uploaded.']);
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) jsonOut(['success'=>false,'message'=>'Upload error: '.$file['error']]);
    if ($file['size'] > 16 * 1024 * 1024) jsonOut(['success'=>false,'message'=>'Max 16MB per file.']);
    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','audio/webm','audio/ogg','audio/mpeg','audio/wav','audio/mp4','application/octet-stream'];
    // Allow any audio type for voice
    $isAudio = strpos($mime,'audio/')===0;
    $isImage = strpos($mime,'image/')===0;
    $isVideo = strpos($mime,'video/')===0;
    if (!in_array($mime,$allowed) && !$isAudio && !$isImage && !$isVideo) jsonOut(['success'=>false,'message'=>'File type not allowed: '.$mime]);
    $dir = 'uploads/'.date('Ym');
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    if (!is_dir($dir)) jsonOut(['success'=>false,'message'=>'Cannot create upload directory. Check folder permissions.']);
    $ext = pathinfo($file['name'],PATHINFO_EXTENSION);
    if (!$ext) $ext = $isAudio?'webm':($isImage?'jpg':($isVideo?'mp4':'bin'));
    $filename = $uid.'_'.uniqid().'.'.$ext;
    $path = $dir.'/'.$filename;
    if (!move_uploaded_file($file['tmp_name'],$path)) jsonOut(['success'=>false,'message'=>'Failed to save. Check folder permissions.']);
    $type = $isImage?'image':($isVideo?'video':($isAudio?'voice':'file'));
    $url  = rtrim(SITE_URL,'/').'/'.ltrim($path,'/');
    jsonOut(['success'=>true,'url'=>$url,'type'=>$type,'mime'=>$mime]);
}

function uploadAvatar(): void {
    $user = getAuthUser();
    $uid  = $user['id'];
    if (empty($_FILES['avatar'])) jsonOut(['success'=>false,'message'=>'No file.']);
    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK) jsonOut(['success'=>false,'message'=>'Upload error.']);
    if ($file['size'] > 5*1024*1024) jsonOut(['success'=>false,'message'=>'Max 5MB.']);
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'])) jsonOut(['success'=>false,'message'=>'Images only.']);
    $dir = 'uploads/avatars';
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    if (!is_dir($dir)) jsonOut(['success'=>false,'message'=>'Cannot create directory.']);
    $ext = str_replace(['image/','jpeg'],['','jpg'],$mime);
    $filename = 'av_'.$uid.'_'.uniqid().'.'.$ext;
    $path = $dir.'/'.$filename;
    if (!move_uploaded_file($file['tmp_name'],$path)) jsonOut(['success'=>false,'message'=>'Save failed.']);
    $url = rtrim(SITE_URL,'/').'/'.ltrim($path,'/');
    try { getDB()->prepare("UPDATE users SET profile_pic=? WHERE id=?")->execute([$url,$uid]); } catch(\Exception $e){}
    jsonOut(['success'=>true,'url'=>$url]);
}

function markDelivered(array $b): void {
    $user   = getAuthUser();
    $peerId = (int)($b['peer_id'] ?? 0);
    if (!$peerId) jsonOut(['success'=>true]);
    try { getDB()->prepare("UPDATE messages SET is_delivered=1 WHERE sender_id=? AND receiver_id=? AND is_delivered=0")->execute([$peerId,$user['id']]); } catch(\Exception $e){}
    jsonOut(['success'=>true]);
}

function deleteMessage(array $b): void {
    $user  = getAuthUser();
    $msgId = (int)($b['msg_id'] ?? 0);
    if (!$msgId) jsonOut(['success'=>false,'message'=>'msg_id required.']);
    $pdo = getDB();
    $st = $pdo->prepare("SELECT id FROM messages WHERE id=? AND sender_id=?");
    $st->execute([$msgId,$user['id']]);
    if (!$st->fetch()) jsonOut(['success'=>false,'message'=>'Not allowed.']);
    $pdo->prepare("DELETE FROM messages WHERE id=?")->execute([$msgId]);
    jsonOut(['success'=>true]);
}

function editMessage(array $b): void {
    $user  = getAuthUser();
    $msgId = (int)($b['msg_id'] ?? 0);
    $newTxt = trim($b['message'] ?? '');
    if (!$msgId||!$newTxt) jsonOut(['success'=>false,'message'=>'msg_id and message required.']);
    $pdo = getDB();
    $st = $pdo->prepare("SELECT id FROM messages WHERE id=? AND sender_id=? AND msg_type='text'");
    $st->execute([$msgId,$user['id']]);
    if (!$st->fetch()) jsonOut(['success'=>false,'message'=>'Not allowed.']);
    try { $pdo->prepare("UPDATE messages SET message=?,is_edited=1 WHERE id=?")->execute([$newTxt,$msgId]); }
    catch(\Exception $e){ $pdo->prepare("UPDATE messages SET message=? WHERE id=?")->execute([$newTxt,$msgId]); }
    jsonOut(['success'=>true]);
}

function markRead(array $b): void {
    $user   = getAuthUser();
    $peerId = (int)($b['peer_id'] ?? 0);
    if (!$peerId) jsonOut(['success'=>true]);
    try { getDB()->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0")->execute([$peerId,$user['id']]); } catch(\Exception $e){}
    jsonOut(['success'=>true]);
}

function searchUsers(array $b): void {
    $user = getAuthUser();
    $uid  = $user['id'];
    $q    = trim($b['q'] ?? '');
    if (strlen($q) < 2) jsonOut(['success'=>true,'users'=>[]]);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id,name,email,profile_pic FROM users WHERE (email LIKE ? OR name LIKE ?) AND id!=? AND is_verified=1 AND is_banned=0 LIMIT 12");
    $st->execute(['%'.$q.'%','%'.$q.'%',$uid]);
    jsonOut(['success'=>true,'users'=>$st->fetchAll()]);
}

function updateProfile(array $b): void {
    $user   = getAuthUser();
    $uid    = $user['id'];
    $name   = trim($b['name'] ?? '');
    $status = trim($b['status'] ?? '');
    if (!$name) jsonOut(['success'=>false,'message'=>'Name required.']);
    if (strlen($name) > 100) jsonOut(['success'=>false,'message'=>'Name too long (max 100 chars).']);
    if (strlen($status) > 100) $status = substr($status, 0, 100);
    getDB()->prepare("UPDATE users SET name=?,status=? WHERE id=?")->execute([$name,$status,$uid]);
    jsonOut(['success'=>true,'message'=>'Profile updated.']);
}

function submitComplaint(array $b): void {
    $user    = getAuthUser();
    $uid     = $user['id'];
    $subject = trim($b['subject'] ?? '');
    $message = trim($b['message'] ?? '');
    if (!$subject||!$message) jsonOut(['success'=>false,'message'=>'Subject and message required.']);
    getDB()->prepare("INSERT INTO complaints (user_id,subject,message) VALUES (?,?,?)")->execute([$uid,$subject,$message]);
    jsonOut(['success'=>true,'message'=>'Report submitted.']);
}

function doLogout(): void {
    // Token can come from cookie or from X-Auth-Token header (used by JS api())
    $token = $_COOKIE['pchat_token'] ?? '';
    if (!$token) $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!$token) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (strpos($auth, 'Bearer ') === 0) $token = substr($auth, 7);
    }
    if ($token) getDB()->prepare("UPDATE users SET session_token=NULL WHERE session_token=?")->execute([$token]);
    setcookie('pchat_token','',time()-3600,'/','',false,true);
    jsonOut(['success'=>true]);
}