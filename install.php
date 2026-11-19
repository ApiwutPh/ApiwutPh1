<?php
/**
 * ติดตั้งฐานข้อมูล: สร้าง DB, ตาราง, ผู้ดูแลระบบ และข้อมูลตัวอย่าง
 * เปิด http://localhost/Demo/install.php ครั้งเดียว แล้วลบไฟล์นี้ทิ้ง (แนะนำ)
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/schema.php';

$messages = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        $messages[] = 'สร้างฐานข้อมูล ' . DB_NAME . ' แล้ว';

        schema_create($pdo);
        $messages[] = "สร้างตารางที่จำเป็นแล้ว";
        foreach (schema_migrate($pdo) as $m) $messages[] = "อัปเกรดฐานข้อมูล: $m";

        // ผู้ดูแลระบบ
        $adminPass = $_POST['admin_pass'] ?: 'admin1234';
        $st = $pdo->prepare("INSERT INTO users (username, first_name, last_name, password_hash, role) VALUES ('admin', 'ผู้ดูแลระบบ', '', ?, 'admin')
                             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
        $st->execute([password_hash($adminPass, PASSWORD_DEFAULT)]);
        $messages[] = 'บัญชีผู้ดูแล: admin / ' . e($adminPass);

        // ค่าเริ่มต้น
        $defaults = sample_data()["settings"] + [
            "election_mode" => "auto",
            "start_at"      => date("Y-m-d 08:00:00"),
            "end_at"        => date("Y-m-d 23:59:00", strtotime("+7 days")),
            "gps_max_accuracy" => "0",
        ];
        $st = $pdo->prepare("INSERT IGNORE INTO settings (k, v) VALUES (?, ?)");
        foreach ($defaults as $k => $v) $st->execute([$k, $v]);

        // ตำแหน่ง (สร้างเสมอ) + ผู้สมัครและผู้มีสิทธิ์ตัวอย่าง (ถ้าเลือก)
        $sample = !empty($_POST["sample"]) && !$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
        $noPositions = !$pdo->query("SELECT COUNT(*) FROM positions")->fetchColumn();
        $added = ($noPositions || $sample) ? seed_positions($pdo, $sample) : 0;
        $messages[] = "ตำแหน่งที่เลือกตั้ง: " . implode(", ", $pdo->query("SELECT title FROM positions ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN));
        if ($sample) {
            $messages[] = "เพิ่มผู้สมัครตัวอย่าง $added คน";
            // ผู้มีสิทธิ์ตัวอย่าง: เข้าสู่ระบบด้วยเลขสมาชิก + เลขบัตรประชาชน + วันเกิด (ไม่ใช้รหัสผ่าน)
            $st = $pdo->prepare('INSERT IGNORE INTO users (username, member_no, birth_date, first_name, last_name, password_hash) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (sample_data()['voters'] as $cid => [$memberNo, $first, $last, $dob]) {
                $st->execute([(string)$cid, $memberNo, $dob, $first, $last, unusable_password_hash()]);
                $messages[] = "ผู้มีสิทธิ์ตัวอย่าง: สมาชิก $memberNo · บัตร $cid · เกิด " . thai_date($dob);
            }
        }
        $ok = true;
    } catch (PDOException $ex) {
        $messages[] = '<b style="color:#dc2626">ผิดพลาด:</b> ' . e($ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งระบบเลือกตั้ง</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="container narrow" style="padding-top:60px">
    <div class="card">
        <h1 class="card-title">⚙️ ติดตั้งระบบเลือกตั้ง</h1>
        <?php if ($messages): ?>
            <ul class="install-log">
                <?php foreach ($messages as $m): ?><li><?= $m ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($ok): ?>
            <div class="alert alert-success">ติดตั้งเสร็จสมบูรณ์! แนะนำให้ลบไฟล์ install.php ออกเพื่อความปลอดภัย</div>
            <a href="index.php" class="btn btn-primary btn-block">ไปที่หน้าแรก →</a>
        <?php else: ?>
            <p class="muted">ฐานข้อมูล: <code><?= e(DB_NAME) ?></code> @ <code><?= e(DB_HOST) ?></code> (แก้ไขได้ใน config.php)</p>
            <form method="post">
                <label class="field">
                    <span>รหัสผ่านผู้ดูแลระบบ (username: admin)</span>
                    <input type="text" name="admin_pass" placeholder="admin1234">
                </label>
                <label class="check"><input type="checkbox" name="sample" value="1" checked> เพิ่มผู้สมัครและผู้มีสิทธิ์ตัวอย่าง</label>
                <button class="btn btn-primary btn-block">เริ่มติดตั้ง</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
