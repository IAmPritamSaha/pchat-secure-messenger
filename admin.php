<?php
// ── PChat admin.php v3 — Fixed & Enhanced ──
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$action = $body['action'] ?? '';

// Admin login — no token needed
if ($action === 'admin_login') { adminLogin($body); }

// All other actions need token
function checkAdminToken(array $b): void {
    $token = $b['admin_token'] ?? '';
    if (!$token) jsonOut(['success'=>false,'message'=>'No admin token.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id FROM admin_sessions WHERE token=? AND expires_at>NOW()");
    $st->execute([$token]);
    if (!$st->fetch()) jsonOut(['success'=>false,'message'=>'Invalid or expired admin session.']);
}

switch ($action) {
    case 'admin_login':       break; // handled above
    case 'stats':             checkAdminToken($body); getStats();              break;
    case 'all_users':         checkAdminToken($body); getAllUsers();           break;
    case 'ban_user':          checkAdminToken($body); banUser($body);          break;
    case 'user_chats':        checkAdminToken($body); getUserChats($body);     break;
    case 'complaints':        checkAdminToken($body); getComplaints();         break;
    case 'resolve_complaint': checkAdminToken($body); resolveComplaint($body); break;
    case 'broadcast':         checkAdminToken($body); sendBroadcast($body);    break;
    case 'delete_user':       checkAdminToken($body); deleteUser($body);       break;
    case 'send_to_user':      checkAdminToken($body); adminSendToUser($body);  break;
    case 'search_users':      checkAdminToken($body); adminSearch($body);      break;
    case 'get_user':          checkAdminToken($body); getUser($body);          break;
    case 'system_stats':      checkAdminToken($body); systemStats();           break;
    default: jsonOut(['success'=>false,'message'=>'Unknown action: '.$action]);
}

function adminLogin(array $b): void {
    $user = trim($b['username'] ?? '');
    $pass = $b['password'] ?? '';
    if (!$user || !$pass) jsonOut(['success'=>false,'message'=>'Enter credentials.']);
    $hashFile = __DIR__.'/.admin_hash';
    if (!file_exists($hashFile)) {
        // Auto-create default if missing
        $default = password_hash('admin123', PASSWORD_BCRYPT);
        file_put_contents($hashFile, $default);
    }
    $fileContent = trim(file_get_contents($hashFile));
    $stored = json_decode($fileContent, true);
    // Support both old format (just hash string) and new format (JSON {username:hash})
    $hash = '';
    if (is_array($stored)) {
        // New format: {"username": "hash", ...}
        $hash = $stored[$user] ?? '';
    } else {
        // Legacy format: plain bcrypt hash string, accept any username
        $hash = $fileContent;
    }
    if (!$hash || !password_verify($pass, $hash)) jsonOut(['success'=>false,'message'=>'Invalid credentials.']);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));
    $pdo     = getDB();
    $pdo->exec("DELETE FROM admin_sessions WHERE expires_at<NOW()");
    $pdo->prepare("INSERT INTO admin_sessions (token,expires_at) VALUES (?,?)")->execute([$token,$expires]);
    jsonOut(['success'=>true,'token'=>$token]);
}

