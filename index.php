<?php
require __DIR__ . '/config.php';

$pageTitle  = 'หน้าแรก';
$positions  = positions_with_candidates();
$candCount  = array_sum(array_map(fn($p) => count($p['candidates']), $positions));
$stats      = get_results();
$status     = election_status();
$target     = $status === 'upcoming' ? setting('start_at') : ($status === 'open' ? setting('end_at') : null);
if ($status === 'open' && setting('election_mode') === 'open') $target = null; // เปิดแบบไม่มีกำหนด

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero-text">
        <span class="eyebrow">🗳️ การเลือกตั้งออนไลน์</span>
        <h1><?= e(setting('election_title')) ?></h1>
        <p class="lead"><?= e(setting('election_desc')) ?></p>

        <?php if ($target): ?>
            <div class="countdown" data-countdown="<?= e(date('c', strtotime($target))) ?>">
                <p class="countdown-label"><?= $status === 'upcoming' ? 'เปิดหีบในอีก' : 'ปิดหีบในอีก' ?></p>
                <div class="countdown-boxes">
                    <div><b data-d>0</b><span>วัน</span></div>
                    <div><b data-h>0</b><span>ชั่วโมง</span></div>
                    <div><b data-m>0</b><span>นาที</span></div>
                    <div><b data-s>0</b><span>วินาที</span></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="hero-actions">
            <?php if ($status === 'open'): ?>
                <a href="vote.php" class="btn btn-primary btn-lg">ลงคะแนนเลย →</a>
            <?php endif; ?>
            <a href="results.php" class="btn btn-ghost btn-lg">ดูผลคะแนน</a>
        </div>
    </div>
    <div class="hero-stats">
        <div class="stat"><b><?= $candCount ?></b><span>ผู้สมัคร (<?= count($positions) ?> ตำแหน่ง)</span></div>
        <div class="stat"><b><?= number_format($stats['voters']) ?></b><span>ผู้มีสิทธิ์</span></div>
        <div class="stat"><b><?= number_format($stats['total']) ?></b><span>มาใช้สิทธิ์</span></div>
        <div class="stat"><b><?= $stats['turnout'] ?>%</b><span>อัตราการใช้สิทธิ์</span></div>
    </div>
</section>

<?php foreach ($positions as $p): $candidates = $p['candidates']; ?>
<section class="candidate-section" id="position-<?= (int)$p['id'] ?>" data-title="<?= e($p['title']) ?>">
    <h2 class="section-title">ผู้สมัครตำแหน่ง<?= e($p['title']) ?> <small class="muted section-sub"><?= e(position_rule($p)) ?></small></h2>
    <div class="grid candidates">
        <?php foreach ($candidates as $c): ?>
            <article class="candidate-card" style="--accent: <?= e($c['color']) ?>">
                <div class="cand-number"><?= (int)$c['number'] ?></div>
                <div class="avatar">
                    <?php if ($c['photo_url']): ?>
                        <img src="<?= e($c['photo_url']) ?>" alt="<?= e($c['name']) ?>">
                    <?php else: ?>
                        <?= e(initials($c['name'])) ?>
                    <?php endif; ?>
                </div>
                <h3><?= e($c['name']) ?></h3>
                <p class="party"><?= e($c['party']) ?></p>
                <p class="slogan">“<?= e($c['slogan']) ?>”</p>
                <?php if ($c['policy']): ?>
                    <details class="policy">
                        <summary>ดูนโยบาย</summary>
                        <ul>
                            <?php foreach (preg_split('/\R/', $c['policy']) as $p): if (trim($p) === '') continue; ?>
                                <li><?= e($p) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$candidates): ?>
            <p class="muted">ยังไม่มีผู้สมัคร</p>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<section class="steps">
    <h2 class="section-title">ขั้นตอนการลงคะแนน</h2>
    <div class="grid steps-grid">
        <div class="step"><span>1</span><h4>เข้าสู่ระบบ</h4><p>ใช้เลขประจำตัวประชาชน 13 หลักและรหัสผ่าน แล้วอ่านและยอมรับข้อกำหนดและเงื่อนไข</p></div>
        <div class="step"><span>2</span><h4>ยืนยันตัวตน</h4><p>ถ่ายภาพใบหน้าและระบุตำแหน่งที่ตั้ง เพื่อป้องกันการใช้สิทธิ์แทนกัน</p></div>
        <div class="step"><span>3</span><h4>ลงคะแนนทุกตำแหน่ง</h4><p>เลือกผู้สมัครไม่เกินจำนวนที่กำหนดในแต่ละตำแหน่ง หรือเลือก “งดออกเสียง”</p></div>
        <div class="step"><span>4</span><h4>ยืนยันการลงคะแนน</h4><p>ตรวจสอบรายการก่อนยืนยัน ลงคะแนนได้เพียงครั้งเดียว</p></div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
