<?php
require dirname(__DIR__) . '/config.php';

$admin = require_admin();
$base  = '../';
$tab   = strtolower(trim((string)($_GET['tab'] ?? 'dashboard')));
$pdo   = db();

// แท็บที่ถูกต้อง: ชื่อที่สะกดใกล้เคียง (เช่น voter, candidate) พาไปแท็บที่ถูก ชื่อที่ไม่รู้จักกลับไปหน้าภาพรวม
const ADMIN_TABS = ['dashboard', 'positions', 'candidates', 'voters', 'verifications', 'logs', 'settings', 'export'];
if (!in_array($tab, ADMIN_TABS, true)) {
    $fix = $tab . 's';
    $query = $_GET;
    $query['tab'] = in_array($fix, ADMIN_TABS, true) ? $fix : 'dashboard';
    redirect('index.php?' . http_build_query($query));
}

// ================= ACTIONS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_settings':
            $new = [
                'election_title'   => trim($_POST['election_title'] ?? ''),
                'election_desc'    => trim($_POST['election_desc'] ?? ''),
                'election_mode'    => in_array($_POST['election_mode'] ?? '', ['auto', 'open', 'closed'], true) ? $_POST['election_mode'] : 'auto',
                'start_at'         => date('Y-m-d H:i:s', strtotime($_POST['start_at'] ?? 'now')),
                'end_at'           => date('Y-m-d H:i:s', strtotime($_POST['end_at'] ?? '+1 day')),
                'gps_max_accuracy' => (string)max(0, min(100000, (int)($_POST['gps_max_accuracy'] ?? 0))),
                // แถบคำขวัญ: บรรทัดละข้อความ ไม่เกิน 10 บรรทัด บรรทัดละ 200 ตัวอักษร (ว่าง = ซ่อนแถบ)
                'slogan_text'      => implode("\n", array_slice(array_map(fn($l) => mb_substr(trim(preg_replace('/\s+/u', ' ', $l)), 0, 200),
                                        array_filter(preg_split('/\R/u', (string)($_POST['slogan_text'] ?? '')), fn($l) => trim($l) !== '')), 0, 10)),
                'slogan_mode'      => ($_POST['slogan_mode'] ?? '') === 'static' ? 'static' : 'scroll',
                'slogan_speed'     => array_key_exists($_POST['slogan_speed'] ?? '', SLOGAN_SPEEDS) ? $_POST['slogan_speed'] : 'normal',
            ];
            // ข้อกำหนด: เทียบแบบรายข้อที่ตัดช่องว่างแล้ว ถ้าตรงกับค่าเริ่มต้นเก็บเป็นค่าว่าง (ระบบจะใช้ค่าเริ่มต้นล่าสุดเสมอ)
            $norm  = fn(string $t) => implode("\n", array_filter(array_map('trim', preg_split('/\R/u', $t)), 'strlen'));
            $terms = $norm((string)($_POST['voting_terms'] ?? ''));
            $new['voting_terms'] = $terms === $norm(DEFAULT_TERMS) ? '' : $terms;
            // บันทึกเฉพาะค่าที่เปลี่ยน (กำหนดเวลา/โหมด มีผลต่อการเปิดเผยผลคะแนน จึงต้องตรวจสอบย้อนหลังได้)
            $changes = [];
            foreach ($new as $k => $v) {
                $old = (string)setting($k, '');
                if ($old === $v) continue;
                save_setting($k, $v);
                $changes[] = in_array($k, ['election_mode', 'start_at', 'end_at', 'gps_max_accuracy'], true) ? "$k: $old → $v" : $k;
            }
            if ($changes) log_event('admin_setting', $admin, 'admin:' . $admin['username'], implode('; ', $changes));
            flash($changes ? 'บันทึกการตั้งค่าแล้ว' : 'ไม่มีการเปลี่ยนแปลง', $changes ? 'success' : 'info');
            redirect('index.php?tab=settings');

        case 'save_position':
            $id    = (int)($_POST['id'] ?? 0);
            $title = clean_name($_POST['title'] ?? '');
            $seats = (int)($_POST['seats'] ?? 0);
            $max   = (int)($_POST['max_choices'] ?? 0);
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $back  = 'index.php?tab=positions' . ($id ? '&edit=' . $id : '');
            if ($title === '' || $seats < 1 || $seats > 99) {
                flash('กรุณาระบุชื่อตำแหน่ง และจำนวนที่นั่ง 1–99', 'danger');
                redirect($back);
            }
            if ($max < 1 || $max > $seats) {
                flash('จำนวนที่เลือกได้ต้องอยู่ระหว่าง 1 ถึงจำนวนที่นั่ง (' . $seats . ')', 'danger');
                redirect($back);
            }
            if (ballot_locked()) {
                // หลังเปิดลงคะแนน: แก้ได้เฉพาะชื่อตำแหน่งและลำดับการแสดงผล
                $st = $pdo->prepare('SELECT seats, max_choices FROM positions WHERE id = ?');
                $st->execute([$id]);
                $cur = $st->fetch();
                if (!$cur || (int)$cur['seats'] !== $seats || (int)$cur['max_choices'] !== $max) {
                    flash(BALLOT_LOCKED_MSG, 'danger');
                    redirect($back);
                }
            }
            if ($id) {
                $pdo->prepare('UPDATE positions SET title=?, seats=?, max_choices=?, sort_order=? WHERE id=?')->execute([$title, $seats, $max, $sort, $id]);
                flash('แก้ไขตำแหน่งแล้ว');
            } else {
                $pdo->prepare('INSERT INTO positions (title, seats, max_choices, sort_order) VALUES (?,?,?,?)')->execute([$title, $seats, $max, $sort]);
                flash('เพิ่มตำแหน่งแล้ว');
            }
            redirect('index.php?tab=positions');

        case 'delete_position':
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE position_id = ?');
            $st->execute([$id]);
            if (ballot_locked()) {
                flash(BALLOT_LOCKED_MSG, 'danger');
            } elseif ($st->fetchColumn() > 0) {
                flash('ลบไม่ได้: ตำแหน่งนี้ยังมีผู้สมัครอยู่', 'danger');
            } else {
                $pdo->prepare('DELETE FROM positions WHERE id = ?')->execute([$id]);
                flash('ลบตำแหน่งแล้ว');
            }
            redirect('index.php?tab=positions');

        case 'save_candidate':
            $id     = (int)($_POST['id'] ?? 0);
            $number = (int)($_POST['number'] ?? 0);
            $posId  = (int)($_POST['position_id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE id = ?');
            $st->execute([$posId]);
            if (!$st->fetchColumn()) {
                flash('กรุณาเลือกตำแหน่งที่สมัคร', 'danger');
                redirect('index.php?tab=candidates' . ($id ? '&edit=' . $id : ''));
            }
            if ($id || ballot_locked()) {
                // หลังเปิดลงคะแนน: ห้ามเพิ่มผู้สมัคร ย้ายตำแหน่ง หรือเปลี่ยนหมายเลข (แก้ไขได้เฉพาะข้อมูลประกอบ)
                $st = $pdo->prepare('SELECT position_id, number FROM candidates WHERE id = ?');
                $st->execute([$id]);
                $cur = $st->fetch();
                $structural = !$id || !$cur || (int)$cur['position_id'] !== $posId || (int)$cur['number'] !== $number;
                if ($structural && ballot_locked()) {
                    flash(BALLOT_LOCKED_MSG, 'danger');
                    redirect('index.php?tab=candidates' . ($id ? '&edit=' . $id : ''));
                }
            }
            $first  = clean_name($_POST['first_name'] ?? '');
            $last   = clean_name($_POST['last_name'] ?? '');
            $color  = strtoupper(trim((string)($_POST['color'] ?? '')));
            if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
            if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
                flash('รหัสสีไม่ถูกต้อง กรุณาระบุเป็น HEX 6 หลัก เช่น #9400D4', 'danger');
                redirect('index.php?tab=candidates' . ($id ? '&edit=' . $id : ''));
            }
            $photo  = trim($_POST['photo_url'] ?? '');
            if ($photo !== '' && !preg_match('#^(https?://|/|[\w.-]+/)#i', $photo)) $photo = '';
            if ($number < 1 || $first === '' || $last === '') {
                flash('กรุณากรอกหมายเลข ชื่อ และนามสกุลของผู้สมัคร', 'danger');
                redirect('index.php?tab=candidates' . ($id ? '&edit=' . $id : ''));
            }
            $data = [$posId, $number, $first, $last, trim($_POST['party'] ?? ''), trim($_POST['slogan'] ?? ''), trim($_POST['policy'] ?? ''), $color, $photo];
            try {
                if ($id) {
                    $pdo->prepare('UPDATE candidates SET position_id=?, number=?, first_name=?, last_name=?, party=?, slogan=?, policy=?, color=?, photo_url=? WHERE id=?')
                        ->execute([...$data, $id]);
                    flash('แก้ไขข้อมูลผู้สมัครแล้ว');
                } else {
                    $pdo->prepare('INSERT INTO candidates (position_id, number, first_name, last_name, party, slogan, policy, color, photo_url) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute($data);
                    flash('เพิ่มผู้สมัครแล้ว');
                }
            } catch (PDOException $ex) {
                flash($ex->getCode() === '23000' ? 'หมายเลขนี้ถูกใช้แล้วในตำแหน่งเดียวกัน' : 'บันทึกไม่สำเร็จ', 'danger');
                redirect('index.php?tab=candidates' . ($id ? '&edit=' . $id : '&pos=' . $posId));
            }
            redirect('index.php?tab=candidates&pos=' . $posId);

        case 'delete_candidate':
            $id = (int)($_POST['id'] ?? 0);
            // ไม่ตรวจคะแนนรายคน เพื่อไม่ให้ข้อความแจ้งเตือนบอกใบ้ว่าผู้สมัครคนใดมีคะแนน
            if (ballot_locked()) {
                flash(BALLOT_LOCKED_MSG, 'danger');
            } else {
                $pdo->prepare('DELETE FROM candidates WHERE id = ?')->execute([$id]);
                flash('ลบผู้สมัครแล้ว');
            }
            redirect('index.php?tab=candidates');

        case 'save_voter':
            $id       = (int)($_POST['id'] ?? 0);
            $memberNo = clean_member_no($_POST['member_no'] ?? '');
            $uid      = preg_replace('/\D/', '', (string)($_POST['username'] ?? '')) ?? '';
            $dob      = dob_from_parts($_POST['dob_d'] ?? 0, $_POST['dob_m'] ?? 0, $_POST['dob_y'] ?? 0);
            $first    = clean_name($_POST['first_name'] ?? '');
            $last     = clean_name($_POST['last_name'] ?? '');
            $back     = 'index.php?tab=voters' . ($id ? '&edit=' . $id : '');
            $errs = [];
            if ($memberNo === '' || strlen($memberNo) > 20) $errs[] = 'เลขสมาชิก (ตัวเลข 1–20 หลัก)';
            if (!valid_thai_id($uid)) $errs[] = 'เลขบัตรประชาชน 13 หลักที่ถูกต้อง';
            if (!$dob) $errs[] = 'วันเดือนปีเกิด';
            if ($first === '' || $last === '') $errs[] = 'ชื่อและนามสกุล';
            if ($errs) {
                flash('กรุณาตรวจสอบ: ' . implode(', ', $errs), 'danger');
                redirect($back);
            }
            try {
                if ($id) {
                    $st = $pdo->prepare("UPDATE users SET member_no=?, username=?, birth_date=?, first_name=?, last_name=? WHERE id=? AND role='voter'");
                    $st->execute([$memberNo, $uid, $dob, $first, $last, $id]);
                    flash('แก้ไขข้อมูลผู้มีสิทธิ์แล้ว');
                } else {
                    $pdo->prepare('INSERT INTO users (member_no, username, birth_date, first_name, last_name, password_hash) VALUES (?,?,?,?,?,?)')
                        ->execute([$memberNo, $uid, $dob, $first, $last, unusable_password_hash()]);
                    flash('เพิ่มผู้มีสิทธิ์แล้ว');
                }
            } catch (PDOException $ex) {
                flash($ex->getCode() === '23000' ? 'เลขสมาชิกหรือเลขบัตรประชาชนนี้มีอยู่แล้ว' : 'บันทึกไม่สำเร็จ', 'danger');
                redirect($back);
            }
            redirect('index.php?tab=voters');

        case 'delete_voter':
            $st = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'voter' AND has_voted = 0");
            $st->execute([(int)($_POST['id'] ?? 0)]);
            flash($st->rowCount() ? 'ลบผู้มีสิทธิ์แล้ว' : 'ลบไม่ได้ (ผู้นี้ใช้สิทธิ์แล้ว)', $st->rowCount() ? 'success' : 'danger');
            redirect('index.php?tab=voters');

        case 'change_password':
            if (strlen($_POST['new_password'] ?? '') < 6) {
                flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัว', 'danger');
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $admin['id']]);
                flash('เปลี่ยนรหัสผ่านแล้ว');
            }
            redirect('index.php?tab=settings');
    }
}

