<?php
/**
 * สร้างไฟล์ database.sql จาก includes/schema.php (โครงสร้าง + ข้อมูลตัวอย่าง)
 * ใช้: php tools/build-database-sql.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/schema.php';

$q = fn($v) => $v === null ? 'NULL'
    : (is_int($v) ? (string)$v : "'" . strtr((string)$v, ["\\" => "\\\\", "'" => "\\'", "\n" => "\\n", "\r" => '']) . "'");
$row = fn(array $vals) => '(' . implode(', ', array_map($q, $vals)) . ')';
$data = sample_data();

$sql = <<<'SQL'
-- =====================================================================
--  ระบบเลือกตั้งออนไลน์ - ฐานข้อมูล (MariaDB / MySQL)
--  ไฟล์นี้สร้างอัตโนมัติจาก includes/schema.php ด้วย: php tools/build-database-sql.php
--  นำเข้า:  mysql -u <user> -p < database.sql
--       หรือ phpMyAdmin / HeidiSQL → Import ไฟล์นี้
--
--  บัญชีเริ่มต้น
--    ผู้ดูแล : admin          / admin1234
--    ผู้มีสิทธิ์ (เลขสมาชิก / เลขบัตรประชาชน / วันเกิด พ.ศ.)
--      000101 / 1101700203450 / 15 มี.ค. 2528
--      000102 / 3100600123450 / 2 พ.ย. 2522
--  ชื่อผู้สมัครทั้งหมดเป็นชื่อสมมติ
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `election_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `election_db`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `access_logs`, `vote_verifications`, `votes`, `candidates`, `positions`, `users`, `settings`;
SET FOREIGN_KEY_CHECKS = 1;

SQL;

$sql .= preg_replace('/^ {8}/m', '', trim(schema_sql(), "\n")) . "\n\n";

$sql .= "-- =====================================================================\n-- ข้อมูลเริ่มต้น\n-- =====================================================================\n";
$settings = $data['settings'] + [
    'election_mode'    => 'auto',
    'start_at'         => date('Y-m-d 08:00:00'),
    'end_at'           => date('Y-m-d 23:59:00', strtotime('+7 days')),
    'allow_register'   => '1',
    'gps_max_accuracy' => '0',
];
$sql .= "INSERT INTO settings (k, v) VALUES\n  " . implode(",\n  ", array_map(fn($k, $v) => $row([$k, $v]), array_keys($settings), $settings)) . ";\n\n";

// ผู้ดูแล: admin / admin1234 · ผู้มีสิทธิ์: เข้าสู่ระบบด้วยเลขสมาชิก + เลขบัตร + วันเกิด (รหัสผ่านใช้ไม่ได้)
$users = [$row(['admin', null, null, 'ผู้ดูแลระบบ', '', '$2y$10$vJafGJNGXLCgMdbDfBIOEOszusULAsHOBZcfbruFFfu.4D7BB4xmK', 'admin'])];
foreach ($data['voters'] as $cid => [$memberNo, $first, $last, $dob]) {
    $users[] = $row([(string)$cid, $memberNo, $dob, $first, $last, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'voter']);
}
$sql .= "INSERT INTO users (username, member_no, birth_date, first_name, last_name, password_hash, role) VALUES\n  " . implode(",\n  ", $users) . ";\n\n";

$pos = [];
$cand = [];
foreach ($data['positions'] as $i => [$title, $seats, $max, $sort, $cands]) {
    $pid = $i + 1;
    $pos[] = $row([$pid, $title, $seats, $max, $sort]);
    foreach ($cands as $c) $cand[] = $row([$pid, ...$c]);
}
$sql .= "INSERT INTO positions (id, title, seats, max_choices, sort_order) VALUES\n  " . implode(",\n  ", $pos) . ";\n\n";
$sql .= "INSERT INTO candidates (position_id, number, first_name, last_name, party, slogan, policy, color) VALUES\n  " . implode(",\n  ", $cand) . ";\n";

file_put_contents(dirname(__DIR__) . '/database.sql', $sql);
echo "database.sql: " . strlen($sql) . " bytes, " . count($pos) . " positions, " . count($cand) . " candidates\n";
