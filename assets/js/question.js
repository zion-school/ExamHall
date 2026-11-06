/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Question editor: live preview and checks while the GIFT text is typed.
 */
(function () {
    'use strict';

    const form = document.getElementById('question-form');
    if (!form) return;
    const gift = document.getElementById('gift');
    const preview = document.getElementById('preview');
    const messages = document.getElementById('preview-messages');
    const graderField = document.getElementById('grader-info-field');
    let timer = null;
    let seq = 0;

    async function refresh() {
        const mine = ++seq;
        const r = await Exams.api('api/preview.php', { gift: gift.value, category: form.elements.category.value });
        if (mine !== seq || !r.ok) return;
        preview.innerHTML = r.html || '<p class="muted">Nothing to preview yet.</p>';
        messages.innerHTML = '';
        if (r.count > 1) {
            r.messages.unshift({ level: 'error', line: 0, message: `Found ${r.count} questions; write only one here.` });
        }
        r.messages.forEach((m) => {
            const li = document.createElement('li');
            li.className = 'msg-' + m.level;
            li.textContent = (m.line ? `Line ${m.line}: ` : '') + m.message;
            messages.appendChild(li);
        });
        graderField.style.display = r.type === 'essay' ? '' : 'none';
    }

    gift.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(refresh, 350);
    });

    refresh();
})();
