<?php
// PChat DB Installer — DELETE THIS FILE AFTER RUNNING!
require_once 'db.php';
$pdo = getDB();
$sqls = [
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(100) DEFAULT 'Hey there! I am using PChat.',
    is_verified TINYINT(1) DEFAULT 0,
    is_banned TINYINT(1) DEFAULT 0,
    verify_token VARCHAR(64),
    verify_token_expires DATETIME,
    session_token VARCHAR(64),
    last_seen DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conv (sender_id, receiver_id)
)",
"CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open','resolved') DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)",
"CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL
)",
"CREATE TABLE IF NOT EXISTS broadcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)"
];

// ── Migrations for new features (safe — uses IF NOT EXISTS / IF NOT EXISTS column) ──
$migrations = [
    "CREATE TABLE IF NOT EXISTS typing_status (
        user_id INT NOT NULL,
        peer_id INT NOT NULL,
        typing_until DATETIME NOT NULL,
        PRIMARY KEY (user_id, peer_id),
        INDEX idx_peer (peer_id)
    )",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS msg_type ENUM('text','image','video','voice','file','gif') NOT NULL DEFAULT 'text'",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS reply_to INT NULL",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_delivered TINYINT(1) DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_edited TINYINT(1) DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_pic VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS push_subscription TEXT NULL",
    "CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages (sender_id, receiver_id, id)",
];
foreach ($migrations as $sql) {
    try { $pdo->exec($sql); }
    catch (PDOException $e) {
        // Column already exists — not an error
        if (strpos($e->getMessage(),'Duplicate') === false && strpos($e->getMessage(),'already exists') === false) {
            echo "<p style='color:orange;font-family:monospace;'>MIGRATION NOTE: ".$e->getMessage()."</p>";
        }
    }
}
$ok = true;
foreach ($sqls as $sql) {
    try { $pdo->exec($sql); }
    catch (PDOException $e) { echo "<p style='color:red;font-family:monospace;'>ERROR: ".$e->getMessage()."</p>"; $ok=false; }
}
// Save admin hash in JSON format: {username: bcrypt_hash}
$hash = password_hash('pritam', PASSWORD_BCRYPT);
$adminData = json_encode(['pritam' => $hash]);
file_put_contents('.admin_hash', $adminData);
echo $ok
    ? "<div style='font-family:monospace;background:#080c14;color:#00f5c3;padding:30px;'><h2>✅ PChat Installed!</h2><p>All tables created.</p><p>Admin login: <strong>pritam / pritam</strong></p><p style='color:#ff4f6a;'>⚠️ DELETE install.php now!</p></div>"
    : "<div style='font-family:monospace;background:#080c14;color:#ff4f6a;padding:30px;'><h2>⚠️ Some errors occurred. Check above.</h2></div>";