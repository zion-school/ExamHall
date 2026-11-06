/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Shared lab computers: answers typed while offline stay in localStorage. On the
 * login page and the student home page, send any copies that the server has not
 * received yet (using that attempt's own token), then remove finished ones.
 */
(function () {
    'use strict';
    let store;
    try { store = window.localStorage; } catch (e) { return; }
    if (!store) return;
    const WEEK = 7 * 24 * 3600 * 1000;
    const keys = [];
    for (let i = 0; i < store.length; i++) {
        const k = store.key(i);
        if (k && k.startsWith('exams:v1:')) keys.push(k);
    }
    keys.forEach(async (key) => {
        let copy;
        try { copy = JSON.parse(store.getItem(key)); } catch (e) { store.removeItem(key); return; }
        if (!copy || !copy.token || !copy.save_url) { store.removeItem(key); return; }
        if (Date.now() - (copy.saved || 0) > WEEK) { store.removeItem(key); return; }
        if ((copy.ack || 0) >= (copy.seq || 0)) return;   // nothing unsent
        try {
            const res = await fetch(copy.save_url, {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                body: JSON.stringify({
                    exam: copy.exam, user: copy.user, n: copy.n, token: copy.token, epoch: copy.epoch,
                    seq: copy.seq, answers: copy.answers, flags: copy.flags, events: copy.events, final: false,
                }),
            }).then((r) => r.json());
            if (res.ok) {
                copy.ack = Math.max(copy.ack || 0, res.ack || 0);
                store.setItem(key, JSON.stringify(copy));
            }
            if (['finished', 'time_over', 'bad_token', 'not_found'].includes(res.error)) {
                store.removeItem(key);
            }
            // other_device: the student reopened the exam; their page restores this copy
        } catch (e) { /* still offline: try next time */ }
    });
})();