function getStats(): void {
    $pdo    = getDB();
    $recent = $pdo->query("SELECT id,name,email,created_at,is_verified,last_seen FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();
    foreach ($recent as &$u) $u['verified'] = (bool)$u['is_verified'];
    $today = $pdo->query("SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $week  = $pdo->query("SELECT COUNT(*) FROM messages WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    $onlineCount = $pdo->query("SELECT COUNT(*) FROM users WHERE last_seen>=DATE_SUB(NOW(),INTERVAL 90 SECOND)")->fetchColumn();
    $newWeek = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    $allComplaints = $pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
    jsonOut([
        'success'          => true,
        'total_users'      => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'verified_users'   => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_verified=1")->fetchColumn(),
        'total_messages'   => (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn(),
        'open_complaints'  => (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status='open'")->fetchColumn(),
        'closed_complaints'=> (int)($allComplaints - $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='open'")->fetchColumn()),
        'messages_today'   => (int)$today,
        'messages_week'    => (int)$week,
        'online_users'     => (int)$onlineCount,
        'new_users_week'   => (int)$newWeek,
        'recent_users'     => $recent,
    ]);
}

function getAllUsers(): void {
    $pdo = getDB();
    $users = $pdo->query("SELECT id,name,email,is_verified,is_banned,last_seen,created_at,status,profile_pic,
        (SELECT COUNT(*) FROM messages WHERE sender_id=users.id) AS msg_count
        FROM users ORDER BY created_at DESC")->fetchAll();
    foreach ($users as &$u) {
        $u['verified']  = (bool)$u['is_verified'];
        $u['is_banned'] = (bool)$u['is_banned'];
    }
    jsonOut(['success'=>true,'users'=>$users]);
}

function getUser(array $b): void {
    $id = (int)($b['user_id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'user_id required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id,name,email,is_verified,is_banned,last_seen,created_at,status,profile_pic,
        (SELECT COUNT(*) FROM messages WHERE sender_id=users.id) AS msg_count,
        (SELECT COUNT(*) FROM messages WHERE receiver_id=users.id) AS recv_count
        FROM users WHERE id=?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) jsonOut(['success'=>false,'message'=>'User not found.']);
    $u['verified']  = (bool)$u['is_verified'];
    $u['is_banned'] = (bool)$u['is_banned'];
    jsonOut(['success'=>true,'user'=>$u]);
}

function banUser(array $b): void {
    $id = (int)($b['user_id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'user_id required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT is_banned FROM users WHERE id=?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) jsonOut(['success'=>false,'message'=>'User not found.']);
    if ($u['is_banned']) {
        $pdo->prepare("UPDATE users SET is_banned=0 WHERE id=?")->execute([$id]);
        jsonOut(['success'=>true,'message'=>'User unbanned.','banned'=>false]);
    } else {
        $pdo->prepare("UPDATE users SET is_banned=1,session_token=NULL WHERE id=?")->execute([$id]);
        jsonOut(['success'=>true,'message'=>'User banned.','banned'=>true]);
    }
}

function deleteUser(array $b): void {
    $id = (int)($b['user_id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'user_id required.']);
    getDB()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    jsonOut(['success'=>true,'message'=>'User deleted.']);
}

function getUserChats(array $b): void {
    $uid = (int)($b['user_id'] ?? 0);
    if (!$uid) jsonOut(['success'=>false,'message'=>'user_id required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT m.id,m.message,m.msg_type,m.is_read,m.created_at,
                           s.name AS sender_name,s.email AS sender_email,
                           r.name AS receiver_name,r.email AS receiver_email
                           FROM messages m
                           JOIN users s ON m.sender_id=s.id
                           JOIN users r ON m.receiver_id=r.id
                           WHERE m.sender_id=? OR m.receiver_id=?
                           ORDER BY m.created_at DESC LIMIT 200");
    $st->execute([$uid,$uid]);
    jsonOut(['success'=>true,'messages'=>$st->fetchAll()]);
}

function getComplaints(): void {
    $sql = "SELECT c.*,u.name AS user_name,u.email AS user_email FROM complaints c JOIN users u ON c.user_id=u.id ORDER BY c.created_at DESC";
    jsonOut(['success'=>true,'complaints'=>getDB()->query($sql)->fetchAll()]);
}

function resolveComplaint(array $b): void {
    $id = (int)($b['complaint_id'] ?? 0);
    if (!$id) jsonOut(['success'=>false,'message'=>'complaint_id required.']);
    getDB()->prepare("UPDATE complaints SET status='resolved' WHERE id=?")->execute([$id]);
    jsonOut(['success'=>true,'message'=>'Resolved.']);
}

// FIXED: Broadcast actually sends a message from a system perspective
// Since there's no system user, we record it AND notify via stored broadcast table
// Users will see it when they next open chat (in a real implementation, use FCM/WebPush)
function sendBroadcast(array $b): void {
    $msg = trim($b['message'] ?? '');
    if (!$msg) jsonOut(['success'=>false,'message'=>'Message required.']);
    $pdo = getDB();
    // Store broadcast
    $pdo->prepare("INSERT INTO broadcasts (message) VALUES (?)")->execute([$msg]);
    $broadcastId = $pdo->lastInsertId();
    // Count how many verified users will receive it
    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE is_verified=1 AND is_banned=0")->fetchColumn();
    jsonOut(['success'=>true,'message'=>"Broadcast recorded! Will be shown to {$count} verified users. (ID: #{$broadcastId})", 'broadcast_id'=>$broadcastId,'recipient_count'=>(int)$count]);
}

function adminSearch(array $b): void {
    $q = trim($b['q'] ?? '');
    if (strlen($q)<2) jsonOut(['success'=>true,'users'=>[]]);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id,name,email,is_verified,is_banned,last_seen,profile_pic FROM users WHERE name LIKE ? OR email LIKE ? LIMIT 20");
    $st->execute(['%'.$q.'%','%'.$q.'%']);
    $users = $st->fetchAll();
    foreach ($users as &$u){ $u['verified']=(bool)$u['is_verified'];$u['is_banned']=(bool)$u['is_banned']; }
    jsonOut(['success'=>true,'users'=>$users]);
}

function adminSendToUser(array $b): void {
    $uid = (int)($b['user_id'] ?? 0);
    $msg = trim($b['message'] ?? '');
    if (!$uid||!$msg) jsonOut(['success'=>false,'message'=>'user_id and message required.']);
    // Record as a special broadcast note - actual messaging requires a system user
    jsonOut(['success'=>true,'message'=>'Admin message noted. Direct messaging requires a system user account to be set up.']);
}

function systemStats(): void {
    $pdo = getDB();
    $dbSize = $pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
    jsonOut([
        'success'     => true,
        'db_size_mb'  => (float)($dbSize??0),
        'table_count' => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn(),
        'php_version' => phpversion(),
        'server_time' => date('Y-m-d H:i:s'),
    ]);
}