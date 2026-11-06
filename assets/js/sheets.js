/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Lays out result sheets on A4 pages (teacher/result_sheets.php).
 * Each .blk block goes on the current page, or starts a new one; a block taller than a page
 * is split between its .part children. Later pages get a running header, every page a footer
 * "Page n of m". With double-sided printing, a student whose pages end on the front of a
 * sheet gets a blank page, so the next student starts on a sheet of their own.
 */
(function () {
    'use strict';
    const source = document.getElementById('sheet-source');
    const out = document.getElementById('sheets');
    const status = document.getElementById('sheet-status');
    const printBtn = document.getElementById('sheet-print');
    if (!source || !out) return;

    const duplex = source.dataset.duplex === '1';
    const appName = source.dataset.app || '';

    const el = (tag, cls, text) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    };
    const overflows = (body) => body.scrollHeight > body.clientHeight + 1;

    function newPage(student, first) {
        const page = el('div', 'sheet');
        const top = el('div', 'sheet-top');
        if (!first) {
            top.append(el('span', 'top-name', student.dataset.name), el('span', 'top-meta', student.dataset.meta));
        }
        const body = el('div', 'sheet-body');
        const foot = el('div', 'sheet-foot');
        page.append(top, body, foot);
        out.appendChild(page);
        return { page, body, foot };
    }

    /** Pages of one student; returns [pages, blank pages added]. */
    function layoutStudent(student) {
        const pages = [newPage(student, true)];
        let cur = pages[0];
        const next = () => {
            cur = newPage(student, false);
            pages.push(cur);
        };
        for (const block of Array.from(student.children)) {
            if (block.classList.contains('full-page')) {
                if (cur.body.children.length) next();
                cur.page.classList.add('has-full');
                cur.body.appendChild(block);
                next();
                continue;
            }
            cur.body.appendChild(block);
            if (!overflows(cur.body)) continue;
            if (cur.body.children.length > 1) {
                cur.body.removeChild(block);
                next();
                cur.body.appendChild(block);
            }
            // Still too tall for a whole page: continue the block on the next pages, part by part
            let piece = block;
            while (overflows(cur.body) && piece.children.length > 1) {
                const rest = block.cloneNode(false);
                rest.classList.add('cont');
                while (overflows(cur.body) && piece.children.length > 1) {
                    rest.prepend(piece.lastElementChild);
                }
                next();
                cur.body.appendChild(rest);
                piece = rest;
            }
        }
        // A page left empty after a full-page block
        if (pages.length > 1 && !cur.body.children.length) {
            cur.page.remove();
            pages.pop();
        }
        const count = pages.length;
        pages.forEach((p, i) => {
            p.foot.append(el('span', '', `${student.dataset.name} · ${student.dataset.meta}`), el('span', '', `Page ${i + 1} of ${count}`));
        });
        let blank = 0;
        if (duplex && count % 2 === 1 && student.nextElementSibling) {
            const b = el('div', 'sheet blank');
            out.appendChild(b);
            blank = 1;
        }
        return [count, blank];
    }

    function waitForImages() {
        const imgs = Array.from(source.querySelectorAll('img'));
        return Promise.all(imgs.map((img) => (img.complete ? Promise.resolve() : new Promise((ok) => {
            img.addEventListener('load', ok, { once: true });
            img.addEventListener('error', ok, { once: true });
        }))));
    }

    async function run() {
        source.classList.add('measuring');
        if (document.fonts && document.fonts.ready) await document.fonts.ready;
        await waitForImages();
        let pages = 0;
        let blanks = 0;
        const students = Array.from(source.querySelectorAll('section.student'));
        for (const student of students) {
            const [n, b] = layoutStudent(student);
            pages += n;
            blanks += b;
        }
        source.remove();
        if (status) {
            const total = pages + blanks;
            status.textContent = students.length
                ? `${students.length} student${students.length === 1 ? '' : 's'} · ${total} page${total === 1 ? '' : 's'}${blanks ? ` (${blanks} blank)` : ''} · A4${duplex ? ` · ${Math.ceil(total / 2)} sheets two-sided` : ''}`
                : 'Nothing to print';
        }
        if (printBtn) {
            printBtn.disabled = !students.length;
            printBtn.addEventListener('click', () => window.print());
        }
        document.title = document.title + (appName ? ` · ${appName}` : '');
    }

    const form = document.getElementById('sheet-options');
    if (form) form.addEventListener('change', () => form.submit());
    run();
})();
