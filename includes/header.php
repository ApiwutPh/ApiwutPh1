<?php
/** @var string $pageTitle */
/** @var string $base  path prefix ('' or '../') */
$base = $base ?? '';
$user = current_user();
$status = election_status();
$statusLabel = ['open' => 'เปิดลงคะแนน', 'closed' => 'ปิดหีบแล้ว', 'upcoming' => 'ยังไม่เปิด'][$status];
$current = basename($_SERVER['SCRIPT_NAME']);
$isAdmin = str_contains($_SERVER['SCRIPT_NAME'], '/admin/');
$nav = fn(string $file) => (!$isAdmin && $current === $file) ? ' class="active"' : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(($pageTitle ?? '') ? $pageTitle . ' | ' : '') ?>ระบบเลือกตั้งออนไลน์ สหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด</title>
    <link rel="icon" href="<?= $base ?>assets/img/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css">
</head>
<body>
<!-- ส่วนหัว: โลโก้ + ชื่อหน่วยงาน -->
<div class="org-header">
    <div class="container org-inner">
        <a href="<?= $base ?>index.php" class="brand">
            <img class="brand-logo" src="<?= $base ?>assets/img/logo.png" alt="ตราสหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด" width="96" height="98">
            <span class="brand-text">
                <b>สหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด</b>
                <small>THE NACC SAVING AND CREDIT COOPERATIVE, LIMITED</small>
                <em>ระบบเลือกตั้งออนไลน์</em>
            </span>
        </a>
        <div class="org-side">
            <span class="status-pill status-<?= $status ?>"><i></i><?= $statusLabel ?></span>
            <?php if ($user): ?>
                <span class="nav-user">👤 <?= e($user['full_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- แถบคำขวัญ (แก้ไขได้ที่ ผู้ดูแลระบบ → ตั้งค่า) -->
<?php $slogan = slogan_config(); if ($slogan['messages']): ?>
<div class="slogan-bar slogan-<?= $slogan['mode'] ?>" aria-label="คำขวัญ">
    <div class="container"><div class="slogan-track"><span style="--slogan-duration: <?= $slogan['duration'] ?>s"><?php
        foreach ($slogan['messages'] as $i => $m) echo ($i ? '<i class="slogan-sep" aria-hidden="true">•</i>' : '') . e($m);
    ?></span></div></div>
</div>
<?php endif; ?>

<!-- แถบเมนูหลัก (สีม่วง) -->
<header class="masthead">
    <div class="container masthead-inner">
        <button class="menu-toggle" aria-label="เมนู" data-toggle="#mainnav">☰ เมนู</button>
        <nav id="mainnav" class="nav">
            <a href="<?= $base ?>index.php"<?= $nav('index.php') ?>>🏠 หน้าหลัก</a>
            <a href="<?= $base ?>vote.php"<?= $nav('vote.php') ?>>🗳️ ลงคะแนน</a>
            <a href="<?= $base ?>results.php"<?= $nav('results.php') ?>>📊 ผลคะแนน</a>
            <?php if ($user && $user['role'] === 'admin'): ?>
                <a href="<?= $base ?>admin/index.php"<?= $isAdmin ? ' class="active"' : '' ?>>⚙️ ผู้ดูแลระบบ</a>
            <?php endif; ?>
        </nav>
        <?php if ($user): ?>
            <a class="btn-getstarted" href="<?= $base ?>logout.php">ออกจากระบบ</a>
        <?php else: ?>
            <a class="btn-getstarted" href="<?= $base ?>login.php">🔑 เข้าสู่ระบบสมาชิก</a>
        <?php endif; ?>
    </div>
</header>

<!-- แถบ breadcrumb (toolbar) -->
<div class="toolbar">
    <div class="container">
        <ol class="breadcrumb">
            <li><a href="<?= $base ?>index.php" aria-label="หน้าหลัก">🏠</a></li>
            <li class="active"><?= e($pageTitle ?? 'หน้าแรก') ?></li>
        </ol>
    </div>
</div>

<div class="main">
<main class="container wrapper">
<?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" data-dismiss><?= e($f['msg']) ?></div>
<?php endforeach; unset($_SESSION['flash']); ?>