// ================= EXPORT CSV =================
if ($tab === 'export') {
    $r = get_results();
    if (!$r['visible']) {
        flash('ส่งออกผลคะแนนได้หลังปิดหีบเท่านั้น', 'warning');
        redirect('index.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="election-results-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยได้
    $resLabel = ['elected' => $r['status'] === 'closed' ? 'ได้รับเลือก' : 'อยู่ในเกณฑ์ได้รับเลือก', 'tie' => 'คะแนนเท่ากัน', '' => ''];
    fputcsv($out, ['ตำแหน่ง', 'อันดับ', 'หมายเลข', 'ชื่อ', 'นามสกุล', 'สังกัด / หน่วยงาน', 'คะแนน', 'ร้อยละ', 'ผล']);
    foreach ($r['positions'] as $p) {
        foreach ($p['candidates'] as $i => $c) {
            fputcsv($out, [$p['title'], $i + 1, $c['number'], $c['first_name'], $c['last_name'], $c['party'], $c['votes'], $c['percent'], $resLabel[$c['result']]]);
        }
        fputcsv($out, [$p['title'], '', '', 'งดออกเสียง', '', '', $p['abstain'], '', '']);
    }
    fputcsv($out, []);
    fputcsv($out, ['ผู้มีสิทธิ์', $r['voters']]);
    fputcsv($out, ['มาใช้สิทธิ์', $r['total']]);
    fputcsv($out, ['อัตราการใช้สิทธิ์ (%)', $r['turnout']]);
    exit;
}

// ================= ACCESS LOG (ค้นหา + ส่งออก) =================
/** สร้างเงื่อนไขค้นหา log จาก query string: q (เลขสมาชิก/ชื่อ/IP), event, uid, date (Y-m-d) */
function log_filter(): array
{
    $where = ['1'];
    $params = [];
    if (($uid = (int)($_GET['uid'] ?? 0)) > 0) {
        $where[] = '(l.user_id = ? OR (l.member_no <> \'\' AND l.member_no = (SELECT member_no FROM users WHERE id = ?)))';
        array_push($params, $uid, $uid);
    }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
        $where[] = '(l.member_no LIKE ? OR u.full_name LIKE ? OR l.ip LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    if (isset(ACCESS_EVENTS[$_GET['event'] ?? ''])) {
        $where[] = 'l.event = ?';
        $params[] = $_GET['event'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? ''))) {
        $where[] = 'l.created_at >= ? AND l.created_at < ? + INTERVAL 1 DAY';
        array_push($params, $_GET['date'], $_GET['date']);
    }
    return [implode(' AND ', $where), $params];
}

