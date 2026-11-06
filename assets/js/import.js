/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Import page: pick or drop a folder / ZIP / GIFT files, upload them in small
 * batches (large files in chunks, with retries), then start the analysis.
 */
(function () {
    'use strict';

    const form = document.getElementById('import-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const CHUNK = parseInt(form.dataset.chunk, 10);
    const BATCH_FILES = parseInt(form.dataset.batchFiles, 10);
    const BATCH_BYTES = parseInt(form.dataset.batchBytes, 10);
    const ACCEPT = /\.(gift|txt|zip|png|jpe?g|gif|webp|svg)$/i;
    const base = Exams.meta('app-base');

    /** @type {{file: File, path: string}[]} */
    let picked = [];

    const source = () => form.querySelector('input[name=source]:checked').value;
    const pickers = { folder: $('pick-folder'), zip: $('pick-zip'), gift: $('pick-gift') };
    const titles = {
        folder: 'Click to choose a folder, or drop it here',
        zip: 'Click to choose a ZIP file, or drop it here',
        gift: 'Click to choose GIFT files (and pictures), or drop them here',
    };

    function humanSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function setPicked(list) {
        picked = list.filter((x) => ACCEPT.test(x.path) && !/(^|\/)(\.|__MACOSX)/.test(x.path));
        const ignored = list.length - picked.length;
        const gifts = picked.filter((x) => /\.(gift|txt)$/i.test(x.path)).length;
        const zips = picked.filter((x) => /\.zip$/i.test(x.path)).length;
        const pics = picked.length - gifts - zips;
        const size = picked.reduce((s, x) => s + x.file.size, 0);
        const parts = [];
        if (gifts) parts.push(`${gifts} GIFT file${gifts > 1 ? 's' : ''}`);
        if (zips) parts.push(`${zips} ZIP`);
        if (pics) parts.push(`${pics} picture${pics > 1 ? 's' : ''}`);
        $('picked').textContent = picked.length
            ? `Selected: ${parts.join(', ')} (${humanSize(size)})` + (ignored ? ` · ${ignored} other file(s) will be ignored` : '')
            : (list.length ? 'No GIFT files, ZIP files or pictures found.' : '');
        $('start').disabled = picked.length === 0;
    }

    form.querySelectorAll('input[name=source]').forEach((r) => r.addEventListener('change', () => {
        $('drop-title').textContent = titles[source()];
        form.querySelector('input[name=strip]').checked = source() === 'folder';
        setPicked([]);
    }));

    $('dropzone').addEventListener('click', () => pickers[source()].click());
    $('dropzone').addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickers[source()].click(); } });

    Object.values(pickers).forEach((input) => input.addEventListener('change', () => {
        setPicked(Array.from(input.files).map((f) => ({ file: f, path: f.webkitRelativePath || f.name })));
    }));

    // Drag and drop, including whole folders
    const dz = $('dropzone');
    ['dragenter', 'dragover'].forEach((t) => dz.addEventListener(t, (e) => { e.preventDefault(); dz.classList.add('over'); }));
    ['dragleave', 'drop'].forEach((t) => dz.addEventListener(t, () => dz.classList.remove('over')));
    dz.addEventListener('drop', async (e) => {
        e.preventDefault();
        const items = Array.from(e.dataTransfer.items || []);
        const entries = items.map((i) => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
        if (!entries.length) {
            setPicked(Array.from(e.dataTransfer.files).map((f) => ({ file: f, path: f.name })));
            return;
        }
        $('picked').textContent = 'Reading folder…';
        const out = [];
        await Promise.all(entries.map((entry) => walk(entry, out)));
        if (entries.some((en) => en.isDirectory)) {
            form.querySelector('input[name=source][value=folder]').checked = true;
        }
        setPicked(out);
    });

    function walk(entry, out) {
        return new Promise((resolve) => {
            if (entry.isFile) {
                entry.file((f) => { out.push({ file: f, path: entry.fullPath.replace(/^\//, '') }); resolve(); }, () => resolve());
            } else if (entry.isDirectory) {
                const reader = entry.createReader();
                const all = [];
                const readBatch = () => reader.readEntries(async (batch) => {
                    if (!batch.length) {
                        await Promise.all(all.map((c) => walk(c, out)));
                        resolve();
                    } else {
                        all.push(...batch);
                        readBatch();   // readEntries returns results in pages
                    }
                }, () => resolve());
                readBatch();
            } else {
                resolve();
            }
        });
    }

    /** POST FormData with retries; resolves to parsed JSON. */
    async function post(path, data, tries = 4) {
        for (let attempt = 1; ; attempt++) {
            try {
                const res = await fetch(base + path, {
                    method: 'POST', body: data, credentials: 'same-origin',
                    headers: { 'X-CSRF': Exams.meta('csrf') },
                });
                const json = await res.json();
                if (!res.ok && res.status >= 500 && attempt < tries) throw new Error('server');
                return json;
            } catch (err) {
                if (attempt >= tries) return { ok: false, error: 'Could not reach the server. Check the connection and try again.' };
                await new Promise((r) => setTimeout(r, 1000 * attempt));
            }
        }
    }

    function addError(text) {
        const li = document.createElement('li');
        li.className = 'msg-warning';
        li.textContent = text;
        $('upload-errors').appendChild(li);
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!picked.length) return;
        $('start').disabled = true;
        form.querySelectorAll('input').forEach((i) => { i.disabled = true; });
        $('upload-status').hidden = false;
        $('upload-errors').innerHTML = '';

        const total = picked.reduce((s, x) => s + x.file.size, 0) || 1;
        let sent = 0;
        const progress = (text) => {
            $('progress-bar').style.width = Math.min(100, Math.round((sent / total) * 100)) + '%';
            $('progress-text').textContent = text;
        };

        const start = new FormData();
        start.append('action', 'start');
        start.append('base', form.elements.base.value);
        start.append('mode', form.querySelector('input[name=mode]:checked').value);
        if (form.elements.strip.checked) start.append('strip', '1');
        const started = await post('api/import_upload.php', start);
        if (!started.ok) {
            addError(started.error || 'Could not start the import.');
            $('start').disabled = false;
            form.querySelectorAll('input').forEach((i) => { i.disabled = false; });
            return;
        }
        const id = started.id;

        // Small files in batches, large files in chunks
        let batch = new FormData();
        let batchCount = 0;
        let batchBytes = 0;
        const flush = async () => {
            if (!batchCount) return;
            batch.append('action', 'files');
            batch.append('id', id);
            const r = await post('api/import_upload.php', batch);
            (r.errors || (r.ok ? [] : [r.error])).forEach(addError);
            batch = new FormData();
            batchCount = 0;
            batchBytes = 0;
        };

        for (const item of picked) {
            if (item.file.size > CHUNK) {
                await flush();
                for (let offset = 0; offset < item.file.size; offset += CHUNK) {
                    const fd = new FormData();
                    const last = offset + CHUNK >= item.file.size;
                    fd.append('action', 'chunk');
                    fd.append('id', id);
                    fd.append('path', item.path);
                    fd.append('offset', String(offset));
                    if (last) fd.append('last', '1');
                    fd.append('chunk', item.file.slice(offset, offset + CHUNK), 'chunk');
                    const r = await post('api/import_upload.php', fd);
                    (r.errors || (r.ok ? [] : [r.error])).forEach(addError);
                    sent += Math.min(CHUNK, item.file.size - offset);
                    progress(`Uploading ${item.path} … ${humanSize(sent)} of ${humanSize(total)}`);
                    if (!r.ok) break;
                }
                continue;
            }
            if (batchCount >= BATCH_FILES || batchBytes + item.file.size > BATCH_BYTES) {
                await flush();
            }
            batch.append('files[]', item.file, item.file.name);
            batch.append('paths[]', item.path);
            batchCount++;
            batchBytes += item.file.size;
            sent += item.file.size;
            if (batchCount >= BATCH_FILES) {
                progress(`Uploading… ${humanSize(sent)} of ${humanSize(total)}`);
                await flush();
            }
        }
        await flush();
        sent = total;
        progress('Checking the questions… (this can take a minute for a large bank)');

        const res = await fetch(base + 'api/import_analyze.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': Exams.meta('csrf') },
            body: JSON.stringify({ id }),
        }).then((r) => r.json()).catch(() => ({ ok: false, error: 'The check did not finish. Open the import from “Recent imports” to try again.' }));
        if (res.ok) {
            window.location.href = res.redirect;
        } else {
            addError(res.error || 'The check failed.');
            progress('');
        }
    });
})();
