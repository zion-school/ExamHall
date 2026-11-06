/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Attachment uploads (teacher/attachments.php): photos are shrunk in the browser
 * (long side 2000 px, JPEG), and every file is sent in pieces of 1 MB to
 * api/attachment_upload.php. Many files at once are matched to students by file name first.
 */
(function () {
    'use strict';
    const root = document.getElementById('attachments');
    if (!root) return;
    const exam = root.dataset.exam;
    const students = JSON.parse(root.dataset.students || '[]');
    const CHUNK = 1024 * 1024;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /** A photo as a smaller JPEG blob (PDFs and small pictures are sent as they are). */
    async function prepare(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return { blob: file, name: file.name };
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.85));
            return blob ? { blob, name: file.name.replace(/\.[^.]+$/, '') + '.jpg' } : { blob: file, name: file.name };
        } catch (e) {
            return { blob: file, name: file.name };
        }
    }

    /** Upload one file for a student in pieces; resolves to the server's final reply. */
    async function upload(file, username) {
        const { blob, name } = await prepare(file);
        const token = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => (b % 36).toString(36)).join('');
        let reply = null;
        for (let offset = 0; offset < blob.size || offset === 0; offset += CHUNK) {
            const form = new FormData();
            form.append('action', 'put');
            form.append('id', exam);
            form.append('user', username);
            form.append('name', name);
            form.append('upload', token);
            form.append('offset', String(offset));
            form.append('last', offset + CHUNK >= blob.size ? '1' : '');
            form.append('chunk', blob.slice(offset, offset + CHUNK), name);
            const res = await fetch(Exams.meta('app-base') + 'api/attachment_upload.php', {
                method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF': Exams.meta('csrf') },
            });
            try { reply = await res.json(); } catch (e) { reply = { ok: false, error: 'The server did not answer (' + res.status + ').' }; }
            if (!reply.ok) return reply;
            if (offset + CHUNK >= blob.size) break;
        }
        return reply;
    }

    // One student's "Add pages"
    root.querySelectorAll('input.add-files').forEach((input) => {
        input.addEventListener('change', async () => {
            const username = input.dataset.user;
            const status = root.querySelector(`.upload-status[data-user="${CSS.escape(username)}"]`);
            const problems = [];
            let n = 0;
            for (const file of input.files) {
                status.textContent = `Uploading ${++n} of ${input.files.length}…`;
                const r = await upload(file, username);
                if (!r.ok) problems.push(`${file.name}: ${r.error}`);
                else problems.push(...(r.problems || []));
            }
            if (problems.length) {
                status.textContent = problems.join(' ');
                setTimeout(() => location.reload(), 4000);
            } else {
                location.reload();
            }
        });
    });

    // Many files: match, review, upload
    const bulk = document.getElementById('bulk-files');
    const review = document.getElementById('bulk-review');
    const tbody = review ? review.querySelector('tbody') : null;
    let chosen = [];
    if (bulk) {
        bulk.addEventListener('change', async () => {
            chosen = Array.from(bulk.files);
            if (!chosen.length) return;
            const r = await Exams.api('api/attachment_upload.php', { action: 'match', id: exam, names: chosen.map((f) => f.name) });
            const matches = r.ok ? r.matches : {};
            tbody.innerHTML = '';
            chosen.forEach((file, i) => {
                const tr = document.createElement('tr');
                const match = matches[file.name] || { user: null, sure: false };
                const options = ['<option value="">— skip this file —</option>']
                    .concat(students.map((s) => `<option value="${esc(s.username)}" ${match.user === s.username ? 'selected' : ''}>${esc(s.name)}${s.class ? ' (Class ' + esc(s.class) + ')' : ''}</option>`));
                const badge = !match.user ? ' <span class="badge badge-warn">choose</span>' : (match.sure ? '' : ' <span class="badge badge-warn" title="Matched by a similar spelling">check</span>');
                tr.innerHTML = `<td>${esc(file.name)} <span class="small muted">${Math.max(1, Math.round(file.size / 1024))} KB</span></td>
                    <td><select data-i="${i}">${options.join('')}</select>${badge}</td>
                    <td class="small" data-status="${i}"></td>`;
                tbody.appendChild(tr);
            });
            review.hidden = false;
            const found = Object.values(matches).filter((m) => m.user).length;
            const unsure = Object.values(matches).filter((m) => m.user && !m.sure).length;
            document.getElementById('bulk-note').textContent = `${found} of ${chosen.length} files matched by name${unsure ? ` (${unsure} by a similar spelling: check them)` : ''}.`;
        });
        document.getElementById('bulk-upload').addEventListener('click', async (e) => {
            e.target.disabled = true;
            let failed = 0;
            for (let i = 0; i < chosen.length; i++) {
                const username = tbody.querySelector(`select[data-i="${i}"]`).value;
                const cell = tbody.querySelector(`[data-status="${i}"]`);
                if (!username) { cell.textContent = 'skipped'; continue; }
                cell.textContent = 'uploading…';
                const r = await upload(chosen[i], username);
                if (!r.ok) { failed++; cell.textContent = 'Failed: ' + r.error; cell.className = 'small msg-error'; continue; }
                cell.textContent = r.problems && r.problems.length ? r.problems.join(' ') : `Added ${r.added} page${r.added === 1 ? '' : 's'}`;
                cell.className = r.problems && r.problems.length ? 'small msg-warning' : 'small good';
                if (r.problems && r.problems.length) failed++;
            }
            document.getElementById('bulk-note').textContent = failed ? `${failed} file(s) had problems; see the list.` : 'Done. Reloading…';
            if (!failed) setTimeout(() => location.reload(), 1200);
            e.target.disabled = false;
        });
    }
})();