if ($tab === 'logs' && isset($_GET['export'])) {
    [$w, $params] = log_filter();
    $st = $pdo->prepare("SELECT l.*, u.full_name FROM access_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT 100000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="access-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['เวลา', 'เลขสมาชิก', 'ชื่อ - นามสกุล', 'เหตุการณ์', 'รายละเอียด', 'IP', 'อุปกรณ์']);
    while ($l = $st->fetch()) {
        fputcsv($out, [$l['created_at'], $l['member_no'], $l['full_name'] ?? '', ACCESS_EVENTS[$l['event']] ?? $l['event'], $l['detail'], $l['ip'], $l['user_agent']]);
    }
    exit;
}

// ================= DATA =================
$r = get_results();
$pageTitle = 'ผู้ดูแลระบบ';
require dirname(__DIR__) . '/includes/header.php';

$tabs = ['dashboard' => 'ภาพรวม', 'positions' => 'ตำแหน่ง', 'candidates' => 'ผู้สมัคร', 'voters' => 'ผู้มีสิทธิ์',
         'verifications' => 'หลักฐานการใช้สิทธิ์', 'logs' => 'บันทึกการเข้าใช้', 'settings' => 'ตั้งค่า'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow-text">ระบบบริหารจัดการการเลือกตั้ง</p>
        <h1><?= e(setting('election_title')) ?></h1>
    </div>
    <?php if ($r['visible']): ?>
        <a href="index.php?tab=export" class="btn btn-ghost">ส่งออกผลคะแนน (CSV)</a>
    <?php else: ?>
        <span class="tag" title="ผลคะแนนเปิดเผยได้หลังปิดหีบเท่านั้น">🔒 ผลคะแนนปิดผนึกจนกว่าจะปิดหีบ</span>
    <?php endif; ?>
</div>

<nav class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'dashboard'): ?>
    <div class="grid stats-grid">
        <div class="stat-card"><span>ผู้มีสิทธิ์</span><b><?= number_format($r['voters']) ?></b></div>
        <div class="stat-card"><span>มาใช้สิทธิ์</span><b><?= number_format($r['total']) ?></b></div>
        <div class="stat-card"><span>อัตราการใช้สิทธิ์</span><b><?= $r['turnout'] ?>%</b>
            <div class="meter"><i style="width: <?= min(100, $r['turnout']) ?>%"></i></div></div>
        <div class="stat-card"><span>สถานะ</span><b class="status-text status-<?= $r['status'] ?>"><?= ['open' => 'เปิดลงคะแนน', 'closed' => 'ปิดหีบ', 'upcoming' => 'ยังไม่เปิด'][$r['status']] ?></b></div>
    </div>
    <div class="results-layout">
        <div>
            <?php if (!$r['visible']): ?>
            <div class="card sealed-card">
                <div class="sealed-icon">🔒</div>
                <h2>ผลคะแนนปิดผนึก</h2>
                <p>ระหว่างการเลือกตั้งไม่มีผู้ใดดูคะแนนได้ รวมถึงผู้ดูแลระบบ</p>
                <p class="muted small">ผลคะแนนจะเปิดเผยเมื่อปิดหีบ <?= e(thai_datetime(setting('end_at'))) ?>
                    · ระหว่างนี้ดูได้เฉพาะจำนวนผู้มาใช้สิทธิ์</p>
                <ul class="sealed-positions">
                    <?php foreach ($r['positions'] as $p): ?>
                        <li><b><?= e($p['title']) ?></b> <span class="muted small"><?= e($p['rule']) ?> · ผู้สมัคร <?= count($p['candidates']) ?> ราย</span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php foreach ($r['visible'] ? $r['positions'] : [] as $p): ?>
            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">คะแนนล่าสุด · <?= e($p['title']) ?></h2>
                    <span class="tag"><?= e($p['rule']) ?> · งดออกเสียง <?= number_format($p['abstain']) ?></span>
                </div>
                <div class="result-list">
                    <?php foreach ($p['candidates'] as $i => $c): ?>
                        <div class="result-row <?= $c['result'] ? 'is-' . $c['result'] : '' ?>" style="--accent: <?= e($c['color']) ?>">
                            <div class="result-info">
                                <span class="rank"><?= $i + 1 ?></span>
                                <span class="mini-avatar"><?= e($c['initials']) ?></span>
                                <div><b>หมายเลข <?= (int)$c['number'] ?> · <?= e($c['name']) ?></b>
                                    <small><?= e($c['party']) ?><?php if ($c['result']): ?> · <em class="result-flag"><?= $c['result'] === 'tie' ? 'คะแนนเท่ากัน' : 'อยู่ในเกณฑ์ได้รับเลือก' ?></em><?php endif; ?></small></div>
                                <div class="result-num"><b><?= number_format($c['votes']) ?></b><small><?= $c['percent'] ?>%</small></div>
                            </div>
                            <div class="bar"><i style="width: <?= min(100, $c['percent']) ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$p['candidates']): ?><p class="muted">ยังไม่มีผู้สมัคร</p><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h2 class="card-title">การใช้สิทธิ์ล่าสุด</h2>
            <?php
            $recent = $pdo->query("SELECT full_name, username, voted_at FROM users WHERE has_voted = 1 ORDER BY voted_at DESC LIMIT 8")->fetchAll();
            ?>
            <ul class="activity">
                <?php foreach ($recent as $v): ?>
                    <li><b><?= e($v['full_name']) ?></b><small><?= e(mask_id($v['username'])) ?> · <?= e(thai_datetime($v['voted_at'])) ?></small></li>
                <?php endforeach; ?>
                <?php if (!$recent): ?><li class="muted">ยังไม่มีผู้ใช้สิทธิ์</li><?php endif; ?>
            </ul>
        </div>
    </div>

