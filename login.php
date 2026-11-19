<?php
/**
 * เข้าสู่ระบบ
 * - สมาชิก (ผู้มีสิทธิ์): เลขสมาชิก + เลขบัตรประชาชน + วันเดือนปีเกิด
 * - ผู้ดูแลระบบ: login.php?admin=1 ชื่อผู้ใช้ + รหัสผ่าน
 * ทุกครั้งที่พยายามเข้าสู่ระบบจะถูกบันทึกใน access_logs
 */
require __DIR__ . '/config.php';

if (current_user()) redirect('index.php');

$adminMode = isset($_GET['admin']);
$error = '';
$memberNo = '';
$citizenId = '';
$dobText = '';   // วันเกิดตามที่ผู้ใช้กรอก (พ.ศ.) ใช้เติมกลับเมื่อเกิดข้อผิดพลาด
$username = '';

/** ข้อความผิดพลาดเดียวกันทุกกรณี เพื่อไม่บอกใบ้ว่าช่องไหนผิด */
const LOGIN_FAIL_MSG = 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบเลขสมาชิก เลขบัตรประชาชน และวันเดือนปีเกิด';
const LOCKED_MSG     = 'กรอกข้อมูลผิดหลายครั้ง ระบบระงับการเข้าสู่ระบบชั่วคราว กรุณารอ 15 นาทีแล้วลองใหม่ หรือติดต่อเจ้าหน้าที่สหกรณ์';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ($adminMode) {
        // ----- ผู้ดูแลระบบ -----
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (login_locked('admin:' . $username)) {
            log_event('login_locked', null, 'admin:' . $username);
            $error = LOCKED_MSG;
        } else {
            $st = db()->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
            $st->execute([$username]);
            $u = $st->fetch();
            if ($u && password_verify($password, $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                log_event('admin_login', $u, 'admin:' . $username);
                flash('เข้าสู่ระบบผู้ดูแลสำเร็จ');
                redirect('admin/index.php');
            }
            log_event('login_fail', null, 'admin:' . $username, 'ผู้ดูแล: ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        // ----- สมาชิก: เลขสมาชิก + เลขบัตรประชาชน + วันเกิด -----
        $memberNo  = clean_member_no($_POST['member_no'] ?? '');
        $citizenId = preg_replace('/\D/', '', (string)($_POST['citizen_id'] ?? '')) ?? '';
        $dobText   = mb_substr(trim((string)($_POST['dob'] ?? '')), 0, 20);
        // กรอกเป็น พ.ศ. → แปลงเป็น ค.ศ. (Y-m-d) เพื่อเทียบกับ birth_date ในฐานข้อมูล
        [$dob, $dobError] = dob_from_be_text($dobText);

        // ข้อผิดพลาดด้านรูปแบบบอกได้ตรง ๆ (ไม่ได้เทียบกับข้อมูลในระบบ จึงไม่เปิดเผยอะไร)
        if ($memberNo === '' || strlen($citizenId) !== 13) {
            $error = 'กรุณากรอกเลขสมาชิก และเลขบัตรประชาชน 13 หลักให้ครบถ้วน';
        } elseif (!$dob) {
            $error = $dobError;
        } elseif (login_locked($memberNo)) {
            log_event('login_locked', null, $memberNo);
            $error = LOCKED_MSG;
        } else {
            $st = db()->prepare("SELECT * FROM users WHERE member_no = ? AND role = 'voter'");
            $st->execute([$memberNo]);
            $u = $st->fetch();
            // เทียบทุกช่องเสมอ (hash_equals) เพื่อไม่ให้เวลาตอบสนองบอกใบ้ว่าช่องไหนผิด
            $ok = $u !== false
                && hash_equals((string)$u['username'], $citizenId)
                && hash_equals((string)$u['birth_date'], $dob);
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                log_event('login_ok', $u);
                flash('เข้าสู่ระบบสำเร็จ');
                redirect('vote.php');
            }
            // detail ใช้ตรวจสอบภายในเท่านั้น ไม่แสดงต่อผู้ใช้
            $why = !$u ? 'ไม่พบเลขสมาชิก' : (!hash_equals((string)$u['username'], $citizenId) ? 'เลขบัตรประชาชนไม่ตรง' : 'วันเกิดไม่ตรง');
            log_event('login_fail', $u ?: null, $memberNo, $why);
            $error = LOGIN_FAIL_MSG;
        }
    }
}

$pageTitle = $adminMode ? 'เข้าสู่ระบบผู้ดูแล' : 'เข้าสู่ระบบ';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <div class="card auth-card">
        <nav class="auth-tabs" aria-label="ประเภทผู้ใช้">
            <a href="login.php" class="<?= $adminMode ? '' : 'active' ?>"<?= $adminMode ? '' : ' aria-current="page"' ?>>สมาชิกผู้มีสิทธิ์</a>
            <a href="login.php?admin=1" class="<?= $adminMode ? 'active' : '' ?>"<?= $adminMode ? ' aria-current="page"' : '' ?>>ผู้ดูแลระบบ</a>
        </nav>
        <?php if ($adminMode): ?>
            <h1 class="card-title center">เข้าสู่ระบบผู้ดูแลระบบ</h1>
            <p class="muted center small">สำหรับเจ้าหน้าที่ผู้ดูแลการเลือกตั้งเท่านั้น</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="field"><span>ชื่อผู้ใช้</span>
                    <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus></label>
                <label class="field"><span>รหัสผ่าน</span>
                    <div class="pw-wrap">
                        <input type="password" name="password" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" aria-label="แสดงรหัสผ่าน">👁</button>
                    </div></label>
                <button class="btn btn-primary btn-block btn-lg">เข้าสู่ระบบ</button>
            </form>
        <?php else: ?>
            <p class="eyebrow-text center">สำหรับสมาชิกผู้มีสิทธิ์เลือกตั้ง</p>
            <h1 class="card-title center">ยืนยันตัวตนเพื่อใช้สิทธิ์</h1>
            <p class="muted center small">กรอกข้อมูลให้ตรงกับทะเบียนสมาชิกของสหกรณ์</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form" novalidate>
                <?= csrf_field() ?>
                <label class="field"><span>เลขสมาชิก</span>
                    <input type="text" name="member_no" value="<?= e($memberNo) ?>" inputmode="numeric" maxlength="20"
                           placeholder="เช่น 000101" autocomplete="off" required autofocus></label>
                <label class="field"><span>เลขบัตรประจำตัวประชาชน</span>
                    <input type="text" name="citizen_id" id="citizenId" value="<?= e($citizenId) ?>" inputmode="numeric" maxlength="17"
                           placeholder="x-xxxx-xxxxx-xx-x" autocomplete="off" required>
                    <small class="hint" id="idHint"></small></label>
                <label class="field"><span>วันเดือนปีเกิด <small class="muted">(ปี พ.ศ.)</small></span>
                    <input type="text" name="dob" id="dobInput" value="<?= e($dobText) ?>" data-dob-be
                           inputmode="numeric" maxlength="10" placeholder="วว/ดด/ปปปป เช่น 15/03/2528"
                           autocomplete="off" aria-describedby="dobHint" required>
                    <small class="hint" id="dobHint" aria-live="polite"></small></label>
                <button class="btn btn-primary btn-block btn-lg">เข้าสู่ระบบ</button>
            </form>
            <p class="muted small center auth-note">ทุกครั้งที่เข้าสู่ระบบ ระบบจะบันทึกเวลา หมายเลข IP และอุปกรณ์ที่ใช้
                หากกรอกข้อมูลผิด 5 ครั้ง ระบบจะระงับการเข้าสู่ระบบชั่วคราว 15 นาที</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
