/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Exam player.
 *  - Renders every question type; choices already come in this student's shuffled order.
 *  - Every change is kept in localStorage at once, then autosaved (debounced + heartbeat).
 *  - Saves carry a growing "seq"; the server ignores stale ones, so retries are safe.
 *  - Offline: keeps working, retries with backoff; reload/crash restores the newer local copy.
 *  - Timer follows the server clock, counted on the browser's steady clock (performance.now), not the
 *    computer's: a lab PC's clock set right after booting jumps ahead and would end the exam. At
 *    time-up answers are locked and sent as a time-up; the server submits only if its clock agrees,
 *    otherwise the exam carries on with the right time.
 *  - Lost connection: a timed exam not heard back from the server for over a minute is covered
 *    ("your time is paused") and the timer stands still; the server adds that time to the deadline
 *    at the next contact (lib/attempts.php), and the exam carries on.
 *  - Optional questions: a section can say "answer any five" (boot.sections[i].choose). The player
 *    says so on a card of its own before the section, on a band above every one of its questions,
 *    in the dot rail and on the finish screen; nothing is ever blocked and the best answers count.
 *  - "epoch": if the exam is opened on another computer/tab, this page stops.
 *  - boot.preview: a teacher's preview (teacher/preview.php): its own copy in localStorage, no problem reports.
 */
