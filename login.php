<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

$body   = getBody();
$action = $body['action'] ?? '';

switch ($action) {
    case 'register':        doRegister($body);        break;
    case 'login':           doLogin($body);           break;
    case 'logout':          doLogout();               break;
    case 'resend_verify':   resendVerify($body);      break;
    case 'forgot_password': forgotPassword($body);    break;
    case 'reset_password':  resetPassword($body);     break;
    case 'check_session':   checkSession();           break;
    default: jsonOut(['success'=>false,'message'=>'Unknown action.']);
}

function doRegister(array $b): void {
    $name     = trim($b['name'] ?? '');
    $email    = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$name || !$email || !$password) jsonOut(['success'=>false,'message'=>'All fields required.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['success'=>false,'message'=>'Invalid email address.']);
    if (strlen($password) < 8) jsonOut(['success'=>false,'message'=>'Password must be at least 8 characters.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $st->execute([$email]);
    if ($st->fetch()) jsonOut(['success'=>false,'message'=>'An account with this email already exists.']);
    $hash    = password_hash($password, PASSWORD_BCRYPT);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("INSERT INTO users (name,email,password_hash,verify_token,verify_token_expires) VALUES (?,?,?,?,?)")
        ->execute([$name,$email,$hash,$token,$expires]);
    $link = SITE_URL.'/ver.html?token='.urlencode($token);
    $html = emailTemplate('Verify Email',$name,
        'Thank you for registering on PChat — your end-to-end encrypted messaging platform. Please verify your email to activate your account. This link expires in <strong style="color:#eef2ff;">24 hours</strong>.',
        '✓ Verify Email Address', $link);
    $sent = sendMail($email,'Verify your PChat email address',$html);
    jsonOut(['success'=>true,'message'=>'Account created! Check your email to verify.','email_sent'=>$sent]);
}

function doLogin(array $b): void {
    $email    = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$email || !$password) jsonOut(['success'=>false,'message'=>'Email and password required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT * FROM users WHERE email=?");
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user || !password_verify($password,$user['password_hash']))
        jsonOut(['success'=>false,'message'=>'Incorrect email or password.']);
    if ($user['is_banned']) jsonOut(['success'=>false,'message'=>'Your account has been suspended.']);
    if (!$user['is_verified'])
        jsonOut(['success'=>false,'message'=>'Please verify your email before logging in. Check your inbox and spam folder.']);

    // Generate token and store in DB (cookie-based auth — no sessions needed)
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE users SET session_token=?,last_seen=NOW() WHERE id=?")->execute([$token,$user['id']]);

    // Detect HTTPS automatically so cookie works on both HTTP and HTTPS hosts
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    // Set cookie — SameSite=Lax works on InfinityFree, secure only when HTTPS
    setcookie('pchat_token', $token, [
        'expires'  => time() + (30 * 24 * 3600),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Also return token in response so JS can store as fallback (localStorage)
    jsonOut(['success'=>true,'message'=>'Logged in.','token'=>$token,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email']]]);
}

function doLogout(): void {
    $token = $_COOKIE['pchat_token'] ?? '';
    if ($token) {
        getDB()->prepare("UPDATE users SET session_token=NULL WHERE session_token=?")->execute([$token]);
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('pchat_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    jsonOut(['success'=>true]);
}

function checkSession(): void {
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!$token) $token = $_COOKIE['pchat_token'] ?? '';
    if (!$token) {
        // Also try reading from JSON body
        $b = getBody();
        $token = $b['token'] ?? '';
    }
    if (!$token) jsonOut(['success'=>false,'message'=>'No token.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT id,name,email FROM users WHERE session_token=? AND is_verified=1 AND is_banned=0");
    $st->execute([trim($token)]);
    $user = $st->fetch();
    if ($user) {
        $pdo->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$user['id']]);
        jsonOut(['success'=>true,'user'=>$user]);
    } else {
        jsonOut(['success'=>false,'message'=>'Session invalid.']);
    }
}

function resendVerify(array $b): void {
    $email = strtolower(trim($b['email'] ?? ''));
    if (!$email) jsonOut(['success'=>false,'message'=>'Email required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT * FROM users WHERE email=?");
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user) jsonOut(['success'=>false,'message'=>'No account found with this email.']);
    if ($user['is_verified']) jsonOut(['success'=>false,'message'=>'Email already verified.']);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("UPDATE users SET verify_token=?,verify_token_expires=? WHERE id=?")->execute([$token,$expires,$user['id']]);
    $link = SITE_URL.'/ver.html?token='.urlencode($token);
    $html = emailTemplate('Verify Email',$user['name'],
        'Here is your new verification link for PChat. This link expires in <strong style="color:#eef2ff;">24 hours</strong>.',
        '✓ Verify Email Address',$link);
    $sent = sendMail($email,'Verify your PChat email address',$html);
    jsonOut(['success'=>true,'message'=>$sent?'Verification email resent!':'Failed to send email.']);
}

function forgotPassword(array $b): void {
    $email = strtolower(trim($b['email'] ?? ''));
    if (!$email) jsonOut(['success'=>false,'message'=>'Email required.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT * FROM users WHERE email=? AND is_verified=1");
    $st->execute([$email]);
    $user = $st->fetch();
    if (!$user) jsonOut(['success'=>true,'message'=>'If this email is registered, you will receive a reset link.']);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $pdo->prepare("UPDATE users SET verify_token=?,verify_token_expires=? WHERE id=?")->execute([$token,$expires,$user['id']]);
    $link = SITE_URL.'/reset.html?token='.urlencode($token);
    $html = emailTemplate('Reset Password',$user['name'],
        'You requested a password reset for your PChat account. Click the button below to set a new password. This link expires in <strong style="color:#eef2ff;">1 hour</strong>.',
        '🔒 Reset Password',$link);
    sendMail($email,'Reset your PChat password',$html);
    jsonOut(['success'=>true,'message'=>'If this email is registered, you will receive a reset link.']);
}

function resetPassword(array $b): void {
    $token    = trim($b['token'] ?? '');
    $password = $b['password'] ?? '';
    if (!$token || !$password) jsonOut(['success'=>false,'message'=>'Token and password required.']);
    if (strlen($password) < 8) jsonOut(['success'=>false,'message'=>'Password must be at least 8 characters.']);
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT * FROM users WHERE verify_token=? AND verify_token_expires > NOW()");
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) jsonOut(['success'=>false,'message'=>'Invalid or expired reset link.']);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password_hash=?,verify_token=NULL,verify_token_expires=NULL,session_token=NULL WHERE id=?")->execute([$hash,$user['id']]);
    jsonOut(['success'=>true,'message'=>'Password updated successfully! You can now log in.']);
}