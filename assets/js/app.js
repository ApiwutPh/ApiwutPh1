/* ===================== ระบบเลือกตั้งออนไลน์ ===================== */
(function () {
    'use strict';
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => [...el.querySelectorAll(s)];

    // ----- เมนูมือถือ -----
    $$('[data-toggle]').forEach(btn => btn.addEventListener('click', () => {
        $(btn.dataset.toggle)?.classList.toggle('open');
    }));

    // (ข้อความแจ้งเตือน flash แสดงเป็น toast โดย enhance.js)

    // ----- แสดง/ซ่อนรหัสผ่าน -----
    $$('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        input.type = input.type === 'password' ? 'text' : 'password';
    }));

    // ----- สีแบบ HEX: จานสี <-> ช่องพิมพ์รหัส -----
    $$('input[type=color][data-hex-for]').forEach(picker => {
        const text = document.getElementById(picker.dataset.hexFor);
        if (!text) return;
        picker.addEventListener('input', () => {
            text.value = picker.value.toUpperCase();
            text.classList.remove('invalid');
        });
        text.addEventListener('input', () => {
            let v = text.value.trim().toUpperCase();
            if (v && v[0] !== '#') v = '#' + v;
            if (/^#[0-9A-F]{3}$/.test(v)) v = '#' + [...v.slice(1)].map(c => c + c).join(''); // #ABC -> #AABBCC
            const ok = /^#[0-9A-F]{6}$/.test(v);
            text.classList.toggle('invalid', !ok && v.length >= 7);
            if (ok) picker.value = v.toLowerCase();
        });
        text.addEventListener('blur', () => {
            text.dispatchEvent(new Event('input'));
            const v = text.value.trim().toUpperCase().replace(/^([^#])/, '#$1');
            const full = /^#[0-9A-F]{3}$/.test(v) ? '#' + [...v.slice(1)].map(c => c + c).join('') : v;
            if (/^#[0-9A-F]{6}$/.test(full)) text.value = full;
        });
    });

    // ----- ฟอร์มผู้สมัคร: เปลี่ยนตำแหน่งแล้วเติมหมายเลขถัดไปของตำแหน่งนั้น -----
    $$('select[data-next-number]').forEach(sel => {
        const map = JSON.parse(sel.dataset.nextNumber || '{}');
        const num = sel.form.querySelector('input[name=number]');
        sel.addEventListener('change', () => { if (map[sel.value]) num.value = map[sel.value]; });
    });

    // ----- วันเกิดแบบพิมพ์ (พ.ศ.): ใส่ / อัตโนมัติ + แสดงวันที่ที่อ่านได้ (ฝั่งเซิร์ฟเวอร์แปลงเป็น ค.ศ. ก่อนเก็บ/เทียบ) -----
    const TH_MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $$('input[data-dob-be]').forEach(inp => {
        const hint = document.getElementById(inp.getAttribute('aria-describedby'));
        // เติม วัน(2) → เดือน(2) → ปี(4) ตามลำดับ: ตัวเลขที่เกินช่องไหลไปช่องถัดไป
        // ตัวคั่นที่ผู้ใช้พิมพ์เอง (/ - . เว้นวรรค) ปิดช่องปัจจุบันและเติม 0 ข้างหน้า เช่น 1/3/2528 → 01/03/2528
        const format = raw => {
            const max = [2, 2, 4], f = ['', '', ''];
            let i = 0;
            for (const seg of raw.split(/(\D+)/)) {
                if (seg === '') continue;
                if (/\D/.test(seg)) {
                    if (i < 2 && f[i]) { f[i] = f[i].padStart(2, '0'); i++; }
                    continue;
                }
                for (const ch of seg) {
                    while (i < 3 && f[i].length >= max[i]) i++;
                    if (i < 3) f[i] += ch;
                }
            }
            let out = f[0];
            if (f[0].length === 2) out += '/' + f[1];
            if (f[1].length === 2) out += '/' + f[2];
            return out;
        };
        const check = () => {
            hint.className = 'hint';
            hint.textContent = '';
            inp.classList.remove('valid', 'invalid');
            const m = inp.value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
            if (!m) {
                if (inp.value.replace(/\D/g, '').length >= 8) { hint.className = 'hint err'; hint.textContent = '✗ รูปแบบไม่ถูกต้อง กรุณากรอก วว/ดด/ปปปป'; }
                return;
            }
            const [d, mo, be] = [+m[1], +m[2], +m[3]];
            const ce = be - 543, dt = new Date(ce, mo - 1, d), now = new Date();
            let err = '';
            if (be < 2400) err = 'กรุณากรอกปีเป็น พ.ศ. (เช่น 2528 ไม่ใช่ 1985)';
            else if (dt.getFullYear() !== ce || dt.getMonth() !== mo - 1 || dt.getDate() !== d) err = 'ไม่มีวันที่นี้ในปฏิทิน';
            else if (dt > now || ce < now.getFullYear() - 120) err = 'ปีเกิดไม่อยู่ในช่วงที่เป็นไปได้';
            inp.classList.add(err ? 'invalid' : 'valid');
            hint.className = 'hint ' + (err ? 'err' : 'ok');
            hint.textContent = err ? '✗ ' + err : `✓ ${d} ${TH_MONTHS[mo]} พ.ศ. ${be}`;
        };
        inp.addEventListener('input', e => {
            // ระหว่างลบไม่จัดรูปแบบใหม่ เพื่อไม่ให้เคอร์เซอร์ติดอยู่ที่เครื่องหมาย /
            if (!(e.inputType || '').startsWith('delete')) inp.value = format(inp.value);
            check();
        });
        inp.addEventListener('blur', () => { inp.value = format(inp.value); check(); });
        if (inp.value) check();
    });

    // ----- ตั้งค่าแถบคำขวัญ: ตัวอย่างสด (สูตรเวลาเดียวกับ slogan_config() ใน config.php) -----
    const sloganText = $('#sloganText');
    if (sloganText) {
        const bar = $('#sloganPreview'), span = $('span', bar), mode = $('#sloganMode'), speed = $('#sloganSpeed');
        const render = () => {
            const msgs = sloganText.value.split(/\r?\n/).map(s => s.trim().replace(/\s+/g, ' ')).filter(Boolean).slice(0, 10);
            span.replaceChildren(...msgs.flatMap((m, i) => {
                const parts = [document.createTextNode(m)];
                if (i) {
                    const sep = document.createElement('i');
                    sep.className = 'slogan-sep';
                    sep.textContent = '•';
                    parts.unshift(sep);
                }
                return parts;
            }));
            if (!msgs.length) span.textContent = '(ซ่อนแถบคำขวัญ)';
            bar.classList.toggle('is-empty', !msgs.length);
            bar.classList.toggle('slogan-static', mode.value === 'static' || !msgs.length);
            bar.classList.toggle('slogan-scroll', mode.value !== 'static' && msgs.length > 0);
            const chars = msgs.join('   •   ').length;
            const factor = { slow: 1.5, normal: 1, fast: 0.6 }[speed.value] || 1;
            span.style.setProperty('--slogan-duration', (Math.max(10, 8 + chars * 0.3) * factor).toFixed(1) + 's');
            speed.disabled = mode.value === 'static';
            // เริ่มแอนิเมชันใหม่ ให้เห็นผลความเร็วทันที
            span.style.animation = 'none';
            void span.offsetWidth;
            span.style.animation = '';
        };
        [sloganText, mode, speed].forEach(el => el.addEventListener('input', render));
        render();
    }

    // ----- ยืนยันก่อนส่งฟอร์ม -----
    $$('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }));

    // ----- นับถอยหลัง -----
    const cd = $('[data-countdown]');
    if (cd) {
        const target = new Date(cd.dataset.countdown).getTime();
        const tick = () => {
            let diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
            if (diff === 0) { location.reload(); return; }
            const d = Math.floor(diff / 86400); diff %= 86400;
            const h = Math.floor(diff / 3600);  diff %= 3600;
            const m = Math.floor(diff / 60);
            const s = diff % 60;
            $('[data-d]', cd).textContent = d;
            $('[data-h]', cd).textContent = String(h).padStart(2, '0');
            $('[data-m]', cd).textContent = String(m).padStart(2, '0');
            $('[data-s]', cd).textContent = String(s).padStart(2, '0');
        };
        tick();
        setInterval(tick, 1000);
    }

    // ----- ตรวจเลขบัตรประชาชน (ฝั่ง client) -----
    function validThaiId(id) {
        if (!/^\d{13}$/.test(id)) return false;
        let sum = 0;
        for (let i = 0; i < 12; i++) sum += +id[i] * (13 - i);
        return (11 - sum % 11) % 10 === +id[12];
    }
    function formatId(v) {
        const d = v.replace(/\D/g, '').slice(0, 13);
        const parts = [d.slice(0, 1), d.slice(1, 5), d.slice(5, 10), d.slice(10, 12), d.slice(12)];
        return parts.filter(Boolean).join('-');
    }
    const cid = $('#citizenId');
    if (cid) {
        const hint = $('#idHint');
        const check = () => {
            cid.value = formatId(cid.value);
            const raw = cid.value.replace(/-/g, '');
            cid.classList.remove('valid', 'invalid');
            hint.className = 'hint';
            hint.textContent = '';
            if (raw.length === 13) {
                const ok = validThaiId(raw);
                cid.classList.add(ok ? 'valid' : 'invalid');
                hint.classList.add(ok ? 'ok' : 'err');
                hint.textContent = ok ? '✓ เลขประจำตัวถูกต้อง' : '✗ เลขประจำตัวไม่ถูกต้อง';
            }
        };
        cid.addEventListener('input', check);
        check();
    }

    // ----- ข้อกำหนดและเงื่อนไข: ปุ่มยอมรับใช้ได้เมื่อทำเครื่องหมายแล้ว -----
    const agree = $('#agreeTerms');
    if (agree) {
        const b = $('#agreeBtn');
        const sync = () => { b.disabled = !agree.checked; };
        agree.addEventListener('change', sync);
        sync();
    }

    // ----- บัตรเลือกตั้ง -----
    const ballot = $('#ballotForm');
    if (ballot) {
        const btn = $('#voteBtn');
        const modal = $('#confirmModal');
        let confirmed = false;
        const verify = setupVerification();

        // แต่ละตำแหน่ง: เลือกผู้สมัครได้ไม่เกิน data-max คน หรือ "งดออกเสียง" (อย่างใดอย่างหนึ่ง)
        const sections = $$('.ballot-position', ballot);
        const picks = sec => $$('input[type=checkbox]:not([data-abstain])', sec);
        const abstainBox = sec => $('input[data-abstain]', sec);
        const isDone = sec => abstainBox(sec).checked || picks(sec).some(i => i.checked);

        function syncSection(sec, changed) {
            const max = Number(sec.dataset.max) || 1;
            const abst = abstainBox(sec);
            if (changed === abst && abst.checked) {
                picks(sec).forEach(i => { i.checked = false; });
            } else if (changed && changed !== abst && changed.checked) {
                abst.checked = false;
                const chosen = picks(sec).filter(i => i.checked);
                // เลือกได้ 1 คน: ทำงานแบบ radio (เปลี่ยนตัวเลือกได้ทันที)
                if (max === 1) chosen.forEach(i => { if (i !== changed) i.checked = false; });
            }
            const n = picks(sec).filter(i => i.checked).length;
            const full = max > 1 && n >= max;
            picks(sec).forEach(i => {
                i.disabled = full && !i.checked;
                i.closest('.ballot-option').classList.toggle('is-locked', i.disabled);
            });
            $('[data-count]', sec).textContent = n;
            sec.classList.toggle('is-done', isDone(sec));
        }
        sections.forEach(sec => syncSection(sec, null));

        const refresh = () => {
            const need = [];
            if (verify && !verify.hasPhoto()) need.push('ถ่ายภาพใบหน้า');
            if (verify && !verify.hasGeo()) need.push('ระบุตำแหน่ง GPS');
            if (verify && !$('#consent').checked) need.push('ให้ความยินยอม');
            const left = sections.filter(s => !isDone(s)).map(s => s.dataset.title);
            if (left.length) need.push('ลงคะแนนตำแหน่ง' + left.join(', '));
            if (!sections.length) need.push('รอรายชื่อผู้สมัคร');
            btn.disabled = need.length > 0;
            const note = $('#voteNeed');
            if (note) note.textContent = need.length ? 'ยังต้อง: ' + need.join(' · ') : '';
        };
        ballot.addEventListener('change', e => {
            const sec = e.target.closest('.ballot-position');
            if (sec) syncSection(sec, e.target);
            refresh();
        });
        ballot.addEventListener('verify-change', refresh);
        refresh();

        ballot.addEventListener('submit', e => {
            if (confirmed) return;
            e.preventDefault();
            if (btn.disabled) return;
            const box = $('#confirmChoice');
            box.innerHTML = '';
            sections.forEach(sec => {
                const dt = document.createElement('dt');
                dt.textContent = sec.dataset.title;
                const dd = document.createElement('dd');
                const chosen = $$('input:checked', sec).map(i => i.dataset.label);
                dd.innerHTML = chosen.map(l => `<span>${esc(l)}</span>`).join('');
                box.append(dt, dd);
            });
            modal.hidden = false;
            $('#confirmBtn').focus();
        });

        const close = () => { modal.hidden = true; };
        $$('[data-close]', modal).forEach(b => b.addEventListener('click', close));
        modal.addEventListener('click', e => { if (e.target === modal) close(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

        $('#confirmBtn').addEventListener('click', function () {
            confirmed = true;
            this.disabled = true;
            this.textContent = 'กำลังบันทึก...';
            ballot.submit();
        });
    }

    // ----- ยืนยันตัวตน: กล้อง + GPS -----
    function setupVerification() {
        const box = $('#verifyBox');
        if (!box) return null;
        const form = box.closest('form');
        const changed = () => form.dispatchEvent(new Event('verify-change'));
        const video = $('#camVideo'), shot = $('#camShot'), hint = $('#camHint');
        const bStart = $('#camStart'), bSnap = $('#camSnap'), bRetake = $('#camRetake');
        const camStatus = $('#camStatus');
        let stream = null, photo = false, geo = false;

        const setStatus = (el, msg, ok) => { el.textContent = msg; el.className = 'hint ' + (ok === undefined ? '' : ok ? 'ok' : 'err'); };
        const mark = (id, state) => { $(id).className = state; }; // '', 'ok', 'err', 'wait'

        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || !navigator.geolocation) {
            $('#secureWarn').hidden = false;
            bStart.disabled = true;
            $('#geoRetry').disabled = true;
            mark('#chkPhoto', 'err'); mark('#chkGeo', 'err');
            return { hasPhoto: () => false, hasGeo: () => false };
        }

        const stop = () => { stream?.getTracks().forEach(t => t.stop()); stream = null; };

        async function startCamera() {
            stop();
            setStatus(camStatus, 'กำลังเปิดกล้อง...');
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false,
                });
                video.srcObject = stream;
                await video.play();
                video.hidden = false; shot.hidden = true; hint.hidden = true;
                bStart.hidden = true; bSnap.hidden = false; bRetake.hidden = true;
                setStatus(camStatus, 'จัดใบหน้าให้อยู่กลางกรอบ แล้วกด “ถ่ายภาพ”');
            } catch (err) {
                const denied = err.name === 'NotAllowedError';
                setStatus(camStatus, denied
                    ? '✗ ไม่ได้รับอนุญาตให้ใช้กล้อง — กรุณาอนุญาตที่ไอคอนกล้องบนแถบที่อยู่ แล้วลองใหม่'
                    : '✗ เปิดกล้องไม่ได้ (' + err.name + ')', false);
                mark('#chkPhoto', 'err');
            }
        }

        function snap() {
            if (!video.videoWidth) return;
            const scale = Math.min(1, 640 / video.videoWidth);
            const c = document.createElement('canvas');
            c.width = Math.round(video.videoWidth * scale);
            c.height = Math.round(video.videoHeight * scale);
            c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
            const url = c.toDataURL('image/jpeg', 0.85);
            $('#photoInput').value = url;
            shot.src = url;
            shot.hidden = false; video.hidden = true;
            stop();
            bSnap.hidden = true; bRetake.hidden = false;
            photo = true;
            mark('#chkPhoto', 'ok');
            setStatus(camStatus, '✓ ถ่ายภาพแล้ว', true);
            changed();
        }

        function retake() {
            photo = false;
            $('#photoInput').value = '';
            mark('#chkPhoto', '');
            changed();
            startCamera();
        }

        // ----- GPS: ฟังตำแหน่งต่อเนื่องแล้วเก็บค่าที่แม่นที่สุด -----
        // ค่าแรกมักมาจาก Wi-Fi/IP (คลาดเคลื่อนหลายร้อยเมตร) ชิป GPS ต้องใช้เวลาสักพักจึงจะแม่นขึ้น
        const GEO_TARGET = 20;        // แม่นถึง ±20 ม. แล้วหยุดค้นหา
        const GEO_MAX_WAIT = 45000;   // ค้นหานานสุด 45 วินาที
        const geoLimit = Number(box.dataset.geoMax) || 0; // เกณฑ์จากผู้ดูแล (0 = ไม่บังคับ)
        const geoBtn = $('#geoRetry');
        let watchId = null, geoTimer = null, best = null;

        const quality = acc =>
            acc <= 30 ? ['ดีมาก', 'ok'] : acc <= 100 ? ['ดี', 'ok'] : acc <= 500 ? ['พอใช้', 'warn'] : ['คลาดเคลื่อนสูง', 'warn'];

        function stopWatch() {
            if (watchId !== null) navigator.geolocation.clearWatch(watchId);
            clearTimeout(geoTimer);
            watchId = null;
            geoBtn.disabled = false;
            geoBtn.textContent = '📍 ค้นหาตำแหน่งใหม่';
        }

        function showGeo(searching) {
            const acc = Math.round(best.coords.accuracy);
            const [label, cls] = quality(acc);
            const tooFar = geoLimit > 0 && acc > geoLimit;
            // ระหว่างค้นหา: ยังไม่ให้ใช้ค่าหยาบ (> 100 ม.) จนกว่าจะค้นหาเสร็จ
            geo = !tooFar && (!searching || acc <= 100);
            mark('#chkGeo', geo ? cls : (searching ? 'wait' : 'err'));
            let msg = `${best.coords.latitude.toFixed(5)}, ${best.coords.longitude.toFixed(5)} · ±${acc.toLocaleString('th-TH')} ม. (${label})`;
            if (searching) msg += ' — กำลังปรับให้แม่นขึ้น...';
            else if (tooFar) msg += ` — ต้องแม่นกว่า ±${geoLimit} ม. กรุณาเปิด GPS/ตำแหน่งที่แม่นยำบนอุปกรณ์ หรือออกไปที่โล่ง แล้วค้นหาใหม่`;
            else if (acc > 100) msg += ' — แนะนำให้เปิด GPS บนมือถือ หรือย้ายไปใกล้หน้าต่าง/ที่โล่งแล้วค้นหาใหม่';
            $('#geoText').textContent = msg;
            changed();
        }

        function locate() {
            stopWatch();
            geo = false;
            best = null;
            const started = Date.now();
            mark('#chkGeo', 'wait');
            $('#geoText').textContent = 'กำลังค้นหาตำแหน่ง...';
            geoBtn.disabled = true;
            geoBtn.textContent = 'กำลังค้นหา...';
            changed();

            watchId = navigator.geolocation.watchPosition(pos => {
                if (pos.timestamp < started - 5000) return;              // ข้ามค่าเก่าที่แคชไว้
                if (best && pos.coords.accuracy >= best.coords.accuracy) return;
                best = pos;
                $('#latInput').value = pos.coords.latitude.toFixed(7);
                $('#lngInput').value = pos.coords.longitude.toFixed(7);
                $('#accInput').value = Math.round(pos.coords.accuracy);
                const done = pos.coords.accuracy <= GEO_TARGET;
                if (done) stopWatch();
                showGeo(!done);
            }, err => {
                if (best) return; // มีค่าที่ใช้ได้แล้ว ไม่ต้องแสดงข้อผิดพลาดชั่วคราว
                stopWatch();
                $('#geoText').textContent = err.code === err.PERMISSION_DENIED
                    ? 'ไม่ได้รับอนุญาต — กรุณาอนุญาตการเข้าถึงตำแหน่งแล้วกด “ค้นหาตำแหน่งใหม่”'
                    : 'หาตำแหน่งไม่ได้ กรุณาเปิด GPS/Location แล้วลองใหม่';
                mark('#chkGeo', 'err');
                changed();
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: GEO_MAX_WAIT });

            geoTimer = setTimeout(() => {
                stopWatch();
                if (best) showGeo(false);
                else {
                    $('#geoText').textContent = 'หาตำแหน่งไม่ได้ภายในเวลาที่กำหนด กรุณาลองใหม่';
                    mark('#chkGeo', 'err');
                    changed();
                }
            }, GEO_MAX_WAIT);
        }

        bStart.addEventListener('click', startCamera);
        bSnap.addEventListener('click', snap);
        bRetake.addEventListener('click', retake);
        $('#geoRetry').addEventListener('click', locate);
        window.addEventListener('pagehide', () => { stop(); stopWatch(); });
        locate();

        return { hasPhoto: () => photo, hasGeo: () => geo };
    }

    // ----- ผลคะแนน (เรียลไทม์ + กราฟโดนัท) -----
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = n => Number(n).toLocaleString('th-TH');

    const RESULT_LABEL = { elected: 'อยู่ในเกณฑ์ได้รับเลือก', tie: 'คะแนนเท่ากัน' };

    // กราฟโดนัทของ 1 ตำแหน่ง
    function drawDonut(canvas, legend, pos) {
        const dpr = window.devicePixelRatio || 1;
        const size = 220;
        canvas.width = size * dpr; canvas.height = size * dpr;
        canvas.style.width = size + 'px'; canvas.style.height = size + 'px';
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        const slices = pos.candidates.map(c => ({ label: `${c.number}. ${c.name}`, value: c.votes, color: c.color }));
        if (pos.abstain) slices.push({ label: 'งดออกเสียง', value: pos.abstain, color: '#94a3b8' });
        const total = slices.reduce((s, x) => s + x.value, 0);
        const cx = size / 2, cy = size / 2, r = size / 2 - 6, inner = r * 0.62;
        const css = getComputedStyle(document.documentElement);

        ctx.clearRect(0, 0, size, size);
        if (!total) {
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.arc(cx, cy, inner, 0, Math.PI * 2, true);
            ctx.fillStyle = css.getPropertyValue('--border'); ctx.fill();
        } else {
            let a = -Math.PI / 2;
            slices.forEach(s => {
                if (!s.value) return;
                const end = a + (s.value / total) * Math.PI * 2;
                ctx.beginPath();
                ctx.arc(cx, cy, r, a, end);
                ctx.arc(cx, cy, inner, end, a, true);
                ctx.closePath();
                ctx.fillStyle = s.color; ctx.fill();
                ctx.strokeStyle = css.getPropertyValue('--surface'); ctx.lineWidth = 2; ctx.stroke();
                a = end;
            });
        }
        ctx.fillStyle = css.getPropertyValue('--text');
        ctx.textAlign = 'center';
        ctx.font = '700 26px Sarabun, sans-serif';
        ctx.fillText(fmt(total), cx, cy + 4);
        ctx.fillStyle = css.getPropertyValue('--muted');
        ctx.font = '13px Sarabun, sans-serif';
        ctx.fillText('คะแนนรวม', cx, cy + 24);

        legend.innerHTML = slices.map(s =>
            `<li><i style="background:${esc(s.color)}"></i>${esc(s.label)}<span>${total ? (s.value * 100 / total).toFixed(1) : 0}%</span></li>`
        ).join('');
    }

    function positionHtml(pos, closed) {
        const label = r => r === 'elected' && closed ? 'ได้รับเลือก' : RESULT_LABEL[r];
        const rows = pos.candidates.map((c, i) => `
            <div class="result-row ${c.result ? 'is-' + c.result : ''}" style="--accent:${esc(c.color)}">
                <div class="result-info">
                    <span class="rank">${i + 1}</span>
                    <span class="mini-avatar">${esc(c.initials)}</span>
                    <div><b>หมายเลข ${c.number} · ${esc(c.name)}</b>
                        <small>${esc(c.party)}${c.result ? ` · <em class="result-flag">${label(c.result)}</em>` : ''}</small></div>
                    <div class="result-num"><b>${fmt(c.votes)}</b><small>${c.percent}%</small></div>
                </div>
                <div class="bar"><i style="width:0" data-w="${Math.min(100, c.percent)}"></i></div>
            </div>`).join('');
        return `
            <section class="card result-position" data-pos="${pos.id}">
                <header class="result-position-head">
                    <div><h2>${esc(pos.title)}</h2><p class="muted small">${esc(pos.rule)}</p></div>
                    <span class="tag">งดออกเสียง ${fmt(pos.abstain)}</span>
                </header>
                <div class="results-layout">
                    <div class="result-list">${rows || '<p class="muted">ยังไม่มีผู้สมัคร</p>'}</div>
                    <div class="chart-card"><canvas aria-label="แผนภูมิสัดส่วนคะแนน ${esc(pos.title)}"></canvas><ul class="legend"></ul></div>
                </div>
            </section>`;
    }

    function render(data) {
        const wrap = $('#resultPositions');
        const closed = data.status === 'closed';
        wrap.innerHTML = data.positions.map(p => positionHtml(p, closed)).join('')
            || '<p class="muted">ยังไม่มีตำแหน่งที่เปิดรับเลือกตั้ง</p>';
        data.positions.forEach(p => {
            const sec = $(`[data-pos="${p.id}"]`, wrap);
            drawDonut($('canvas', sec), $('.legend', sec), p);
        });
        requestAnimationFrame(() => requestAnimationFrame(() =>
            $$('.bar i[data-w]', wrap).forEach(b => { b.style.width = b.dataset.w + '%'; })));

        $('#sVoters').textContent = fmt(data.voters);
        $('#sTotal').textContent = fmt(data.total);
        $('#sTurnout').textContent = data.turnout + '%';
        $('#sTurnoutBar').style.width = Math.min(100, data.turnout) + '%';
        $('#updated').textContent = data.updated;
    }

    window.initResults = function (url) {
        if (!window.RESULTS) return;
        const sig = d => JSON.stringify([d.positions, d.total, d.voters, d.status]);
        let last = sig(window.RESULTS);
        render(window.RESULTS);
        if (window.RESULTS.status === 'closed') return; // ปิดหีบแล้ว ไม่ต้องอัปเดต

        setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, { cache: 'no-store' });
                if (!res.ok) return;
                const data = await res.json();
                $('#updated').textContent = data.updated;
                if (sig(data) !== last) {   // วาดใหม่เฉพาะเมื่อคะแนนเปลี่ยน
                    last = sig(data);
                    render(data);
                }
            } catch (_) { /* ออฟไลน์ชั่วคราว */ }
        }, 5000);
    };
})();
