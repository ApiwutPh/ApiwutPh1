<?php
require __DIR__ . '/config.php';

$user   = require_login();
$status = election_status();

// ตำแหน่งที่มีผู้สมัครอย่างน้อย 1 คน (ตำแหน่งที่ไม่มีผู้สมัครจะไม่อยู่ในบัตร)
$positions = array_values(array_filter(positions_with_candidates(), fn($p) => $p['candidates']));
$termsAccepted = ($_SESSION['terms_ok'] ?? 0) === (int)$user['id'];

/** ปฏิเสธคำขอ: บันทึก log (เหตุผลเท่านั้น ไม่มีข้อมูลการเลือก) แจ้งผู้ใช้ แล้วกลับไปหน้าบัตร */
$reject = function (string $msg) use ($user): never {
    log_event('vote_reject', $user, '', $msg);
    flash($msg, 'danger');
    redirect('vote.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ($user['role'] !== 'voter') {
        $reject('บัญชีผู้ดูแลระบบไม่สามารถลงคะแนนได้');
    }
    if ($status !== 'open') {
        $reject('ขณะนี้ไม่อยู่ในช่วงเวลาลงคะแนน');
    }

    // ----- ยอมรับข้อกำหนดและเงื่อนไข -----
    if (($_POST['action'] ?? '') === 'accept_terms') {
        if (empty($_POST['agree'])) {
            $reject('กรุณาทำเครื่องหมายยอมรับข้อกำหนดและเงื่อนไขก่อนดำเนินการต่อ');
        }
        db()->prepare('UPDATE users SET terms_accepted_at = NOW() WHERE id = ?')->execute([$user['id']]);
        $_SESSION['terms_ok'] = (int)$user['id'];
        log_event('terms_accept', $user);
        redirect('vote.php');
    }
    if (!$termsAccepted) {
        $reject('กรุณาอ่านและยอมรับข้อกำหนดและเงื่อนไขก่อนลงคะแนน');
    }

    // ----- ตรวจบัตร: ทุกตำแหน่งต้องเลือกผู้สมัคร (ไม่เกินจำนวนที่กำหนด) หรืองดออกเสียง -----
    if (!$positions) {
        $reject('ยังไม่มีรายชื่อผู้สมัคร');
    }
    $choices = is_array($_POST['choice'] ?? null) ? $_POST['choice'] : [];
    $abstain = is_array($_POST['abstain'] ?? null) ? $_POST['abstain'] : [];
    $ballot  = []; // [[position_id, candidate_id|null], ...]
    foreach ($positions as $p) {
        $pid   = (int)$p['id'];
        $valid = array_map('intval', array_column($p['candidates'], 'id'));
        $sel   = array_values(array_unique(array_map('intval', (array)($choices[$pid] ?? []))));
        $skip  = !empty($abstain[$pid]);
        $where = 'ตำแหน่ง' . $p['title'];
        if ($skip && $sel) {
            $reject("$where: เลือกผู้สมัครพร้อมกับงดออกเสียงไม่ได้");
        }
        if ($skip) { $ballot[] = [$pid, null]; continue; }
        if (!$sel) {
            $reject("$where: กรุณาเลือกผู้สมัคร หรือเลือกงดออกเสียง");
        }
        if (count($sel) > (int)$p['max_choices']) {
            $reject("$where: เลือกได้ไม่เกิน {$p['max_choices']} หมายเลข");
        }
        foreach ($sel as $cid) {
            if (!in_array($cid, $valid, true)) {
                $reject("$where: ข้อมูลผู้สมัครไม่ถูกต้อง");
            }
            $ballot[] = [$pid, $cid];
        }
    }

    // ----- ยืนยันตัวตน: รูปถ่ายจากกล้อง + พิกัด GPS (ผูกกับผู้ใช้ ไม่ผูกกับบัตร) -----
    if (empty($_POST['consent'])) {
        $reject('กรุณายินยอมให้เก็บภาพถ่ายและตำแหน่งก่อนลงคะแนน');
    }
    $photo = verification_photo((string)($_POST['photo'] ?? ''));
    $lat = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);
    $acc = filter_var($_POST['accuracy'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($photo === null) {
        $reject('ไม่พบภาพถ่ายยืนยันตัวตน หรือไฟล์ภาพไม่ถูกต้อง กรุณาถ่ายภาพใหม่');
    }
    if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) {
        $reject('ไม่พบตำแหน่ง GPS กรุณาอนุญาตการเข้าถึงตำแหน่งแล้วลองใหม่');
    }
    $geoMax = (int)setting('gps_max_accuracy', '0');
    if ($geoMax > 0 && ($acc === false || $acc > $geoMax)) {
        $reject("ตำแหน่ง GPS คลาดเคลื่อนเกิน ±{$geoMax} ม. กรุณาเปิด GPS บนอุปกรณ์แล้วค้นหาตำแหน่งใหม่");
    }
    $photoFile = bin2hex(random_bytes(16)) . '.jpg';
    $photoPath = VERIFY_DIR . '/' . $photoFile;
    if (file_put_contents($photoPath, $photo) === false) {
        $reject('บันทึกภาพถ่ายไม่สำเร็จ กรุณาลองใหม่');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        // ทำเครื่องหมายว่าใช้สิทธิ์แล้ว (atomic: ป้องกันการลงคะแนนซ้ำแม้กดพร้อมกัน)
        $st = $pdo->prepare('UPDATE users SET has_voted = 1, voted_at = NOW() WHERE id = ? AND has_voted = 0');
        $st->execute([$user['id']]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            @unlink($photoPath);
            $reject('คุณได้ใช้สิทธิ์ไปแล้ว');
        }
        // หลักฐานการมาใช้สิทธิ์ (ใคร/ที่ไหน) — ไม่มีข้อมูลว่าเลือกใคร
        $pdo->prepare('INSERT INTO vote_verifications (user_id, photo_file, latitude, longitude, accuracy_m, ip, user_agent) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $user['id'], $photoFile, round($lat, 7), round($lng, 7),
                $acc === false ? null : (int)round($acc),
                substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        // บันทึกบัตรแบบไม่ระบุตัวตน (1 แถวต่อ 1 การเลือก, candidate_id = NULL คืองดออกเสียง)
        $ins = $pdo->prepare('INSERT INTO votes (position_id, candidate_id) VALUES (?, ?)');
        foreach ($ballot as [$pid, $cid]) $ins->execute([$pid, $cid]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($photoPath);
        $reject('เกิดข้อผิดพลาด กรุณาลองใหม่');
    }

    log_event('vote_ok', $user, '', 'ลงคะแนนครบ ' . count($positions) . ' ตำแหน่ง');
    $_SESSION['just_voted'] = true;
    unset($_SESSION['terms_ok']);
    redirect('vote.php');
}

$justVoted  = !empty($_SESSION['just_voted']);
unset($_SESSION['just_voted']);

// บันทึกการเปิดหน้า (ครั้งแรกของแต่ละ session ต่อประเภท เพื่อไม่ให้ log ซ้ำทุกครั้งที่รีเฟรช)
if ($user['role'] === 'voter' && !$user['has_voted'] && $status === 'open') {
    $viewEvent = $termsAccepted ? 'ballot_view' : 'terms_view';
    if (empty($_SESSION['logged'][$viewEvent])) {
        $_SESSION['logged'][$viewEvent] = true;
        log_event($viewEvent, $user);
    }
}

$pageTitle = 'ลงคะแนน';
require __DIR__ . '/includes/header.php';
?>

<?php if ($user['has_voted']): ?>
    <div class="card done-card <?= $justVoted ? 'celebrate' : '' ?>">
        <div class="done-icon">✅</div>
        <h1><?= $justVoted ? 'ลงคะแนนสำเร็จ!' : 'คุณใช้สิทธิ์แล้ว' ?></h1>
        <p>ขอบคุณที่ร่วมใช้สิทธิ์เลือกตั้ง</p>
        <p class="muted">ลงคะแนนเมื่อ <?= e(thai_datetime($user['voted_at'])) ?></p>
        <p class="muted small">ระบบไม่บันทึกว่าคุณเลือกผู้สมัครคนใด การลงคะแนนของคุณเป็นความลับ</p>
        <a href="results.php" class="btn btn-primary">ดูผลคะแนน</a>
    </div>

<?php elseif ($user['role'] === 'admin'): ?>
    <div class="card done-card">
        <div class="done-icon">🛡️</div>
        <h1>บัญชีผู้ดูแลระบบ</h1>
        <p>บัญชีผู้ดูแลไม่มีสิทธิ์ลงคะแนน</p>
        <a href="admin/index.php" class="btn btn-primary">ไปที่แผงผู้ดูแล</a>
    </div>

<?php elseif ($status !== 'open'): ?>
    <div class="card done-card">
        <div class="done-icon"><?= $status === 'upcoming' ? '⏳' : '🔒' ?></div>
        <h1><?= $status === 'upcoming' ? 'ยังไม่เปิดให้ลงคะแนน' : 'ปิดการลงคะแนนแล้ว' ?></h1>
        <p class="muted">
            <?= $status === 'upcoming'
                ? 'เปิดลงคะแนน ' . e(thai_datetime(setting('start_at')))
                : 'ปิดหีบเมื่อ ' . e(thai_datetime(setting('end_at'))) ?>
        </p>
        <a href="results.php" class="btn btn-primary">ดูผลคะแนน</a>
    </div>

<?php elseif (!$termsAccepted): ?>
    <!-- ข้อกำหนดและเงื่อนไข: ต้องยอมรับก่อนเข้าสู่บัตรเลือกตั้ง -->
    <section class="card terms-card">
        <p class="eyebrow-text">ก่อนเข้าสู่บัตรเลือกตั้ง</p>
        <h1>ข้อกำหนดและเงื่อนไขการใช้สิทธิ์เลือกตั้ง</h1>
        <p class="muted"><?= e(setting('election_title')) ?></p>
        <ol class="terms-list" tabindex="0" aria-label="ข้อกำหนดและเงื่อนไข">
            <?php foreach (terms_clauses() as $t): ?><li><?= e($t) ?></li><?php endforeach; ?>
        </ol>
        <form method="post" class="terms-accept">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="accept_terms">
            <label class="check consent">
                <input type="checkbox" name="agree" value="1" id="agreeTerms" required>
                <span>ข้าพเจ้า <b><?= e($user['full_name']) ?></b> ได้อ่านและเข้าใจข้อกำหนดและเงื่อนไขข้างต้นทั้งหมดแล้ว และยอมรับที่จะปฏิบัติตาม</span>
            </label>
            <div class="form-actions">
                <a href="index.php" class="btn btn-ghost">ไม่ยอมรับ / กลับหน้าหลัก</a>
                <button class="btn btn-primary" id="agreeBtn" disabled>ยอมรับและเข้าสู่บัตรเลือกตั้ง</button>
            </div>
        </form>
    </section>

<?php else: ?>
    <div class="ballot-head">
        <h1>บัตรเลือกตั้ง</h1>
        <p class="muted">ผู้มีสิทธิ์: <b><?= e($user['full_name']) ?></b> · สมาชิกเลขที่ <?= e($user['member_no'] ?? '-') ?></p>
        <p class="muted">ลงคะแนนให้ครบทุกตำแหน่ง แล้วกด “ลงคะแนน” — ลงคะแนนได้ครั้งเดียว ไม่สามารถแก้ไขได้</p>
    </div>

    <form method="post" id="ballotForm" class="ballot">
        <?= csrf_field() ?>

        <!-- ขั้นที่ 1: ยืนยันตัวตนด้วยกล้อง + GPS -->
        <section class="card verify-card" id="verifyBox" data-geo-max="<?= (int)setting('gps_max_accuracy', '0') ?>">
            <h2 class="card-title">ขั้นที่ 1 · ยืนยันตัวตนผู้มาใช้สิทธิ์</h2>
            <div class="alert alert-danger" id="secureWarn" hidden>
                เบราว์เซอร์อนุญาตให้ใช้กล้องและ GPS เฉพาะเว็บที่เปิดผ่าน <b>HTTPS</b> (หรือ localhost) เท่านั้น — กรุณาติดต่อผู้ดูแลระบบ
            </div>
            <div class="verify-grid">
                <div>
                    <div class="camera-box">
                        <video id="camVideo" playsinline muted></video>
                        <img id="camShot" alt="ภาพถ่ายยืนยันตัวตน" hidden>
                        <div class="camera-placeholder" id="camHint">📷<span>กด “เปิดกล้อง” เพื่อถ่ายภาพใบหน้า</span></div>
                    </div>
                    <div class="row camera-actions">
                        <button type="button" class="btn btn-ghost" id="camStart">📷 เปิดกล้อง</button>
                        <button type="button" class="btn btn-primary" id="camSnap" hidden>ถ่ายภาพ</button>
                        <button type="button" class="btn btn-ghost" id="camRetake" hidden>↺ ถ่ายใหม่</button>
                    </div>
                    <p class="hint" id="camStatus"></p>
                </div>
                <div class="verify-side">
                    <ul class="verify-checklist">
                        <li id="chkPhoto"><i></i>ภาพถ่ายใบหน้า</li>
                        <li id="chkGeo"><i></i>ตำแหน่ง GPS <small id="geoText" class="muted"></small></li>
                    </ul>
                    <button type="button" class="btn btn-ghost btn-sm" id="geoRetry">📍 ค้นหาตำแหน่งใหม่</button>
                    <p class="muted small geo-tip">💡 ตำแหน่งจะแม่นที่สุดเมื่อใช้มือถือที่เปิด GPS และอยู่ใกล้หน้าต่างหรือที่โล่ง
                        คอมพิวเตอร์/โน้ตบุ๊กไม่มีชิป GPS จึงอาจคลาดเคลื่อนหลายร้อยเมตร</p>
                    <label class="check consent">
                        <input type="checkbox" name="consent" value="1" id="consent">
                        <span>ข้าพเจ้ายินยอมให้สหกรณ์เก็บภาพถ่ายใบหน้าและตำแหน่งที่ตั้ง ณ เวลาลงคะแนน
                        เพื่อใช้ยืนยันตัวตนและตรวจสอบการใช้สิทธิ์เท่านั้น
                        <b>ข้อมูลนี้ไม่ผูกกับหมายเลขที่ท่านเลือก</b></span>
                    </label>
                </div>
            </div>
            <input type="hidden" name="photo" id="photoInput">
            <input type="hidden" name="lat" id="latInput">
            <input type="hidden" name="lng" id="lngInput">
            <input type="hidden" name="accuracy" id="accInput">
        </section>

        <h2 class="section-title">ขั้นที่ 2 · ลงคะแนนเลือกตั้ง</h2>
        <?php if (!$positions): ?><p class="muted">ยังไม่มีรายชื่อผู้สมัคร</p><?php endif; ?>
        <?php foreach ($positions as $i => $p): $pid = (int)$p['id']; ?>
            <section class="ballot-position" data-position="<?= $pid ?>" data-max="<?= (int)$p['max_choices'] ?>" data-title="<?= e($p['title']) ?>">
                <header class="ballot-position-head">
                    <div>
                        <span class="position-no">ตำแหน่งที่ <?= $i + 1 ?></span>
                        <h3><?= e($p['title']) ?></h3>
                        <p class="muted small"><?= e(position_rule($p)) ?> หรือเลือก “งดออกเสียง”</p>
                    </div>
                    <span class="pick-count" aria-live="polite">เลือกแล้ว <b data-count>0</b> / <?= (int)$p['max_choices'] ?></span>
                </header>
                <div class="grid candidates">
                    <?php foreach ($p['candidates'] as $c): ?>
                        <label class="ballot-option" style="--accent: <?= e($c['color']) ?>">
                            <input type="checkbox" name="choice[<?= $pid ?>][]" value="<?= (int)$c['id'] ?>"
                                   data-label="หมายเลข <?= (int)$c['number'] ?> <?= e($c['name']) ?>">
                            <div class="cand-number"><?= (int)$c['number'] ?></div>
                            <div class="avatar">
                                <?php if ($c['photo_url']): ?>
                                    <img src="<?= e($c['photo_url']) ?>" alt="">
                                <?php else: ?>
                                    <?= e(initials($c['name'])) ?>
                                <?php endif; ?>
                            </div>
                            <h3><?= e($c['name']) ?></h3>
                            <p class="party"><?= e($c['party']) ?></p>
                            <span class="mark">✗</span>
                        </label>
                    <?php endforeach; ?>
                    <label class="ballot-option abstain" style="--accent: #64748b">
                        <input type="checkbox" name="abstain[<?= $pid ?>]" value="1" data-abstain data-label="งดออกเสียง">
                        <div class="avatar">—</div>
                        <h3>งดออกเสียง</h3>
                        <p class="party">ไม่ประสงค์ลงคะแนนในตำแหน่งนี้</p>
                        <span class="mark">✗</span>
                    </label>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="ballot-actions">
            <button type="submit" class="btn btn-primary btn-lg" id="voteBtn" disabled>ลงคะแนน</button>
        </div>
        <p class="center small muted" id="voteNeed"></p>
    </form>

    <!-- Modal ยืนยัน -->
    <div class="modal" id="confirmModal" hidden>
        <div class="modal-box">
            <h2>ยืนยันการลงคะแนน</h2>
            <p>โปรดตรวจสอบรายการที่ท่านเลือก</p>
            <dl class="confirm-summary" id="confirmChoice"></dl>
            <p class="muted small">ภาพถ่ายและตำแหน่งของท่านจะถูกบันทึกเป็นหลักฐานการมาใช้สิทธิ์</p>
            <p class="muted small">เมื่อยืนยันแล้วจะไม่สามารถเปลี่ยนแปลงได้</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>ย้อนกลับ</button>
                <button type="button" class="btn btn-primary" id="confirmBtn">ยืนยัน</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