<?php elseif ($tab === 'positions'):
    $edit = null;
    if (!empty($_GET['edit'])) {
        $st = $pdo->prepare('SELECT * FROM positions WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
    $plist = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM candidates c WHERE c.position_id = p.id) AS cand_count
                          FROM positions p ORDER BY p.sort_order, p.id')->fetchAll();
    $nextSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM positions')->fetchColumn();
?>
    <div class="admin-layout admin-layout--list">
        <section class="card registry">
            <div class="registry-head">
                <div>
                    <h2>ตำแหน่งที่เปิดรับเลือกตั้ง</h2>
                    <p class="muted small">ลำดับในตารางคือลำดับที่แสดงในบัตรเลือกตั้ง</p>
                </div>
                <?php if ($edit): ?><a href="?tab=positions" class="btn btn-primary btn-sm">+ เพิ่มตำแหน่งใหม่</a><?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table registry-table">
                    <thead><tr>
                        <th class="col-no">ลำดับ</th><th>ตำแหน่ง</th><th class="num">ที่นั่ง</th><th class="num">เลือกได้ไม่เกิน</th><th class="num">ผู้สมัคร</th>
                        <th class="col-actions"><span class="sr-only">จัดการ</span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($plist as $p): ?>
                        <tr class="<?= $edit && (int)$edit['id'] === (int)$p['id'] ? 'is-editing' : '' ?>">
                            <td class="col-no"><span class="reg-no"><?= (int)$p['sort_order'] ?></span></td>
                            <td><b><?= e($p['title']) ?></b></td>
                            <td class="num"><?= (int)$p['seats'] ?></td>
                            <td class="num"><?= (int)$p['max_choices'] ?></td>
                            <td class="num"><a href="?tab=candidates&pos=<?= (int)$p['id'] ?>"><?= (int)$p['cand_count'] ?> ราย</a></td>
                            <td class="col-actions"><div class="actions">
                                <a href="?tab=positions&edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-ghost">แก้ไข</a>
                                <?php if (!$p['cand_count'] && !ballot_locked()): ?>
                                <form method="post" data-confirm="ยืนยันการลบตำแหน่ง <?= e($p['title']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_position">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                </form>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$plist): ?><tr><td colspan="6" class="muted center">ยังไม่มีตำแหน่ง</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small registry-note"><?= ballot_locked() ? '🔒 ' . e(BALLOT_LOCKED_MSG) : 'ลบได้เฉพาะตำแหน่งที่ยังไม่มีผู้สมัคร' ?></p>
        </section>

        <section class="card form-card">
            <h2 class="card-title"><?= $edit ? 'แก้ไขตำแหน่ง' : 'เพิ่มตำแหน่ง' ?></h2>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_position">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <label class="field"><span>ชื่อตำแหน่ง <em class="req">*</em></span>
                    <input type="text" name="title" maxlength="100" value="<?= e($edit['title'] ?? '') ?>" placeholder="เช่น กรรมการดำเนินการ" required></label>
                <div class="row">
                    <label class="field"><span>จำนวนที่นั่ง <em class="req">*</em></span>
                        <input type="number" name="seats" min="1" max="99" value="<?= (int)($edit['seats'] ?? 1) ?>" required></label>
                    <label class="field"><span>เลือกได้ไม่เกิน <em class="req">*</em></span>
                        <input type="number" name="max_choices" min="1" max="99" value="<?= (int)($edit['max_choices'] ?? 1) ?>" required></label>
                </div>
                <small class="muted">จำนวนที่นั่ง = จำนวนผู้ได้รับเลือก · เลือกได้ไม่เกิน = จำนวนหมายเลขสูงสุดที่ผู้มีสิทธิ์เลือกได้ในตำแหน่งนี้ (ไม่เกินจำนวนที่นั่ง)</small>
                <label class="field field-no"><span>ลำดับในบัตรเลือกตั้ง</span>
                    <input type="number" name="sort_order" min="0" value="<?= (int)($edit['sort_order'] ?? $nextSort) ?>"></label>
                <div class="form-actions">
                    <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มตำแหน่ง' ?></button>
                    <?php if ($edit): ?><a href="?tab=positions" class="btn btn-ghost">ยกเลิก</a><?php endif; ?>
                </div>
            </form>
        </section>
    </div>

