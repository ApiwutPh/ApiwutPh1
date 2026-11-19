<?php
/**
 * ระบบเลือกตั้งออนไลน์ - ไฟล์ตั้งค่าหลัก
 */
declare(strict_types=1);

// ===== ตั้งค่าฐานข้อมูล (ค่าเริ่มต้นของ XAMPP) =====
const DB_HOST = '127.0.0.1';
const DB_NAME = 'election_db';
const DB_USER = 'root';
const DB_PASS = '1234';

date_default_timezone_set('Asia/Bangkok');

// ===== ที่เก็บภาพถ่ายยืนยันตัวตน (storage/.htaccess ปิดการเข้าถึงจากเว็บ) =====
const VERIFY_DIR = __DIR__ . '/storage/verify';
const VERIFY_MAX_BYTES = 1_500_000;

/**
 * ตรวจภาพถ่ายจากกล้อง (data URL JPEG) แล้วคืนค่าไบต์ของภาพ หรือ null ถ้าไม่ถูกต้อง
 * ถ้ามี GD จะเข้ารหัสภาพใหม่เพื่อตัด metadata ทิ้ง
 */
function verification_photo(string $dataUrl): ?string
{
    if (!str_starts_with($dataUrl, 'data:image/jpeg;base64,')) return null;
    $bin = base64_decode(substr($dataUrl, 23), true);
    if ($bin === false || strlen($bin) < 2_000 || strlen($bin) > VERIFY_MAX_BYTES) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 160 || $info[1] < 120 || $info[0] > 2000 || $info[1] > 2000) return null;
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($bin);
        if (!$img) return null;
        ob_start();
        imagejpeg($img, null, 85);
        $bin = (string)ob_get_clean();
        imagedestroy($img);
    }
    return $bin;
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** เชื่อมต่อฐานข้อมูล (PDO) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            $base = base_url();
            die('<div style="font-family:sans-serif;padding:40px;text-align:center">'
                . '<h2>ยังไม่ได้ติดตั้งฐานข้อมูล</h2>'
                . '<p>กรุณาเปิด MySQL ใน XAMPP แล้วไปที่ <a href="' . $base . '/install.php">install.php</a></p></div>');
        }
    }
    return $pdo;
}

/** URL ฐานของแอป เช่น /Demo (ใช้ได้ทั้งจากโฟลเดอร์ admin/ และ api/) */
function base_url(): string
{
    $base = strtr(dirname($_SERVER['SCRIPT_NAME']), '\\', '/');
    if (preg_match('#/(admin|api)$#', $base)) {
        $base = strtr(dirname($base), '\\', '/');
    }
    return rtrim($base, '/');
}

/** ทำความสะอาดชื่อ/นามสกุล: ตัดช่องว่างหัวท้าย ยุบช่องว่างซ้ำ จำกัด 100 ตัวอักษร */
function clean_name(mixed $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s) ?? '');
    return mb_substr($s, 0, 100);
}

/** escape HTML */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** อ่านค่า setting */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$key] ?? $default;
}

// ===== แถบคำขวัญ (ผู้ดูแลแก้ไขได้ในแท็บตั้งค่า) =====
const DEFAULT_SLOGAN = 'มั่นคง ทันสมัย ใส่ใจสมาชิก';
const SLOGAN_SPEEDS  = ['slow' => 'ช้า', 'normal' => 'ปกติ', 'fast' => 'เร็ว'];

/**
 * ค่าแถบคำขวัญ: messages (บรรทัดละข้อความ; ว่างทั้งหมด = ซ่อนแถบ), mode (scroll|static), speed
 * duration = เวลาเลื่อนผ่าน 1 รอบ (วินาที) คำนวณตามความยาวข้อความ ให้ความเร็วในการอ่านคงที่
 */
