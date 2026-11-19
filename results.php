<?php
require __DIR__ . '/config.php';

$pageTitle = 'ผลคะแนน';
$visible   = results_visible();
$r         = $visible ? get_results() : null;

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>ผลการเลือกตั้ง</h1>
        <p class="muted"><?= e(setting('election_title')) ?></p>
    </div>
    <?php if ($visible): ?>
        <span class="live-badge" id="liveBadge"><i></i> ผลการนับคะแนน · <span id="updated"><?= e($r['updated']) ?></span></span>
    <?php endif; ?>
</div>

<?php if (!$visible): ?>
    <div class="card done-card">
        <div class="done-icon">🔒</div>
        <h1>ผลคะแนนยังไม่เปิดเผย</h1>
        <p>ระหว่างการเลือกตั้ง ไม่มีผู้ใดดูผลคะแนนได้ รวมถึงผู้ดูแลระบบ</p>
        <p class="muted">ผลคะแนนจะเปิดเผยหลังปิดหีบ <?= e(thai_datetime(setting('end_at'))) ?></p>
    </div>
<?php else: ?>
    <div class="grid stats-grid">
        <div class="stat-card"><span>ผู้มีสิทธิ์</span><b id="sVoters"><?= number_format($r['voters']) ?></b></div>
        <div class="stat-card"><span>มาใช้สิทธิ์</span><b id="sTotal"><?= number_format($r['total']) ?></b></div>
        <div class="stat-card"><span>อัตราการใช้สิทธิ์</span><b id="sTurnout"><?= $r['turnout'] ?>%</b>
            <div class="meter"><i id="sTurnoutBar" style="width: <?= min(100, $r['turnout']) ?>%"></i></div>
        </div>
        <div class="stat-card"><span>จำนวนตำแหน่ง</span><b><?= number_format(count($r['positions'])) ?></b></div>
    </div>

    <p class="muted small">ร้อยละคำนวณจากจำนวนผู้มาใช้สิทธิ์ · ตำแหน่งที่เลือกได้หลายคน ผลรวมร้อยละอาจเกิน 100 · ผลอย่างเป็นทางการเป็นไปตามประกาศของสหกรณ์</p>
    <div id="resultPositions">
        <noscript><p class="muted">กรุณาเปิดใช้งาน JavaScript เพื่อดูผลคะแนน</p></noscript>
    </div>

    <script>window.RESULTS = <?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?php endif; ?>

<?php
$extraScript = $visible ? '<script>initResults("api/results.php");</script>' : '';
require __DIR__ . '/includes/footer.php';