<?php elseif ($tab === 'candidates'):
    $edit = null;
    if (!empty($_GET['edit'])) {
        $st = $pdo->prepare('SELECT * FROM candidates WHERE id = ?');
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
    $allPositions = positions_with_candidates();
    $posIds  = array_map(fn($p) => (int)$p['id'], $allPositions);
    $formPos = (int)($edit['position_id'] ?? ($_GET['pos'] ?? ($posIds[0] ?? 0)));
    if (!in_array($formPos, $posIds, true)) $formPos = $posIds[0] ?? 0;
    $st = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM candidates WHERE position_id = ?');
    $st->execute([$formPos]);
    $nextNo  = (int)$st->fetchColumn();
    $votesBy = array_column($r['candidates'], 'votes', 'id');
    $pctBy   = array_column($r['candidates'], 'percent', 'id');
    $total   = array_sum(array_map(fn($p) => count($p['candidates']), $allPositions));
?>
    <div class="admin-layout admin-layout--list">
        <section class="card registry">
            <div class="registry-head">
                <div>
                    <h2>ทะเบียนผู้สมัครรับเลือกตั้ง</h2>
                    <p class="muted small">จำนวนผู้สมัคร <?= number_format($total) ?> ราย ใน <?= count($allPositions) ?> ตำแหน่ง · เรียงตามหมายเลข</p>
                </div>
                <?php if ($edit): ?><a href="?tab=candidates&pos=<?= $formPos ?>" class="btn btn-primary btn-sm">+ ลงทะเบียนผู้สมัครใหม่</a><?php endif; ?>
            </div>
            <div class="table-wrap">
                <table class="table registry-table">
                    <thead>
                    <tr>
                        <th class="col-no">หมายเลข</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>สังกัด / หน่วยงาน</th>
                        <th class="num">คะแนน</th>
                        <th class="num">ร้อยละ</th>
                        <th class="col-actions"><span class="sr-only">จัดการ</span></th>
                    </tr>
                    </thead>
                    <?php foreach ($allPositions as $p): ?>
                    <tbody>
                        <tr class="group-row"><th colspan="6"><div class="group-head">
                            <span>ตำแหน่ง<?= e($p['title']) ?> <span class="muted small">· <?= e(position_rule($p)) ?> · ผู้สมัคร <?= count($p['candidates']) ?> ราย</span></span>
                            <a href="?tab=candidates&pos=<?= (int)$p['id'] ?>" class="group-add">+ เพิ่มผู้สมัคร</a>
                        </div></th></tr>
                    <?php foreach ($p['candidates'] as $c): ?>
                        <tr class="<?= $edit && (int)$edit['id'] === (int)$c['id'] ? 'is-editing' : '' ?>">
                            <td class="col-no"><span class="reg-no"><?= (int)$c['number'] ?></span></td>
                            <td>
                                <div class="person">
                                    <span class="person-photo">
                                        <?php if ($c['photo_url']): ?><img src="<?= e($c['photo_url']) ?>" alt=""><?php else: ?><?= e(initials($c['name'])) ?><?php endif; ?>
                                    </span>
                                    <span>
                                        <b><?= e($c['first_name']) ?> <?= e($c['last_name']) ?></b>
                                        <?php if ($c['slogan']): ?><small class="muted"><?= e($c['slogan']) ?></small><?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td><?= $c['party'] !== '' ? e($c['party']) : '<span class="muted">—</span>' ?></td>
                            <td class="num"><?= $r['visible'] ? number_format($votesBy[$c['id']] ?? 0) : '<span class="muted" title="ปิดผนึกจนกว่าจะปิดหีบ">—</span>' ?></td>
                            <td class="num"><?= $r['visible'] ? number_format((float)($pctBy[$c['id']] ?? 0), 2) : '<span class="muted">—</span>' ?></td>
                            <td class="col-actions">
                                <div class="actions">
                                    <a href="?tab=candidates&edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-ghost">แก้ไข</a>
                                    <form method="post" data-confirm="ยืนยันการลบผู้สมัครหมายเลข <?= (int)$c['number'] ?> <?= e($c['name']) ?> ออกจากทะเบียน?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_candidate">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$p['candidates']): ?><tr><td colspan="6" class="muted center">ยังไม่มีผู้สมัครในตำแหน่งนี้</td></tr><?php endif; ?>
                    </tbody>
                    <?php endforeach; ?>
                    <?php if (!$allPositions): ?><tbody><tr><td colspan="6" class="muted center">ยังไม่มีตำแหน่ง — <a href="?tab=positions">เพิ่มตำแหน่งก่อน</a></td></tr></tbody><?php endif; ?>
                </table>
            </div>
            <p class="muted small registry-note"><?= ballot_locked() ? '🔒 ' . e(BALLOT_LOCKED_MSG) : 'เพิ่ม/ลบ/ย้ายตำแหน่งผู้สมัครได้จนถึงเวลาเปิดลงคะแนน' ?><?= $r['visible'] ? '' : ' · คะแนนปิดผนึกจนกว่าจะปิดหีบ' ?></p>
        </section>

        <section class="card form-card">
            <h2 class="card-title"><?= $edit ? 'แก้ไขข้อมูลผู้สมัคร หมายเลข ' . (int)$edit['number'] : 'ลงทะเบียนผู้สมัคร' ?></h2>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_candidate">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                <fieldset class="form-section">
                    <legend>ข้อมูลผู้สมัคร</legend>
                    <?php
                    // หมายเลขถัดไปของแต่ละตำแหน่ง: ใช้เติมช่องหมายเลขอัตโนมัติเมื่อเปลี่ยนตำแหน่ง (เฉพาะการเพิ่มใหม่)
                    $nextBy = [];
                    foreach ($allPositions as $p) $nextBy[$p['id']] = $p['candidates'] ? max(array_column($p['candidates'], 'number')) + 1 : 1;
                    ?>
                    <label class="field"><span>ตำแหน่งที่สมัคร <em class="req">*</em></span>
                        <select name="position_id" required <?= $edit ? '' : 'data-next-number=\'' . e(json_encode($nextBy)) . '\'' ?>>
                            <?php foreach ($allPositions as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $formPos ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <label class="field field-no"><span>หมายเลขผู้สมัคร <em class="req">*</em></span>
                        <input type="number" name="number" min="1" value="<?= (int)($edit['number'] ?? $nextNo) ?>" required>
                        <small class="muted">ไม่ซ้ำกันภายในตำแหน่งเดียวกัน</small></label>
                    <div class="row">
                        <label class="field"><span>ชื่อ <em class="req">*</em></span><input type="text" name="first_name" maxlength="100" value="<?= e($edit['first_name'] ?? '') ?>" required></label>
                        <label class="field"><span>นามสกุล <em class="req">*</em></span><input type="text" name="last_name" maxlength="100" value="<?= e($edit['last_name'] ?? '') ?>" required></label>
                    </div>
                    <label class="field"><span>สังกัด / หน่วยงาน</span><input type="text" name="party" maxlength="150" value="<?= e($edit['party'] ?? '') ?>" placeholder="เช่น สำนักงาน ป.ป.ช. ภาค 1"></label>
                </fieldset>

                <fieldset class="form-section">
                    <legend>ข้อมูลประกอบการพิจารณา</legend>
                    <label class="field"><span>คำขวัญ / วิสัยทัศน์โดยย่อ</span><input type="text" name="slogan" maxlength="255" value="<?= e($edit['slogan'] ?? '') ?>"></label>
                    <label class="field"><span>นโยบาย</span><textarea name="policy" rows="5" placeholder="ระบุบรรทัดละ 1 ข้อ"><?= e($edit['policy'] ?? '') ?></textarea>
                        <small class="muted">แต่ละบรรทัดจะแสดงเป็น 1 ข้อในหน้าข้อมูลผู้สมัคร</small></label>
                </fieldset>

                <fieldset class="form-section">
                    <legend>การแสดงผล</legend>
                    <label class="field"><span>ที่อยู่รูปถ่าย (URL)</span><input type="text" name="photo_url" value="<?= e($edit['photo_url'] ?? '') ?>" placeholder="https://... หรือ uploads/xxx.jpg">
                        <small class="muted">รูปหน้าตรง สัดส่วน 1:1 · ไม่บังคับ</small></label>
                    <?php $hex = strtoupper($edit['color'] ?? '#9400D4'); ?>
                    <div class="field field-color">
                        <label for="colorHex">สีประจำผู้สมัคร (HEX)</label>
                        <span class="color-input">
                            <input type="color" value="<?= e($hex) ?>" data-hex-for="colorHex" aria-label="เลือกสีจากจานสี">
                            <input type="text" id="colorHex" name="color" value="<?= e($hex) ?>" maxlength="7"
                                   pattern="#[0-9A-Fa-f]{6}" title="รหัสสี HEX 6 หลัก เช่น #9400D4" spellcheck="false" autocomplete="off" required>
                        </span>
                        <small class="muted">รูปแบบ #RRGGBB เช่น #9400D4 · ใช้เป็นสีเน้นในบัตรเลือกตั้งและกราฟผลคะแนน</small>
                    </div>
                </fieldset>

                <div class="form-actions">
                    <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูลผู้สมัคร' ?></button>
                    <?php if ($edit): ?><a href="?tab=candidates" class="btn btn-ghost">ยกเลิก</a><?php endif; ?>
                </div>
            </form>
        </section>
    </div>

<?php elseif ($tab === 'voters'):
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20;
    $where = "role = 'voter'";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (member_no LIKE ? OR username LIKE ? OR full_name LIKE ?)';
        $params = ["%$q%", "%$q%", "%$q%"];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
    $st->execute($params);
    $count = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($count / $per));
    $st = $pdo->prepare("SELECT * FROM users WHERE $where ORDER BY member_no IS NULL, member_no, id LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $voters = $st->fetchAll();
    $incomplete = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'voter' AND (member_no IS NULL OR birth_date IS NULL)")->fetchColumn();
    $edit = null;
    if (!empty($_GET['edit'])) {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'voter'");
        $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
?>
    <?php if ($incomplete): ?>
        <div class="alert alert-warning">มีผู้มีสิทธิ์ <?= $incomplete ?> รายที่ยังไม่มีเลขสมาชิกหรือวันเกิด — จะเข้าสู่ระบบไม่ได้จนกว่าจะเพิ่มข้อมูลให้ครบ</div>
    <?php endif; ?>
    <div class="admin-layout">
        <div class="card form-card">
            <h2 class="card-title"><?= $edit ? 'แก้ไขข้อมูลผู้มีสิทธิ์' : 'เพิ่มผู้มีสิทธิ์' ?></h2>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_voter">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <label class="field"><span>เลขสมาชิก <em class="req">*</em></span>
                    <input type="text" name="member_no" inputmode="numeric" maxlength="20" value="<?= e($edit['member_no'] ?? '') ?>" required></label>
                <label class="field"><span>เลขบัตรประจำตัวประชาชน <em class="req">*</em></span>
                    <input type="text" name="username" inputmode="numeric" maxlength="17" value="<?= e($edit['username'] ?? '') ?>" required></label>
                <div class="field"><span>วันเดือนปีเกิด <em class="req">*</em></span><?= dob_select('dob', $edit['birth_date'] ?? null) ?></div>
                <div class="row">
                    <label class="field"><span>ชื่อ <em class="req">*</em></span><input type="text" name="first_name" maxlength="100" value="<?= e($edit['first_name'] ?? '') ?>" required></label>
                    <label class="field"><span>นามสกุล <em class="req">*</em></span><input type="text" name="last_name" maxlength="100" value="<?= e($edit['last_name'] ?? '') ?>" required></label>
                </div>
                <small class="muted">สมาชิกเข้าสู่ระบบด้วยเลขสมาชิก + เลขบัตรประชาชน + วันเดือนปีเกิด (ไม่ใช้รหัสผ่าน)</small>
                <div class="form-actions">
                    <button class="btn btn-primary"><?= $edit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้มีสิทธิ์' ?></button>
                    <?php if ($edit): ?><a href="?tab=voters" class="btn btn-ghost">ยกเลิก</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="card">
            <div class="card-head">
                <h2 class="card-title">ผู้มีสิทธิ์ (<?= number_format($count) ?>)</h2>
                <form method="get" class="search">
                    <input type="hidden" name="tab" value="voters">
                    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเลขสมาชิก / ชื่อ / เลขบัตร">
                </form>
            </div>
            <div class="table-wrap">
                <table class="table voter-table">
                    <thead><tr><th>สมาชิก</th><th>เลขบัตร / วันเกิด</th><th>สถานะ</th><th class="col-actions"><span class="sr-only">จัดการ</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($voters as $v): ?>
                        <tr class="<?= $edit && (int)$edit['id'] === (int)$v['id'] ? 'is-editing' : '' ?>">
                            <td><b><?= e($v['full_name']) ?></b>
                                <small class="cell-sub">เลขสมาชิก <?= $v['member_no'] !== null ? '<code>' . e($v['member_no']) . '</code>' : '<span class="tag tag-warn">ไม่มี</span>' ?></small></td>
                            <td><code><?= e(mask_id($v['username'])) ?></code>
                                <small class="cell-sub">เกิด <?= $v['birth_date'] ? e(thai_date($v['birth_date'])) : '<span class="tag tag-warn">ไม่มี</span>' ?></small></td>
                            <td><?= $v['has_voted']
                                    ? '<span class="tag tag-green">ใช้สิทธิ์แล้ว</span>'
                                    : '<span class="tag">ยังไม่ใช้สิทธิ์</span>' ?></td>
                            <td class="col-actions"><div class="actions">
                                <a href="?tab=voters&edit=<?= (int)$v['id'] ?>&q=<?= urlencode($q) ?>&page=<?= $page ?>" class="btn btn-sm btn-ghost">แก้ไข</a>
                                <a href="?tab=logs&member=<?= urlencode((string)($v['member_no'] ?? '')) ?>&uid=<?= (int)$v['id'] ?>" class="btn btn-sm btn-ghost">ประวัติ</a>
                                <?php if (!$v['has_voted']): ?>
                                    <form method="post" data-confirm="ยืนยันการลบผู้มีสิทธิ์ <?= e($v['full_name']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_voter">
                                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">ลบ</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted small" title="ผู้ที่ใช้สิทธิ์แล้วลบไม่ได้">—</span>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$voters): ?><tr><td colspan="4" class="muted center">ไม่พบข้อมูล</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <a href="?tab=voters&q=<?= urlencode($q) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'verifications'):
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $where = '1';
    $params = [];
    if ($q !== '') {
        $where = '(u.username LIKE ? OR u.full_name LIKE ?)';
        $params = ["%$q%", "%$q%"];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM vote_verifications v JOIN users u ON u.id = v.user_id WHERE $where");
    $st->execute($params);
    $count = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($count / $per));
    $st = $pdo->prepare("SELECT v.*, u.full_name, u.username, u.terms_accepted_at FROM vote_verifications v JOIN users u ON u.id = v.user_id
                         WHERE $where ORDER BY v.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll();
?>
    <div class="card">
        <div class="card-head">
            <h2 class="card-title">หลักฐานการมาใช้สิทธิ์ (<?= number_format($count) ?>)</h2>
            <form method="get" class="search">
                <input type="hidden" name="tab" value="verifications">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่อ / เลขบัตร">
            </form>
        </div>
        <p class="muted small">ภาพถ่ายและตำแหน่ง ณ เวลาลงคะแนน — ไม่มีข้อมูลว่าผู้ใช้สิทธิ์เลือกผู้สมัครคนใด กดที่ภาพเพื่อดูขนาดเต็ม</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ภาพ</th><th>ผู้ใช้สิทธิ์</th><th>เวลา</th><th>ตำแหน่ง</th><th>IP / อุปกรณ์</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $v):
                    $img = 'verify_photo.php?id=' . (int)$v['user_id'];
                    $map = 'https://www.google.com/maps?q=' . rawurlencode($v['latitude'] . ',' . $v['longitude']);
                ?>
                    <tr>
                        <td><a href="<?= $img ?>" target="_blank" rel="noopener"><img class="verify-thumb" src="<?= $img ?>" alt="" loading="lazy"></a></td>
                        <td><b><?= e($v['full_name']) ?></b><br><code><?= e(mask_id($v['username'])) ?></code></td>
                        <td><?= e(thai_datetime($v['created_at'])) ?>
                            <?php if ($v['terms_accepted_at']): ?><br><small class="muted">ยอมรับเงื่อนไข <?= e(thai_datetime($v['terms_accepted_at'])) ?></small><?php endif; ?></td>
                        <td>
                            <a href="<?= e($map) ?>" target="_blank" rel="noopener noreferrer">📍 <?= e(number_format((float)$v['latitude'], 5)) ?>, <?= e(number_format((float)$v['longitude'], 5)) ?></a>
                            <?php if ($v['accuracy_m'] !== null):
                                $a = (int)$v['accuracy_m'];
                                [$aLabel, $aCls] = $a <= 100 ? ['แม่นยำ', 'acc-good'] : ($a <= 500 ? ['พอใช้', 'acc-fair'] : ['คลาดเคลื่อนสูง', 'acc-poor']);
                            ?><br><small class="<?= $aCls ?>">±<?= number_format($a) ?> ม. · <?= $aLabel ?></small><?php endif; ?>
                        </td>
                        <td class="small"><?= e($v['ip']) ?><br><span class="muted" title="<?= e($v['user_agent']) ?>"><?= e(mb_strimwidth($v['user_agent'], 0, 40, '…')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="5" class="muted center">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="?tab=verifications&q=<?= urlencode($q) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'logs'):
    [$w, $params] = log_filter();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = 50;
    $st = $pdo->prepare("SELECT COUNT(*) FROM access_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w");
    $st->execute($params);
    $count = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($count / $per));
    $st = $pdo->prepare("SELECT l.*, u.full_name FROM access_logs l LEFT JOIN users u ON u.id = l.user_id
                         WHERE $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $logs = $st->fetchAll();
    $today = $pdo->query("SELECT
            COUNT(DISTINCT CASE WHEN event = 'login_ok' THEN user_id END) AS members,
            SUM(event = 'login_fail') AS fails, SUM(event = 'login_locked') AS locked, SUM(event = 'vote_ok') AS votes
        FROM access_logs WHERE created_at >= CURDATE()")->fetch();
    $qs = http_build_query(array_intersect_key($_GET, array_flip(['q', 'event', 'uid', 'date'])));
    $filterUser = null;
    if (!empty($_GET['uid'])) {
        $s2 = $pdo->prepare('SELECT full_name, member_no FROM users WHERE id = ?');
        $s2->execute([(int)$_GET['uid']]);
        $filterUser = $s2->fetch() ?: null;
    }
    $evClass = fn($e) => match (true) {
        in_array($e, ['login_fail', 'vote_reject'], true) => 'tag-warn',
        $e === 'login_locked' => 'tag-danger',
        in_array($e, ['login_ok', 'vote_ok', 'terms_accept'], true) => 'tag-green',
        str_starts_with($e, 'admin_') => 'tag-admin',
        default => '',
    };
?>
    <div class="grid stats-grid">
        <div class="stat-card"><span>สมาชิกที่เข้าสู่ระบบวันนี้</span><b><?= number_format((int)$today['members']) ?></b></div>
        <div class="stat-card"><span>ลงคะแนนสำเร็จวันนี้</span><b><?= number_format((int)$today['votes']) ?></b></div>
        <div class="stat-card"><span>เข้าสู่ระบบไม่สำเร็จวันนี้</span><b><?= number_format((int)$today['fails']) ?></b></div>
        <div class="stat-card"><span>ถูกระงับชั่วคราววันนี้</span><b><?= number_format((int)$today['locked']) ?></b></div>
    </div>
    <div class="card">
        <div class="card-head">
            <h2 class="card-title">บันทึกการเข้าใช้งาน (<?= number_format($count) ?>)
                <?php if ($filterUser): ?><span class="muted small">· <?= e($filterUser['full_name']) ?> (สมาชิก <?= e($filterUser['member_no'] ?? '-') ?>)</span><?php endif; ?></h2>
            <a href="?tab=logs&export=1&<?= e($qs) ?>" class="btn btn-sm btn-ghost">ส่งออก CSV</a>
        </div>
        <form method="get" class="log-filter">
            <input type="hidden" name="tab" value="logs">
            <?php if (!empty($_GET['uid'])): ?><input type="hidden" name="uid" value="<?= (int)$_GET['uid'] ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="เลขสมาชิก / ชื่อ / IP">
            <select name="event">
                <option value="">ทุกเหตุการณ์</option>
                <?php foreach (ACCESS_EVENTS as $k => $label): ?>
                    <option value="<?= $k ?>" <?= ($_GET['event'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?= e($_GET['date'] ?? '') ?>" aria-label="วันที่">
            <button class="btn btn-primary btn-sm">กรอง</button>
            <?php if ($qs !== ''): ?><a href="?tab=logs" class="btn btn-ghost btn-sm">ล้างตัวกรอง</a><?php endif; ?>
        </form>
        <div class="table-wrap">
            <table class="table log-table">
                <thead><tr><th>เวลา</th><th>เลขสมาชิก</th><th>ชื่อ - นามสกุล</th><th>เหตุการณ์</th><th>รายละเอียด</th><th>IP / อุปกรณ์</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="nowrap" data-sort="<?= e($l['created_at']) ?>"><?= e(thai_date($l['created_at'])) ?><br><small class="muted"><?= e(substr($l['created_at'], 11, 8)) ?> น.</small></td>
                        <td><?= $l['member_no'] !== '' ? '<code>' . e($l['member_no']) . '</code>' : '<span class="muted">—</span>' ?></td>
                        <td><?= $l['full_name'] ? e($l['full_name']) : '<span class="muted">ไม่พบในระบบ</span>' ?></td>
                        <td><span class="tag <?= $evClass($l['event']) ?>"><?= e(ACCESS_EVENTS[$l['event']] ?? $l['event']) ?></span></td>
                        <td class="small"><?= e($l['detail']) ?></td>
                        <td class="small"><?= e($l['ip']) ?><br><span class="muted" title="<?= e($l['user_agent']) ?>"><?= e(mb_strimwidth($l['user_agent'], 0, 36, '…')) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?><tr><td colspan="6" class="muted center">ไม่พบบันทึก</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php for ($p = max(1, $page - 5); $p <= min($pages, $page + 5); $p++): ?>
                    <a href="?tab=logs&<?= e($qs) ?>&page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        <p class="muted small">บันทึกนี้ไม่มีข้อมูลว่าสมาชิกเลือกผู้สมัครคนใด · ข้อมูลส่วนบุคคล ควรเข้าถึงเฉพาะผู้มีหน้าที่ตรวจสอบ</p>
    </div>

<?php elseif ($tab === 'settings'): ?>
    <div class="admin-layout">
        <div class="card">
            <h2 class="card-title">ตั้งค่าการเลือกตั้ง</h2>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <label class="field"><span>ชื่อการเลือกตั้ง</span><input type="text" name="election_title" value="<?= e(setting('election_title')) ?>" required></label>
                <label class="field"><span>คำอธิบาย</span><input type="text" name="election_desc" value="<?= e(setting('election_desc')) ?>"></label>
                <?php $sl = slogan_config(); ?>
                <fieldset class="form-section slogan-settings">
                    <legend>แถบคำขวัญ (ใต้ชื่อสหกรณ์)</legend>
                    <label class="field"><span>ข้อความ (บรรทัดละ 1 ข้อความ สูงสุด 10 บรรทัด)</span>
                        <textarea name="slogan_text" rows="3" id="sloganText" placeholder="เว้นว่างเพื่อซ่อนแถบคำขวัญ"><?= e($sl['text']) ?></textarea></label>
                    <div class="row">
                        <label class="field"><span>รูปแบบ</span>
                            <select name="slogan_mode" id="sloganMode">
                                <option value="scroll" <?= $sl['mode'] === 'scroll' ? 'selected' : '' ?>>เลื่อนจากขวาไปซ้าย</option>
                                <option value="static" <?= $sl['mode'] === 'static' ? 'selected' : '' ?>>อยู่กับที่ (กึ่งกลาง)</option>
                            </select></label>
                        <label class="field"><span>ความเร็ว</span>
                            <select name="slogan_speed" id="sloganSpeed">
                                <?php foreach (SLOGAN_SPEEDS as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= $sl['speed'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select></label>
                    </div>
                    <div class="slogan-preview" aria-label="ตัวอย่างแถบคำขวัญ">
                        <small class="muted">ตัวอย่าง</small>
                        <div class="slogan-bar" id="sloganPreview"><div class="slogan-track"><span></span></div></div>
                    </div>
                </fieldset>
                <div class="row">
                    <label class="field"><span>เปิดหีบ</span><input type="datetime-local" name="start_at" value="<?= e(date('Y-m-d\TH:i', strtotime(setting('start_at')))) ?>"></label>
                    <label class="field"><span>ปิดหีบ</span><input type="datetime-local" name="end_at" value="<?= e(date('Y-m-d\TH:i', strtotime(setting('end_at')))) ?>"></label>
                </div>
                <label class="field"><span>โหมด</span>
                    <select name="election_mode">
                        <?php foreach (['auto' => 'อัตโนมัติตามช่วงเวลา', 'open' => 'บังคับเปิด', 'closed' => 'บังคับปิด'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= setting('election_mode') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="muted small sealed-note">🔒 ผลคะแนนเปิดเผยหลังปิดหีบเท่านั้น ระหว่างการเลือกตั้งไม่มีผู้ใดดูได้ รวมถึงผู้ดูแลระบบ
                    · การเปลี่ยนโหมดและกำหนดเวลาจะถูกบันทึกในบันทึกการเข้าใช้</p>
                <label class="field"><span>ความแม่นยำ GPS ขั้นต่ำ (เมตร)</span>
                    <input type="number" name="gps_max_accuracy" min="0" step="10" value="<?= (int)setting('gps_max_accuracy', '0') ?>">
                    <small class="muted">ไม่รับการลงคะแนนถ้าตำแหน่งคลาดเคลื่อนเกินค่านี้ · 0 = ไม่บังคับ · แนะนำ 100–200 ม.
                        (ค่าน้อยเกินไปอาจทำให้ผู้ใช้คอมพิวเตอร์ที่ไม่มี GPS ลงคะแนนไม่ได้)</small>
                </label>
                <label class="field"><span>ข้อกำหนดและเงื่อนไขการใช้สิทธิ์ (บรรทัดละ 1 ข้อ)</span>
                    <textarea name="voting_terms" rows="10"><?= e(implode("\n", terms_clauses())) ?></textarea>
                    <small class="muted">แสดงให้ผู้มีสิทธิ์อ่านและยอมรับก่อนเข้าสู่บัตรเลือกตั้งทุกครั้งที่เข้าสู่ระบบ · ลบข้อความทั้งหมดเพื่อกลับไปใช้ค่าเริ่มต้น</small>
                </label>
                <button class="btn btn-primary">บันทึก</button>
            </form>
        </div>
        <div>
            <div class="card">
                <h2 class="card-title">เปลี่ยนรหัสผ่านผู้ดูแล</h2>
                <form method="post" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <label class="field"><span>รหัสผ่านใหม่</span><input type="password" name="new_password" minlength="6" required></label>
                    <button class="btn btn-primary">เปลี่ยนรหัสผ่าน</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