function slogan_config(): array
{
    $text = setting('slogan_text', DEFAULT_SLOGAN) ?? DEFAULT_SLOGAN;
    $messages = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), 'strlen'));
    $mode  = setting('slogan_mode', 'scroll') === 'static' ? 'static' : 'scroll';
    $speed = array_key_exists((string)setting('slogan_speed'), SLOGAN_SPEEDS) ? setting('slogan_speed') : 'normal';
    $chars = mb_strlen(implode('   •   ', $messages));
    $factor = ['slow' => 1.5, 'normal' => 1.0, 'fast' => 0.6][$speed];
    return [
        'messages' => $messages,
        'text'     => implode("\n", $messages),
        'mode'     => $mode,
        'speed'    => $speed,
        'duration' => round(max(10, 8 + $chars * 0.3) * $factor, 1),
    ];
}

function save_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$key, $value]);
}

/**
 * สถานะการเลือกตั้ง: upcoming | open | closed
 * ผู้ดูแลกำหนดช่วงเวลา หรือบังคับเปิด/ปิดได้
 */
function election_status(): string
{
    $mode = setting('election_mode', 'auto'); // auto | open | closed
    if ($mode === 'open' || $mode === 'closed') {
        return $mode;
    }
    $now   = time();
    $start = strtotime((string)setting('start_at'));
    $end   = strtotime((string)setting('end_at'));
    if ($start && $now < $start) return 'upcoming';
    if ($end && $now > $end)     return 'closed';
    return 'open';
}

/**
 * เปิดเผยผลคะแนนได้เมื่อปิดหีบแล้วเท่านั้น — ระหว่างการเลือกตั้งไม่มีใครดูได้ รวมถึงผู้ดูแลระบบ
 * (get_results() จะไม่คืนจำนวนคะแนนเลยถ้ายังไม่ถึงเวลา)
 */
function results_visible(): bool
{
    return election_status() === 'closed';
}

/**
 * บัตรเลือกตั้งถูกล็อกแล้วหรือไม่: เปิดลงคะแนนแล้ว หรือมีผู้ใช้สิทธิ์แล้ว
 * เมื่อล็อก ห้ามเพิ่ม/ลบผู้สมัครหรือตำแหน่ง ห้ามเปลี่ยนหมายเลข ตำแหน่ง จำนวนที่นั่ง
 * (ตรวจจากจำนวนผู้ใช้สิทธิ์รวม ไม่ใช่คะแนนรายคน จึงไม่เปิดเผยคะแนน)
 */
function ballot_locked(): bool
{
    static $locked = null;
    return $locked ??= election_status() !== 'upcoming'
        || (bool)db()->query("SELECT EXISTS (SELECT 1 FROM users WHERE has_voted = 1)")->fetchColumn();
}
const BALLOT_LOCKED_MSG = 'เปิดลงคะแนนแล้วหรือมีผู้ใช้สิทธิ์แล้ว จึงไม่สามารถเพิ่ม/ลบผู้สมัครหรือตำแหน่ง หรือเปลี่ยนหมายเลข ตำแหน่ง และจำนวนที่นั่งได้ (แก้ไขข้อมูลประกอบอื่นได้)';

// ===== บันทึกการเข้าใช้งาน =====
const ACCESS_EVENTS = [
    'login_ok'      => 'เข้าสู่ระบบสำเร็จ',
    'login_fail'    => 'เข้าสู่ระบบไม่สำเร็จ',
    'login_locked'  => 'ถูกระงับชั่วคราว (กรอกผิดหลายครั้ง)',
    'logout'        => 'ออกจากระบบ',
    'terms_view'    => 'เปิดอ่านข้อกำหนดและเงื่อนไข',
    'terms_accept'  => 'ยอมรับข้อกำหนดและเงื่อนไข',
    'ballot_view'   => 'เปิดบัตรเลือกตั้ง',
    'vote_ok'       => 'ลงคะแนนสำเร็จ',
    'vote_reject'   => 'ลงคะแนนไม่สำเร็จ',
    'admin_login'   => 'ผู้ดูแลเข้าสู่ระบบ',
    'admin_setting' => 'ผู้ดูแลแก้ไขการตั้งค่า',
    'admin_reset'   => 'ผู้ดูแลรีเซ็ตการเลือกตั้ง',   // ฟีเจอร์ถูกนำออกแล้ว คงไว้เพื่อแสดง log เก่า
];

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** บันทึกเหตุการณ์ — ห้ามใส่ข้อมูลว่าเลือกผู้สมัครคนใดใน detail */
function log_event(string $event, ?array $user = null, string $memberNo = '', string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO access_logs (user_id, member_no, event, detail, ip, user_agent) VALUES (?,?,?,?,?,?)')->execute([
            $user['id'] ?? null,
            mb_substr($memberNo !== '' ? $memberNo : (string)($user['member_no'] ?? ''), 0, 20),
            $event,
            mb_substr($detail, 0, 255),
            client_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable) {
        // การบันทึก log ล้มเหลวต้องไม่ทำให้ระบบหลักล่ม
    }
}

