/* ===================== ลูกเล่นเสริม (progressive enhancement) =====================
 * ทุกอย่างในไฟล์นี้ "เสริม" หน้าเว็บเท่านั้น — ถ้า JavaScript ไม่ทำงาน หน้าเว็บยังใช้งานได้ครบ
 * ผู้ใช้ที่ตั้งค่า "ลดการเคลื่อนไหว" (prefers-reduced-motion) จะไม่เห็นแอนิเมชัน
 * ================================================================================ */
(function () {
    'use strict';
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => [...el.querySelectorAll(s)];
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isAdmin = location.pathname.includes('/admin/');
    const headerOffset = () => ($('.masthead')?.offsetHeight || 0) + 16;
    const scrollToEl = el => window.scrollTo({
        top: el.getBoundingClientRect().top + window.scrollY - headerOffset(),
        behavior: reduceMotion ? 'auto' : 'smooth',
    });

    /* ---------- 1) Toast: ย้ายข้อความแจ้งเตือนไปมุมขวาบน ---------- */
    (function toasts() {
        const alerts = $$('.alert[data-dismiss]');
        if (!alerts.length) return;
        const stack = document.createElement('div');
        stack.className = 'toast-stack';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.append(stack);

        alerts.forEach((al, i) => {
            const type = (al.className.match(/alert-(success|danger|warning|info)/) || [])[1] || 'info';
            const ms = type === 'danger' || type === 'warning' ? 9000 : 5000;
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<span class="toast-icon" aria-hidden="true">${{ success: '✓', danger: '!', warning: '!', info: 'i' }[type]}</span>
                <div class="toast-body"></div>
                <button type="button" class="toast-close" aria-label="ปิดข้อความ">×</button>
                <i class="toast-timer" style="animation-duration:${ms}ms"></i>`;
            $('.toast-body', toast).textContent = al.textContent.trim();
            al.remove();
            stack.append(toast);

            let left = ms, started = Date.now(), timer;
            const close = () => {
                clearTimeout(timer);
                toast.classList.add('is-leaving');
                setTimeout(() => toast.remove(), reduceMotion ? 0 : 250);
            };
            const run = () => { started = Date.now(); timer = setTimeout(close, left); };
            toast.addEventListener('mouseenter', () => { clearTimeout(timer); left -= Date.now() - started; toast.classList.add('is-paused'); });
            toast.addEventListener('mouseleave', () => { toast.classList.remove('is-paused'); run(); });
            $('.toast-close', toast).addEventListener('click', close);
            setTimeout(run, i * 150);
        });
    })();

    /* ---------- 2) นับตัวเลขขึ้นจาก 0 (สถิติ) ---------- */
    function countUp(el) {
        const raw = el.textContent.trim();
        const m = raw.match(/^([\d,]+(?:\.\d+)?)(%?)$/);
        if (!m || reduceMotion) return;
        const target = parseFloat(m[1].replace(/,/g, ''));
        if (!target) return;
        const decimals = (m[1].split('.')[1] || '').length;
        const dur = 900, t0 = performance.now();
        const fmt = v => v.toLocaleString('th-TH', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + m[2];
        const step = now => {
            const p = Math.min(1, (now - t0) / dur);
            el.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(step); else el.textContent = raw;
        };
        requestAnimationFrame(step);
    }

    /* ---------- 3) ค่อย ๆ ปรากฏเมื่อเลื่อนมาถึง + นับเลขเมื่อมองเห็น ---------- */
    (function reveal() {
        const counters = $$('.stat b, .stat-card b');
        if (!('IntersectionObserver' in window)) return;
        const io = new IntersectionObserver(entries => entries.forEach(en => {
            if (!en.isIntersecting) return;
            en.target.classList.add('is-visible');
            if (en.target.matches('.stat b, .stat-card b')) countUp(en.target);
            io.unobserve(en.target);
        }), { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        counters.forEach(el => io.observe(el));
        if (reduceMotion) return;
        const vh = window.innerHeight;
        $$('.candidate-card, .step, .card:not(.toast), .ballot-position, .result-position, .stat, .stat-card').forEach(el => {
            if (el.getBoundingClientRect().top < vh) return;   // อยู่ในจอแล้วตอนโหลด: ไม่ต้องซ่อน
            const idx = [...el.parentElement.children].indexOf(el) % 4;
            el.classList.add('reveal');
            el.style.transitionDelay = idx * 70 + 'ms';
            io.observe(el);
        });
    })();

    /* ---------- 4) ปุ่มกลับขึ้นด้านบน ---------- */
    (function backToTop() {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'to-top';
        btn.setAttribute('aria-label', 'กลับขึ้นด้านบน');
        btn.innerHTML = '<span aria-hidden="true">↑</span>';
        btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' }));
        document.body.append(btn);
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => { btn.classList.toggle('is-shown', window.scrollY > 600); ticking = false; });
        }, { passive: true });
    })();

    /* ---------- 5) นาฬิกาเวลาปัจจุบันใต้สถานะการเลือกตั้ง ---------- */
    (function clock() {
        const side = $('.org-side');
        if (!side) return;
        const el = document.createElement('span');
        el.className = 'live-clock';
        side.append(el);
        const tick = () => {
            el.textContent = 'เวลา ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' น.';
        };
        tick();
        setInterval(tick, 1000);
    })();

    /* ---------- 6) หน้าแรก: ค้นหาผู้สมัคร + ปุ่มไปยังตำแหน่ง ---------- */
    (function candidateFinder() {
        const sections = $$('.candidate-section');
        if (sections.length === 0) return;
        const bar = document.createElement('div');
        bar.className = 'finder';
        bar.innerHTML = `
            <label class="finder-search"><span class="sr-only">ค้นหาผู้สมัคร</span>
                <input type="search" placeholder="ค้นหาผู้สมัครจากชื่อ หรือสังกัด" autocomplete="off"></label>
            <nav class="finder-chips" aria-label="ไปยังตำแหน่ง"></nav>
            <p class="finder-empty muted" hidden>ไม่พบผู้สมัครที่ตรงกับคำค้น</p>`;
        sections[0].before(bar);
        const chips = $('.finder-chips', bar);
        sections.forEach(sec => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'chip';
            b.innerHTML = `${sec.dataset.title} <small></small>`;
            b.addEventListener('click', () => scrollToEl(sec));
            chips.append(b);
            sec._chip = b;
        });
        const input = $('input', bar);
        const norm = s => s.toLowerCase().replace(/\s+/g, ' ').trim();
        const apply = () => {
            const q = norm(input.value);
            let any = 0;
            sections.forEach(sec => {
                const cards = $$('.candidate-card', sec);
                let n = 0;
                cards.forEach(c => {
                    const hit = !q || norm(c.textContent).includes(q);
                    c.hidden = !hit;
                    if (hit) { n++; c.classList.add('is-visible'); }
                });
                sec.hidden = n === 0;
                $('small', sec._chip).textContent = q ? n : cards.length;
                sec._chip.disabled = n === 0;
                any += n;
            });
            $('.finder-empty', bar).hidden = any > 0;
        };
        input.addEventListener('input', apply);
        apply();
    })();

    /* ---------- 7) บัตรเลือกตั้ง: แถบความคืบหน้า + เลื่อนไปตำแหน่งถัดไป ---------- */
    (function ballotProgress() {
        const form = $('#ballotForm');
        const secs = form ? $$('.ballot-position', form) : [];
        if (!secs.length) return;
        const bar = document.createElement('nav');
        bar.className = 'ballot-progress';
        bar.setAttribute('aria-label', 'ความคืบหน้าการลงคะแนน');
        bar.innerHTML = `<span class="bp-label">ลงคะแนนแล้ว <b>0</b> / ${secs.length} ตำแหน่ง</span><span class="bp-steps"></span>`;
        secs[0].before(bar);
        const steps = $('.bp-steps', bar);
        secs.forEach((sec, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'bp-step';
            b.textContent = `${i + 1}. ${sec.dataset.title}`;
            b.addEventListener('click', () => scrollToEl(sec));
            steps.append(b);
            sec._step = b;
            sec._wasDone = sec.classList.contains('is-done');
        });
        const update = changedSec => {
            let done = 0;
            secs.forEach(sec => {
                const ok = sec.classList.contains('is-done');
                sec._step.classList.toggle('is-done', ok);
                if (ok) done++;
            });
            $('b', bar).textContent = done;
            bar.classList.toggle('is-complete', done === secs.length);
            // ตำแหน่งเพิ่งครบ (เลือกครบจำนวน หรืองดออกเสียง): เลื่อนไปตำแหน่งถัดไปที่ยังไม่ได้ลง
            if (changedSec && !changedSec._wasDone && changedSec.classList.contains('is-done')) {
                const max = Number(changedSec.dataset.max) || 1;
                const picked = $$('input:checked', changedSec).length;
                const full = $('input[data-abstain]:checked', changedSec) || picked >= max;
                const next = secs.slice(secs.indexOf(changedSec) + 1).find(s => !s.classList.contains('is-done'));
                if (full) setTimeout(() => next ? scrollToEl(next) : scrollToEl($('.ballot-actions')), 350);
            }
            secs.forEach(s => { s._wasDone = s.classList.contains('is-done'); });
        };
        // app.js อัปเดตคลาส is-done ก่อน จึงรอให้ handler นั้นทำงานเสร็จ
        form.addEventListener('change', e => setTimeout(() => update(e.target.closest('.ballot-position')), 0));
        update(null);
    })();

    /* ---------- 8) ไม่มีการใช้งานนาน: เตือนแล้วออกจากระบบ (หน้าบัตรเลือกตั้ง) ---------- */
    (function idleLogout() {
        if (!$('#ballotForm') && !$('#agreeTerms')) return;
        const IDLE_MS = 5 * 60 * 1000, GRACE_S = 60;
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.hidden = true;
        modal.innerHTML = `<div class="modal-box" role="alertdialog" aria-modal="true" aria-labelledby="idleTitle">
            <h2 id="idleTitle">ยังใช้งานอยู่หรือไม่?</h2>
            <p>ไม่มีการใช้งานมาระยะหนึ่ง เพื่อความปลอดภัยระบบจะออกจากระบบอัตโนมัติใน</p>
            <p class="idle-count"><b>${GRACE_S}</b> วินาที</p>
            <div class="modal-actions">
                <a href="logout.php" class="btn btn-ghost">ออกจากระบบ</a>
                <button type="button" class="btn btn-primary">ใช้งานต่อ</button>
            </div></div>`;
        document.body.append(modal);
        let idleTimer, countTimer;
        const reset = () => {
            if (!modal.hidden) return;              // ระหว่างแสดงคำเตือน ต้องกดปุ่มเท่านั้น
            clearTimeout(idleTimer);
            idleTimer = setTimeout(warn, IDLE_MS);
        };
        const warn = () => {
            let left = GRACE_S;
            modal.hidden = false;
            $('.btn-primary', modal).focus();
            countTimer = setInterval(() => {
                left--;
                $('.idle-count b', modal).textContent = left;
                if (left <= 0) { clearInterval(countTimer); location.href = 'logout.php'; }
            }, 1000);
        };
        $('.btn-primary', modal).addEventListener('click', () => {
            clearInterval(countTimer);
            modal.hidden = true;
            $('.idle-count b', modal).textContent = GRACE_S;
            reset();
        });
        ['pointermove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(ev =>
            window.addEventListener(ev, reset, { passive: true }));
        reset();
    })();

    /* ---------- 9) เลขสมาชิก: รับเฉพาะตัวเลข ---------- */
    $$('input[name=member_no]').forEach(inp => inp.addEventListener('input', () => {
        const v = inp.value.replace(/\D/g, '');
        if (v !== inp.value) inp.value = v;
    }));

    /* ---------- 10) หน้าผู้ดูแล: กดหัวตารางเพื่อเรียงลำดับ ---------- */
    (function sortableTables() {
        if (!isAdmin) return;
        const collator = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });
        const value = td => {
            const s = (td?.dataset.sort ?? td?.textContent ?? '').trim();
            const n = s.replace(/[,%\s]/g, '');
            return /^-?\d+(\.\d+)?$/.test(n) ? parseFloat(n) : s;
        };
        $$('table.table').forEach(table => {
            const ths = $$('thead th', table);
            ths.forEach((th, col) => {
                if (th.classList.contains('col-actions') || !th.textContent.trim()) return;
                th.classList.add('sortable');
                th.tabIndex = 0;
                th.setAttribute('aria-sort', 'none');
                const sort = () => {
                    const dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
                    ths.forEach(h => h.hasAttribute('aria-sort') && h.setAttribute('aria-sort', 'none'));
                    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
                    // เรียงภายในแต่ละ tbody (ทะเบียนผู้สมัครแบ่งกลุ่มตามตำแหน่ง) ไม่ย้ายแถวหัวกลุ่ม
                    $$('tbody', table).forEach(tb => {
                        const rows = $$(':scope > tr', tb).filter(r => !r.classList.contains('group-row') && r.cells.length === ths.length);
                        rows.sort((a, b) => {
                            const x = value(a.cells[col]), y = value(b.cells[col]);
                            return dir * (typeof x === 'number' && typeof y === 'number' ? x - y : collator.compare(String(x), String(y)));
                        }).forEach(r => tb.append(r));
                    });
                };
                th.addEventListener('click', sort);
                th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
            });
        });
    })();

    /* ---------- 11) หน้าผู้ดูแล: เตือนเมื่อออกจากหน้าโดยยังไม่บันทึกฟอร์ม ---------- */
    (function unsavedGuard() {
        if (!isAdmin) return;
        let dirty = null;
        $$('form[method=post].form').forEach(f => {
            f.addEventListener('input', () => { dirty = f; });
            f.addEventListener('change', () => { dirty = f; });
            f.addEventListener('submit', () => { dirty = null; });
        });
        window.addEventListener('beforeunload', e => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    })();
})();
