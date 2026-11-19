<?php
/**
 * โครงสร้างฐานข้อมูล + การอัปเกรดฐานข้อมูลเดิม + ข้อมูลตัวอย่าง
 * ใช้ร่วมกันโดย install.php (และใช้สร้าง database.sql)
 */

/** สร้างตารางทั้งหมด (ถ้ายังไม่มี) */
function schema_create(PDO $pdo): void
{
    $pdo->exec(schema_sql());
}

/** SQL สร้างตาราง (ใช้ทั้งตอนติดตั้งและสร้าง database.sql) */
function schema_sql(): string
{
    return "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(20) NOT NULL UNIQUE,  -- เลขบัตรประชาชน 13 หลัก หรือ 'admin'
            member_no VARCHAR(20) NULL UNIQUE,     -- เลขสมาชิกสหกรณ์ (เฉพาะผู้มีสิทธิ์)
            birth_date DATE NULL,                  -- วันเกิด (ค.ศ.) ใช้ยืนยันตัวตนตอนเข้าสู่ระบบ
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            full_name VARCHAR(201) AS (TRIM(CONCAT(first_name, ' ', last_name))) STORED,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('voter','admin') NOT NULL DEFAULT 'voter',
            has_voted TINYINT(1) NOT NULL DEFAULT 0,
            voted_at DATETIME NULL,
            terms_accepted_at DATETIME NULL,       -- เวลาที่ยอมรับข้อกำหนดและเงื่อนไขล่าสุด
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- ตำแหน่งที่เลือกตั้ง เช่น ประธานกรรมการ (1 ที่นั่ง), กรรมการดำเนินการ (7 ที่นั่ง)
        CREATE TABLE IF NOT EXISTS positions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            seats INT NOT NULL DEFAULT 1,          -- จำนวนผู้ได้รับเลือก
            max_choices INT NOT NULL DEFAULT 1,    -- ผู้มีสิทธิ์เลือกได้ไม่เกินกี่คน
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS candidates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            position_id INT NOT NULL,
            number INT NOT NULL,                   -- หมายเลขผู้สมัคร (ไม่ซ้ำภายในตำแหน่งเดียวกัน)
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            name VARCHAR(201) AS (TRIM(CONCAT(first_name, ' ', last_name))) STORED,
            party VARCHAR(150) NOT NULL DEFAULT '',   -- สังกัด / หน่วยงาน
            slogan VARCHAR(255) NOT NULL DEFAULT '',  -- วิสัยทัศน์โดยย่อ
            policy TEXT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#9400D4',
            photo_url VARCHAR(255) NOT NULL DEFAULT '',
            UNIQUE KEY uq_position_number (position_id, number),
            CONSTRAINT fk_candidates_position FOREIGN KEY (position_id) REFERENCES positions (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- บัตรลงคะแนน: 1 แถวต่อ 1 การเลือก ไม่ผูกกับผู้ใช้ เพื่อรักษาความลับ
        -- candidate_id = NULL หมายถึง 'งดออกเสียง' ในตำแหน่งนั้น
        CREATE TABLE IF NOT EXISTS votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            position_id INT NOT NULL,
            candidate_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_position (position_id),
            KEY idx_candidate (candidate_id),
            CONSTRAINT fk_votes_position FOREIGN KEY (position_id) REFERENCES positions (id),
            CONSTRAINT fk_votes_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(50) PRIMARY KEY,
            v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- บันทึกการเข้าใช้งาน (ไม่มีข้อมูลว่าเลือกผู้สมัครคนใด)
        CREATE TABLE IF NOT EXISTS access_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,                      -- NULL = ระบุตัวไม่ได้ (เช่น กรอกข้อมูลผิด)
            member_no VARCHAR(20) NOT NULL DEFAULT '',  -- เลขสมาชิกที่กรอก/ของผู้ใช้
            event VARCHAR(30) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id, created_at),
            KEY idx_member (member_no, created_at),
            KEY idx_event (event, created_at),
            KEY idx_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- หลักฐานการมาใช้สิทธิ์ (ภาพถ่าย + GPS) ผูกกับผู้ใช้ ไม่ผูกกับบัตร
        CREATE TABLE IF NOT EXISTS vote_verifications (
            user_id INT NOT NULL PRIMARY KEY,
            photo_file VARCHAR(64) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            accuracy_m INT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
}

/** อัปเกรดฐานข้อมูลรุ่นเก่า — รันซ้ำได้ (ไม่ทำซ้ำสิ่งที่ทำไปแล้ว) คืนรายการข้อความสิ่งที่ทำ */
function schema_migrate(PDO $pdo): array
{
    $done = [];
    $hasCol = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    };
    $hasIndex = function (string $table, string $index) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $index]);
        return (bool)$st->fetchColumn();
    };

    // 1) แยกชื่อ-นามสกุล (ตัดที่ช่องว่างแรก)
    foreach (['users' => ['full_name', 'username'], 'candidates' => ['name', 'number']] as $table => [$col, $after]) {
        if ($hasCol($table, 'first_name')) continue;
        $pdo->exec("ALTER TABLE $table
            ADD first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER $after,
            ADD last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name");
        $pdo->exec("UPDATE $table SET
            first_name = SUBSTRING_INDEX(TRIM($col), ' ', 1),
            last_name  = IF(LOCATE(' ', TRIM($col)) > 0, TRIM(SUBSTRING(TRIM($col), LOCATE(' ', TRIM($col)) + 1)), '')");
        $pdo->exec("ALTER TABLE $table DROP COLUMN $col");
        $pdo->exec("ALTER TABLE $table ADD $col VARCHAR(201) AS (TRIM(CONCAT(first_name, ' ', last_name))) STORED AFTER last_name");
        $done[] = "แยกชื่อ-นามสกุลในตาราง $table";
    }

    // 2) รองรับหลายตำแหน่ง: ผู้สมัครและคะแนนเดิมทั้งหมดย้ายไปอยู่ตำแหน่งแรก
    if (!$hasCol('candidates', 'position_id')) {
        $first = (int)$pdo->query('SELECT id FROM positions ORDER BY sort_order, id LIMIT 1')->fetchColumn();
        if (!$first) {
            $pdo->exec("INSERT INTO positions (title, seats, max_choices, sort_order) VALUES ('ประธานกรรมการดำเนินการ', 1, 1, 1)");
            $first = (int)$pdo->lastInsertId();
        }
        $pdo->exec('ALTER TABLE candidates ADD position_id INT NULL AFTER id');
        $pdo->exec("UPDATE candidates SET position_id = $first");
        $pdo->exec('ALTER TABLE candidates MODIFY position_id INT NOT NULL');
        if ($hasIndex('candidates', 'number')) $pdo->exec('ALTER TABLE candidates DROP INDEX number');
        $pdo->exec('ALTER TABLE candidates
            ADD UNIQUE KEY uq_position_number (position_id, number),
            ADD CONSTRAINT fk_candidates_position FOREIGN KEY (position_id) REFERENCES positions (id)');
        $done[] = 'เพิ่มตำแหน่งให้ผู้สมัคร (ผู้สมัครเดิมอยู่ในตำแหน่งแรก)';
    }
    if (!$hasCol('votes', 'position_id')) {
        $first = (int)$pdo->query('SELECT id FROM positions ORDER BY sort_order, id LIMIT 1')->fetchColumn();
        $pdo->exec('ALTER TABLE votes ADD position_id INT NULL AFTER id');
        $pdo->exec("UPDATE votes v LEFT JOIN candidates c ON c.id = v.candidate_id SET v.position_id = COALESCE(c.position_id, $first)");
        $pdo->exec('ALTER TABLE votes MODIFY position_id INT NOT NULL,
            ADD KEY idx_position (position_id),
            ADD CONSTRAINT fk_votes_position FOREIGN KEY (position_id) REFERENCES positions (id)');
        $done[] = 'เพิ่มตำแหน่งให้บัตรลงคะแนนเดิม';
    }

    // 3) บันทึกการยอมรับข้อกำหนดและเงื่อนไข
    if (!$hasCol('users', 'terms_accepted_at')) {
        $pdo->exec('ALTER TABLE users ADD terms_accepted_at DATETIME NULL AFTER voted_at');
        $done[] = 'เพิ่มการบันทึกการยอมรับข้อกำหนดและเงื่อนไข';
    }

    // 4) เข้าสู่ระบบด้วยเลขสมาชิก + เลขบัตรประชาชน + วันเกิด
    if (!$hasCol('users', 'member_no')) {
        $pdo->exec('ALTER TABLE users
            ADD member_no VARCHAR(20) NULL UNIQUE AFTER username,
            ADD birth_date DATE NULL AFTER member_no');
        $done[] = 'เพิ่มเลขสมาชิกและวันเกิดของผู้มีสิทธิ์';
    }
    return $done;
}

/** ข้อมูลตัวอย่าง (ชื่อบุคคลทั้งหมดเป็นชื่อสมมติ) */
function sample_data(): array
{
    return [
        'settings' => [
            'election_title' => 'การเลือกตั้งประธานกรรมการและกรรมการดำเนินการ สหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด ประจำปี 2570',
            'election_desc'  => 'ขอเชิญสมาชิกร่วมใช้สิทธิ์เลือกตั้งประธานกรรมการและกรรมการดำเนินการ เพื่อร่วมกำหนดทิศทางการบริหารสหกรณ์ให้มั่นคง โปร่งใส และเป็นประโยชน์ต่อสมาชิกทุกคน',
        ],
        // title, seats, max_choices, sort_order, candidates[number, first, last, สังกัด, วิสัยทัศน์, นโยบาย, สี]
        'positions' => [
            ['ประธานกรรมการดำเนินการ', 1, 1, 1, [
                [1, 'ประเสริฐ', 'วงศ์สุวรรณ', 'สำนักบริหารงานกลาง', 'มั่นคง โปร่งใส ตรวจสอบได้',
                    "รักษาอัตราเงินปันผลและเงินเฉลี่ยคืนให้อยู่ในระดับที่เหมาะสมและยั่งยืน\nเปิดเผยรายงานฐานะการเงินรายไตรมาสให้สมาชิกตรวจสอบได้\nทบทวนระเบียบว่าด้วยเงินกู้ให้ทันสมัยและเป็นธรรม", '#6A0099'],
                [2, 'วิไลวรรณ', 'ศรีสมบูรณ์', 'สำนักกฎหมาย', 'สวัสดิการทั่วถึง ดูแลสมาชิกทุกช่วงวัย',
                    "เพิ่มทุนสวัสดิการกรณีเจ็บป่วยและเกษียณอายุราชการ\nจัดตั้งกองทุนช่วยเหลือสมาชิกที่ประสบภัยพิบัติ\nขยายสิทธิ์สวัสดิการให้ครอบคลุมคู่สมรสและบุตร", '#1F4E79'],
                [3, 'ธนกฤต', 'จันทร์เพ็ญ', 'สำนักตรวจสอบภายใน', 'บริการทันสมัย เข้าถึงง่าย',
                    "พัฒนาระบบยื่นคำขอกู้และตรวจสอบข้อมูลสมาชิกผ่านช่องทางออนไลน์\nลดระยะเวลาการพิจารณาเงินกู้สามัญ\nส่งเสริมการออมผ่านระบบหักเงินเดือนอัตโนมัติ", '#2E6B4F'],
                [4, 'สุภาพร', 'ทองมี', 'สำนักงานภาค 1', 'ลดภาระหนี้ สร้างวินัยการออม',
                    "ปรับโครงสร้างหนี้และลดอัตราดอกเบี้ยเงินกู้ฉุกเฉิน\nจัดอบรมการวางแผนการเงินส่วนบุคคลให้แก่สมาชิก\nเพิ่มผลิตภัณฑ์เงินฝากออมทรัพย์พิเศษอัตราดอกเบี้ยจูงใจ", '#8A2432'],
            ]],
            ['กรรมการดำเนินการ', 10, 10, 2, [
                [1, 'กิตติพงษ์', 'แสงทอง', 'สำนักงานภาค 2', 'บริหารด้วยข้อมูล ตัดสินใจอย่างรอบคอบ',
                    "จัดทำแผนบริหารความเสี่ยงด้านสินเชื่อ\nรายงานผลการดำเนินงานต่อสมาชิกทุกไตรมาส", '#6A0099'],
                [2, 'นันทนา', 'บุญเรือง', 'สำนักการคลังและพัสดุ', 'การเงินมั่นคง บัญชีโปร่งใส',
                    "ควบคุมค่าใช้จ่ายดำเนินงานให้อยู่ในเกณฑ์มาตรฐาน\nเร่งรัดการติดตามหนี้ค้างชำระอย่างเป็นธรรม", '#1F4E79'],
                [3, 'สมเกียรติ', 'รุ่งโรจน์', 'สำนักงานภาค 3', 'ใกล้ชิดสมาชิกทุกภูมิภาค',
                    "จัดประชุมชี้แจงสมาชิกในส่วนภูมิภาคปีละ 2 ครั้ง\nเพิ่มช่องทางรับเรื่องร้องเรียนออนไลน์", '#2E6B4F'],
                [4, 'พรทิพย์', 'ใจมั่น', 'สำนักบริหารงานบุคคล', 'ดูแลสมาชิกเหมือนครอบครัว',
                    "ขยายสวัสดิการรับขวัญบุตรและการศึกษาบุตร\nจัดตรวจสุขภาพประจำปีให้สมาชิกอาวุโส", '#8A2432'],
                [5, 'อนุชา', 'ศรีวงศ์', 'สำนักเทคโนโลยีสารสนเทศ', 'สหกรณ์ดิจิทัล บริการไร้รอยต่อ',
                    "พัฒนาระบบสมาชิกออนไลน์ให้ใช้งานผ่านมือถือได้ครบถ้วน\nยกระดับความปลอดภัยของข้อมูลสมาชิก", '#7A5C00'],
                [6, 'รัตนา', 'พึ่งบุญ', 'สำนักนโยบายและแผน', 'วางแผนระยะยาว เติบโตอย่างยั่งยืน',
                    "จัดทำแผนกลยุทธ์สหกรณ์ 5 ปี\nกระจายการลงทุนอย่างระมัดระวังตามหลักความเสี่ยง", '#3D5A80'],
                [7, 'ชัยวัฒน์', 'มีสุข', 'สำนักงานภาค 5', 'ออมง่าย กู้สะดวก',
                    "ลดขั้นตอนเอกสารการขอกู้\nเพิ่มวงเงินกู้ฉุกเฉินตามความสามารถชำระหนี้", '#5B3A70'],
                [8, 'ศิริพร', 'แก้วประเสริฐ', 'สำนักประชาสัมพันธ์', 'สื่อสารชัดเจน สมาชิกมีส่วนร่วม',
                    "เผยแพร่มติคณะกรรมการให้สมาชิกทราบภายใน 7 วัน\nจัดทำจดหมายข่าวสหกรณ์รายเดือน", '#22577A'],
                [9, 'ณัฐพล', 'อินทรสุวรรณ', 'สำนักบริหารงานกลาง', 'ธรรมาภิบาลนำการบริหาร',
                    "ทบทวนระเบียบให้สอดคล้องกับกฎหมายสหกรณ์ฉบับปัจจุบัน\nส่งเสริมการตรวจสอบภายในที่เป็นอิสระ", '#6B4226'],
                [10, 'มาลัย', 'ศรีทอง', 'สำนักงานภาค 4', 'สหกรณ์เพื่อสมาชิกทุกคน',
                    "จัดตั้งศูนย์บริการสมาชิกในส่วนภูมิภาค\nเพิ่มวงเงินสวัสดิการผู้พิการและผู้ดูแล", '#6A0099'],
                [11, 'ปิยะพงษ์', 'วิเศษสุข', 'สำนักกฎหมาย', 'ระเบียบชัดเจน ปฏิบัติเป็นธรรม',
                    "ปรับปรุงคู่มือระเบียบให้สมาชิกเข้าใจง่าย\nเปิดรับฟังความคิดเห็นก่อนแก้ไขระเบียบสำคัญ", '#1F4E79'],
                [12, 'จันทร์เพ็ญ', 'สุขเจริญ', 'สำนักงานภาค 6', 'ออมวันนี้ มั่นคงวันหน้า',
                    "ส่งเสริมการออมเพื่อการเกษียณ\nจัดกิจกรรมให้ความรู้ทางการเงินแก่สมาชิกรุ่นใหม่", '#2E6B4F'],
            ]],
            ['ผู้ตรวจสอบกิจการ', 1, 1, 3, [
                [1, 'วรวุฒิ', 'ธรรมรักษ์', 'สำนักตรวจสอบภายใน', 'ตรวจสอบอย่างอิสระ รายงานอย่างตรงไปตรงมา',
                    "ตรวจสอบการดำเนินงานและการเงินของสหกรณ์ทุกเดือน\nรายงานผลการตรวจสอบต่อที่ประชุมใหญ่อย่างครบถ้วน", '#3D5A80'],
                [2, 'สุนีย์', 'พงษ์ไพบูลย์', 'สำนักการคลังและพัสดุ', 'บัญชีถูกต้อง ทุกบาทตรวจสอบได้',
                    "สุ่มตรวจเอกสารการให้สินเชื่อและการจัดซื้อจัดจ้าง\nติดตามการแก้ไขข้อสังเกตของผู้สอบบัญชี", '#8A2432'],
                [3, 'ธีรวัฒน์', 'บุญมา', 'สำนักงานภาค 2', 'ป้องกันก่อนเกิดความเสียหาย',
                    "ประเมินความเสี่ยงการดำเนินงานรายไตรมาส\nเสนอแนวทางปรับปรุงระบบควบคุมภายใน", '#7A5C00'],
            ]],
        ],
        // ผู้มีสิทธิ์ตัวอย่าง: เลขบัตรประชาชน => [เลขสมาชิก, ชื่อ, นามสกุล, วันเกิด (ค.ศ.)]
        'voters' => [
            '1101700203450' => ['000101', 'ผู้มีสิทธิ์', 'ทดสอบหนึ่ง', '1985-03-15'],
            '3100600123450' => ['000102', 'ผู้มีสิทธิ์', 'ทดสอบสอง', '1979-11-02'],
        ],
    ];
}

/** ใส่ตำแหน่งและผู้สมัครตัวอย่าง (เฉพาะตำแหน่งที่ยังไม่มี ตามชื่อตำแหน่ง) คืนจำนวนผู้สมัครที่เพิ่ม */
function seed_positions(PDO $pdo, bool $withCandidates = true): int
{
    $added = 0;
    $find = $pdo->prepare('SELECT id FROM positions WHERE title = ?');
    $addPos = $pdo->prepare('INSERT INTO positions (title, seats, max_choices, sort_order) VALUES (?, ?, ?, ?)');
    $addCand = $pdo->prepare('INSERT IGNORE INTO candidates (position_id, number, first_name, last_name, party, slogan, policy, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (sample_data()['positions'] as [$title, $seats, $max, $sort, $cands]) {
        $find->execute([$title]);
        $pid = (int)$find->fetchColumn();
        if (!$pid) {
            $addPos->execute([$title, $seats, $max, $sort]);
            $pid = (int)$pdo->lastInsertId();
        }
        if (!$withCandidates) continue;
        foreach ($cands as $c) {
            $addCand->execute([$pid, ...$c]);
            $added += $addCand->rowCount();
        }
    }
    return $added;
}
