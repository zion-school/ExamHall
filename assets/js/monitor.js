/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Live exam monitor: refreshes every 10 seconds and runs teacher actions.
 */
(function () {
    'use strict';
    const root = document.getElementById('monitor');
    if (!root) return;
    const exam = root.dataset.exam;
    const reward = root.dataset.reward === '1';
    const tbody = document.querySelector('#mon-table tbody');

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const mmss = (sec) => {
        if (sec === null || sec === undefined) return '—';
        if (sec <= 0) return 'time up';
        const m = Math.floor(sec / 60);
        return m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m}:${String(sec % 60).padStart(2, '0')}`;
    };
    const ago = (iso) => {
        if (!iso) return '';
        const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
        const d = new Date(iso);
        const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return s < 60 ? `${s}s ago` : s < 3600 ? `${Math.round(s / 60)} min ago`
            : s < 18 * 3600 ? time : `${d.toLocaleDateString([], { day: '2-digit', month: 'short' })}, ${time}`;
    };

    async function call(body) {
        const r = await Exams.api('api/monitor.php', Object.assign({ exam }, body || {}));
        if (!r.ok) {
            document.getElementById('mon-updated').textContent = r.error || 'Could not refresh.';
            return;
        }
        draw(r);
    }

    function draw(r) {
        document.getElementById('mon-updated').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const counts = { writing: 0, online: 0, offline: 0, paused: 0, done: 0, not: 0, marking: 0, unsubmitted: 0 };
        tbody.innerHTML = '';
        r.rows.forEach((row) => {
            let status;
            if (row.status === 'in_progress' && row.paused) {
                counts.paused++;
                status = '<span class="badge badge-warn" title="The connection was lost; the time left stands still until it is back">Paused</span>';
            } else if (row.status === 'in_progress' && row.idle) {
                counts.unsubmitted++;
                status = '<span class="badge badge-warn">Not submitted</span>';
            } else if (row.status === 'in_progress') {
                counts.writing++;
                row.online ? counts.online++ : counts.offline++;
                status = row.online ? '<span class="badge badge-ok"><span class="dot dot-ok"></span>Writing</span>' : '<span class="badge badge-warn"><span class="dot dot-warn"></span>Offline</span>';
            } else if (row.status === 'not_started') {
                counts.not++;
                status = '<span class="badge">Not started</span>';
            } else {
                counts.done++;
                if (row.needs_marking) counts.marking++;
                status = row.needs_marking ? '<span class="badge badge-info">Needs marking</span>' : '<span class="badge badge-ok">Finished</span>';
            }
            const notes = [];
            if (row.late) notes.push('Late save');
            if (row.blur > 2) notes.push(`Left the page ${row.blur}×`);
            if (row.paused_sec >= 60) notes.push(`Time paused ${Math.round(row.paused_sec / 60)} min`);
            else if (row.offline_ms > 60000) notes.push(`Offline ${Math.round(row.offline_ms / 60000)} min`);
            if (row.attempts > 1) notes.push(`${row.attempts} tries`);
            const actions = [];
            if (row.status === 'in_progress') {
                if (row.seconds_left !== null) actions.push(`<button class="btn btn-sm" data-act="extend" data-user="${esc(row.username)}">+10 min</button>`);
                actions.push(`<a class="btn btn-sm" href="${Exams.meta('app-base')}teacher/mark.php?id=${encodeURIComponent(exam)}&user=${encodeURIComponent(row.username)}&n=${row.n}">View</a>`);
                actions.push(`<button class="btn btn-sm btn-ghost" data-act="submit" data-user="${esc(row.username)}" data-n="${row.n}" data-confirm="Click again to submit">Submit now</button>`);
            } else if (reward) {
                actions.push(`<button class="btn btn-sm" data-act="unlock-user" data-user="${esc(row.username)}">Unlock a try</button>`);
            }
            const progress = row.questions ? `<span class="progress-cell"><span class="progress"><span style="width:${Math.round(100 * row.answered / row.questions)}%"></span></span><span class="small">${row.answered}/${row.questions}</span></span>` : '';
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><strong>${esc(row.name)}</strong><span class="sub">${esc(row.username)}${row.class ? ' · Class ' + esc(row.class) : ''}</span></td>
                <td>${status}${row.status === 'in_progress' ? `<span class="sub">${ago(row.last_seen)}</span>` : ''}</td>
                <td>${progress}</td>
                <td class="nowrap">${mmss(row.seconds_left)}</td>
                <td class="small">${notes.map(esc).join('<br>')}</td>
                <td class="num">${row.percent === null ? '' : row.percent + '%'}${row.so_far ? '<span class="sub">so far</span>' : ''}</td>
                <td><div class="row-actions">${actions.join('')}</div></td>`;
            tbody.appendChild(tr);
        });
        const cells = [
            ['is-ok', counts.online, 'writing and online'], ['is-warn', counts.offline, 'writing but offline'],
            ['', counts.not, 'not started'], ['is-ok', counts.done, 'finished'], ['is-warn', counts.marking, 'need marking'],
        ].concat(counts.paused ? [['is-warn', counts.paused, 'paused (connection lost)']] : [])
            .concat(counts.unsubmitted ? [['is-warn', counts.unsubmitted, 'not submitted']] : []);
        const stats = document.getElementById('mon-stats');
        stats.className = `card summary summary-${cells.length}`;
        stats.innerHTML = cells.map(([cls, n, label]) => `<div class="summary-cell ${n ? cls : 'is-zero'}"><span><b>${n}</b><span>${label}</span></span></div>`).join('');

        const list = document.getElementById('unlock-list');
        if (list) {
            list.innerHTML = r.unlocks.length ? '' : '<li class="msg-info">No unlock is active. Students see the exam as locked.</li>';
            r.unlocks.forEach((u) => {
                const li = document.createElement('li');
                li.className = 'msg-info';
                li.innerHTML = `Unlocked for ${u.scope === 'class' ? 'Class ' + esc(u.target) : esc(u.target)} by ${esc(u.by_name)} — ${u.minutes_left} min left
                    <button class="btn btn-sm btn-ghost" data-act="end-unlock" data-no="${u.no}">End now</button>`;
                list.appendChild(li);
            });
        }
    }

    // Capture phase: runs before app.js arms two-step buttons, so the first click only arms them
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b || (b.dataset.confirm && b.dataset.armed !== '1')) return;
        const act = b.dataset.act;
        if (act === 'extend') call({ action: 'extend', user: b.dataset.user, minutes: 10 });
        if (act === 'submit') call({ action: 'submit', user: b.dataset.user, n: Number(b.dataset.n) });
        if (act === 'unlock-user') call({ action: 'unlock', scope: 'user', target: b.dataset.user, minutes: Number(document.getElementById('unlock-minutes')?.value || 40) });
        if (act === 'end-unlock') call({ action: 'end_unlock', no: Number(b.dataset.no) });
    }, true);
    const classBtn = document.getElementById('unlock-class-btn');
    if (classBtn) {
        classBtn.addEventListener('click', () => call({
            action: 'unlock', scope: 'class', target: document.getElementById('unlock-class').value,
            minutes: Number(document.getElementById('unlock-minutes').value || 40),
        }));
    }

    call();
    setInterval(() => { if (document.visibilityState === 'visible') call(); }, 10000);
})();