/** กรอกข้อมูลเข้าสู่ระบบผิดบ่อยเกินไปหรือไม่ (5 ครั้ง/เลขสมาชิก หรือ 20 ครั้ง/IP ภายใน 15 นาที) */
function login_locked(string $memberNo): bool
{
    $st = db()->prepare("SELECT
        SUM(member_no = ? AND ? <> '') AS by_member, SUM(ip = ?) AS by_ip
        FROM access_logs WHERE event = 'login_fail' AND created_at > NOW() - INTERVAL 15 MINUTE");
    $st->execute([$memberNo, $memberNo, client_ip()]);
    $r = $st->fetch();
    return (int)$r['by_member'] >= 5 || (int)$r['by_ip'] >= 20;
}

// ===== วันเกิด (แสดงและรับค่าเป็น พ.ศ.) =====
const THAI_MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/** วัน/เดือน/ปี พ.ศ. → 'Y-m-d' (ค.ศ.) หรือ null ถ้าไม่ใช่วันที่ที่ถูกต้อง */
function dob_from_parts(mixed $d, mixed $m, mixed $beYear): ?string
{
    $d = (int)$d; $m = (int)$m; $y = (int)$beYear - 543;
    $thisYear = (int)date('Y');
    if ($y < $thisYear - 120 || $y > $thisYear || !checkdate($m, $d, $y)) return null;
    $ymd = sprintf('%04d-%02d-%02d', $y, $m, $d);
    return $ymd <= date('Y-m-d') ? $ymd : null;
}

/**
 * วันเกิดที่พิมพ์เป็น พ.ศ. → 'Y-m-d' (ค.ศ.) สำหรับเก็บ/เทียบในฐานข้อมูล
 * รับ วว/ดด/ปปปป, วว-ดด-ปปปป, วว.ดด.ปปปป หรือตัวเลขติดกัน 8 หลัก (ววดดปปปป)
 * คืนค่า [ymd|null, ข้อความผิดพลาด|''] — แยกกรณีกรอกปีเป็น ค.ศ. เพื่อบอกผู้ใช้ให้ชัด
 */
function dob_from_be_text(string $text): array
{
    $t = trim(preg_replace('/\s+/u', '', $text) ?? '');
    if ($t === '') return [null, 'กรุณากรอกวันเดือนปีเกิด'];
    if (!preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$~', $t, $m) && !preg_match('~^(\d{2})(\d{2})(\d{4})$~', $t, $m)) {
        return [null, 'รูปแบบวันเกิดไม่ถูกต้อง กรุณากรอกเป็น วว/ดด/ปปปป (พ.ศ.) เช่น 15/03/2528'];
    }
    [, $d, $mo, $y] = array_map('intval', $m);
    if ($y < 2400) return [null, 'กรุณากรอกปีเกิดเป็น พ.ศ. (เช่น 2528 ไม่ใช่ 1985)'];
    $ymd = dob_from_parts($d, $mo, $y);
    return $ymd ? [$ymd, ''] : [null, 'วันเกิดไม่ถูกต้อง กรุณาตรวจสอบวัน เดือน และปี พ.ศ.'];
}

/** 'Y-m-d' (ค.ศ.) → 'วว/ดด/ปปปป' (พ.ศ.) สำหรับเติมกลับในช่องกรอก */
function dob_to_be_text(?string $ymd): string
{
    if (!$ymd || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return '';
    return $m[3] . '/' . $m[2] . '/' . ((int)$m[1] + 543);
}

/** 'Y-m-d' → '15 มี.ค. 2528' */
function thai_date(?string $ymd): string
{
    if (!$ymd || !($ts = strtotime($ymd))) return '-';
    $short = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return date('j', $ts) . ' ' . $short[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
}

/** ช่องเลือกวันเกิด วัน / เดือน / ปี พ.ศ. ($value = 'Y-m-d' หรือ [d, m, beYear]) */
function dob_select(string $prefix, string|array|null $value = null, bool $required = true): string
{
    [$d, $m, $by] = is_array($value) ? array_map('intval', $value + [0, 0, 0])
        : ($value ? [(int)substr($value, 8, 2), (int)substr($value, 5, 2), (int)substr($value, 0, 4) + 543] : [0, 0, 0]);
    $req = $required ? ' required' : '';
    $nowBE = (int)date('Y') + 543;
    $h = '<span class="dob-select">';
    $h .= "<select name=\"{$prefix}_d\" aria-label=\"วันที่\"$req><option value=\"\">วัน</option>";
    for ($i = 1; $i <= 31; $i++) $h .= "<option value=\"$i\"" . ($i === $d ? ' selected' : '') . ">$i</option>";
    $h .= "</select><select name=\"{$prefix}_m\" aria-label=\"เดือน\"$req><option value=\"\">เดือน</option>";
    for ($i = 1; $i <= 12; $i++) $h .= "<option value=\"$i\"" . ($i === $m ? ' selected' : '') . '>' . THAI_MONTHS[$i] . '</option>';
    $h .= "</select><select name=\"{$prefix}_y\" aria-label=\"ปี พ.ศ.\"$req><option value=\"\">ปี พ.ศ.</option>";
    for ($i = $nowBE - 15; $i >= $nowBE - 100; $i--) $h .= "<option value=\"$i\"" . ($i === $by ? ' selected' : '') . ">$i</option>";
    return $h . '</select></span>';
}

/** รหัสผ่านที่ใช้ไม่ได้ สำหรับบัญชีผู้มีสิทธิ์ (ผู้มีสิทธิ์ไม่ได้เข้าสู่ระบบด้วยรหัสผ่าน) */
function unusable_password_hash(): string
{
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
}

/** เลขสมาชิก: ตัวเลข 1–20 หลัก */
function clean_member_no(mixed $s): string
{
    return preg_replace('/\D/', '', (string)$s) ?? '';
}

// ===== ผู้ใช้ / สิทธิ์ =====
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    static $user = false;
    if ($user === false) {
        $st = db()->prepare('SELECT id, username, member_no, first_name, last_name, full_name, role, has_voted, voted_at FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('กรุณาเข้าสู่ระบบก่อน', 'warning');
        redirect(base_url() . '/login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        die('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $u;
}

// ===== CSRF =====
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die('คำขอไม่ถูกต้อง (CSRF) กรุณาลองใหม่');
    }
}

// ===== Flash / Redirect =====
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** ตรวจเลขบัตรประชาชนไทย 13 หลัก (checksum) */
function valid_thai_id(string $id): bool
{
    if (!preg_match('/^\d{13}$/', $id)) return false;
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$id[$i] * (13 - $i);
    }
    return (11 - $sum % 11) % 10 === (int)$id[12];
}

function mask_id(string $id): string
{
    return strlen($id) === 13 ? substr($id, 0, 1) . '-XXXX-XXXXX-' . substr($id, 10, 2) . '-' . $id[12] : $id;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_substr($p, 0, 1);
    }
    return $out;
}

function thai_datetime(?string $dt): string
{
    if (!$dt) return '-';
    $ts = strtotime($dt);
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543) . ' ' . date('H:i', $ts) . ' น.';
}

