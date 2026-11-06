/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Live countdowns: <span data-countdown="2026-12-18T09:00:00+05:30" data-server-time="..." data-prefix="Opens in ">.
 * Counts with the server's clock on the browser's steady clock (lab computers are often wrong, and
 * their clock jumps when set after booting) and reloads the page at zero, so the exam card changes state.
 */
(function () {
    'use strict';
    const items = Array.from(document.querySelectorAll('[data-countdown]'));
    if (!items.length) return;

    const text = (seconds) => {
        if (seconds >= 86400) return `${Math.floor(seconds / 86400)} day${seconds >= 172800 ? 's' : ''} ${Math.floor(seconds % 86400 / 3600)} h`;
        if (seconds >= 3600) return `${Math.floor(seconds / 3600)} h ${Math.floor(seconds % 3600 / 60)} min`;
        if (seconds >= 60) return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
        return `${seconds} s`;
    };
    const server = items[0].dataset.serverTime ? new Date(items[0].dataset.serverTime).getTime() : NaN;
    const base = Number.isNaN(server) ? Date.now() : server;
    const start = performance.now();
    let reloading = false;
    const tick = () => {
        const now = base + (performance.now() - start);
        items.forEach((el) => {
            const left = Math.round((new Date(el.dataset.countdown).getTime() - now) / 1000);
            if (left <= 0) {
                if (!reloading && left > -5) {
                    reloading = true;
                    setTimeout(() => location.reload(), 1500);
                }
                return;
            }
            el.textContent = (el.dataset.prefix || '') + text(left);
        });
    };
    tick();
    setInterval(tick, 1000);
})();
