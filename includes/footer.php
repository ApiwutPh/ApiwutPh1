</main>
</div>
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col">
            <h3 class="footer-title">ระบบเลือกตั้ง</h3>
            <ul class="footer-links">
                <li><a href="<?= $base ?? '' ?>index.php">หน้าหลัก</a></li>
                <li><a href="<?= $base ?? '' ?>vote.php">ลงคะแนน</a></li>
                <li><a href="<?= $base ?? '' ?>results.php">ผลคะแนน</a></li>
            </ul>
            <p><span class="footer-icon">🗓️</span>สถานะ: <?= e($statusLabel ?? '') ?></p>
            <p><span class="footer-icon">🔒</span>การลงคะแนนเป็นความลับ ระบบไม่บันทึกว่าใครเลือกผู้สมัครคนใด</p>
            <p><span class="footer-icon">ℹ️</span>ลงคะแนนได้คนละ 1 ครั้งเท่านั้น</p>
        </div>
        <div class="footer-col">
            <h3 class="footer-title">ติดต่อเรา</h3>
            <p><span class="footer-icon">🏢</span><span>สหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด<br><small class="footer-en">THE NACC SAVINGS AND CREDIT CO-OPERATIVES, LIMITED</small></span></p>
            <p><span class="footer-icon">📍</span>เลขที่ 361 ถนนนนทบุรี ตำบลท่าทราย อำเภอเมืองนนทบุรี จังหวัดนนทบุรี 11000</p>
            <p><span class="footer-icon">📞</span>02 589 3635 และ 02 528 4800 ต่อ 1117, 4430</p>
            <p><span class="footer-icon">📠</span>02 589 3635</p>
        </div>
        <div class="footer-col">
            <h3 class="footer-title">เวลาทำการ</h3>
            <p>จันทร์ - ศุกร์ เวลา 08:30 - 16:30 น.</p>
            <p><span>ปิดทำการ วันเสาร์ - อาทิตย์<br>และวันหยุดนักขัตฤกษ์</span></p>
            <p class="muted small">วันที่: <?= date('Y-m-d') ?></p>
        </div>
    </div>
    <div class="copyright">
        <div class="container">สงวนลิขสิทธิ์ พ.ศ. <?= date('Y') + 543 ?> สหกรณ์ออมทรัพย์ ป.ป.ช. จำกัด</div>
    </div>
</footer>
<script src="<?= $base ?? '' ?>assets/js/app.js"></script>
<script src="<?= $base ?? '' ?>assets/js/enhance.js" defer></script>
<?php if (!empty($extraScript)) echo $extraScript; ?>
</body>
</html>
