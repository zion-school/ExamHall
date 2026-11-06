/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Grading page: run AI checking step by step while the page is open.
 */
(function () {
    'use strict';
    const card = document.getElementById('ai-card');
    const button = document.getElementById('ai-run');
    if (!card || !button) return;
    const log = document.getElementById('ai-log');
    const waiting = document.getElementById('ai-waiting');
    let running = false;

    function line(text, cls) {
        const li = document.createElement('li');
        li.className = cls || 'msg-info';
        li.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' ' + text;
        log.prepend(li);
    }

    button.addEventListener('click', async () => {
        if (running) { running = false; button.textContent = 'Stopping…'; return; }
        running = true;
        button.textContent = 'Stop';
        line('Started. The AI checks a few answers at a time.');
        while (running) {
            const r = await Exams.api('api/grade_pump.php', { exam: card.dataset.exam });
            if (!r.ok) { line(r.error || 'Stopped: server error', 'msg-warning'); break; }
            waiting.textContent = `${r.workload.pending_llm} waiting`;
            line(r.message, r.error ? 'msg-warning' : 'msg-info');
            if (r.done) { line('Finished. Reloading…'); setTimeout(() => window.location.reload(), 1200); break; }
            if (r.error) { await new Promise((res) => setTimeout(res, 10000)); }
        }
        running = false;
        button.textContent = 'Check now';
    });
})();
