/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Exam editor: sections with fixed questions and random picks, a bank picker,
 * marks, ordering, "answer any five" (section.choose) and a matching question's pairs for this
 * exam (item.pairs, edited in its preview). The result is sent as JSON in #sections-json.
 */
(function () {
    'use strict';

    const form = document.getElementById('exam-form');
    const root = document.getElementById('sections');
    if (!form || !root) return;
    const data = JSON.parse(document.getElementById('sections-data').textContent);
    const locked = root.dataset.locked === '1';
    const names = data.names || {};
    const typeLabels = data.types || {};
    let sections = data.sections && data.sections.length ? data.sections : [{ title: '', shuffle: true, items: [] }];

    const picker = document.getElementById('picker');
    let pickerTarget = 0;
    let pickerMode = 'bank';
    const openPreviews = new Set();   // questions whose preview is open; it stays open while its pairs are edited

    function el(tag, attrs, children) {
        const e = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') e.className = v;
            else if (k === 'text') e.textContent = v;
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
            else if (v !== false && v !== null && v !== undefined) e.setAttribute(k, v === true ? '' : v);
        });
        (children || []).forEach((c) => c && e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
        return e;
    }

    /** The marks of every question a section gives a student (a random pick, once per question). */
    function sectionMarks(section) {
        const marks = [];
        section.items.forEach((it) => {
            const n = it.random ? Math.max(1, Number(it.random.count) || 1) : 1;
            for (let i = 0; i < n; i++) marks.push(Number(it.mark || 0));
        });
        return marks;
    }

    /** "Answer any N" of this section (0 = every question), never more than it offers. */
    function sectionChoose(section) {
        const offered = sectionMarks(section).length;
        const choose = Math.max(0, Math.min(offered, Number(section.choose) || 0));
        return choose >= offered ? 0 : choose;
    }

    /** The most a section can give: with "answer any N", its N largest marks (the best answers count). */
    function sectionMax(section) {
        const marks = sectionMarks(section);
        const choose = sectionChoose(section);
        const best = choose > 0 ? marks.slice().sort((a, b) => b - a).slice(0, choose) : marks;
        return best.reduce((a, b) => a + b, 0);
    }

    function totals() {
        let count = 0;
        let need = 0;
        let marks = 0;
        sections.forEach((s) => {
            const offered = sectionMarks(s).length;
            const choose = sectionChoose(s);
            count += offered;
            need += choose > 0 ? choose : offered;
            marks += sectionMax(s);
        });
        document.getElementById('sections-total').textContent = `${count} question${count === 1 ? '' : 's'} per student`
            + (need !== count ? `, ${need} to answer` : '') + `, ${+marks.toFixed(2)} marks in total`;
        const railCount = document.getElementById('rail-count');
        if (railCount) railCount.textContent = count || '';
    }

    function move(list, i, delta) {
        const j = i + delta;
        if (j < 0 || j >= list.length) return;
        [list[i], list[j]] = [list[j], list[i]];
        render();
    }

    function render() {
        root.innerHTML = '';
        sections.forEach((section, si) => {
            const title = el('input', { type: 'text', value: section.title || '', placeholder: `Section ${si + 1} title (optional, e.g. "Choose the right answer")`, disabled: locked });
            title.addEventListener('input', () => { section.title = title.value; });
            const shuffle = el('input', { type: 'checkbox', checked: section.shuffle !== false, disabled: locked });
            shuffle.addEventListener('change', () => { section.shuffle = shuffle.checked; });

            // "Answer any five": students choose which of this section's questions to answer
            const offered = sectionMarks(section).length;
            const choose = sectionChoose(section);
            const chooseInput = el('input', {
                type: 'number', min: '0', max: String(offered), step: '1', class: 'mark-input', disabled: locked,
                value: choose ? String(choose) : '', placeholder: 'all', 'aria-label': 'How many of these questions a student answers',
                title: 'Leave empty for "every question". 5 means "Answer any five" \u2014 the best five a student answers count.',
            });
            chooseInput.addEventListener('input', () => { section.choose = Number(chooseInput.value) || 0; totals(); });
            chooseInput.addEventListener('change', render);

            const list = el('ul', { class: 'qlist' });
            const icon = (name) => Exams.icon(name, 16);
            section.items.forEach((item, ii) => {
                const mark = el('input', { type: 'number', min: '0', step: '0.5', value: String(item.mark ?? 1), class: 'mark-input', disabled: locked, title: 'Marks', 'aria-label': 'Marks' });
                mark.addEventListener('input', () => { item.mark = Number(mark.value); totals(); });
                let label;
                if (item.random) {
                    const r = item.random;
                    label = el('span', { class: 'qrow-name' }, [
                        el('span', { class: 'chip type-badge t-description' }, [Exams.icon('dice', 14), 'Random']), ' ',
                        `${r.count} from ${r.category}${r.sub ? ' (+ sub-folders)' : ''}` +
                        (r.types && r.types.length ? ` · ${r.types.map((t) => typeLabels[t] || t).join(', ')}` : '') +
                        (r.tags && r.tags.length ? ` · tag ${r.tags.join(', ')}` : ''),
                    ]);
                } else {
                    const info = names[item.q] || { name: '(question not found)', type: 'description' };
                    label = el('span', { class: 'qrow-name', title: info.category || '' }, [
                        el('span', { class: `chip type-badge t-${info.type}`, text: typeLabels[info.type] || info.type }), ' ', info.name,
                    ]);
                }
                // The exam keeps its own copy; the bank may have changed or dropped the question since
                let state = null;
                if (item.q && item.refresh) {
                    state = el('span', { class: 'badge badge-ok', text: 'New version on save' });
                } else if (item.q && (item.pairs || (names[item.q] && names[item.q].state === 'edited'))) {
                    state = el('span', { class: 'badge badge-info', title: 'The pairs differ from the question bank' }, [
                        'Pairs edited for this exam',
                        locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost', title: 'All the bank\'s pairs again, without your own', onclick: () => { item.refresh = true; delete item.pairs; render(); } }, ['Use bank version']),
                    ]);
                } else if (item.q && names[item.q] && names[item.q].state === 'changed') {
                    state = el('span', { class: 'badge badge-warn', title: 'The question was changed in the bank after it was added to this exam' }, [
                        'Changed in bank',
                        locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: () => { item.refresh = true; delete item.pairs; render(); } }, ['Use new version']),
                    ]);
                } else if (item.q && names[item.q] && names[item.q].state === 'gone') {
                    state = el('span', { class: 'badge', title: 'The exam keeps its own copy', text: 'No longer in bank' });
                }
                const preview = item.q
                    ? el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost preview-toggle', title: 'Preview', 'aria-expanded': 'false', 'data-question': item.q, 'data-exam': item.refresh ? null : root.dataset.exam, 'data-pairs': item.pairs ? JSON.stringify(item.pairs) : null }, [Exams.icon('chevron-right', 16)])
                    : el('span', { class: 'preview-spacer' });
                const row = el('li', { class: 'qrow' }, [
                    el('div', { class: 'qrow-head' }, [
                        el('span', { class: 'qrow-num', text: String(ii + 1) }),
                        preview,
                        label,
                        item.q && names[item.q] && names[item.q].label ? el('span', { class: 'qrow-label', text: names[item.q].label, title: names[item.q].label }) : null,
                        state,
                        mark, el('span', { class: 'small muted', text: 'marks' + (item.random ? ' each' : '') }),
                        locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost', title: 'Up', onclick: () => move(section.items, ii, -1) }, [icon('arrow-up')]),
                        locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost', title: 'Down', onclick: () => move(section.items, ii, 1) }, [icon('arrow-down')]),
                        locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost btn-danger', title: 'Remove', onclick: () => { section.items.splice(ii, 1); render(); } }, [icon('x')]),
                    ]),
                ]);
                if (item.q) preview.examItem = item;
                list.appendChild(row);
            });

            const block = el('div', { class: 'section-block' }, [
                el('div', { class: 'section-bar' }, [
                    el('strong', { text: `Section ${si + 1}` }),
                    el('div', { class: 'grow' }, [title]),
                    offered > 1 ? el('label', { class: 'choose-field', title: 'Optional questions: students answer only some of them' },
                        ['Answer any ', chooseInput, ` of ${offered}`]) : null,
                    el('label', { class: 'check' }, [shuffle, ' Shuffle']),
                    locked || sections.length < 2 ? null : el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost', title: 'Move section up', onclick: () => move(sections, si, -1) }, [icon('arrow-up')]),
                    locked || sections.length < 2 ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost btn-danger', onclick: () => { sections.splice(si, 1); render(); } }, [icon('trash'), 'Remove section']),
                ]),
                section.items.length ? list : el('p', { class: 'muted small mt-8', text: 'No questions in this section yet.' }),
                choose > 0 ? el('p', { class: 'small mt-8 choose-note' }, [
                    Exams.icon('check-circle', 14),
                    ` Students answer any ${choose} of these ${offered} questions; the best ${choose} count (${+sectionMax(section).toFixed(2)} marks).`
                    + (new Set(sectionMarks(section)).size > 1 ? ' These questions carry different marks, so the highest ones make the maximum.' : ''),
                ]) : null,
                locked ? null : el('div', { class: 'section-add' }, [
                    el('button', { type: 'button', class: 'btn btn-sm', onclick: () => openPicker(si, 'bank') }, [icon('plus'), 'Add from question bank']),
                    el('button', { type: 'button', class: 'btn btn-sm', onclick: () => openPicker(si, 'random') }, [icon('dice'), 'Add a random pick']),
                ]),
            ]);
            root.appendChild(block);
        });
        if (!locked) {
            root.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost mt-12', onclick: () => { sections.push({ title: '', shuffle: true, items: [] }); render(); } }, [Exams.icon('plus', 16), 'Add another section']));
        }
        totals();
        root.querySelectorAll('.preview-toggle').forEach((b) => { if (openPreviews.has(b.dataset.question)) b.click(); });
    }

    // ---------- A matching question's pairs, in its preview ----------
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('.preview-toggle');
        if (!btn) return;
        if (btn.getAttribute('aria-expanded') === 'true') openPreviews.delete(btn.dataset.question);
        else openPreviews.add(btn.dataset.question);
    });

    /**
     * A bin before each pair and a row to add a pair of your own. The preview shows item.pairs (or the
     * question's own pairs), so row i is pair i of the list. A Match question keeps at least 2 pairs.
     */
    root.addEventListener('preview-loaded', (e) => {
        const item = e.target.examItem;
        const table = e.detail.box.querySelector('table.qv-match');
        if (!item || !table || locked) return;
        const rows = Array.from(table.querySelectorAll('tr[data-pair]'));
        const list = item.pairs || rows.map((r) => ({ keep: Number(r.dataset.pair) }));
        if (rows.length !== list.length) return;
        const real = rows.filter((r) => !r.hasAttribute('data-extra')).length;
        const change = (next, realAfter) => {
            if (Number(item.mark) === real) item.mark = realAfter;   // 1 mark a pair (7 for 7) follows the pairs
            item.pairs = next;
            render();
        };
        const texts = (col) => rows.map((r) => r.children[col].textContent.trim().toLowerCase());
        const lefts = texts(0);
        const answers = texts(2);
        rows.forEach((row, i) => {
            const extra = row.hasAttribute('data-extra');
            const last = !extra && real <= 2;
            const bin = el('button', {
                type: 'button', class: 'btn btn-sm btn-icon btn-ghost btn-danger', disabled: last,
                title: last ? 'A Match question needs at least 2 pairs' : 'Remove from this exam (the question bank keeps it)',
                onclick: () => change(list.filter((_, j) => j !== i), real - (extra ? 0 : 1)),
            }, [Exams.icon('trash', 14)]);
            row.insertBefore(el('td', { class: 'qv-bin' }, [bin]), row.firstChild);
        });

        const left = el('input', { type: 'text', maxlength: '200', placeholder: 'Left side, e.g. Speaker', 'aria-label': 'Left side' });
        const right = el('input', { type: 'text', maxlength: '200', placeholder: 'Right side, e.g. Plays sound', 'aria-label': 'Right side' });
        const note = el('p', { class: 'small muted', text: 'Changes are for this exam only; the question bank keeps its pairs. Save to keep them.' });
        const add = () => {
            const q = left.value.trim().replace(/\s+/g, ' ');
            const a = right.value.trim().replace(/\s+/g, ' ');
            if (!q || !a) { (q ? right : left).focus(); return; }
            if (lefts.includes(q.toLowerCase())) { note.textContent = `"${q}" is already on the left side.`; note.classList.add('qv-bad'); return; }
            if (answers.includes(a.toLowerCase())) { note.textContent = `"${a}" is already an answer.`; note.classList.add('qv-bad'); return; }
            change(list.concat([{ question: q, answer: a }]), real + 1);
        };
        [left, right].forEach((input) => input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); add(); } }));
        table.after(el('div', { class: 'pair-add' }, [
            left, el('span', { class: 'qv-arrow', text: '→' }), right,
            el('button', { type: 'button', class: 'btn btn-sm', onclick: add }, [Exams.icon('plus', 14), 'Add pair']),
        ]), note);
    });

    // ---------- Picker ----------
    const $ = (id) => document.getElementById(id);
    const typeBox = $('rand-types');
    Object.entries(typeLabels).forEach(([type, label]) => {
        if (type === 'description') return;
        typeBox.appendChild(el('label', { class: 'check' }, [el('input', { type: 'checkbox', value: type }), ` ${label}`]));
    });

    function openPicker(si, mode) {
        pickerTarget = si;
        pickerMode = mode;
        $('picker-bank').hidden = mode !== 'bank';
        $('picker-random').hidden = mode !== 'random';
        $('picker-title').textContent = mode === 'bank' ? 'Add questions from the bank' : 'Add a random pick';
        picker.hidden = false;
        (mode === 'bank' ? $('pick-folder') : $('rand-folder')).focus();
    }
    picker.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => { picker.hidden = true; }));

    async function loadBank() {
        const list = $('pick-list');
        list.innerHTML = '<li class="muted small">Loading…</li>';
        const r = await Exams.api('api/bank_list.php', { category: $('pick-folder').value, sub: $('pick-sub').checked, q: $('pick-search').value });
        list.innerHTML = '';
        if (!r.ok || !r.questions.length) {
            list.appendChild(el('li', { class: 'muted small', text: r.ok ? 'No questions found.' : (r.error || 'Could not load.') }));
            return;
        }
        const already = new Set(sections.flatMap((s) => s.items.map((i) => i.q)).filter(Boolean));
        r.questions.forEach((q) => {
            names[q.id] = { name: q.name, type: q.type, category: q.category, label: q.label, state: 'same' };
            list.appendChild(el('li', { class: 'qrow' }, [
                el('div', { class: 'qrow-head' }, [
                    el('input', { type: 'checkbox', class: 'q-pick', value: q.id, disabled: already.has(q.id), title: already.has(q.id) ? 'Already in this exam' : '' }),
                    el('button', { type: 'button', class: 'btn btn-sm btn-icon btn-ghost preview-toggle', title: 'Preview', 'aria-expanded': 'false', 'data-question': q.id }, [Exams.icon('chevron-right', 16)]),
                    el('span', { class: `chip type-badge t-${q.type}`, text: typeLabels[q.type] || q.type }),
                    el('span', { class: 'qrow-name', title: q.text, text: q.name }),
                ]),
                el('div', { class: 'qrow-body small muted' }, [el('span', { class: 'mono', text: q.category }), ` · ${q.label}`]),
            ]));
        });
        if (r.truncated) list.appendChild(el('li', { class: 'muted small', text: 'Showing the first 500. Choose a smaller folder or search.' }));
    }
    $('pick-load').addEventListener('click', loadBank);
    [$('pick-folder'), $('pick-search')].forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); loadBank(); } }));

    $('picker-add').addEventListener('click', () => {
        const section = sections[pickerTarget];
        if (pickerMode === 'bank') {
            $('pick-list').querySelectorAll('input.q-pick:checked').forEach((c) => section.items.push({ q: c.value, mark: 1 }));
        } else {
            const category = $('rand-folder').value.trim();
            if (!category) { $('rand-folder').focus(); return; }
            section.items.push({
                random: {
                    category, sub: $('rand-sub').checked, count: Math.max(1, Number($('rand-count').value) || 1),
                    types: Array.from(typeBox.querySelectorAll('input:checked')).map((i) => i.value),
                    tags: $('rand-tags').value.split(/[;,]/).map((t) => t.trim()).filter(Boolean),
                },
                mark: Number($('rand-mark').value) || 1,
            });
        }
        picker.hidden = true;
        render();
    });

    form.addEventListener('submit', () => {
        document.getElementById('sections-json').value = JSON.stringify(sections);
    });

    render();

    /* Schedule: live summary like "Today, 9:00 AM – 11:30 AM" (same wording as the server) */
    const schedule = document.getElementById('schedule');
    const summary = document.getElementById('schedule-summary');
    if (schedule && summary) {
        const field = (name) => schedule.querySelector(`[name="${name}"]`);
        const clock = (d) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase().replace(/\s+/g, ' ');
        const day = (d) => {
            const key = (x) => x.toDateString();
            const today = new Date();
            const shift = (n) => { const x = new Date(today); x.setDate(x.getDate() + n); return x; };
            if (key(d) === key(today)) return 'Today';
            if (key(d) === key(shift(1))) return 'Tomorrow';
            if (key(d) === key(shift(-1))) return 'Yesterday';
            return d.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: d.getFullYear() !== today.getFullYear() ? 'numeric' : undefined }).replace(',', '');
        };
        const at = (date, time, fallback) => (date ? new Date(`${date}T${time || fallback}`) : null);
        const update = () => {
            const openDate = field('open_date').value;
            const closeDate = field('close_date').value || (field('close_time').value ? openDate : '');
            const open = at(openDate, field('open_time').value, '00:00');
            const close = at(closeDate, field('close_time').value, '23:59');
            let text = 'Any time';
            if (open && close) {
                text = close <= open ? 'Ends before it starts'
                    : open.toDateString() === close.toDateString() ? `${day(open)}, ${clock(open)} – ${clock(close)}`
                    : `${day(open)}, ${clock(open)} – ${day(close)}, ${clock(close)}`;
            } else if (open) {
                text = `From ${day(open)}, ${clock(open)}`;
            } else if (close) {
                text = `Until ${day(close)}, ${clock(close)}`;
            } else if (field('close_time').value) {
                text = 'Choose a date';
            }
            summary.textContent = text;
            summary.parentElement.classList.toggle('is-warn', text === 'Ends before it starts' || text === 'Choose a date');
        };
        schedule.addEventListener('input', update);
    }

    /* Section list on the left: marks the part of the form that is on screen */
    const rail = document.querySelector('.section-rail');
    if (rail) {
        const links = Array.from(rail.querySelectorAll('a[href^="#"]'));
        const parts = links.map((a) => document.getElementById(a.getAttribute('href').slice(1)));
        let queued = false;
        const mark = () => {
            queued = false;
            let current = 0;
            parts.forEach((p, i) => { if (p && p.getBoundingClientRect().top <= 160) current = i; });
            if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) current = parts.length - 1;
            links.forEach((a, i) => a.classList.toggle('active', i === current));
        };
        window.addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(mark); } }, { passive: true });
        mark();
    }
})();
