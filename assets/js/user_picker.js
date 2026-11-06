/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 *
 * Username box with suggestions, made by user_picker() in lib/layout.php:
 *   <input type="text" name="users" data-user-picker="many|one" data-users='[["aril","Aril Tovan","5"], ...]'>
 * Typing suggests users by username or name (↑ ↓ to move, Enter or Tab to pick, Esc to close).
 * A chosen user becomes a chip with ×; Backspace in the empty box removes the last one.
 * Pasting "aril, belo" adds both. The input stays in the form, hidden, holding the usernames
 * joined by ", ", so the server reads it like the plain text field it replaces.
 */
(function () {
    'use strict';
    const MAX_SUGGESTIONS = 8;
    let uid = 0;

    function picker(input) {
        const many = input.dataset.userPicker !== 'one';
        const users = [];
        const byName = new Map();
        try {
            JSON.parse(input.dataset.users || '[]').forEach(([u, n, c]) => {
                const user = { u: String(u), n: String(n || u), c: String(c || '') };
                users.push(user);
                byName.set(user.u.toLowerCase(), user);
            });
        } catch (e) { return; }
        const id = 'up' + (++uid);
        const required = input.required;
        const placeholder = input.placeholder || 'Type a name or username';

        const box = document.createElement('div');
        box.className = 'user-picker' + (many ? '' : ' user-picker-one');
        const chips = document.createElement('span');
        chips.className = 'up-chips';
        const entry = document.createElement('input');
        entry.type = 'text';
        entry.className = 'up-entry';
        entry.autocomplete = 'off';
        entry.spellcheck = false;
        entry.setAttribute('role', 'combobox');
        entry.setAttribute('aria-autocomplete', 'list');
        entry.setAttribute('aria-expanded', 'false');
        entry.setAttribute('aria-controls', id + '-list');
        ['aria-labelledby', 'aria-label', 'id'].forEach((a) => {
            if (input.hasAttribute(a)) { entry.setAttribute(a, input.getAttribute(a)); if (a === 'id') input.removeAttribute('id'); }
        });
        const list = document.createElement('ul');
        list.className = 'up-list';
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        box.append(chips, entry, list);
        input.type = 'hidden';
        input.required = false;
        input.removeAttribute('placeholder');
        input.after(box);

        let chosen = [];
        let shown = [];
        let active = -1;

        function sync(quiet) {
            input.value = chosen.join(', ');
            entry.hidden = !many && chosen.length > 0;
            entry.required = required && chosen.length === 0;
            entry.placeholder = many && chosen.length ? '' : placeholder;
            if (!quiet) input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function chip(username) {
            const user = byName.get(username.toLowerCase());
            const el = document.createElement('span');
            el.className = 'up-chip' + (user ? '' : ' up-unknown');
            el.title = user ? `${user.n} (${user.u}${user.c ? ', Class ' + user.c : ''})` : `No user named "${username}": it is left out when saved`;
            const label = document.createElement('span');
            label.className = 'up-chip-name';
            label.textContent = user ? user.n : username;
            el.appendChild(label);
            if (user && user.n !== user.u) {
                const small = document.createElement('span');
                small.className = 'up-chip-user';
                small.textContent = user.u;
                el.appendChild(small);
            }
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'up-remove';
            x.setAttribute('aria-label', 'Remove ' + (user ? user.n : username));
            x.appendChild(Exams.icon('x', 14));
            x.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                remove(username);
                entry.focus();
            });
            el.appendChild(x);
            return el;
        }

        function render(quiet) {
            chips.textContent = '';
            chosen.forEach((u) => chips.appendChild(chip(u)));
            sync(quiet);
        }

        /** Add a user (an unknown username only in a many-box, shown red and left out by the server). */
        function add(username) {
            const user = byName.get(username.toLowerCase());
            if (!user && !many) return;
            const name = user ? user.u : username;
            if (!many) chosen = [];
            if (!chosen.some((u) => u.toLowerCase() === name.toLowerCase())) chosen.push(name);
            entry.value = '';
            entry.setCustomValidity('');
            close();
            render();
            if (entry.hidden) box.querySelector('.up-remove').focus();
        }

        function remove(username) {
            chosen = chosen.filter((u) => u !== username);
            render();
        }

        function matches(query) {
            const q = query.trim().toLowerCase();
            if (!q) return [];
            const taken = new Set(chosen.map((u) => u.toLowerCase()));
            const scored = [];
            users.forEach((user) => {
                if (taken.has(user.u.toLowerCase())) return;
                const u = user.u.toLowerCase();
                const n = user.n.toLowerCase();
                let score = -1;
                if (u === q) score = 0;
                else if (u.startsWith(q)) score = 1;
                else if (n.startsWith(q) || n.split(/[\s.]+/).some((w) => w.startsWith(q))) score = 2;
                else if (u.includes(q) || n.includes(q)) score = 3;
                if (score >= 0) scored.push([score, user]);
            });
            scored.sort((a, b) => a[0] - b[0] || a[1].n.localeCompare(b[1].n));
            return scored.slice(0, MAX_SUGGESTIONS).map((s) => s[1]);
        }

        function open() {
            shown = matches(entry.value);
            list.textContent = '';
            active = shown.length ? 0 : -1;
            if (!shown.length && entry.value.trim()) {
                const li = document.createElement('li');
                li.className = 'up-none';
                li.textContent = 'No one matches';
                list.appendChild(li);
            }
            shown.forEach((user, i) => {
                const li = document.createElement('li');
                li.id = `${id}-o${i}`;
                li.setAttribute('role', 'option');
                const strong = document.createElement('strong');
                strong.textContent = user.n;
                const meta = document.createElement('span');
                meta.className = 'muted';
                meta.textContent = user.u + (user.c ? ' · Class ' + user.c : '');
                li.append(strong, meta);
                li.addEventListener('mousedown', (ev) => { ev.preventDefault(); add(user.u); });
                list.appendChild(li);
            });
            list.hidden = !entry.value.trim();
            entry.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
            highlight();
        }

        function close() {
            list.hidden = true;
            shown = [];
            active = -1;
            entry.setAttribute('aria-expanded', 'false');
            entry.removeAttribute('aria-activedescendant');
        }

        function highlight() {
            list.querySelectorAll('[role=option]').forEach((li, i) => li.setAttribute('aria-selected', i === active ? 'true' : 'false'));
            if (active >= 0) {
                entry.setAttribute('aria-activedescendant', `${id}-o${active}`);
                list.children[active].scrollIntoView({ block: 'nearest' });
            } else {
                entry.removeAttribute('aria-activedescendant');
            }
        }

        // A comma or semicolon ends a typed username ("aril, belo"); spaces do not, since names have them
        entry.addEventListener('input', () => {
            entry.setCustomValidity('');
            if (/[,;]/.test(entry.value)) {
                const parts = entry.value.split(/[,;]+/);
                const rest = parts.pop();
                parts.map((p) => p.trim()).filter(Boolean).forEach(add);
                entry.value = rest.trimStart();
                entry.focus();
            }
            open();
        });
        // A pasted list of usernames, one per line or separated by commas or spaces
        entry.addEventListener('paste', (ev) => {
            const text = ((ev.clipboardData || window.clipboardData).getData('text') || '').trim();
            const parts = text.split(/[\s,;]+/).filter(Boolean);
            if (parts.length < 2 || !(/[,;\n]/.test(text) || parts.every((p) => byName.has(p.toLowerCase())))) return;
            ev.preventDefault();
            parts.forEach(add);
            entry.focus();
        });
        entry.addEventListener('keydown', (ev) => {
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                if (list.hidden) { open(); }
                if (shown.length) {
                    active = (active + (ev.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length;
                    highlight();
                }
                ev.preventDefault();
            } else if (ev.key === 'Enter') {
                if (!list.hidden && active >= 0) { ev.preventDefault(); add(shown[active].u); }
                else if (entry.value.trim()) { ev.preventDefault(); }
            } else if (ev.key === 'Tab') {
                if (!list.hidden && active >= 0 && entry.value.trim()) {
                    if (many) ev.preventDefault();
                    add(shown[active].u);
                }
            } else if (ev.key === 'Escape') {
                if (!list.hidden) { ev.preventDefault(); close(); }
            } else if (ev.key === 'Backspace' && entry.value === '' && chosen.length) {
                remove(chosen[chosen.length - 1]);
            }
        });
        entry.addEventListener('blur', () => {
            close();
            const text = entry.value.trim();
            if (!text) return;
            if (byName.has(text.toLowerCase())) { add(text); return; }
            entry.setCustomValidity(`No user named "${text}". Choose one from the list.`);
        });
        box.addEventListener('mousedown', (ev) => {
            if (ev.target === box || ev.target === chips) { ev.preventDefault(); (entry.hidden ? box.querySelector('.up-remove') : entry).focus(); }
        });

        chosen = input.value.split(/[\s,;]+/).filter(Boolean);
        render(true);
    }

    document.querySelectorAll('input[data-user-picker]').forEach(picker);
})();