/** ตำแหน่งทั้งหมด พร้อมผู้สมัครของแต่ละตำแหน่ง (เรียงตามลำดับตำแหน่ง แล้วตามหมายเลข) */
function positions_with_candidates(): array
{
    $positions = db()->query('SELECT * FROM positions ORDER BY sort_order, id')->fetchAll(PDO::FETCH_UNIQUE);
    foreach ($positions as $id => &$p) {
        $p['id'] = $id;
        $p['candidates'] = [];
    }
    unset($p);
    foreach (db()->query('SELECT * FROM candidates ORDER BY number') as $c) {
        if (isset($positions[$c['position_id']])) $positions[$c['position_id']]['candidates'][] = $c;
    }
    return array_values($positions);
}

/** ข้อกำหนดและเงื่อนไขเริ่มต้น (ผู้ดูแลแก้ไขได้ในแท็บตั้งค่า บรรทัดละ 1 ข้อ) */
const DEFAULT_TERMS = <<<TXT
ผู้มีสิทธิ์เลือกตั้งต้องเป็นสมาชิกของสหกรณ์ตามบัญชีรายชื่อที่สหกรณ์ประกาศ และต้องใช้สิทธิ์ด้วยตนเองเท่านั้น ห้ามมอบหมายหรือยินยอมให้ผู้อื่นใช้สิทธิ์แทน
ผู้มีสิทธิ์ลงคะแนนได้เพียงครั้งเดียว เมื่อยืนยันการลงคะแนนแล้วไม่สามารถแก้ไขหรือยกเลิกได้
ในแต่ละตำแหน่ง ผู้มีสิทธิ์เลือกผู้สมัครได้ไม่เกินจำนวนที่กำหนดไว้ในบัตรเลือกตั้ง หรือเลือก “งดออกเสียง” และต้องลงคะแนนให้ครบทุกตำแหน่งก่อนยืนยัน
การลงคะแนนเป็นความลับ ระบบไม่บันทึกข้อมูลที่เชื่อมโยงตัวผู้มีสิทธิ์กับผู้สมัครที่เลือก
เพื่อยืนยันตัวตนและป้องกันการใช้สิทธิ์แทนกัน ระบบจะบันทึกภาพถ่ายใบหน้า ตำแหน่งที่ตั้ง (GPS) หมายเลข IP และข้อมูลอุปกรณ์ ณ เวลาลงคะแนน ข้อมูลดังกล่าวจะใช้เพื่อการตรวจสอบการเลือกตั้งเท่านั้น และเก็บรักษาตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562
ห้ามเปิดเผย บันทึก หรือส่งต่อภาพบัตรเลือกตั้งที่ลงคะแนนแล้ว เพื่อแสดงต่อผู้อื่นหรือเพื่อแลกกับประโยชน์ใด ๆ
หากพบการทุจริต การใช้สิทธิ์แทนผู้อื่น หรือการกระทำที่ทำให้การเลือกตั้งไม่สุจริตเที่ยงธรรม คณะกรรมการดำเนินการเลือกตั้งมีอำนาจตรวจสอบ และอาจดำเนินการตามข้อบังคับของสหกรณ์และกฎหมายที่เกี่ยวข้อง
ผลการเลือกตั้งอย่างเป็นทางการเป็นไปตามประกาศของสหกรณ์ กรณีผู้สมัครได้คะแนนเท่ากันในลำดับสุดท้ายของจำนวนที่นั่ง ให้ดำเนินการตามข้อบังคับของสหกรณ์
TXT;

