/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Small shared helpers for all pages.
 */
(function () {
    'use strict';

    const meta = (name) => (document.querySelector(`meta[name="${name}"]`) || {}).content || '';

    /** POST JSON to an app-relative API path; resolves to the parsed JSON body. */
    async function api(path, body, opts = {}) {
        const res = await fetch(meta('app-base') + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': meta('csrf') },
            body: JSON.stringify(body || {}),
            credentials: 'same-origin',
            keepalive: !!opts.keepalive,
        });
        let data = null;
        try { data = await res.json(); } catch (e) { data = { ok: false, error: 'bad_response' }; }
        data.httpStatus = res.status;
        return data;
    }

    /** A drawn icon from assets/icons.svg (the sprite is in every page). */
    function icon(name, size = 18) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'ic');
        svg.setAttribute('width', size);
        svg.setAttribute('height', size);
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(ns, 'use');
        use.setAttribute('href', '#i-' + name);
        svg.appendChild(use);
        return svg;
    }

    /* Two-step confirm without browser dialogs: first click arms, second click submits. */
    document.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-confirm]');
        if (!btn) return;
        if (btn.dataset.armed === '1') return;
        ev.preventDefault();
        btn.dataset.armed = '1';
        const label = btn.innerHTML;
        btn.textContent = btn.dataset.confirm;
        btn.classList.add('btn-danger');
        setTimeout(() => {
            btn.dataset.armed = '';
            btn.innerHTML = label;
        }, 4000);
    });

    /* A button that shows or hides a panel: <button data-toggle="panel-id"> */
    document.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-toggle]');
        if (!btn) return;
        const panel = document.getElementById(btn.dataset.toggle);
        if (!panel) return;
        panel.hidden = !panel.hidden;
        btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        const first = panel.querySelector('input:not([type=hidden]), select, textarea');
        if (!panel.hidden && first) first.focus();
    });

    /* Names on hover, focus or tap: <span data-names='["Aril","Belo"]'>. One floating box, so table scrolling never clips it. */
    let tip = null;
    let tipFor = null;
    function showNames(el) {
        let names = [];
        try { names = JSON.parse(el.dataset.names || '[]'); } catch (e) { return; }
        if (!names.length) return;
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'names-tip';
            tip.setAttribute('role', 'tooltip');
            tip.id = 'names-tip';
            document.body.appendChild(tip);
        }
        tip.textContent = '';
        const list = document.createElement('ul');
        list.className = names.length > 10 ? 'two-cols' : '';
        names.forEach((n) => { const li = document.createElement('li'); li.textContent = n; list.appendChild(li); });
        tip.append(list);
        tip.hidden = false;
        tipFor = el;
        el.setAttribute('aria-describedby', 'names-tip');
        const r = el.getBoundingClientRect();
        const t = tip.getBoundingClientRect();
        const above = r.top - 6 - t.height >= 0;   // above the chip; below only when there is no room
        tip.style.top = `${above ? r.top - 6 - t.height : r.bottom + 6}px`;
        tip.style.left = `${Math.max(6, Math.min(r.left, window.innerWidth - t.width - 6))}px`;
    }
    function hideNames() {
        if (tip) tip.hidden = true;
        if (tipFor) tipFor.removeAttribute('aria-describedby');
        tipFor = null;
    }
    document.addEventListener('pointerover', (ev) => {
        const el = ev.target.closest('[data-names]');
        if (el && el !== tipFor) showNames(el);
    });
    document.addEventListener('pointerout', (ev) => {
        if (tipFor && !tipFor.contains(ev.relatedTarget) && ev.pointerType === 'mouse') hideNames();
    });
    document.addEventListener('focusin', (ev) => {
        const el = ev.target.closest && ev.target.closest('[data-names]');
        if (el) showNames(el); else hideNames();
    });
    document.addEventListener('focusout', (ev) => {
        if (tipFor && !tipFor.contains(ev.relatedTarget)) hideNames();
    });
    document.addEventListener('click', (ev) => {
        if (!ev.target.closest('[data-names]')) hideNames();
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') hideNames(); });
    window.addEventListener('scroll', hideNames, true);

    window.Exams = { api, meta, icon };
})();
