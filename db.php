<?php
// ─── DATABASE CONFIGURATION ──────────────────────────────────────────────────
// Update these values to match your hosting environment
define('DB_HOST', 'your host name');  // Your MySQL host
define('DB_USER', 'username');              // Your DB username
define('DB_PASS', 'password');           // Your DB password
define('DB_NAME', 'db name ');        // Your database name
define('SITE_URL', 'your site url');    // Your site URL (no trailing slash)
define('SITE_NAME', 'site name');

// ─── GMAIL SMTP SETTINGS ──────────────────────────────────────────────
// 1. Use your real Gmail address below
// 2. DO NOT use your normal Gmail password here!
//    Go to: https://myaccount.google.com/apppasswords
//    Create an App Password (requires 2FA enabled on your Google account)
//    Paste that 16-character App Password in GMAIL_PASS below
define('GMAIL_USER', 'user male');   // <-- change this
define('GMAIL_PASS', 'your app password');    // <-- your 16-char App Password
define('SMTP_FROM',  'app mail');   // <-- same as GMAIL_USER
define('SMTP_NAME',  'app name');
// ──────────────────────────────────────────────────────────────────────

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES=>false]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success'=>false,'message'=>'Database connection failed.']));
        }
    }
    return $pdo;
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

/**
 * Send email via Gmail SMTP using cURL (works on InfinityFree).
 * No PHPMailer needed.
 */
function sendMail(string $to, string $subject, string $html): bool {
    $from     = SMTP_NAME . ' <' . SMTP_FROM . '>';
    $boundary = md5(uniqid(rand(), true));
    $date     = date('r');
    $msgId    = '<' . time() . '.' . md5($to) . '@pchat.free.nf>';

    // Build raw MIME email
    $raw  = "Date: {$date}\r\n";
    $raw .= "To: {$to}\r\n";
    $raw .= "From: {$from}\r\n";
    $raw .= "Message-ID: {$msgId}\r\n";
    $raw .= "Subject: {$subject}\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $raw .= "\r\n";
    $raw .= "--{$boundary}\r\n";
    $raw .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $raw .= strip_tags($html) . "\r\n";
    $raw .= "--{$boundary}\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $raw .= $html . "\r\n";
    $raw .= "--{$boundary}--\r\n";

    if (!function_exists('curl_init')) {
        error_log('PChat: cURL not available for email sending');
        return false;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'smtps://smtp.gmail.com:465',
        CURLOPT_USE_SSL        => CURLUSESSL_ALL,
        CURLOPT_USERNAME       => GMAIL_USER,
        CURLOPT_PASSWORD       => GMAIL_PASS,
        CURLOPT_MAIL_FROM      => '<' . SMTP_FROM . '>',
        CURLOPT_MAIL_RCPT      => [$to],
        CURLOPT_READDATA       => fopen('data://text/plain,' . rawurlencode($raw), 'r'),
        CURLOPT_UPLOAD         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_VERBOSE        => false,
    ]);

    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    return ($result !== false && empty($error));
}

function emailTemplate(string $title, string $name, string $body, string $btnText='', string $btnLink=''): string {
    $btn = $btnText ? "<div style='text-align:center;margin:24px 0;'><a href='{$btnLink}' style='display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#00f5c3,#0af);color:#060a11;text-decoration:none;border-radius:12px;font-weight:700;font-size:.95rem;letter-spacing:1px;'>{$btnText}</a></div>" : '';
    $spamNote = $btnLink ? "<div style='background:rgba(0,245,195,.05);border:1px solid rgba(0,245,195,.12);border-radius:10px;padding:14px;margin-top:16px;font-size:.85rem;color:#8892a4;'><strong style='color:#eef2ff;'>📂 Don't see this email?</strong><br>Check your <strong>Spam</strong> or <strong>Junk</strong> folder. Add us to contacts for future delivery.</div>" : '';
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#080c14;font-family:Arial,sans-serif;color:#eef2ff;">
<div style="max-width:520px;margin:40px auto;background:#0d1322;border:1px solid rgba(0,245,195,.15);border-radius:20px;overflow:hidden;">
  <div style="background:linear-gradient(135deg,#00f5c3,#0af);padding:32px;text-align:center;">
    <h1 style="margin:0;font-size:1.8rem;color:#060a11;font-weight:800;letter-spacing:2px;">⬡ PChat</h1>
  </div>
  <div style="padding:36px;">
    <p style="color:#8892a4;line-height:1.8;margin:0 0 16px;">Hi <strong style="color:#eef2ff;">'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'</strong>,</p>
    <p style="color:#8892a4;line-height:1.8;">{$body}</p>
    {$btn}{$spamNote}
    <p style="margin-top:20px;font-size:.82rem;color:#4a5568;">If you did not request this, please ignore this email.</p>
  </div>
  <div style="padding:20px 36px;border-top:1px solid rgba(255,255,255,.06);color:#4a5568;font-size:.8rem;">
    PChat Encrypted Messaging &copy; 2025 &middot; All messages are E2E encrypted
  </div>
</div>
</body></html>
HTML;
}
