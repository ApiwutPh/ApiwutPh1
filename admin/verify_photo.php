<?php
/** แสดงภาพถ่ายยืนยันตัวตน — เฉพาะผู้ดูแลระบบ */
require dirname(__DIR__) . '/config.php';
require_admin();

$st = db()->prepare('SELECT photo_file FROM vote_verifications WHERE user_id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$file = (string)$st->fetchColumn();
$path = VERIFY_DIR . '/' . basename($file);

if ($file === '' || !is_file($path)) {
    http_response_code(404);
    exit('ไม่พบภาพ');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