/** ข้อกำหนดและเงื่อนไขเป็นรายข้อ */
function terms_clauses(): array
{
    $text = (string)setting('voting_terms', '');
    if (trim($text) === '') $text = DEFAULT_TERMS;
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), 'strlen'));
}

/** ข้อความอธิบายจำนวนที่เลือกได้ของตำแหน่ง */
function position_rule(array $p): string
{
    $seats = (int)$p['seats'];
    $max   = (int)$p['max_choices'];
    return "จำนวน {$seats} ที่นั่ง · " . ($max === 1 ? 'เลือกได้ 1 หมายเลข' : "เลือกได้ไม่เกิน {$max} หมายเลข");
}

/**
 * สรุปผลคะแนนแยกตามตำแหน่ง
 * - ร้อยละ = คะแนน / จำนวนผู้มาใช้สิทธิ์ (ตำแหน่งที่เลือกได้หลายคน ผลรวมร้อยละจึงเกิน 100 ได้)
 * - result: 'elected' = อยู่ในเกณฑ์ได้รับเลือก, 'tie' = คะแนนเท่ากันที่ลำดับที่นั่งสุดท้าย
 */
function get_results(): array
{
    $pdo = db();
    $total  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'voter' AND has_voted = 1")->fetchColumn();
    $voters = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'voter'")->fetchColumn();

    // ยังไม่ปิดหีบ: ไม่อ่านตาราง votes เลย คืนเฉพาะรายชื่อและจำนวนผู้มาใช้สิทธิ์ (votes/percent = null)
    $visible = results_visible();
    $votes   = $visible ? $pdo->query('SELECT candidate_id, COUNT(*) FROM votes WHERE candidate_id IS NOT NULL GROUP BY candidate_id')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $abstain = $visible ? $pdo->query('SELECT position_id, COUNT(*) FROM votes WHERE candidate_id IS NULL GROUP BY position_id')->fetchAll(PDO::FETCH_KEY_PAIR) : [];

    $positions = [];
    $flat = [];
    foreach (positions_with_candidates() as $p) {
        $rows = [];
        foreach ($p['candidates'] as $c) {
            $n = (int)($votes[$c['id']] ?? 0);
            $rows[] = [
                'id' => (int)$c['id'], 'position_id' => (int)$p['id'], 'number' => (int)$c['number'],
                'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'name' => $c['name'],
                'party' => $c['party'], 'color' => $c['color'], 'photo_url' => $c['photo_url'],
                'initials' => initials($c['name']),
                'votes' => $n,
                'percent' => $total ? round($n * 100 / $total, 2) : 0,
                'result' => '',
            ];
        }
        usort($rows, fn($a, $b) => [$b['votes'], $a['number']] <=> [$a['votes'], $b['number']]);

        // ผู้อยู่ในเกณฑ์ได้รับเลือก: N อันดับแรกที่มีคะแนน ถ้าคะแนนเท่ากันคร่อมที่นั่งสุดท้าย ให้ติดสถานะ 'tie'
        $seats = (int)$p['seats'];
        $cut = $rows[$seats - 1]['votes'] ?? 0;
        $tie = $cut > 0 && isset($rows[$seats]) && $rows[$seats]['votes'] === $cut;
        foreach ($rows as $i => &$r) {
            if (!$visible) { $r['votes'] = $r['percent'] = null; continue; }
            if ($r['votes'] === 0) continue;
            if ($tie && $r['votes'] === $cut) $r['result'] = 'tie';
            elseif ($i < $seats) $r['result'] = 'elected';
        }
        unset($r);
        if (!$visible) usort($rows, fn($a, $b) => $a['number'] <=> $b['number']);

        $positions[] = [
            'id' => (int)$p['id'], 'title' => $p['title'],
            'seats' => $seats, 'max_choices' => (int)$p['max_choices'], 'rule' => position_rule($p),
            'candidates' => $rows,
            'abstain' => $visible ? (int)($abstain[$p['id']] ?? 0) : null,
        ];
        array_push($flat, ...$rows);
    }

    return [
        'visible'    => $visible,
        'positions'  => $positions,
        'candidates' => $flat,
        'total'      => $total,
        'voters'     => $voters,
        'turnout'    => $voters ? round($total * 100 / $voters, 2) : 0,
        'status'     => election_status(),
        'updated'    => date('H:i:s'),
    ];
}