(function () {
    'use strict';

    const boot = JSON.parse(document.getElementById('p-boot').textContent);
    const player = document.getElementById('player');
    const main = document.getElementById('p-main');
    const dotsEl = document.getElementById('p-dots');
    const syncEl = document.getElementById('p-sync');
    const timerEl = document.getElementById('p-timer');
    const overlay = document.getElementById('p-overlay');
    const overlayCard = document.getElementById('p-overlay-card');
    const questions = boot.questions;
    const A = boot.attempt;
    const worksheet = boot.exam.per_page === 0;
    const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const STORE_KEY = `exams:v1:${A.user}:${boot.exam.id}:${boot.preview ? 'preview-' : ''}${A.n}`;

    const state = {
        answers: A.answers || {},
        flags: new Set(A.flags || []),
        seq: A.seq || 0,
        ack: A.seq || 0,
        events: { blur: 0, offline_ms: 0 },
        epoch: A.epoch,
        current: 0,
        finished: false,
        locked: false,
    };

    // ---------- Local copy (survives reloads, crashes and lost connections) ----------
    function storage() {
        try { return window.localStorage; } catch (e) { return null; }
    }
    function writeLocal() {
        const s = storage();
        if (!s) return;
        try {
            s.setItem(STORE_KEY, JSON.stringify({
                exam: boot.exam.id, user: A.user, n: A.n, token: A.token, epoch: state.epoch,
                seq: state.seq, ack: state.ack, answers: state.answers, flags: [...state.flags],
                events: state.events, saved: Date.now(), save_url: boot.urls.save,
            }));
        } catch (e) { /* storage full or blocked: server saves still work */ }
    }
    function clearLocal() {
        const s = storage();
        if (s) { try { s.removeItem(STORE_KEY); } catch (e) { /* ignore */ } }
    }
    (function restoreLocal() {
        const s = storage();
        if (!s) return;
        try {
            const local = JSON.parse(s.getItem(STORE_KEY) || 'null');
            if (local && local.seq > state.seq) {
                state.answers = local.answers || {};
                state.flags = new Set(local.flags || []);
                state.events = Object.assign(state.events, local.events || {});
                state.seq = local.seq;       // newer than the server copy: send it
            }
        } catch (e) { /* ignore broken copy */ }
    })();

    // ---------- Answers ----------
    const bySlot = {};
    questions.forEach((q, i) => { q.index = i; bySlot[q.slot] = q; });

    // ---------- Sections, and the ones that say "answer any five" ----------
    const sectionList = (boot.sections || []).map((s, i) => {
        const info = typeof s === 'string' ? { title: s, choose: 0 } : (s || {});
        const qs = questions.filter((q) => q.section === i && q.type !== 'description');
        const choose = Math.max(0, Math.min(qs.length, Number(info.choose) || 0));
        return { index: i, title: info.title || '', questions: qs, choose: choose >= qs.length ? 0 : choose };
    });
    const hasChoice = sectionList.some((s) => s.choose > 0);
    const sectionOf = (q) => sectionList[q.section] || { index: -1, title: '', choose: 0, questions: [] };
    const answeredIn = (s) => s.questions.filter(isAnswered).length;
    /** This question is not needed any more: its section has enough answers and this one is blank. */
    const isExtra = (q) => { const s = sectionOf(q); return s.choose > 0 && !isAnswered(q) && answeredIn(s) >= s.choose; };
    /** How many answers are still missing; a section with "any five" needs five, not all of them. */
    function missingCount() {
        return sectionList.reduce((sum, s) => sum + Math.max(0, (s.choose > 0 ? s.choose : s.questions.length) - answeredIn(s)), 0);
    }
    const sectionRule = (s) => `answer any ${s.choose} of ${s.questions.length}`;

    function getAnswer(q) {
        return state.answers[q.slot];
    }

    function isAnswered(q) {
        const v = getAnswer(q);
        if (q.type === 'description') return true;
        if (v === undefined || v === null || v === '') return false;
        if (Array.isArray(v)) return v.length > 0;
        if (typeof v === 'object') return Object.values(v).some((x) => x !== '' && x !== null && x !== undefined);
        if (typeof v === 'string') return q.type === 'essay' ? writtenText(v) !== '' : v.trim() !== '';
        return true;
    }

    function setAnswer(q, value) {
        if (state.locked) return;
        const empty = value === '' || value === null || value === undefined
            || (Array.isArray(value) && !value.length)
            || (typeof value === 'object' && !Array.isArray(value) && !Object.keys(value).length);
        if (empty) {
            delete state.answers[q.slot];
        } else {
            state.answers[q.slot] = value;
        }
        changed();
        updateDots();
        updateChooseBands();
    }

    function changed(delay) {
        state.seq++;
        writeLocal();
        scheduleSave(delay === undefined ? 1500 : delay);
        setSync('saving');
    }

    // ---------- Rendering ----------
    function el(tag, attrs, children) {
        const e = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') e.className = v;
            else if (k === 'html') e.innerHTML = v;
            else if (k === 'text') e.textContent = v;
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
            else e.setAttribute(k, v);
        });
        (children || []).forEach((c) => c && e.appendChild(c));
        return e;
    }

    /** Replace text markers (e.g. "_____" or "{#2}") inside rendered HTML with elements. */
    function replaceMarkers(root, regex, make) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        let replaced = 0;
        nodes.forEach((node) => {
            const text = node.nodeValue;
            regex.lastIndex = 0;
            if (!regex.test(text)) return;
            regex.lastIndex = 0;
            const frag = document.createDocumentFragment();
            let last = 0;
            let m;
            while ((m = regex.exec(text)) !== null) {
                frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                const made = make(m, replaced++);
                frag.appendChild(made || document.createTextNode(m[0]));
                last = m.index + m[0].length;
                if (!regex.global) break;
            }
            frag.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(frag, node);
        });
        return replaced;
    }

    /** A typed answer box. size: width in characters (from the server); it grows while typing. */
    function textInput(value, onInput, cls, numeric, size) {
        const input = el('input', {
            type: 'text', class: cls, autocomplete: 'off', autocorrect: 'off', autocapitalize: 'off', spellcheck: 'false',
            'aria-label': 'Answer',
        });
        if (numeric) input.setAttribute('inputmode', 'decimal');
        input.value = value === undefined || value === null ? '' : String(value);
        const fit = () => { if (size) input.style.width = `calc(${Math.max(size, input.value.length + 1)}ch + 18px)`; };
        fit();
        input.addEventListener('input', () => { fit(); onInput(input.value); });
        return input;
    }

    const INLINE_TAGS = ['B', 'STRONG', 'I', 'EM', 'U', 'CODE', 'SPAN', 'SUB', 'SUP', 'A', 'MARK', 'SMALL'];

    /** Keep a blank on the same line as the word before it and the punctuation after it. */
    function glueBlank(box) {
        const glue = el('span', { class: 'q-glue' });
        const prev = box.previousSibling;
        const next = box.nextSibling;
        box.parentNode.insertBefore(glue, box);
        if (prev && prev.nodeType === Node.TEXT_NODE) {
            const m = /\S+\s*$/.exec(prev.nodeValue);
            if (m) {
                prev.nodeValue = prev.nodeValue.slice(0, m.index);
                glue.appendChild(document.createTextNode(m[0]));
            }
        } else if (prev && INLINE_TAGS.includes(prev.nodeName)) {
            glue.appendChild(prev);
        }
        glue.appendChild(box);
        if (next && next.nodeType === Node.TEXT_NODE) {
            const m = /^[.,;:!?)\]'"’”%]+/.exec(next.nodeValue);
            if (m) {
                next.nodeValue = next.nodeValue.slice(m[0].length);
                glue.appendChild(document.createTextNode(m[0]));
            }
        }
    }

    /** Put a box right after the last words of the question, unless it ends with a picture, table or line break. */
    function appendAtTextEnd(root, box) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        let last = null;
        while (walker.nextNode()) {
            const n = walker.currentNode;
            if (n.nodeType === Node.TEXT_NODE ? n.nodeValue.trim() !== '' : /^(IMG|SVG|TABLE|PRE|BR|UL|OL|VIDEO|AUDIO|IFRAME|HR)$/i.test(n.nodeName)) last = n;
        }
        if (!last || last.nodeType !== Node.TEXT_NODE) return false;
        let target = last;
        while (target.parentNode !== root && INLINE_TAGS.includes(target.parentNode.nodeName)) target = target.parentNode;
        if (target === last) last.nodeValue = last.nodeValue.replace(/\s+$/, '');
        target.parentNode.insertBefore(document.createTextNode(' '), target.nextSibling);
        target.parentNode.insertBefore(box, target.nextSibling.nextSibling);
        return true;
    }

    // ---------- Written answers: the formatting toolbar ----------
    // Colours are kept as classes, never as inline styles: the server's allowlist (lib/render.php
    // StudentHtml) drops styles, and the printed answer sheet turns the classes black on a mono
    // printer. The browser writes colours its own way (<font color> or style=), so everything is
    // mapped back to a class before the answer is saved.
    const INKS = [['c-blue', '#146EB4', 'Blue'], ['c-red', '#C42B1C', 'Red'], ['c-green', '#1B7A36', 'Green'],
        ['c-purple', '#6B2FA0', 'Purple'], ['c-brown', '#8A5A00', 'Brown']];
    const HIGHLIGHTS = [['hl-yellow', '#FFF3A3', 'Yellow'], ['hl-green', '#CDF2D5', 'Green'],
        ['hl-blue', '#D6E9FB', 'Blue'], ['hl-pink', '#FBD7E4', 'Pink']];
    const INDENTS = ['in-1', 'in-2', 'in-3'];
    const KEEP_CLASSES = [...INKS.map((c) => c[0]), ...HIGHLIGHTS.map((c) => c[0]), ...INDENTS, 'al-center', 'al-right'];
    const ESSAY_LIMIT = boot.essay_limit || 20000;

    function hexOf(colour) {
        if (!colour) return '';
        const m = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(colour);
        const hex = m ? '#' + [1, 2, 3].map((i) => Number(m[i]).toString(16).padStart(2, '0')).join('') : colour;
        return hex.toUpperCase().replace(/^#(.)\1(.)\2(.)\3$/, '#$1$2$3');
    }
    function classOfColour(colour, table) {
        const hex = hexOf(colour);
        const found = table.find((c) => c[1].toUpperCase() === hex);
        return found ? found[0] : '';
    }

    /** The words of a written answer, for the word count and for "has this been answered?". */
    function writtenText(html) {
        const d = document.createElement('div');
        d.innerHTML = html;
        return (d.textContent || '').replace(/ /g, ' ').trim();
    }

    /** The answer as it is saved: colours turned into classes, and nothing the server drops. */
    function writtenHtml(editor) {
        const clone = editor.cloneNode(true);
        clone.querySelectorAll('*').forEach((node) => {
            const keep = [...node.classList].filter((c) => KEEP_CLASSES.includes(c));
            const ink = classOfColour(node.style.color || node.getAttribute('color'), INKS);
            const mark = classOfColour(node.style.backgroundColor, HIGHLIGHTS);
            const align = node.style.textAlign === 'center' ? 'al-center' : node.style.textAlign === 'right' ? 'al-right' : '';
            [ink, mark, align].forEach((c) => c && !keep.includes(c) && keep.push(c));
            node.removeAttribute('style');
            node.removeAttribute('color');
            node.removeAttribute('align');
            keep.length ? (node.className = keep.join(' ')) : node.removeAttribute('class');
        });
        const html = clone.innerHTML.replace(/<br>(\s*<\/(p|div|li|h[34])>)/gi, '$1');
        return writtenText(html) === '' ? '' : html;
    }

    /** The block (paragraph, heading or list item) the cursor is in. */
    function currentBlock(editor) {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount) return null;
        let node = sel.getRangeAt(0).startContainer;
        if (node.nodeType === Node.TEXT_NODE) node = node.parentNode;
        if (!editor.contains(node)) return null;
        while (node && node !== editor && !/^(P|DIV|LI|H3|H4|BLOCKQUOTE)$/.test(node.nodeName)) node = node.parentNode;
        return node && node !== editor ? node : null;
    }

    /** $label: letterforms (B, x²) as HTML, everything else a drawn icon from assets/icons.svg. */
    function fmtButton(label, title, onClick, cls) {
        const b = el('button', { type: 'button', class: 'q-fmt-btn' + (cls ? ' ' + cls : ''), title: title, 'aria-label': title });
        // The letterforms go in a span of their own: inside the flex button, <sub>/<sup> would
        // become flex items and lose the raised and lowered 2 of x² and H₂O.
        if (typeof label === 'string') b.innerHTML = '<span>' + label + '</span>'; else b.appendChild(label);
        // mousedown, not click: the editor must keep the selection the button works on
        b.addEventListener('mousedown', (e) => { e.preventDefault(); onClick(b); });
        return b;
    }

    /** A drawn toolbar icon (24px grid, 1.8 line, like every other icon in the app). */
    function fmtIcon(name) {
        return Exams.icon('fmt-' + name, 17);
    }

    /** The colours that open under a colour button; the one in use keeps a ring. */
    function swatchMenu(colours, chosen, onPick) {
        const menu = el('div', { class: 'q-fmt-menu', hidden: true });
        const all = [];
        const pick = (swatch, hex) => {
            all.forEach((s) => s.classList.toggle('on', s === swatch));
            onPick(hex);
            menu.hidden = true;
        };
        colours.forEach(([cls, hex, name]) => {
            const swatch = el('button', { type: 'button', class: 'q-swatch' + (hex === chosen ? ' on' : ''), title: name, 'aria-label': name });
            swatch.style.background = hex;
            swatch.addEventListener('mousedown', (e) => { e.preventDefault(); pick(swatch, hex); });
            all.push(swatch);
            menu.appendChild(swatch);
        });
        const none = el('button', { type: 'button', class: 'q-swatch none', title: 'None', 'aria-label': 'No colour', text: '✕' });
        none.addEventListener('mousedown', (e) => { e.preventDefault(); pick(none, null); });
        all.push(none);
        menu.appendChild(none);
        return menu;
    }

    /**
     * A colour button in two halves, the way a word processor draws it: pressing the letter uses the
     * colour on the bar straight away, pressing the arrow opens the colours and changes the bar.
     */
    function colourButton(label, title, colours, first, apply) {
        let chosen = first;
        const bar = el('span', { class: 'q-fmt-bar' });
        bar.style.background = chosen;
        const face = el('span', { class: 'q-fmt-stack' }, [typeof label === 'string' ? el('span', { class: 'q-fmt-a', text: label }) : label, bar]);
        const main = fmtButton(face, title, () => apply(chosen), 'q-fmt-main');
        const menu = swatchMenu(colours, chosen, (hex) => {
            if (hex) { chosen = hex; bar.style.background = hex; }
            apply(hex);
        });
        const caret = fmtButton('<svg class="ic q-fmt-caret" width="9" height="9" aria-hidden="true"><use href="#i-chevron-down"></use></svg>',
            'More Colours', () => { menu.hidden = !menu.hidden; }, 'q-fmt-caret-btn');
        return { box: el('span', { class: 'q-fmt-pick' }, [main, caret, menu]), menu: menu };
    }

    /** A written answer: the toolbar, the box the student types in, and the word count. */
    function writtenAnswer(q, value) {
        const editor = el('div', {
            class: 'q-essay', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true',
            spellcheck: 'false', 'aria-label': 'Your answer',
        });
        editor.innerHTML = value || '';
        const bar = el('div', { class: 'q-fmt', role: 'toolbar', 'aria-label': 'Change how your answer looks' });
        const count = el('div', { class: 'q-count' });
        const wrap = el('div', { class: 'q-written' }, [bar, editor, count]);

        const saved = () => {
            setAnswer(q, writtenHtml(editor));
            updateCount();
            updateButtons();
        };
        const run = (cmd, arg) => {
            editor.focus();
            try { document.execCommand(cmd, false, arg === undefined ? null : arg); } catch (e) { /* not supported */ }
            saved();
        };
        const updateCount = () => {
            const text = writtenText(editor.innerHTML);
            const words = text ? text.split(/\s+/).length : 0;
            count.textContent = `${words} word${words === 1 ? '' : 's'}`;
            count.classList.toggle('full', text.length >= ESSAY_LIMIT);
        };

        // The order and the names a word processor uses: undo first, then the size box where the font
        // box sits in WordPad, then Font, then Paragraph, then clearing. Each group wraps whole.
        const stateButtons = [];
        const menus = [];
        const group = (name) => {
            const row = el('div', { class: 'q-fmt-row' });
            bar.appendChild(el('div', { class: 'q-fmt-group' }, [row, el('span', { class: 'q-fmt-name', text: name })]));
            return row;
        };
        const add = (row, label, title, cmd, arg) => {
            const b = fmtButton(label, title, () => run(cmd, arg));
            stateButtons.push([b, cmd]);
            row.appendChild(b);
        };
        const divider = () => bar.appendChild(el('span', { class: 'q-fmt-sep' }));

        const undoRow = group('Undo');
        undoRow.appendChild(fmtButton(fmtIcon('undo'), 'Undo (Ctrl+Z)', () => run('undo')));
        undoRow.appendChild(fmtButton(fmtIcon('redo'), 'Redo (Ctrl+Y)', () => run('redo')));
        divider();

        const letters = group('Font');
        const size = el('select', { class: 'q-fmt-size', 'aria-label': 'Text Size' });
        [['p', 'Normal text'], ['h3', 'Heading'], ['h4', 'Small heading']].forEach(([tag, label]) => {
            size.appendChild(el('option', { value: tag, text: label }));
        });
        size.addEventListener('change', () => run('formatBlock', size.value));
        letters.appendChild(size);
        add(letters, '<b>B</b>', 'Bold (Ctrl+B)', 'bold');
        add(letters, '<i>I</i>', 'Italic (Ctrl+I)', 'italic');
        add(letters, '<u>U</u>', 'Underline (Ctrl+U)', 'underline');
        add(letters, '<s>abc</s>', 'Strikethrough', 'strikeThrough');
        add(letters, 'x<sub>2</sub>', 'Subscript (H₂O)', 'subscript');
        add(letters, 'x<sup>2</sup>', 'Superscript (x²)', 'superscript');
        const ink = colourButton('A', 'Text Colour', INKS, INKS[1][1], (hex) => run('foreColor', hex || '#111111'));
        const marker = colourButton(fmtIcon('marker'), 'Text Highlight Colour', HIGHLIGHTS, HIGHLIGHTS[0][1], (hex) => run('hiliteColor', hex || 'transparent'));
        letters.appendChild(ink.box);
        letters.appendChild(marker.box);
        menus.push(ink.menu, marker.menu);
        [ink, marker].forEach((c) => c.box.addEventListener('mousedown', () => {
            menus.forEach((m) => { if (m !== c.menu) { m.hidden = true; } });
        }));
        divider();

        const lines = group('Paragraph');
        add(lines, fmtIcon('list-dot'), 'Bulleted List', 'insertUnorderedList');
        add(lines, fmtIcon('list-number'), 'Numbered List', 'insertOrderedList');
        // Outside a list, indenting makes a <blockquote> the server does not keep, so a plain
        // paragraph is moved in and out with a class of its own instead.
        const indent = (by) => {
            const block = currentBlock(editor);
            if (!block || block.nodeName === 'LI' || block.closest('li')) { run(by > 0 ? 'indent' : 'outdent'); return; }
            const level = INDENTS.findIndex((c) => block.classList.contains(c)) + 1;
            const next = Math.min(INDENTS.length, Math.max(0, level + by));
            INDENTS.forEach((c) => block.classList.remove(c));
            if (next) block.classList.add(INDENTS[next - 1]);
            editor.focus();
            saved();
        };
        lines.appendChild(fmtButton(fmtIcon('outdent'), 'Decrease Indent', () => indent(-1)));
        lines.appendChild(fmtButton(fmtIcon('indent'), 'Increase Indent', () => indent(1)));
        add(lines, fmtIcon('align-left'), 'Left Align', 'justifyLeft');
        add(lines, fmtIcon('align-center'), 'Centre Align', 'justifyCenter');
        add(lines, fmtIcon('align-right'), 'Right Align', 'justifyRight');
        divider();

        const clear = group('Clear');
        clear.appendChild(fmtButton('A<span class="q-fmt-x">\u2715</span>', 'Clear Formatting', () => {
            const block = currentBlock(editor);
            if (block) INDENTS.forEach((c) => block.classList.remove(c));
            run('removeFormat');
        }));

        function updateButtons() {
            stateButtons.forEach(([b, cmd]) => {
                let on = false;
                try { on = document.queryCommandState(cmd); } catch (e) { /* not supported */ }
                b.classList.toggle('on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            const block = currentBlock(editor);
            const tag = block ? block.nodeName.toLowerCase() : 'p';
            size.value = tag === 'h3' || tag === 'h4' ? tag : 'p';
        }

        editor.addEventListener('input', saved);
        editor.addEventListener('keyup', updateButtons);
        editor.addEventListener('mouseup', updateButtons);
        editor.addEventListener('focus', updateButtons);
        editor.addEventListener('blur', () => menus.forEach((m) => { m.hidden = true; }));
        // Pasted text comes in as words only: no pictures, and no colours from somewhere else
        editor.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        editor.addEventListener('drop', (e) => e.preventDefault());
        // The server keeps 20,000 letters; stop there rather than lose the end of a long answer
        editor.addEventListener('beforeinput', (e) => {
            if (/^(delete|history|format)/.test(e.inputType)) return;
            if (writtenText(editor.innerHTML).length >= ESSAY_LIMIT) e.preventDefault();
        });
        updateCount();
        return wrap;
    }

    /** letter: A, B, C… (typed to choose); null for True/False, which the words say on their own. */
    function choiceButton(letter, html, selected, onClick, multi) {
        const b = el('button', { type: 'button', class: 'choice' + (selected ? ' selected' : '') + (multi ? ' multi' : ''), 'aria-pressed': selected ? 'true' : 'false' }, [
            letter === null ? null : el('span', { class: 'letter', text: multi ? (selected ? '✓' : letter) : letter }),
            el('span', { class: 'choice-html', html }),
        ]);
        b.addEventListener('click', onClick);
        return b;
    }

    function plainLength(html) {
        const d = document.createElement('div');
        d.innerHTML = html;
        return d.querySelector('img,svg,table') ? 999 : d.textContent.trim().length;
    }

    function renderAnswerArea(q, card, textEl) {
        const value = getAnswer(q);
        switch (q.type) {
            case 'multichoice': {
                if (!q.single) card.appendChild(el('p', { class: 'q-hint', text: 'Choose all the answers that are right.' }));
                // Cards only when the answers are pictures alone; a word with an icon stays in the list
                const pics = q.choices.every((c) => /<(svg|img)\b/i.test(c.html) && plainLength(c.html.replace(/<(svg|img)[\s\S]*?(<\/svg>|>)/gi, '')) === 0);
                // Three or more answers sit two across (2x2), so a question fits a 1024x800 screen
                const two = q.choices.length >= 3;
                const box = el('div', { class: 'q-choices' + (pics ? ' pics' : '') + (two ? ' two' : ''), role: q.single ? 'radiogroup' : 'group' });
                q.choices.forEach((c, i) => {
                    const selected = q.single ? value === c.id : Array.isArray(value) && value.includes(c.id);
                    box.appendChild(choiceButton(LETTERS[i], c.html, selected, () => {
                        if (q.single) {
                            setAnswer(q, value === c.id ? null : c.id);
                        } else {
                            const set = new Set(Array.isArray(getAnswer(q)) ? getAnswer(q) : []);
                            set.has(c.id) ? set.delete(c.id) : set.add(c.id);
                            setAnswer(q, [...set].sort((a, b) => a - b));
                        }
                        rerender(q);
                    }, !q.single));
                });
                card.appendChild(box);
                break;
            }
            case 'truefalse': {
                const box = el('div', { class: 'q-tf', role: 'radiogroup' });
                [[true, 'True'], [false, 'False']].forEach(([v, label]) => {
                    const b = choiceButton(null, label, value === v, () => { setAnswer(q, value === v ? null : v); rerender(q); });
                    box.appendChild(b);
                });
                card.appendChild(box);
                break;
            }
            case 'shortanswer':
            case 'numerical': {
                const numeric = q.type === 'numerical';
                const inline = () => textInput(value, (v) => setAnswer(q, v), 'q-inline' + (numeric ? ' num' : ''), numeric, q.size);
                let placed = false;
                if (q.missing_word) {
                    // Moodle marks the blank with exactly five underscores; longer runs are the author's own
                    placed = replaceMarkers(textEl, /(?<!_)_{5}(?!_)/, inline) > 0;
                } else {
                    // Answer written at the end ("... released in the year {=2015}"): the box follows the words
                    placed = appendAtTextEnd(textEl, inline());
                }
                if (placed) {
                    textEl.querySelectorAll('.q-inline').forEach(glueBlank);
                } else {
                    card.appendChild(el('label', { class: 'field' }, [
                        el('span', { class: 'field-label', text: numeric ? 'Your answer (a number)' : 'Your answer' }),
                        textInput(value, (v) => setAnswer(q, v), 'q-input', numeric),
                    ]));
                }
                break;
            }
            case 'essay':
                card.appendChild(writtenAnswer(q, typeof value === 'string' ? value : ''));
                break;
            case 'match': {
                const current = value && typeof value === 'object' ? value : {};
                const pics = q.stems.every((st) => /<(svg|img)\b/i.test(st.html) && plainLength(st.html.replace(/<(svg|img)[\s\S]*?(<\/svg>|>)/gi, '')) === 0);
                const box = el('div', { class: 'q-match' + (pics ? ' pics' : '') });
                q.stems.forEach((stem) => {
                    const select = el('select', { 'aria-label': 'Match' });
                    select.appendChild(el('option', { value: '', text: 'Choose…' }));
                    q.options.forEach((o) => select.appendChild(el('option', { value: String(o.id), text: o.text })));
                    if (current[stem.id] !== undefined) select.value = String(current[stem.id]);
                    select.classList.toggle('chosen', select.value !== '');
                    select.addEventListener('change', () => {
                        const next = Object.assign({}, getAnswer(q) || {});
                        if (select.value === '') delete next[stem.id]; else next[stem.id] = Number(select.value);
                        select.classList.toggle('chosen', select.value !== '');
                        setAnswer(q, next);
                    });
                    box.appendChild(el('div', { class: 'q-match-row' }, [
                        el('div', { class: 'stem', html: stem.html }), el('span', { class: 'arrow', text: '→' }), select,
                    ]));
                });
                card.appendChild(box);
                break;
            }
            case 'multiblank': {
                const current = value && typeof value === 'object' ? value : {};
                const update = (i, v) => {
                    const next = Object.assign({}, getAnswer(q) || {});
                    if (v === '' || v === null) delete next[i]; else next[i] = v;
                    setAnswer(q, next);
                };
                replaceMarkers(textEl, /\{#(\d+)\}/g, (m) => {
                    const i = Number(m[1]) - 1;
                    const blank = q.blanks[i];
                    if (!blank) return null;
                    if (blank.type === 'multichoice' || blank.type === 'truefalse') {
                        const select = el('select', { class: 'q-inline', 'aria-label': `Blank ${i + 1}` });
                        select.appendChild(el('option', { value: '', text: '…' }));
                        const opts = blank.type === 'truefalse'
                            ? [{ id: 'true', text: 'True' }, { id: 'false', text: 'False' }]
                            : blank.choices.map((c) => ({ id: String(c.id), text: c.text }));
                        opts.forEach((o) => select.appendChild(el('option', { value: o.id, text: o.text })));
                        if (current[i] !== undefined) select.value = String(current[i]);
                        select.addEventListener('change', () => {
                            const v = select.value;
                            update(i, v === '' ? null : blank.type === 'truefalse' ? v === 'true' : Number(v));
                        });
                        return select;
                    }
                    return textInput(current[i], (v) => update(i, v), 'q-inline' + (blank.type === 'numerical' ? ' num' : ''), blank.type === 'numerical', blank.size);
                });
                textEl.querySelectorAll('.q-inline').forEach(glueBlank);
                break;
            }
        }
    }

    function speak(q) {
        if (!('speechSynthesis' in window)) return;
        window.speechSynthesis.cancel();
        const d = document.createElement('div');
        d.innerHTML = q.html.replace(/_{3,}/g, ' blank ').replace(/\{#\d+\}/g, ' blank ');
        let text = d.textContent;
        (q.choices || []).forEach((c, i) => {
            const cd = document.createElement('div');
            cd.innerHTML = c.html;
            text += `. ${LETTERS[i]}: ${cd.textContent}`;
        });
        const u = new SpeechSynthesisUtterance(text);
        u.lang = /[஀-௿]/.test(text) ? 'ta-IN' : 'en-IN';
        u.rate = 0.9;
        window.speechSynthesis.speak(u);
    }

    /** "You choose: answer any 5 of these 6", with a live count, above each of the section's questions. */
    function chooseBand(q) {
        const s = sectionOf(q);
        if (!s.choose) return null;
        const done = answeredIn(s);
        const enough = done >= s.choose;
        const answered = isAnswered(q);
        const left = s.choose - done;
        return el('div', { class: 'q-choose' + (enough ? (answered ? ' ok' : ' extra') : '') }, [
            el('span', { class: 'q-choose-icon' }, [Exams.icon(enough ? 'check-circle' : 'star', 22)]),
            el('div', { class: 'q-choose-text' }, [
                el('strong', { text: `You choose: answer any ${s.choose} of these ${s.questions.length} questions` }),
                el('span', {
                    class: 'q-choose-sub',
                    text: !enough
                        ? `You have answered ${done}. ${left} more to go — pick the ones you know best.`
                        : (answered
                            ? `You have answered ${done}. That is enough — well done!`
                            : `You have already answered ${done}. This one is extra: answer it only if you want to.`),
                }),
            ]),
            enough && !answered ? el('span', { class: 'q-extra-chip', text: 'Extra' }) : null,
        ]);
    }

    /** Keep the "you have answered 2" counters right while the student works. */
    function updateChooseBands() {
        if (!hasChoice) return;
        main.querySelectorAll('.q-card[data-slot]').forEach((card) => {
            const q = bySlot[Number(card.dataset.slot)];
            const band = card.querySelector('.q-choose');
            const fresh = q && chooseBand(q);
            if (band && fresh) band.replaceWith(fresh);
        });
    }

    function renderCard(q) {
        const numbered = questions.filter((x) => x.type !== 'description');
        const number = numbered.indexOf(q) + 1;
        const flag = el('button', { type: 'button', class: 'q-tool', 'aria-pressed': state.flags.has(q.slot) ? 'true' : 'false', title: 'Mark to look at again (F)' });
        flag.textContent = state.flags.has(q.slot) ? '⚑ Look again' : '⚐ Look again';
        flag.addEventListener('click', () => toggleFlag(q));
        const report = el('div', { class: 'q-report', hidden: true });
        const tools = el('div', { class: 'q-tools' }, [
            'speechSynthesis' in window ? el('button', { type: 'button', class: 'q-tool', title: 'Read aloud', 'aria-label': 'Read aloud', onclick: () => speak(q) }, [Exams.icon('volume')]) : null,
            boot.preview ? null : el('button', { type: 'button', class: 'q-tool', title: 'Something wrong with this question?', 'aria-label': 'Something wrong with this question?', onclick: () => { report.hidden = !report.hidden; } }, [Exams.icon('alert')]),
            q.type !== 'description' ? flag : null,
        ]);
        const reportText = el('textarea', { rows: '2', placeholder: 'What is wrong with this question? (e.g. no picture, two right answers)' });
        const reportSend = el('button', { type: 'button', class: 'btn btn-sm', text: 'Tell my teacher' });
        reportSend.addEventListener('click', async () => {
            if (!reportText.value.trim()) { reportText.focus(); return; }
            reportSend.disabled = true;
            try {
                const r = await fetch(boot.urls.report, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                    body: JSON.stringify({ exam: boot.exam.id, user: A.user, n: A.n, token: A.token, slot: q.slot, text: reportText.value }),
                }).then((res) => res.json());
                report.innerHTML = '';
                report.appendChild(r.ok
                    ? el('p', { class: 'small with-icon' }, [Exams.icon('check-circle', 16), document.createTextNode('Thank you! Your teacher will check this question.')])
                    : el('p', { class: 'small', text: 'Could not send. Tell your teacher.' }));
            } catch (e) {
                reportSend.disabled = false;
            }
        });
        report.appendChild(reportText);
        report.appendChild(reportSend);
        const head = el('div', { class: 'q-head' }, [
            el('span', { class: 'q-num', text: q.type === 'description' ? 'Read this' : `Question ${number} of ${numbered.length}` }),
            q.mark > 0 ? el('span', { class: 'q-marks', text: `${+q.mark.toFixed(2)} mark${q.mark === 1 ? '' : 's'}` }) : null,
            tools,
        ]);
        const text = el('div', { class: 'q-text', html: q.html });
        const card = el('section', { class: 'q-card' + (sectionOf(q).choose ? ' q-card-choice' : ''), id: `q-${q.slot}`, 'data-slot': q.slot },
            [chooseBand(q), head, report, text]);
        renderAnswerArea(q, card, text);
        if (boot.exam.practice && q.type !== 'description' && q.type !== 'essay') {
            const result = el('div', { class: 'q-check-result', hidden: true });
            const check = el('button', { type: 'button', class: 'btn btn-accent' }, [Exams.icon('check'), document.createTextNode('Check my answer')]);
            check.addEventListener('click', async () => {
                check.disabled = true;
                try {
                    const r = await fetch(boot.urls.check, {
                        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
                        body: JSON.stringify({ exam: boot.exam.id, user: A.user, n: A.n, token: A.token, slot: q.slot, answer: getAnswer(q) ?? null }),
                    }).then((res) => res.json());
                    result.hidden = false;
                    result.className = 'q-check-result ' + (r.fraction >= 1 ? 'ok' : r.fraction > 0 ? 'part' : 'no');
                    result.innerHTML = '';
                    result.appendChild(el('strong', { text: r.ok ? r.message : 'Could not check right now.' }));
                    if (r.right_answer) result.appendChild(el('div', { class: 'small', text: 'Right answer: ' + r.right_answer }));
                    if (r.pairs) {
                        const rows = card.querySelectorAll('.q-match-row select');
                        q.stems.forEach((stem, i) => {
                            if (!rows[i]) return;
                            rows[i].classList.toggle('pair-right', r.pairs[stem.id] === true);
                            rows[i].classList.toggle('pair-wrong', r.pairs[stem.id] === false);
                        });
                        const wrong = Object.values(r.pairs).filter((v) => !v).length;
                        if (wrong) result.appendChild(el('div', { class: 'small', text: `${wrong} match${wrong > 1 ? 'es are' : ' is'} not right yet (shown in red).` }));
                    }
                    if (r.feedback) result.appendChild(el('div', { class: 'small', html: r.feedback }));
                } catch (e) {
                    result.hidden = false;
                    result.textContent = 'You seem to be offline. Try again soon.';
                } finally {
                    check.disabled = false;
                }
            });
            card.appendChild(el('div', { class: 'q-check' }, [check, result]));
        }
        return card;
    }

    function rerender(q) {
        const old = document.getElementById(`q-${q.slot}`);
        if (!old) return;
        const focusedIndex = Array.from(old.querySelectorAll('button.choice')).indexOf(document.activeElement);
        const fresh = renderCard(q);
        old.replaceWith(fresh);
        if (focusedIndex >= 0) {
            const b = fresh.querySelectorAll('button.choice')[focusedIndex];
            if (b) b.focus();
        }
        if (state.locked) lockInputs();
    }

    function render() {
        main.innerHTML = '';
        if (worksheet) {
            player.classList.add('worksheet');
            let lastSection = -1;
            questions.forEach((q) => {
                const s = sectionOf(q);
                if (q.section !== lastSection && (s.title || s.choose)) {
                    main.appendChild(el('h2', { class: 'q-card q-section-head' + (s.choose ? ' choice' : ''), style: 'padding:10px 26px;font-size:1.1rem' }, [
                        document.createTextNode(s.title || `Section ${q.section + 1}`),
                        s.choose ? el('span', { class: 'q-choose-chip', text: `You choose — ${sectionRule(s)}` }) : null,
                    ]));
                }
                lastSection = q.section;
                main.appendChild(renderCard(q));
            });
        } else {
            const q = questions[state.current];
            main.appendChild(renderCard(q));
            main.scrollTop = 0;
            document.getElementById('p-prev').disabled = state.current === 0;
            const next = document.getElementById('p-next');
            const last = state.current === questions.length - 1;
            next.replaceChildren(document.createTextNode(last ? 'Finish' : 'Next'), Exams.icon(last ? 'check' : 'arrow-right', 20));
            const s = sectionOf(q);
            document.getElementById('p-section').textContent = s.title + (s.choose ? ` · ${sectionRule(s)}` : '');
            maybeIntro(q);
        }
        updateDots();
        if (state.locked) lockInputs();
    }

    /** The first time a student reaches a section they may choose from, say so on a card of its own. */
    const introduced = new Set();
    function maybeIntro(q) {
        const s = sectionOf(q);
        if (!s.choose || introduced.has(s.index) || worksheet || state.locked || state.finished || paused || !overlay.hidden) return;
        introduced.add(s.index);
        showMessage('star', 'Now you can choose!',
            `${s.title ? s.title + ': ' : ''}there are ${s.questions.length} questions here and you answer any ${s.choose} of them. `
            + `Pick the ones you know best — your best ${s.choose} answers count.`,
            [['Show me the questions', () => { overlay.hidden = true; }, 'btn-accent']]);
    }

    function updateDots() {
        dotsEl.innerHTML = '';
        let n = 0;
        let lastSection = -1;
        questions.forEach((q, i) => {
            if (q.type === 'description') return;
            n++;
            const s = sectionOf(q);
            if (s.choose && q.section !== lastSection) {
                dotsEl.appendChild(el('span', { class: 'p-group', text: `You choose ${s.choose} of ${s.questions.length}` }));
            }
            lastSection = q.section;
            const extra = isExtra(q);
            const dot = el('button', {
                type: 'button',
                class: 'p-dot' + (isAnswered(q) ? ' answered' : '') + (s.choose ? ' optional' : '') + (extra ? ' extra' : '')
                    + (state.flags.has(q.slot) ? ' flagged' : '') + (!worksheet && i === state.current ? ' current' : ''),
                title: `Question ${n}` + (isAnswered(q) ? ' (answered)' : (extra ? ' (extra: you need not answer it)' : ''))
                    + (s.choose && !extra ? ` (${sectionRule(s)})` : '') + (state.flags.has(q.slot) ? ' (look again)' : ''),
                text: String(n),
            });
            dot.addEventListener('click', () => goTo(i));
            dotsEl.appendChild(dot);
        });
        const current = dotsEl.querySelector('.current');
        if (current) current.scrollIntoView({ block: 'nearest', inline: 'center' });
    }

    function goTo(i) {
        if (worksheet) {
            const card = document.getElementById(`q-${questions[i].slot}`);
            if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        state.current = Math.max(0, Math.min(questions.length - 1, i));
        render();
        main.focus({ preventScroll: true });
    }

    function toggleFlag(q) {
        state.flags.has(q.slot) ? state.flags.delete(q.slot) : state.flags.add(q.slot);
        changed(800);
        rerender(q);
        updateDots();
    }

    function lockInputs() {
        player.classList.add('locked');
        main.querySelectorAll('input, textarea, select, button.choice, .q-fmt button, .q-fmt select').forEach((e) => { e.disabled = true; });
        // A written answer is a contenteditable box: it has nothing to disable, so typing is turned off
        main.querySelectorAll('.q-essay').forEach((e) => e.setAttribute('contenteditable', 'false'));
    }

    document.getElementById('p-prev').addEventListener('click', () => goTo(state.current - 1));
    document.getElementById('p-next').addEventListener('click', () => {
        if (state.current === questions.length - 1) showReview(); else goTo(state.current + 1);
    });
    document.getElementById('p-finish').addEventListener('click', showReview);

    document.addEventListener('keydown', (e) => {
        if (state.locked || !overlay.hidden) return;
        const tag = (e.target.tagName || '').toLowerCase();
        // A written answer is a contenteditable box, not a textarea: while the student is typing in
        // one, "f" is a letter and the arrow keys move the cursor, not the question.
        if (['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable
            || e.ctrlKey || e.altKey || e.metaKey) return;
        if (e.key === 'ArrowRight' || e.key === 'PageDown') { if (!worksheet) { e.preventDefault(); goTo(state.current + 1); } return; }
        if (e.key === 'ArrowLeft' || e.key === 'PageUp') { if (!worksheet) { e.preventDefault(); goTo(state.current - 1); } return; }
        if (worksheet) return;
        const q = questions[state.current];
        if (e.key === 'f' || e.key === 'F') { toggleFlag(q); return; }
        const buttons = main.querySelectorAll('button.choice');
        let idx = -1;
        if (/^[1-9]$/.test(e.key)) idx = Number(e.key) - 1;
        else if (/^[a-z]$/i.test(e.key) && q.type === 'multichoice') idx = LETTERS.indexOf(e.key.toUpperCase());
        if (idx >= 0 && buttons[idx]) { e.preventDefault(); buttons[idx].click(); }
    });

    // ---------- Sync ----------
    let saveTimer = null;
    let inflight = null;
    let againAfter = false;
    let backoff = 0;
    let offlineSince = null;
    // Steady time in ms: unlike Date.now() it does not jump when the computer's clock is set
    const clock = () => performance.now();
    let serverBase = new Date(A.server_time).getTime();   // server time at clockBase
    let clockBase = clock();
    const serverNow = (at) => serverBase + (at - clockBase);
    let deadline = A.deadline ? new Date(A.deadline).getTime() : null;
    const PAUSE_AFTER_MS = 60000;   // same as ATTEMPT_PAUSE_AFTER_SEC on the server
    let lastContact = clock();   // last reply from the server
    let lastTry = 0;
    let paused = false;

    function setSync(kind, label) {
        syncEl.dataset.state = kind;
        syncEl.querySelector('.label').textContent = label || {
            saved: 'Saved', saving: 'Saving…', offline: 'Offline – kept on this computer', error: 'Not saved',
        }[kind];
    }

    function scheduleSave(delay) {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => saveNow(false), delay);
    }

    function payload(final, timeUp) {
        return {
            exam: boot.exam.id, user: A.user, n: A.n, token: A.token, epoch: state.epoch, seq: state.seq,
            answers: state.answers, flags: [...state.flags], events: state.events, final: !!final, time_up: !!timeUp,
        };
    }

    function saveNow(final, timeUp) {
        if (state.finished) return Promise.resolve(null);
        if (inflight) { againAfter = true; return inflight; }
        const body = JSON.stringify(payload(final, timeUp));
        const sentSeq = state.seq;
        lastTry = clock();
        const abort = new AbortController();
        const giveUp = setTimeout(() => abort.abort(), 10000);   // a dead network can hang a request for minutes
        inflight = fetch(boot.urls.save, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body,
            credentials: 'same-origin', keepalive: body.length < 60000, cache: 'no-store', signal: abort.signal,
        })
            .then((r) => r.json())
            .then((res) => handleResponse(res, sentSeq, final))
            .catch(() => handleOffline(final))
            .finally(() => {
                clearTimeout(giveUp);
                inflight = null;
                if (againAfter && !state.finished) { againAfter = false; scheduleSave(200); }
            });
        return inflight;
    }

    function handleResponse(res, sentSeq, final) {
        if (res.server_time) { serverBase = new Date(res.server_time).getTime(); clockBase = clock(); }
        if (res.deadline !== undefined) deadline = res.deadline ? new Date(res.deadline).getTime() : null;
        lastContact = clock();
        if (offlineSince) { state.events.offline_ms += Math.round(clock() - offlineSince); offlineSince = null; }
        setPaused(false);
        backoff = 0;
        if (res.ok) {
            state.ack = Math.max(state.ack, res.ack || 0);
            writeLocal();
            if (res.status && res.status !== 'in_progress') {
                finishedOnServer();
                return res;
            }
            if (state.ack >= state.seq) setSync('saved'); else scheduleSave(500);
            return res;
        }
        switch (res.error) {
            case 'other_device':
                stop();
                showMessage('monitor', 'This exam was opened somewhere else',
                    'Your answers are saved. To keep working on this computer, continue here.',
                    [['Continue here', () => { window.location.href = boot.urls.resume; }, 'btn-primary']]);
                break;
            case 'finished':
            case 'time_over':
                finishedOnServer(res.error === 'time_over');
                break;
            case 'account_removed':
                stop();
                setSync('error', 'Not saved');
                showMessage('lock', 'Your account is no longer active',
                    'Answers saved before this are kept. Please tell your teacher.', []);
                break;
            default:
                setSync('error', 'Not saved – reload the page');
                showMessage('alert', 'Your answers could not be saved',
                    'Please reload the page. Answers typed on this computer are kept.',
                    [['Reload', () => window.location.reload(), 'btn-primary']]);
        }
        return res;
    }

    function handleOffline(final) {
        setSync('offline');
        if (!offlineSince) offlineSince = clock();
        backoff = paused ? 3000 : Math.min(30000, backoff ? backoff * 2 : 2000);
        if (!final) scheduleSave(backoff);
        return null;
    }

    function stop() {
        state.finished = true;
        state.locked = true;
        clearTimeout(saveTimer);
        lockInputs();
    }

    function finishedOnServer(timeOver) {
        stop();
        clearLocal();
        setSync('saved', 'Submitted');
        showMessage(timeOver ? 'clock' : 'check-circle', timeOver ? 'Time is up' : 'Your answers were submitted',
            'Well done! You can see your result now.',
            [['See my result', () => { window.location.href = boot.urls.result; }, 'btn-primary']]);
    }

    // Heartbeat: also tells the teacher's monitor this computer is online
    setInterval(() => { if (!inflight && !state.finished) saveNow(false); }, 15000);
    window.addEventListener('online', () => { if (!state.finished) saveNow(false); });

    function beacon() {
        if (state.finished || state.ack >= state.seq || !navigator.sendBeacon) return;
        navigator.sendBeacon(boot.urls.save, new Blob([JSON.stringify(payload(false))], { type: 'application/json' }));
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !state.finished && !inflight) saveNow(false);   // back from another tab: check in at once
        if (document.visibilityState === 'hidden') {
            if (!state.finished) { state.events.blur++; writeLocal(); }
            beacon();
        }
    });
    window.addEventListener('pagehide', beacon);
    window.addEventListener('beforeunload', (e) => {
        if (!state.finished && state.ack < state.seq) { beacon(); e.preventDefault(); e.returnValue = ''; }
    });

    // ---------- Lost connection ----------
    /** Cover the exam while the server has not answered for over a minute; uncover when it answers. */
    function setPaused(on) {
        if (on === paused) return;
        paused = on;
        player.classList.toggle('paused', on);
        if (on) {
            state.locked = true;
            lockInputs();
            showMessage('wifi-off', 'Connection lost – your time is paused',
                'Your answers are safe on this computer. Keep this page open: the exam carries on as soon as the connection is back.', []);
            overlay.dataset.kind = 'paused';
            return;
        }
        if (overlay.dataset.kind === 'paused') overlay.hidden = true;
        if (state.finished || timeUpStarted) return;
        unlock();
    }

    function unlock() {
        state.locked = false;
        player.classList.remove('locked');
        if (worksheet) questions.forEach(rerender); else render();
    }

    // ---------- Timer ----------
    function tick() {
        if (!deadline || state.finished) { timerEl.hidden = !deadline; return; }
        timerEl.hidden = false;
        // Paused only after a failed save: a tab coming back from the background checks in first
        const now = clock();
        if (!timeUpStarted && offlineSince !== null && now - lastContact > PAUSE_AFTER_MS) {
            setPaused(true);
            if (!inflight && now - lastTry > 3000) saveNow(false);
        }
        const at = paused ? lastContact + PAUSE_AFTER_MS : now;
        const left = Math.max(0, Math.round((deadline - serverNow(at)) / 1000));
        const h = Math.floor(left / 3600);
        const m = Math.floor((left % 3600) / 60);
        const s = left % 60;
        timerEl.querySelector('span').textContent = (h ? `${h}:${String(m).padStart(2, '0')}` : String(m)) + ':' + String(s).padStart(2, '0');
        timerEl.classList.toggle('warn', left <= 300 && left > 60);
        timerEl.classList.toggle('danger', left <= 60);
        timerEl.classList.toggle('paused', paused);
        if (left === 0 && !paused) timeUp();
    }
    let timeUpStarted = false;
    function timeUp() {
        if (timeUpStarted) return;
        timeUpStarted = true;
        state.locked = true;
        lockInputs();
        showMessage('clock', 'Time is up', 'Sending your answers… please keep this page open.', []);
        submitWithRetry(true);
    }

    /** The server says time is left after all (this page's timer was wrong): carry on with its time. */
    function timeUpTooEarly() {
        timeUpStarted = false;
        overlay.hidden = true;
        unlock();
    }
    setInterval(tick, 500);
    tick();

    // ---------- Finish ----------
    function showMessage(icon, title, text, actions) {
        overlay.dataset.kind = '';
        overlayCard.innerHTML = '';
        overlayCard.appendChild(el('span', { class: 'big-icon' }, [Exams.icon(icon, 44)]));
        overlayCard.appendChild(el('h2', { text: title }));
        overlayCard.appendChild(el('p', { class: 'muted', text }));
        const row = el('div', { class: 'overlay-actions' });
        actions.forEach(([label, fn, cls]) => row.appendChild(el('button', { type: 'button', class: 'btn btn-lg ' + (cls || ''), text: label, onclick: fn })));
        overlayCard.appendChild(row);
        overlay.hidden = false;
        const first = row.querySelector('button');
        if (first) first.focus();
    }

    function showReview() {
        if (state.finished || paused) return;
        overlay.dataset.kind = '';
        const real = questions.filter((q) => q.type !== 'description');
        const missing = missingCount();   // an "answer any five" section needs five, not all of them
        const flagged = real.filter((q) => state.flags.has(q.slot));
        const numberOf = new Map(real.map((q, i) => [q.slot, i + 1]));
        overlayCard.innerHTML = '';
        overlayCard.appendChild(el('h2', { text: missing ? 'Almost done!' : 'All done!' }));
        const lines = [];
        if (missing) lines.push(`${missing} answer${missing > 1 ? 's are' : ' is'} still missing.`);
        if (flagged.length) lines.push(`${flagged.length} marked to look at again.`);
        lines.push('Tap a number to go back to it.');
        overlayCard.appendChild(el('p', { class: 'muted', text: lines.join(' ') }));
        overlayCard.appendChild(el('div', { class: 'review-legend' }, [
            el('span', { class: 'l-ans', text: 'Answered' }),
            el('span', { class: 'l-unanswered', text: 'Not answered' }),
            hasChoice ? el('span', { class: 'l-extra', text: 'Extra — you need not answer' }) : null,
        ]));
        const tileFor = (q) => {
            const tile = el('button', {
                type: 'button', text: String(numberOf.get(q.slot)),
                class: 'review-tile ' + (isAnswered(q) ? 'answered' : (isExtra(q) ? 'extra' : 'unanswered')) + (state.flags.has(q.slot) ? ' flagged' : ''),
            });
            tile.addEventListener('click', () => { overlay.hidden = true; goTo(q.index); });
            return tile;
        };
        if (!hasChoice) {
            const grid = el('div', { class: 'review-grid' });
            real.forEach((q) => grid.appendChild(tileFor(q)));
            overlayCard.appendChild(grid);
        } else {
            sectionList.forEach((s) => {
                if (!s.questions.length) return;
                const done = answeredIn(s);
                const enough = done >= s.choose;
                overlayCard.appendChild(el('div', { class: 'review-group' + (s.choose ? (enough ? ' ok' : ' choice') : '') }, [
                    s.choose ? Exams.icon(enough ? 'check-circle' : 'star', 16) : null,
                    el('strong', { text: s.title || `Section ${s.index + 1}` }),
                    s.choose ? el('span', { class: 'review-group-rule', text: enough
                        ? `${sectionRule(s)} · ${done} answered, that is enough`
                        : `${sectionRule(s)} · ${s.choose - done} more to go` }) : null,
                ]));
                const grid = el('div', { class: 'review-grid' });
                s.questions.forEach((q) => grid.appendChild(tileFor(q)));
                overlayCard.appendChild(grid);
            });
        }
        const submit = el('button', { type: 'button', class: 'btn btn-lg btn-accent', text: 'Submit my answers' });
        submit.addEventListener('click', () => {
            if (submit.dataset.armed !== '1') {
                submit.dataset.armed = '1';
                submit.textContent = missing ? 'Submit anyway?' : 'Yes, submit';
                return;
            }
            submit.disabled = true;
            submit.textContent = 'Submitting…';
            state.locked = true;
            lockInputs();
            submitWithRetry();
        });
        overlayCard.appendChild(el('div', { class: 'overlay-actions' }, [
            el('button', { type: 'button', class: 'btn btn-lg', text: 'Go back', onclick: () => { overlay.hidden = true; } }),
            submit,
        ]));
        overlay.hidden = false;
    }

    /** timeUp: sent by the timer; the server submits it only when its own clock says time is up. */
    async function submitWithRetry(timeUp) {
        for (let attempt = 1; !state.finished; attempt++) {
            state.seq++;
            writeLocal();
            const res = await saveNow(true, timeUp);
            if (state.finished) return;
            if (res === null) {
                showMessage('wifi-off', 'Cannot reach the server',
                    'Your answers are safe on this computer. Please keep this page open – we keep trying.', []);
                await new Promise((r) => setTimeout(r, Math.min(15000, 2000 * attempt)));
            } else if (!res.ok) {
                return;   // handleResponse showed what happened
            } else if (res.early) {
                timeUpTooEarly();
                return;
            }
        }
    }

    overlay.addEventListener('click', (e) => { if (e.target === overlay && !state.locked) overlay.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !overlay.hidden && !state.locked) overlay.hidden = true; });

    // ---------- Start ----------
    render();
    if (state.seq > state.ack) { setSync('saving'); saveNow(false); } else { setSync('saved'); }
})();
