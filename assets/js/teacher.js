/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Shared behaviour for staff pages: question previews under a row, select-all boxes,
 * import commit selection and the question bank bulk bar.
 */
(function () {
    'use strict';

    // Preview arrow on a row: <button class="preview-toggle" data-question="<key>"> (a bank question; with data-exam
    // the exam's own copy; data-pairs: matching pairs chosen in the exam editor), or data-import + data-key (a question in
    // an import). A loaded preview fires "preview-loaded" on the button.
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.preview-toggle');
        if (!btn) return;
        e.preventDefault();
        const row = btn.closest('.qrow') || btn.parentElement;
        const open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        let box = row.querySelector(':scope > .qrow-preview');
        if (box && !box.dataset.failed) {
            box.hidden = !open;
            return;
        }
        if (box) box.remove();
        if (!open) return;
        box = document.createElement('div');
        box.className = 'qrow-preview muted small';
        box.textContent = 'Loading…';
        row.appendChild(box);
        const req = btn.dataset.question ? { question: btn.dataset.question, exam: btn.dataset.exam || undefined } : { import: btn.dataset.import, key: Number(btn.dataset.key) };
        if (btn.dataset.pairs) req.pairs = JSON.parse(btn.dataset.pairs);   // matching pairs chosen in the exam editor
        const r = await Exams.api('api/preview.php', req);
        if (r.ok) {
            box.classList.remove('muted', 'small');
            box.innerHTML = r.html;
            btn.dispatchEvent(new CustomEvent('preview-loaded', { bubbles: true, detail: { box } }));
        } else {
            box.textContent = r.error || 'Preview failed.';
            box.dataset.failed = '1';
        }
    });

    // "Select all in this file"
    document.addEventListener('change', (e) => {
        if (!e.target.classList.contains('select-all')) return;
        const scope = e.target.closest('details, .card, form') || document;
        scope.querySelectorAll('input.q-pick:not(:disabled)').forEach((c) => { c.checked = e.target.checked; });
        updateCommitCount();
        updateBulkBar();
    });
    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('q-pick')) {
            updateCommitCount();
            updateBulkBar();
        }
    });

    // Import review: unticked questions are sent as "skip"
    const commitForm = document.getElementById('commit-form');
    function updateCommitCount() {
        const count = document.getElementById('commit-count');
        if (!commitForm || !count) return;
        count.textContent = commitForm.querySelectorAll('input.q-pick:checked').length;
    }
    if (commitForm) {
        commitForm.addEventListener('submit', () => {
            const skip = Array.from(commitForm.querySelectorAll('input.q-pick:not(:checked):not(:disabled)')).map((c) => c.value);
            document.getElementById('skip-keys').value = skip.join(',');
            const btn = document.getElementById('commit-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }
        });
    }

    // Question bank: bulk actions for ticked questions
    const bulk = document.getElementById('bulkbar');
    function updateBulkBar() {
        if (!bulk) return;
        const ids = Array.from(document.querySelectorAll('.qlist input.q-pick:checked')).map((c) => c.value);
        bulk.classList.toggle('show', ids.length > 0);
        bulk.querySelector('.bulk-count').textContent = ids.length;
        bulk.querySelector('input[name=ids]').value = ids.join(',');
    }
})();
