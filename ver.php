<?php
require_once 'db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$token = trim($_GET['token'] ?? '');
if (!$token) {
    jsonOut(['success' => false, 'message' => 'No verification token provided.']);
}

$pdo = getDB();

// Check if already verified
$st = $pdo->prepare("SELECT * FROM users WHERE verify_token = ?");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    // Check if user already verified with this token (used token)
    $st2 = $pdo->prepare("SELECT id, is_verified FROM users WHERE verify_token IS NULL AND is_verified = 1");
    // We can't know which user this was — just report invalid/used
    jsonOut(['success' => false, 'message' => 'This verification link is invalid or has already been used. If you already verified, you can sign in.']);
}

if ((int)$user['is_verified'] === 1) {
    jsonOut(['success' => false, 'message' => 'Your email is already verified. You can sign in now.']);
}

if (!empty($user['verify_token_expires']) && strtotime($user['verify_token_expires']) < time()) {
    jsonOut(['success' => false, 'message' => 'This verification link has expired. Please request a new one from the login page.']);
}

$pdo->prepare("UPDATE users SET is_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ?")
    ->execute([$user['id']]);

jsonOut(['success' => true, 'message' => 'Email verified successfully! You can now sign in to PChat.']);